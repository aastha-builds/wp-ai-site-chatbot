# Testing SiteSage Chat

Demo project with fictional data. Three layers of tests, plus a manual checklist.

## 1. Automated tests

| Suite | Command | What it covers |
| --- | --- | --- |
| PHP unit tests (74 tests) | `composer install && vendor/bin/phpunit` | Chunker, cosine similarity and top-k ranking, demo embedder and tokenizer, prompt builder (system rules, only question and context sent), answer service (threshold, handoff, cited sources, refusals, 120-word limit), demo and live providers (OpenAI and Claude request/response shapes), rate limiter, lead intent words, lead validation, CSV formula guard, Google Sheets payload, settings sanitizing and key masking, handoff links, PDF text extraction, evaluator and report, demo data consistency |
| Coding standards | `vendor/bin/phpcs` | WordPress Coding Standards (WordPress ruleset) and PHP 7.4+ compatibility |
| JavaScript tests | `npm test` | GitHub Pages keyword matcher: all 30 saved questions, 12 paraphrases, out-of-scope handoff, source links, tokenizer parity |
| End to end | `bin/integration-test.sh` | Real WordPress with SQLite: activation, demo import, indexing, `wp sitesage eval` pass rate, REST nonce, length limit, rate limit (429), lead validation, honeypot, lead storage, CSV export, re-index on save, and no notices in `debug.log` with `WP_DEBUG` on |
| Evaluation | `wp sitesage eval` | 30 patient questions in `docs/test-questions.csv`, report in `docs/eval-report.md` |

All of these run in GitHub Actions on every push (`.github/workflows/ci.yml`).

`bin/integration-test.sh` needs PHP with `pdo_sqlite`, WP-CLI as `wp` and internet for the first WordPress download. It creates a throwaway site in a temp folder and removes nothing else.

## 2. Manual checklist

Run `bin/local-wp.sh setup && bin/local-wp.sh serve`, or open the Playground link. Log in as `admin` / `password` locally.

### Install and settings
- [ ] Activating the plugin creates `wp_sitesage_chunks` and `wp_sitesage_leads` and shows **SiteSage Chat** in the admin menu (Overview, FAQs, Leads, Evaluation, Settings).
- [ ] With `WP_DEBUG` and `WP_DEBUG_LOG` on, browsing every SiteSage screen and the front end adds nothing to `wp-content/debug.log`.
- [ ] Settings: save an API key, reload. The field is empty and shows only `••••••••` plus the last 4 characters. Saving again with the field empty keeps the key. "Remove saved value" deletes it.
- [ ] Switching provider to Anthropic Claude changes the model placeholder to `claude-opus-5-5`.
- [ ] With `define( 'SITESAGE_DEMO_MODE', true );` in `wp-config.php`, the demo mode checkbox is disabled and explains why.
- [ ] A user without `manage_options` (Editor) cannot open any SiteSage screen.

### Indexing
- [ ] **Overview > Index site content** shows "Indexed N sources into N chunks" and the table lists pages, the PDF and FAQs.
- [ ] Edit a page, add a sentence, update. Ask the chat about that sentence: the new text is found without re-indexing manually.
- [ ] Trash a FAQ: it disappears from the Overview table.
- [ ] Upload a text-based PDF to the Media Library: it appears in the table after upload.
- [ ] Turn demo mode off without an OpenAI key: the Overview shows "Add an OpenAI API key for embeddings, or turn on demo mode." and the button is disabled.

### Chat widget
- [ ] The bubble appears bottom right in the brand color. Changing the brand color in Settings changes the bubble and header.
- [ ] Tab to the bubble, press Enter: the panel opens and focus is in the message box. Esc closes it and focus returns to the bubble.
- [ ] The greeting and the suggested questions show. Clicking a suggestion asks it.
- [ ] While waiting, a typing indicator shows. The answer has a "Read more" link and a Sources list.
- [ ] Ask "What is the capital of France?": the reply is exactly "I don't know that one, let me connect you with our team" with no sources.
- [ ] A 600-character message is blocked (the box stops at the max length; the API returns 400 if forced).
- [ ] On a phone-sized screen (375 px) the open chat fills the screen and the close button works.
- [ ] Screen reader: new messages are announced (the log is `aria-live="polite"`).

### Lead capture and handoff
- [ ] The lead form appears after the 2nd question, or straight away when a message contains book, appointment or price.
- [ ] Focus moves to the Name field when the form appears.
- [ ] Empty fields, an invalid email or a too-short phone show an error. A valid submission shows the thanks message and the lead appears in **Leads**.
- [ ] **Leads > Export CSV** downloads a CSV; a name starting with `=` is exported as `'=`.
- [ ] With a Google Sheets webhook set (see `integrations/google-sheets.gs`), a new lead adds a row and the Leads table shows "Sent". A wrong secret leaves the sheet unchanged.
- [ ] "Talk to a person" opens WhatsApp (`wa.me/<number>`) or a pre-filled email, depending on the setting.

### Guardrails
- [ ] Send 16 questions quickly: the 16th is refused with "You are sending messages too quickly" (default 15 per 10 minutes).
- [ ] Calling `/wp-json/sitesage/v1/ask` without the `X-SiteSage-Nonce` header returns 403.
- [ ] With a live key and request logging on your side, the request body contains only the system prompt, the retrieved passages and the question.

### Evaluation
- [ ] `wp sitesage eval` prints one PASS/FAIL line per question and writes `docs/eval-report.md`.
- [ ] **Evaluation > Run evaluation** shows the same result in the admin.

### GitHub Pages preview
- [ ] `docs/index.html` loads with no console errors and no horizontal scroll at 375 px.
- [ ] The demo shows the label "Interactive preview with sample data", answers the suggested questions, links source anchors, and the lead form says nothing is sent.

### Playground
- [ ] The Playground link installs the plugin from GitHub, imports BrightSmile Dental, and opens the homepage with the chat open.
