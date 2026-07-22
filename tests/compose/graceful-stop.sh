#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE=(docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml)

grace_seconds="$("${COMPOSE[@]}" config --format json | python3 -c '
import json, sys, re
config = json.load(sys.stdin)
raw = config["services"]["analytics-worker"].get("stop_grace_period")
if raw is None:
    raise SystemExit("analytics-worker must declare stop_grace_period")
if isinstance(raw, (int, float)):
    print(int(raw))
    raise SystemExit
text = str(raw)
match = re.fullmatch(r"(\d+)(ns|us|µs|ms|s|m|h)?", text)
if not match:
    raise SystemExit(f"Unrecognized stop_grace_period: {raw!r}")
value = int(match.group(1))
unit = match.group(2) or "s"
factor = {"ns": 1e-9, "us": 1e-6, "µs": 1e-6, "ms": 1e-3, "s": 1, "m": 60, "h": 3600}[unit]
seconds = int(value * factor)
if seconds < 1:
    raise SystemExit(f"stop_grace_period too small: {raw!r}")
print(seconds)
')"

if [[ "${grace_seconds}" -lt 30 ]]; then
  echo "Expected analytics-worker stop_grace_period >= 30s, got ${grace_seconds}s" >&2
  exit 1
fi

started_at="$(date +%s)"
"${COMPOSE[@]}" stop -t "${grace_seconds}" analytics-worker
elapsed="$(( $(date +%s) - started_at ))"
limit="$(( grace_seconds + 5 ))"

if (( elapsed > limit )); then
  echo "Expected analytics-worker to stop within stop_grace_period (${grace_seconds}s), took ${elapsed}s" >&2
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

echo "OK graceful stop using compose stop_grace_period=${grace_seconds}s; redis-queue AOF intact"
