#!/usr/bin/env bash
# Stops the local test stack and deletes its data (the database runs on tmpfs).
# SPF_LOCAL picks a second stack, see up.sh.
set -euo pipefail
DEV="$(cd "$(dirname "$0")" && pwd)"
LOCAL="${SPF_LOCAL:-$DEV/local}"
CRED="$LOCAL/credentials.env"
set -a; . "$CRED"; set +a
ARGS=(-p "${SPF_PROJECT:-spf-dev}" --env-file "$CRED" -f "$DEV/docker-compose.yml")
[ -f "$LOCAL/compose.override.yml" ] && ARGS+=(-f "$LOCAL/compose.override.yml")
docker compose "${ARGS[@]}" down --remove-orphans
