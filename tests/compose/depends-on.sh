#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE=(docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml)

"${COMPOSE[@]}" config --format json | python3 -c '
import json, sys

config = json.load(sys.stdin)
services = config["services"]

required = {
    "backend": {"postgres", "redis-ephemeral", "redis-queue"},
    "frontend": {"backend"},
    "nginx": {"backend", "frontend"},
    "scheduler": {"postgres", "redis-queue"},
    "analytics-worker": {"postgres", "redis-queue"},
    "notification-worker": {"postgres", "redis-queue"},
}

for service_name, expected_deps in required.items():
    depends = services[service_name].get("depends_on") or {}
    if not isinstance(depends, dict):
        raise SystemExit(f"{service_name} depends_on must be a mapping with conditions")
    actual = set(depends)
    if not expected_deps.issubset(actual):
        raise SystemExit(f"{service_name} missing depends_on {expected_deps - actual}")
    for dep in expected_deps:
        condition = depends[dep].get("condition") if isinstance(depends[dep], dict) else None
        if condition != "service_healthy":
            raise SystemExit(
                f"{service_name} must depend on {dep} with service_healthy, got {depends[dep]!r}"
            )

print("OK depends_on service_healthy graph matches design")
'
