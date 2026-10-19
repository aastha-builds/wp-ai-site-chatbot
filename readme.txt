=== SiteSage Chat ===
Contributors: aastha-builds
Tags: chatbot, ai, openai, claude, lead capture
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

An AI chatbot that answers visitors from your own pages, FAQs and PDFs, links the source page, captures leads and hands off to a person.

== Description ==

SiteSage Chat indexes your published pages, a FAQ post type and PDFs from the Media Library, and answers visitor questions using only that content. It works with OpenAI or Anthropic Claude, and runs in demo mode without any API key.

* One-click indexing into chunks of about 500 tokens, with automatic re-indexing when you save a page.
* Answers under 120 words with a link to the source page, or "I don't know that one, let me connect you with our team" when your content has no answer.
* Lead capture after two questions or on words like book, appointment and price, with an admin table, CSV export and a Google Sheets webhook.
* "Talk to a person" button for WhatsApp or email.
* Nonces, rate limiting per IP, a message length limit, and only the question plus the retrieved text are sent to the AI.
* `wp sitesage eval` runs a set of test questions and writes a pass or fail report.

This is a portfolio demo project. The bundled BrightSmile Dental demo content is fictional.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP from Plugins > Add New.
2. Activate SiteSage Chat.
3. Open SiteSage Chat > Settings. Keep demo mode on to try it, or add your API keys to go live.
4. Click SiteSage Chat > Overview > Index site content.

== Frequently Asked Questions ==

= Which API keys do I need? =

An OpenAI key for embeddings. For answers, the same OpenAI key or an Anthropic key if you choose Claude. Demo mode needs no key.

= What is sent to the AI provider? =

Only the visitor's question and the four most relevant passages from your site. No chat history and no contact details.

= Where are leads stored? =

In the `{prefix}sitesage_leads` table, visible under SiteSage Chat > Leads, and optionally posted to your Google Sheet.

== Changelog ==

= 1.0.0 =
* First release.
