#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

body="$(
  curl -fsSk \
    --resolve "app.localhost:443:127.0.0.1" \
    "https://app.localhost/docs/"
)"

if [[ "${body}" != *[Ss]wagger* ]]; then
  echo "Expected Swagger UI HTML from https://app.localhost/docs/" >&2
  echo "Body (truncated): ${body:0:200}" >&2
  exit 1
fi

echo "OK https://app.localhost/docs/ → Swagger UI"
