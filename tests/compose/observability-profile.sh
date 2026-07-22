#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

docker compose --env-file docker/versions.env --profile observability config --format json \
  | python3 -c '
import json, sys
config = json.load(sys.stdin)
service = config["services"].get("otel-collector")
if not service:
    raise SystemExit("Expected otel-collector when profile observability is active")
ports = service.get("ports") or []
if ports:
    raise SystemExit(f"otel-collector must not publish host ports: {ports}")
print("OK profile observability includes otel-collector without host ports")
'
