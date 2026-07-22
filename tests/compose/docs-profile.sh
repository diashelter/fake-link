#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

docker compose --env-file docker/versions.env --profile docs config --format json \
  | python3 -c '
import json
import sys

config = json.load(sys.stdin)
service = config["services"].get("swagger-ui")
if not service:
    raise SystemExit("Expected swagger-ui when profile docs is active")

image = service.get("image", "")
if "swaggerapi/swagger-ui" not in image:
    raise SystemExit(f"Unexpected swagger-ui image: {image!r}")

volumes = service.get("volumes") or []
mounted = any("openapi.yaml" in json.dumps(volume) for volume in volumes)
if not mounted:
    raise SystemExit("swagger-ui must mount docs/openapi.yaml")

print("OK profile docs includes swagger-ui with OpenAPI mount")
'
