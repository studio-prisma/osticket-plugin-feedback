# Local test stack

osTicket 1.18.4 on PHP 8.3 / Apache, MariaDB 11.4 and Mailpit, all on `127.0.0.1`. For development only.

```bash
./dev/up.sh      # build, install, seed
./dev/down.sh    # stop and delete (the database lives on tmpfs)
```

`up.sh` generates all passwords on the first run into `dev/local/credentials.env`. `dev/local/` is git-ignored; nothing secret is part of the repository. The seed writes agent, department and status IDs to `dev/local/seed.json`.

| URL | What |
|---|---|
| `http://127.0.0.1:8095/` | Client portal and the click page (`ajax.php/feedback/<token>`) |
| `http://127.0.0.1:8095/scp/` | Agent panel, evaluation under Applications → Feedback |
| `http://127.0.0.1:8031/` | Mailpit: every mail osTicket sends |

The ports differ from the other Studio Prisma stacks, so they can run side by side.

The repository root is mounted read-only as `include/plugins/feedback`, so edits show up without a rebuild. The plugin is installed and switched on by the seed, with German texts and the department "Alerts" excluded.

## Test data

`seed/seed.php` creates one customer (`alice@acme.example`), the department "Alerts", two human agents (hanna, paul) and "Mail Bot", an AI agent the way AI Actions creates them (backend `ai-agent`). The specs create their own tickets.

## A second stack

```bash
SPF_LOCAL=dev/local-2 SPF_PROJECT=spf-dev-2 SPF_HTTP_PORT=8096 SPF_MAIL_PORT=8032 ./dev/up.sh
(cd tests/e2e && SPF_LOCAL=../../dev/local-2 npx playwright test)
SPF_LOCAL=dev/local-2 ./dev/down.sh
```

The variables are read once, when `credentials.env` is created in that folder; afterwards the file decides.

## Building without zip (Windows)

Git Bash on Windows has no `zip`. Build in a container:

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$PWD":/src -w /src alpine:3.20 sh -c 'apk add --no-cache bash zip git >/dev/null && git config --global --add safe.directory /src && ./tools/build-zip.sh'
```
