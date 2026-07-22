#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE=(docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml)

started_at="$(date +%s)"
"${COMPOSE[@]}" stop -t 30 analytics-worker
elapsed="$(( $(date +%s) - started_at ))"

if (( elapsed > 35 )); then
  echo "Expected analytics-worker to stop within stop_grace_period (30s), took ${elapsed}s" >&2
  exit 1
fi

appendonly="$("${COMPOSE[@]}" exec -T redis-queue redis-cli CONFIG GET appendonly | tail -n 1)"
if [[ "${appendonly}" != "yes" ]]; then
  echo "Expected redis-queue appendonly=yes after SIGTERM stop, got ${appendonly}" >&2
  exit 1
fi

if ! "${COMPOSE[@]}" exec -T redis-queue redis-cli PING | grep -q PONG; then
  echo "redis-queue did not respond after worker stop" >&2
  exit 1
fi

"${COMPOSE[@]}" start analytics-worker
"${COMPOSE[@]}" up -d --wait analytics-worker

echo "OK graceful stop within ${elapsed}s; redis-queue AOF intact"
