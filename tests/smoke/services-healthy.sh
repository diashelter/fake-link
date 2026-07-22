#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE=(docker compose --env-file docker/versions.env)

rows="$("${COMPOSE[@]}" ps --format '{{.Service}} {{.Health}}')"

if [[ -z "${rows}" ]]; then
  echo "No compose services are running." >&2
  exit 1
fi

failed=0

while IFS= read -r row; do
  [[ -z "${row}" ]] && continue

  service="${row%% *}"
  health="${row#* }"

  if [[ -n "${health}" && "${health}" != "healthy" ]]; then
    echo "Service ${service} is not healthy (${health})." >&2
    failed=1
  fi
done <<< "${rows}"

if ((failed != 0)); then
  "${COMPOSE[@]}" ps
  exit 1
fi

echo "OK all running services report healthy"
