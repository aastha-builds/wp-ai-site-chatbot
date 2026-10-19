# Demo video script (60 to 90 seconds)

Demo project with fictional data. Record at 1280×800, browser zoom 100%, the WordPress Playground link or `bin/local-wp.sh serve` open in one tab and the admin in another. Keep the mouse slow and pause a beat after each answer appears.

| Time | Screen | Action | Voice-over |
| --- | --- | --- | --- |
| 0:00 to 0:07 | BrightSmile homepage, chat closed | Hover the chat bubble | "This is SiteSage Chat, a WordPress plugin that answers your visitors from your own website. Here it's running on a demo dental clinic." |
| 0:07 to 0:17 | WP Admin > SiteSage Chat > Overview | Click **Index site content**, point at "24 sources" and the table | "One click reads every page, the FAQ list and even this PDF guide, splits them into chunks and stores embeddings. Pages re-index on save." |
| 0:17 to 0:32 | Front end, chat open | Click the suggestion "How much is teeth whitening?" | "A patient asks about whitening. The answer comes only from the site, stays short, and links the exact page it used." Point at **Sources**. |
| 0:32 to 0:42 | Chat | Type "Do you take my insurance?" | "Second question, and it asks for their details so the clinic can follow up." Show the lead form. |
| 0:42 to 0:50 | Chat | Fill Name, Phone, Email, click **Send details** | "Leads go to WordPress and straight into a Google Sheet." |
| 0:50 to 0:57 | Chat | Type "Can you recommend a pizza place?" | "If the site doesn't have the answer, it doesn't guess. It hands over to the team." Point at **Talk to a person**. |
| 0:57 to 1:07 | WP Admin > Leads | Show the new row, click **Export CSV** | "Every lead is here, with CSV export." |
| 1:07 to 1:17 | WP Admin > Settings | Scroll provider, model, masked API key | "Pick OpenAI or Claude, any model. Keys are never shown back. Demo mode runs without any key." |
| 1:17 to 1:27 | WP Admin > Evaluation | Show 29 / 30 and the table | "And it's measured: 30 real patient questions, a pass or fail report on every run." |
| 1:27 to 1:30 | GitHub repo | Show README | "Code, demo and Playground link are on GitHub." |

## Tips

- Use the Playground link so viewers can repeat exactly what they saw.
- Hide the WordPress admin bar on the front end (log out, or use a private window) so the chat is the focus.
- Keep the final cut under 90 seconds. Cut the Settings scene first if you need time.
- After uploading, put the video link on the "Watch the 90-second video" button in `docs/index.html` and at the top of the README.
