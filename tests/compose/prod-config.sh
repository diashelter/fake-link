#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

docker compose --env-file docker/versions.env \
  -f docker-compose.yml -f docker-compose.prod.yml \
  config --format json \
  | python3 -c '
import json
import sys

config = json.load(sys.stdin)
services = config["services"]

for name in ("postgres", "redis-ephemeral", "redis-queue"):
    ports = services[name].get("ports") or []
    if ports:
        raise SystemExit(f"Prod datastore {name} must not publish ports: {ports}")

for name in (
    "backend",
    "frontend",
    "scheduler",
    "analytics-worker",
    "analytics-worker-2",
    "notification-worker",
    "nginx",
):
    service = services[name]
    if service.get("restart") != "unless-stopped":
        raise SystemExit(f"{name} must use restart: unless-stopped")

    for volume in service.get("volumes") or []:
        source = volume.get("source", "") if isinstance(volume, dict) else str(volume)
        if source.endswith("/backend") or source.endswith("/frontend") or "./backend" in source or "./frontend" in source:
            raise SystemExit(f"{name} must not bind-mount application code: {volume}")

    limits = ((service.get("deploy") or {}).get("resources") or {}).get("limits") or {}
    if not limits.get("cpus") or not limits.get("memory"):
        raise SystemExit(f"{name} must declare CPU/memory limits")

if "analytics-worker-2" not in services:
    raise SystemExit("Prod config must include analytics-worker-2 (2+1 workers)")

print("OK prod compose config: closed datastores, no code mounts, limits, 2+1 workers")
'
