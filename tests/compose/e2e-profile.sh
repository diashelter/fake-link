#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE_PROJECT_NAME=fake_link_e2e \
  docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.e2e.yml --profile e2e config --format json \
  | python3 -c '
import json
import sys

config = json.load(sys.stdin)
name = config.get("name", "")
if name != "fake_link_e2e":
    raise SystemExit(f"Expected project name fake_link_e2e, got {name!r}")

if "mailpit" not in config["services"]:
    raise SystemExit("Expected mailpit service when profile e2e is active")

for service in ("postgres", "redis-ephemeral", "redis-queue"):
    ports = config["services"][service].get("ports") or []
    if ports:
        raise SystemExit(f"Service {service} must not publish ports in profile e2e: {ports}")

frontend_target = config["services"]["frontend"].get("build", {}).get("target", "")
if frontend_target != "e2e":
    raise SystemExit(f"Expected frontend build target e2e, got {frontend_target!r}")

print("OK profile e2e isolates project, includes mailpit, and uses e2e frontend stage")
'
