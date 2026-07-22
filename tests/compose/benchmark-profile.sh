#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

docker compose --env-file docker/versions.env --profile benchmark config --format json \
  | python3 -c '
import json, sys
config = json.load(sys.stdin)
workers = [name for name in config["services"] if name.startswith("analytics-worker")]
if set(workers) != {"analytics-worker", "analytics-worker-2"}:
    raise SystemExit(f"Expected 2 analytics workers under profile benchmark, got {workers}")
print("OK profile benchmark exposes two analytics workers")
'
