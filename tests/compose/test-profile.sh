#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE_PROJECT_NAME=fake_link_test \
  docker compose --env-file docker/versions.env --profile test config --format json \
  | python3 -c '
import json
import sys

config = json.load(sys.stdin)
name = config.get("name", "")
if name != "fake_link_test":
    raise SystemExit(f"Expected project name fake_link_test, got {name!r}")

for service in ("postgres", "redis-ephemeral", "redis-queue"):
    ports = config["services"][service].get("ports") or []
    if ports:
        raise SystemExit(f"Service {service} must not publish ports in profile test: {ports}")

volumes = config.get("volumes") or {}
for key in ("postgres_data", "redis_queue_data"):
    volume = volumes.get(key) or {}
    volume_name = volume.get("name", "")
    if "fake_link_test" not in volume_name:
        raise SystemExit(
            f"Volume {key} must be isolated under fake_link_test, got {volume_name!r}"
        )

if "test-runner" not in config["services"]:
    raise SystemExit("Expected test-runner service when profile test is active")

print("OK profile test isolates project, volumes, and datastore ports")
'
