# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-11-08

### Added

- **Closing statuses that never ask** (setting "Never ask for → Closing statuses"). Preselected: closed statuses named like "Duplicate", "Spam", "Rejected" or "Hinfällig". Closing with such a status leaves no trace; a later close with another status still asks.
- **Note at the ticket when asked** (own mail mode): short (recipient, scale, valid until; default), short plus the mail as sent with its rating links, or none.
- **Note on a failed send**, always: the question couldn't be sent and isn't retried.

### Documentation

- INSTALL: after creating the instance, open it again and check the checkboxes. osTicket 1.18 doesn't store checkbox defaults on the first save.
- INSTALL: new settings apply their built-in default until the instance is saved once.

## [0.1.0] - 2026-11-03

### Added

- **One satisfaction question per ticket** when it changes to a closed status after a reply by a human agent. No question for tickets without such a reply, tickets an AI agent closes (backend `ai-agent`, also inside osTicket's cron), sub-tickets, excluded departments, help topics and sender domains, and the help desk's own domains (setting, on by default).
- **Two modes:** an own mail right after closing (default), sent at the end of the request after osTicket's reply mail, with the cron as fallback; or a line under the reply that closes the ticket, added to the outgoing mail only, never to the stored thread.
- **Three scales:** thumbs (default), stars 1–5, NPS 0–10, with settable thresholds for "bad" (2 stars, NPS 6).
- **Bad rating:** reopen and assign to the last human agent who replied, with osTicket's assignment alert carrying rating and comment in `%{comments}` (default); or a note plus a mail to the responsible agent. With the comment field on, the action waits up to 10 minutes for the comment.
- **Click page** `ajax.php/feedback/<token>` without login: confirmation form that submits itself (and a button without JavaScript), thank-you page, optional comment, rating changeable while the link is valid (14 days by default). Shows the ticket number only.
- **Links:** random 128-bit nonce plus HMAC-SHA256 over ticket ID, expiry and nonce, key derived from `SECRET_SALT`; no ticket ID in the URL. Rate limit per IP hash and per token.
- **Evaluation page** for admins (Applications → Feedback): per week and month, share good/bad, average or NPS, latest comments.
- Signal `feedback.digest` that answers with one summary line for a weekly digest.
- Retention: rows are deleted after 24 months; the IP is stored only as a keyed hash.
- Mail in the style of a brand line over an accent rule, buttons as table cells, a grey footer with an AI notice; texts editable, German (informal) and English built in.

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-feedback/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/studio-prisma/osticket-plugin-feedback/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/studio-prisma/osticket-plugin-feedback/releases/tag/v0.1.0
