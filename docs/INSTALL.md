# Installation: Feedback for osTicket

The order is binding. Every step ends with a check; if it fails, stop there.

**[Deutsche Fassung →](INSTALL.de.md)**

---

## 0. Before you start

You need:

- osTicket 1.18.x with access to the admin panel
- shell or SFTP access to `include/plugins/`
- a test ticket with a customer address that is **not** one of the help desk's own domains (e.g. a private address). Own domains are never asked by default, so a test with your company address stays silent.

Check three things first:

1. **`SECRET_SALT`**: `include/ost-config.php` has a long random string, not `%CONFIG-SIRI`. Without it the plugin asks nobody (the evaluation page then shows a red message).
2. **Cron**: osTicket's cron (`api/cron.php`) runs at least every 5 minutes. Without it, mails a broken request left behind stay queued, and a bad rating without a comment never reopens the ticket.
3. **Help desk URL**: Admin → Settings → System → "Helpdesk URL" is the public https address. The links in the mails are built from it.

**Kill switch.** If the plugin causes trouble, either

- Admin → Manage → Plugins → Feedback → instance → "Ask customers" off → Save (no new questions; links already sent keep working), or
- untick the instance → Save (questions and click page gone at once), or
- delete the folder `include/plugins/feedback/`.

Ratings, notes and assignments that already happened stay ordinary osTicket data.

Stop if: `SECRET_SALT` is missing or the sample value, or no cron runs.

---

## 1. Files

1. Unzip `feedback-<version>.zip` into `include/plugins/`. Afterwards `include/plugins/feedback/plugin.php` exists.
2. Permissions as for the other plugins: readable by the web server, not writable.

Check: `include/plugins/feedback/` holds `plugin.php`, `config.php`, `class.FeedbackPlugin.php`, `LICENSE` and `src/` with 13 files.

---

## 2. Install the plugin, don't switch it on yet

1. Admin → Manage → Plugins → "Add New Plugin" → Feedback → Install.
2. Status "Active", Save.
3. Tab "Instances" → "Add New Instance", name e.g. `Feedback`, tick "Active".
4. Go through the instance settings. **"Ask customers" stays off.**
   - "Never ask for → Departments": departments for alerts, monitoring, logs. Preselected are departments with "Alert", "Protokoll" or "Monitoring" in their name.
   - "Closing statuses": closing with one of these never asks, e.g. "Duplicate" or "Spam". Preselected are closed statuses with "Duplicate", "Spam", "Rejected" or "Hinfällig" in their name.
   - "Help topics" and "Sender domains" as needed, e.g. partner domains that shouldn't be asked.
   - "Note at the ticket when asked": short (default), with the mail as sent, or none. With the mail, every agent who opens the ticket sees the customer's rating links.
   - "Who decides": "Agents can switch the question off" (tick when closing, More menu) and "Closing without an agent form" for API, cron and mass changes in the ticket list. If tools such as an MCP server close tickets through the API, "only when requested" makes every such close an explicit decision (`feedback` field of API Endpoints 2.9.0).
   - Leave the texts empty for the built-in text. "Language of the built-in texts" decides between German (informal "du") and English; "Help desk language" follows the system language.
5. Save.
6. **Open the instance again and check the checkboxes.** osTicket 1.18 doesn't store checkbox defaults when an instance is created: after the first save "Comment field after the click", "Own domains" and "Footer with AI notice" can be off although they are on by default. Tick them again where wanted, Save, open once more and check they stay ticked.

Check: agent panel → Applications → "Feedback" opens the evaluation with "State: off" and no red message.

Stop if: red message about `SECRET_SALT` (back to step 0).

---

## 3. Prepare the assignment alert (only for "reopen + assign")

On a bad rating osTicket sends its own assignment alert to the agent. Rating and comment are in it only if the template contains `%{comments}`.

1. Admin → Emails → Templates → the departments' set → "Ticket Assigned" (assigned.alert) contains `%{comments}`. osTicket's stock template has it; check custom sets.
2. Admin → Settings → Alerts and Notices → "Ticket Assignment Alert" is on, recipient "Assigned Agent".

Check: both set. Without step 2 the ticket is still reopened and assigned, only the mail is missing.

---

## 4. Switch on and test

1. "Ask customers" on, Save.
2. Create a test ticket with the test address from step 0, reply as an agent, close the ticket.
3. The test mailbox has a mail "A quick question about your ticket [number]" (German: "Kurze Frage zu deinem Ticket") with 👍/👎.
4. At the ticket: internal note "Satisfaction question" with recipient and validity. "Could not be sent" there means osTicket's outgoing mail failed (system log; with OAuth check the token of the outgoing account).
5. Click 👍: page "Thank you!", at the ticket the internal note "Customer rating · Rating: thumbs up (satisfied)". The ticket stays closed.
6. Second test ticket, same steps, click 👎, write a comment, "Send comment": the ticket is open, assigned to the agent who replied, and that agent has an assignment alert with rating and comment.

Stop if: no mail arrives in step 3. Check first: is there a note "Satisfaction question" at the ticket (see step 4)? Is the customer address an own domain? Did a human agent reply (not an AI agent)? Is the department or the closing status excluded? Is there a "Feedback plugin" entry in the system log?

---

## 5. Security gate

| Case | How | Expected |
|---|---|---|
| Forged link | change one character after the dot in a link from the mail, open it | "Link not valid", nothing changes at the ticket |
| Page view without a click | fetch the link with `curl` (like a scanner) | page with a button, rating stays empty |
| Other ticket | open the link of ticket A | changes ticket A only |
| Expired | open after the validity | "Link not valid" |
| HTML in the comment | `<script>alert(1)</script>` as comment | the note shows ‹script›…‹/script› as text, nothing runs |
| Many requests | more than 60 requests in 10 minutes from one IP | "Too many requests" (HTTP 429) |
| Second close | reopen the ticket, close it again | no second question |
| Switch off without token | `curl -X POST …/scp/ajax.php/feedback/ticket/<id>/decline` with a valid agent session cookie but no CSRF header | HTTP 400, nothing changes |
| Switch off a foreign ticket | the same POST as an agent without access to the ticket | HTTP 403, nothing changes |

---

## 6. Privacy notice

Add a sentence about the rating to the customer privacy notice, for example:

> After a ticket is closed we may ask you once how satisfied you were. Rating and optional comment are stored with the ticket in our help desk for up to 24 months and only used to improve our support.

Stored: ticket ID, rating, comment, time and a hash of the IP address (for the rate limit), not the IP itself. The cron deletes rows after 24 months.

---

## 7. Update and removal

- **Update:** unzip the new folder over the old one. Tables and settings stay. New settings apply their built-in default until the instance is saved once: open the instance, check the new fields, Save.
- **Removal:** delete the instance, uninstall the plugin, delete the folder. The tables `<prefix>feedback_request` and `<prefix>feedback_hit` stay in the database until you drop them yourself. The notes at the tickets stay.
