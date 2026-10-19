#!/usr/bin/env bash
# Spin up a throwaway WordPress site with SQLite (no MySQL needed), install
# SiteSage Chat from this folder, import the BrightSmile demo and serve it.
#
# Usage:   bin/local-wp.sh [setup|serve|reset]
# Needs:   PHP 7.4+ with pdo_sqlite, WP-CLI (wp) on PATH, internet for the
#          first download. Reads .env if present (DEMO_MODE, API keys).
#
# Optional environment variables:
#   WP_DIR            Where the site lives (default: .wp)
#   WP_PORT           Port for the PHP server (default: 8080)
#   WP_SOURCE         Local WordPress core folder to copy instead of downloading
#   SQLITE_PLUGIN_ZIP Local sqlite-database-integration zip instead of downloading
#   WP_CLI            WP-CLI command (default: wp)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_DIR="${WP_DIR:-$ROOT/.wp}"
WP_PORT="${WP_PORT:-8080}"
WP_URL="http://localhost:${WP_PORT}"
WP="${WP_CLI:-wp} --path=$WP_DIR"
CMD="${1:-setup}"

if [ -f "$ROOT/.env" ]; then
	set -a
	# shellcheck disable=SC1091
	. "$ROOT/.env"
	set +a
fi

setup() {
	mkdir -p "$WP_DIR"
	if [ ! -f "$WP_DIR/wp-load.php" ]; then
		if [ -n "${WP_SOURCE:-}" ]; then
			cp -R "$WP_SOURCE"/. "$WP_DIR"/
			rm -rf "$WP_DIR/.git"
		else
			$WP core download
		fi
	fi

	local sqlite_dir="$WP_DIR/wp-content/plugins/sqlite-database-integration"
	if [ ! -d "$sqlite_dir" ]; then
		mkdir -p "$WP_DIR/wp-content/plugins"
		local zip="${SQLITE_PLUGIN_ZIP:-}"
		if [ -z "$zip" ]; then
			zip="$WP_DIR/sqlite.zip"
			curl -sSL -o "$zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
		fi
		local tmp
		tmp="$(mktemp -d)"
		unzip -q "$zip" -d "$tmp"
		mv "$tmp"/*/ "$sqlite_dir"
		rm -rf "$tmp"
	fi
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$sqlite_dir#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$sqlite_dir/db.copy" > "$WP_DIR/wp-content/db.php"

	if [ ! -f "$WP_DIR/wp-config.php" ]; then
		$WP config create --dbname=wordpress --dbuser=wp --dbpass=wp --dbhost=localhost --skip-check --force
		$WP config set WP_DEBUG true --raw
		$WP config set WP_DEBUG_LOG true --raw
		$WP config set WP_DEBUG_DISPLAY false --raw
	fi

	if ! $WP core is-installed 2>/dev/null; then
		$WP core install --url="$WP_URL" --title="BrightSmile Dental" --admin_user=admin \
			--admin_password=password --admin_email=admin@example.com --skip-email
	fi
	$WP option update home "$WP_URL" >/dev/null
	$WP option update siteurl "$WP_URL" >/dev/null

	mkdir -p "$WP_DIR/wp-content/plugins"
	ln -sfn "$ROOT" "$WP_DIR/wp-content/plugins/sitesage-chat"
	$WP plugin activate sitesage-chat
	$WP sitesage demo import --with-leads --remove-sample-content
	$WP sitesage status

	cat > "$WP_DIR/router.php" <<'PHP'
<?php
// Router for the PHP built-in server: serve files, run folder index.php
// files (wp-admin/), send everything else to WordPress.
$path = rawurldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
if ( '/' !== $path && is_file( __DIR__ . $path ) ) {
	return false;
}
$dir = rtrim( $path, '/' );
if ( '' !== $dir && is_file( __DIR__ . $dir . '/index.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = $dir . '/index.php';
	chdir( __DIR__ . $dir );
	require __DIR__ . $dir . '/index.php';
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
PHP

	echo
	echo "Ready. Run: bin/local-wp.sh serve   then open $WP_URL/?sitesage_open=1"
	echo "Admin: $WP_URL/wp-admin  (admin / password)"
}

serve() {
	echo "Serving $WP_URL (Ctrl+C to stop)"
	php -S "localhost:${WP_PORT}" -t "$WP_DIR" "$WP_DIR/router.php"
}

case "$CMD" in
	setup) setup ;;
	serve) serve ;;
	reset) rm -rf "$WP_DIR" && setup ;;
	*) echo "Usage: $0 [setup|serve|reset]" >&2; exit 1 ;;
esac
