#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

TMP_ENV="$(mktemp)"
trap 'rm -f "${TMP_ENV}"' EXIT

cp .env.example "${TMP_ENV}"
ENV_FILE="${TMP_ENV}" bash docker/scripts/validate-env.sh

echo "OK .env.example passes validate-env.sh"
