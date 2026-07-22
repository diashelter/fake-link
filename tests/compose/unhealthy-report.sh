#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE=(docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml)
OVERRIDE="$(mktemp)"
trap 'rm -f "${OVERRIDE}"; "${COMPOSE[@]}" up -d --force-recreate --wait redis-ephemeral >/dev/null 2>&1 || true' EXIT

cat > "${OVERRIDE}" <<'YAML'
services:
  redis-ephemeral:
    healthcheck:
      test: ["CMD-SHELL", "exit 1"]
      interval: 2s
      timeout: 1s
      retries: 1
      start_period: 0s
YAML

"${COMPOSE[@]}" -f "${OVERRIDE}" up -d --force-recreate --no-deps redis-ephemeral

health="starting"
for _ in $(seq 1 60); do
  health="$("${COMPOSE[@]}" -f "${OVERRIDE}" ps --format json redis-ephemeral | python3 -c '
import json, sys
raw = sys.stdin.read().strip()
if not raw:
    print("unknown")
    raise SystemExit
print(json.loads(raw.splitlines()[0]).get("Health") or "unknown")
')"
  if [[ "${health}" == "unhealthy" ]]; then
    break
  fi
  sleep 1
done

if [[ "${health}" != "unhealthy" ]]; then
  echo "Expected redis-ephemeral to report unhealthy after healthcheck failures, got ${health}" >&2
  exit 1
fi

trap - EXIT
rm -f "${OVERRIDE}"
"${COMPOSE[@]}" up -d --force-recreate --wait redis-ephemeral

echo "OK docker compose ps reports unhealthy after healthcheck failure"
