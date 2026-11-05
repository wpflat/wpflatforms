# WPFlat Forms

Online forms your clients fill in, for WordPress. Needs [WPFlat Core](https://github.com/ulrea-dev/wpflatcore).

- A public page per form: `yoursite.com/form/<address>/`, no login needed.
- Sections that repeat per company: choose "number of subsidiaries" and the company questions are asked once for the parent and once for each subsidiary.
- Answers save as people type; they get an emailed private link to come back and finish. A submitted form can still be edited through that link.
- In the WPFlat app (Forms): the link to share, open/closed, who to email on submit, every answer (including forms still in progress), copy as text, download Markdown, export CSV (one row per company).
- A cover page before the form (who it is from, what it is for, how saving works), then the form. Forms can be attached to a client; a client's page shows a Forms tab.
- File uploads: a drop zone per company (up to 50 MB each, common document/image types), stored privately; only your team can open them.
- Admins see each person's private resume link next to their answers.
- Spam protection: hidden honeypot field and rate limits.
- Ships with a generic "Website content brief" form. Forms are data, not code: one JSON file per form in `templates/` (add your own through the `wpflatforms_template_dirs` filter; `{site}` in any text becomes your site name): sections of fields (`text`, `textarea`, `email`, `tel`, `url`, `number`, `select`, `radio`, `checkboxes`, `heading`, `note`, plus `count` and `companies` for repeating groups).

Settings → Site home page in WPFlat Core can send your website's home page straight to the first open form.
