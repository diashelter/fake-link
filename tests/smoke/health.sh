#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE=(docker compose --env-file docker/versions.env)

assert_health() {
  local host="$1"
  local body

  body="$(
    curl -fsSk \
      --resolve "${host}:443:127.0.0.1" \
      "https://${host}/health"
  )"

  if [[ "${body}" != '{"status":"ok"}' ]]; then
    echo "Unexpected body from https://${host}/health: ${body}" >&2
    exit 1
  fi

  echo "OK https://${host}/health → 200 ${body}"
}

assert_health app.localhost
assert_health go.localhost
