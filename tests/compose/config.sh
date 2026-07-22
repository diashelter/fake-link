#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

docker compose --env-file docker/versions.env \
  -f docker-compose.yml -f docker-compose.dev.yml config > /dev/null

echo "OK docker compose config is valid"
