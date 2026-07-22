#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

docker compose --env-file docker/versions.env config > /dev/null

echo "OK docker compose config is valid"
