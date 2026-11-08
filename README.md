# Feedback for osTicket

One satisfaction question when a ticket is closed. The customer clicks once in the mail, the rating lands at the ticket as an internal note, and a bad rating puts the ticket straight back on the desk of the agent who last replied.

**[Deutsche Fassung →](README.de.md)**

Ratings stay in the help desk's own database. No survey service, no tracking pixel, no third party.

---

## What it does

- **When a ticket is closed** after at least one reply by a human agent, the customer gets one question. Never twice per ticket.
- **Two ways to ask:** an own mail right after closing (default), or a line under the reply that closes the ticket.
- **Three scales:** thumbs (default), stars 1–5, NPS 0–10. "Bad" means thumbs down, up to 2 stars, NPS up to 6; both thresholds are settings.
- **On a bad rating:** reopen and assign to the last agent who replied (default). osTicket's own assignment alert carries rating and comment in `%{comments}`. Or only a note plus a mail to the responsible agent, the ticket stays closed.
- **Click page** without login: confirms, says thank you, offers an optional comment. It shows the ticket number and nothing else of the ticket. The rating can be changed while the link is valid (14 days by default); the latest one counts.
- **Evaluation** for admins under Applications → Feedback: per week and month, share of good and bad, average or NPS, the latest comments.

## Who never gets asked

- tickets without a reply by a human agent
- tickets an AI agent closes (agents with the backend `ai-agent`, as [AI Actions](https://github.com/studio-prisma/osticket-plugin-ai-actions) creates them), including closes it makes in osTicket's cron
- sub-tickets
- departments, help topics and sender domains from the settings; the help desk's own domains by default
- closes with a status like "Duplicate" or "Spam" (setting, preselected by name)
- tickets that were already asked

## Requirements

- osTicket 1.18.x (tested: 1.18.4), PHP 8.1 or later with `mbstring`
- a `SECRET_SALT` in `include/ost-config.php` that isn't the sample value (the installer sets one). Without it the plugin asks nobody and accepts no link.
- osTicket's cron running (for queued mails that a request didn't finish and for bad ratings that wait for a comment)
- the help desk URL under Admin → Settings → System set to the public address: links are built from it, never from the request

## Installation

Step by step with checkpoints: **[docs/INSTALL.md](docs/INSTALL.md)**. Short version: unzip into `include/plugins/feedback/`, install under Admin → Manage → Plugins, check the exceptions, switch on "Ask customers".

## Settings

| Setting | Choices | Default |
|---|---|---|
| Ask customers | on / off | off |
| When to ask | own mail / line under the closing reply | own mail |
| Scale | thumbs / stars / NPS | thumbs |
| On a bad rating | reopen + assign / note + mail | reopen + assign |
| Bad means | stars up to … / NPS up to … | 2 / 6 |
| Link valid for | days (1–90) | 14 |
| Comment field after the click | on / off | on |
| Note at the ticket when asked | none / short / with the mail as sent | short; a failed send always gets a note |
| Never ask for | departments, help topics, closing statuses, sender domains, own domains | departments named like "Alert"/"Protokoll", statuses named like "Duplicate"/"Spam"/"Hinfällig", own domains |
| Texts | subject, mail text, question, line, AI notice; empty = built-in text | built-in, German (informal) or English |
| Accent colour | `#rrggbb` | `#330066` |

The mail texts accept osTicket variables such as `%{ticket.number}` and `%{recipient.name.first}`.

## How it works

- **Close detection:** the plugin listens to `model.updated` on tickets and reacts when the status changes from an open to a closed state. In "mail" mode it only queues the request; the mail goes out at the end of the request, after osTicket's own reply mail. The cron sends whatever a broken request left behind.
- **"Line" mode** hooks into `Ticket::postReply()` without a core patch: osTicket creates the reply, then sets the status, then renders the mail from the same reply object. The plugin appends the question to the reply's cached body in between. The mail carries the line, the stored thread doesn't.
- **Links** carry a random 128-bit value and an HMAC-SHA256 (key derived from `SECRET_SALT` under its own subkey) over ticket ID, expiry and that value. No ticket ID in the URL.
- **Link scanners** such as Microsoft Safe Links open every link in a mail. A page view never stores anything: the page submits a form by JavaScript, with a button for browsers without it. A scanner that doesn't run the form rates nothing.

## Known limits

- **No emoji in thread notes.** osTicket decodes HTML entities before saving and its database connection is 3-byte utf8, so a 👍 would vanish. Notes say "Daumen hoch"/"thumbs up"; stars (★) work. Emojis in comments are dropped from the note but kept in the plugin's table and on the evaluation page.
- **Angle brackets in comments** appear as ‹ › in the note, because osTicket's sanitizer strips anything that looks like a tag.
- **"Line" mode** needs a reply that sets the closed status. Closing without a reply asks nobody in that mode, and a reply posted with "Reply to: none" carries no line and doesn't count as asked.
- **Safe Links with JavaScript:** a scanner that runs the page's JavaScript in a sandbox would submit the rating. Not observed so far; to be checked against a real Microsoft 365 tenant.
- **Checkbox defaults on a new instance:** osTicket 1.18 doesn't store them on the first save. Open the instance again after creating it and check the boxes (see INSTALL, step 2).
- **"With the mail" note** puts the customer's rating links into the ticket. Any agent who can open the ticket can follow them; that is a trade-off for seeing exactly what went out.
- **Only one question,** no reminders, no export, no per-agent rating. These are out of scope on purpose.
- **AI Actions weekly summary:** the plugin answers the signal `feedback.digest` with one line. AI Actions doesn't send it yet.

## Data protection

Stored per asked ticket: ticket ID, rating, comment, time, a keyed hash of the IP address (rate limit), never the IP itself. Rows are deleted after 24 months. Add a sentence to the privacy notice, for example:

> After a ticket is closed we may ask you once how satisfied you were. Rating and optional comment are stored with the ticket in our help desk for up to 24 months and only used to improve our support.

## Development

```bash
./dev/up.sh                       # osTicket 1.18.4, MariaDB, Mailpit on 127.0.0.1:8095 / :8031
php tests/unit.php                # unit tests, no osTicket
(cd tests/e2e && npm ci && npx playwright test)
./tools/build-zip.sh              # dist/feedback-<version>.zip
```

Details: [CONTRIBUTING.md](CONTRIBUTING.md), [dev/README.md](dev/README.md). Security reports: [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later, like osTicket, whose classes the plugin extends. See [LICENSE](LICENSE).
