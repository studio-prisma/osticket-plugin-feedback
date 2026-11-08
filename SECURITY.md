# Security Policy

## Supported Versions

Only the latest minor release receives security fixes.

| Version | Supported |
|---|---|
| `0.1.x` | yes |

## Reporting a Vulnerability

Use **[GitHub Security Advisories](https://github.com/studio-prisma/osticket-plugin-feedback/security/advisories/new)**. Reports there are private by default. Don't open a public issue for a suspected vulnerability.

Include:

- plugin version, osTicket version, the settings that matter (mode, scale, on a bad rating)
- what an attacker gains, not only what misbehaves
- reproduction steps, ideally against a clean install (`dev/up.sh` builds one)

Expected response: an acknowledgement within 7 days, an assessment within 30. This is a spare-time project, so that's the honest number, not a target.

## In Scope

- **A rating stored without a valid link**: a forged, altered, expired or guessed token that the click page accepts.
- **A link that rates another ticket** than the one it was sent for.
- **A rating stored by a plain page view** (GET, HEAD, a prefetch), i.e. without the form being submitted in a session.
- **A POST that stores anything without osTicket's CSRF token** for that session.
- **Ticket data on the click page** beyond the ticket number: subject, thread, names, addresses, status.
- **Markup from a comment rendered** on the click page, in a thread note, in the agent mail, in the assignment alert or on the evaluation page.
- **The token key or `SECRET_SALT`** in a response, a log line, an error message or the release package; a link that works while `SECRET_SALT` is the sample value.
- **The IP address stored in clear text.**
- **A redirect to an address outside the help desk** or a link built from the request's Host header.
- **Bypassing the rate limit** with the same IP, or growing the plugin's tables without bound from outside.
- **Asking twice for one ticket**, asking for a ticket an AI agent closed, a sub-ticket or an excluded department, help topic or domain.
- **Reopening or assigning more than once** for one ticket because of repeated or parallel clicks.
- **Access to the evaluation page** without an agent login, or its data for agents who aren't admins.
- **SQL injection** through any parameter of the click page or the settings.

## Out of Scope

These are documented design decisions, not findings:

- **Anyone who has the mail can rate.** The link is the authorisation, like a password-reset link. A forwarded mail lets the recipient rate and change the rating while it's valid.
- **Agents with the "with the mail" note can rate.** That setting copies the sent mail, links included, into an internal note. Every agent who can open the ticket can follow them. The default note has no links.
- **A link scanner that runs JavaScript** submits the rating like a person would. The confirmation step stops scanners that only fetch the page; one that executes the page in a sandbox is indistinguishable from a click.
- **Rate limit per IP** counts all customers behind one NAT together (60 requests per 10 minutes, about 20 ratings).
- **No revocation of single links** other than switching the plugin off (all links) or deleting the row in `<prefix>feedback_request`.
- **Emoji removed from thread notes and angle brackets shown as ‹ ›**: limits of osTicket's sanitizer and its 3-byte utf8 database connection, see the README.
- **A customer who replies to the feedback mail** reopens the ticket the way any reply to a ticket mail does. That's osTicket's behaviour.
- **osTicket core issues**, other plugins, server and mail configuration.
