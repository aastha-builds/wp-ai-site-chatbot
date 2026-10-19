#!/usr/bin/env bash
# End-to-end test against a real WordPress (SQLite) install.
#
# Creates a throwaway site with bin/local-wp.sh, then checks: activation,
# demo import and indexing, the evaluation pass rate, the REST endpoints
# (nonce, length limit, rate limit, lead validation and storage), the CSV
# export, and that WP_DEBUG logged nothing from the plugin.
#
# Usage: bin/integration-test.sh            (same env vars as bin/local-wp.sh)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export WP_DIR="${WP_DIR:-$(mktemp -d)/site}"
export WP_PORT="${WP_PORT:-8099}"
URL="http://localhost:${WP_PORT}"
WP="${WP_CLI:-wp} --path=$WP_DIR"
PASS_MIN="${PASS_MIN:-27}"
FAILURES=0

pass() { echo "  ok   $1"; }
fail() { echo "  FAIL $1"; FAILURES=$((FAILURES + 1)); }
check() { if eval "$2"; then pass "$1"; else fail "$1"; fi; }

echo "== Setting up WordPress in $WP_DIR"
DEMO_MODE=true "$ROOT/bin/local-wp.sh" setup >/dev/null
: > "$WP_DIR/wp-content/debug.log"

php -S "localhost:${WP_PORT}" -t "$WP_DIR" "$WP_DIR/router.php" >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
sleep 1

echo "== Content and index"
STATUS="$($WP sitesage status)"
check "demo mode is on" "grep -q 'Mode:       demo' <<<\"\$STATUS\""
check "24 chunks indexed" "grep -q 'Index:      24 chunks from 24 sources' <<<\"\$STATUS\""
check "8 pages imported" "[ \"\$($WP post list --post_type=page --post_status=publish --meta_key=_sitesage_demo --format=count)\" = 8 ]"
check "15 FAQs imported" "[ \"\$($WP post list --post_type=sitesage_faq --post_status=publish --format=count)\" = 15 ]"

echo "== Evaluation"
REPORT="$(mktemp)"
$WP sitesage eval --report="$REPORT" >/dev/null 2>&1 || true
PASSED="$(grep -o 'Result: [0-9]* of 30' "$REPORT" | awk '{print $2}')"
check "eval passes at least $PASS_MIN of 30 (got ${PASSED:-0})" "[ \"\${PASSED:-0}\" -ge $PASS_MIN ]"

echo "== REST API"
HOME_HTML="$(curl -s "$URL/")"
NONCE="$(grep -o '"nonce":"[^"]*"' <<<"$HOME_HTML" | head -1 | cut -d'"' -f4)"
check "widget config is printed on the front end" "[ -n \"$NONCE\" ]"
check "widget script is enqueued" "grep -q 'assets/js/widget.js' <<<\"\$HOME_HTML\""

ask() { curl -s -o /dev/null -w '%{http_code}' -X POST "$URL/wp-json/sitesage/v1/ask" -H 'Content-Type: application/json' -H "X-SiteSage-Nonce: $1" -d "$2"; }

BODY="$(curl -s -X POST "$URL/wp-json/sitesage/v1/ask" -H 'Content-Type: application/json' -H "X-SiteSage-Nonce: $NONCE" -d '{"question":"Do you take my insurance?"}')"
check "answer cites the Insurance page" "grep -q '\"title\":\"Insurance\"' <<<\"\$BODY\""
check "out-of-scope question hands off" "curl -s -X POST '$URL/wp-json/sitesage/v1/ask' -H 'Content-Type: application/json' -H 'X-SiteSage-Nonce: $NONCE' -d '{\"question\":\"What is the capital of France?\"}' | grep -q '\"fallback\":true'"
check "bad nonce is rejected (403)" "[ \"\$(ask bad '{\"question\":\"hi\"}')\" = 403 ]"
LONG="$(php -r 'echo json_encode(array("question" => str_repeat("a", 600)));')"
check "over-long message is rejected (400)" "[ \"\$(ask $NONCE '$LONG')\" = 400 ]"

CODES=""
for _ in $(seq 1 16); do CODES="$CODES $(ask "$NONCE" '{"question":"Are you open on Saturday?"}')"; done
check "rate limit returns 429 after the limit" "grep -q 429 <<<\"\$CODES\""

LEAD_BAD="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$URL/wp-json/sitesage/v1/lead" -H 'Content-Type: application/json' -H "X-SiteSage-Nonce: $NONCE" -d '{"name":"A","phone":"1","email":"x"}')"
check "invalid lead is rejected (400)" "[ \"$LEAD_BAD\" = 400 ]"
BEFORE="$($WP eval 'echo ( new SiteSage\Lead_Store() )->count();')"
curl -s -o /dev/null -X POST "$URL/wp-json/sitesage/v1/lead" -H 'Content-Type: application/json' -H "X-SiteSage-Nonce: $NONCE" -d '{"name":"Casey Test","phone":"(415) 555-0190","email":"casey@example.com","question":"Can I book?"}'
AFTER="$($WP eval 'echo ( new SiteSage\Lead_Store() )->count();')"
check "valid lead is saved" "[ \"$AFTER\" -eq \$(( BEFORE + 1 )) ]"
curl -s -o /dev/null -X POST "$URL/wp-json/sitesage/v1/lead" -H 'Content-Type: application/json' -H "X-SiteSage-Nonce: $NONCE" -d '{"name":"Bot","phone":"(415) 555-0191","email":"bot@example.com","website":"spam.example"}'
check "honeypot lead is dropped" "[ \"\$($WP eval 'echo ( new SiteSage\Lead_Store() )->count();')\" -eq $AFTER ]"

echo "== Admin"
CSV="$($WP eval 'echo SiteSage\Leads_Export::to_csv( ( new SiteSage\Lead_Store() )->all() );')"
check "CSV export has the new lead" "grep -q 'Casey Test' <<<\"\$CSV\""
check "re-index on save" "$WP post update \$($WP post list --post_type=page --name=pricing --field=ID) --post_content='<p>We sell gift cards for any amount, starting at fifty dollars.</p>' >/dev/null && $WP sitesage ask 'Do you sell gift cards?' | grep -q 'Pricing'"

echo "== Debug log"
if grep -qi 'sitesage' "$WP_DIR/wp-content/debug.log" 2>/dev/null; then
	fail "WP_DEBUG logged SiteSage notices:"; grep -i sitesage "$WP_DIR/wp-content/debug.log" | head -5
else
	pass "no SiteSage notices, warnings or errors with WP_DEBUG on"
fi

echo
if [ "$FAILURES" -eq 0 ]; then echo "All integration checks passed."; else echo "$FAILURES integration check(s) failed."; exit 1; fi
