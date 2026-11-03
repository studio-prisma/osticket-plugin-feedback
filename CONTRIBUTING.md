# Contributing

Thanks for taking the time. This plugin sends mail to customers and runs a public page without login, so the bar for changes is verification, not volume.

## Before you open a pull request

1. **Open an issue first** for anything beyond a typo or a documentation fix.
2. **Changes to the click page or the token need a threat-model note.** If your change touches `FeedbackPage`, `FeedbackToken` or `FeedbackStore`, describe in the PR what an outsider with a link (or without one) could now do, and why that's acceptable.
3. **Changes to who gets asked** (`FeedbackRules`, `FeedbackTrigger`) need a unit test for the new case and an end-to-end test that the mail goes out or doesn't.
4. **Never report a vulnerability as a pull request.** See [SECURITY.md](SECURITY.md).

## Development setup

No build step for the plugin and no PHP dependencies.

```bash
git clone https://github.com/studio-prisma/osticket-plugin-feedback.git
cd osticket-plugin-feedback
php tests/unit.php
```

For the end-to-end suite you need Docker and Node.js 20 or newer:

```bash
./dev/up.sh                         # osTicket 1.18.4 + MariaDB + Mailpit on 127.0.0.1
cd tests/e2e && npm ci && npx playwright install chromium && npx playwright test
./dev/down.sh
```

`dev/README.md` explains the stack and a second stack next to the first.

To build the install package:

```bash
./tools/build-zip.sh                # writes dist/feedback-<version>.zip
```

## What a change has to pass

| Check | Command |
|---|---|
| Syntax, all production files | `find . -name '*.php' -not -path './tests/*' -not -path './dev/*' -exec php -l {} \;` |
| Unit tests | `php tests/unit.php` |
| End-to-end | `npx playwright test` in `tests/e2e/` against `dev/up.sh` |

CI runs syntax and unit tests on PHP 8.1 to 8.4 plus the full end-to-end suite on every push and pull request. The aggregating job is called **Gate**; it's the only status check the branch protection requires.

## Code conventions

- **PHP 8.1 compatible**, no runtime dependencies.
- **Nothing may break closing a ticket.** Signal handlers catch `Throwable` and log to osTicket's system log.
- **Every query takes values through `db_input()` or an int cast.**
- **No 4-byte characters into osTicket's tables.** The connection is 3-byte utf8; thread notes go through `FeedbackRules::noteText()`, the plugin's own text columns store JSON with `\u` escapes.
- **Mail CSS stays within what caniemail lists for the large clients:** no `text-transform`, `letter-spacing`, `font-weight`, `max-width`, `border-radius` or `display`. Bold is `<strong>`, buttons are table cells.
- **Links come from osTicket's help desk URL**, never from `Host` or `X-Forwarded-*`.
- **Every invalid link gets the same neutral page.** No error tells an outsider which check failed.

## Documentation

Behaviour change: update `README.md` **and** `README.de.md`. Install or configuration change: `docs/INSTALL.md` **and** `docs/INSTALL.de.md`. New limitation: add it to "Known limits".

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/), imperative, English:

```
feat(page): show the rating scale when the link has no value
fix(trigger): skip tickets an AI agent closed in the cron
docs(install): explain the own-domain exception
```

## Release process

Maintainer only.

1. `## [Unreleased]` → `## [X.Y.Z] - YYYY-MM-DD`, add a fresh empty `Unreleased` block
2. bump the version in `plugin.php` and `FeedbackPlugin::VERSION` (the release workflow refuses a mismatch)
3. `git commit -m "chore(release): vX.Y.Z"`, merge via PR
4. `git tag -a vX.Y.Z -m "vX.Y.Z"` from the merged `main`, push the tag
5. the release workflow builds `feedback-X.Y.Z.zip`, attests its provenance and publishes the notes from the changelog

## License

By contributing you agree that your contribution is licensed under the [GPL-2.0-or-later](LICENSE), like osTicket.
