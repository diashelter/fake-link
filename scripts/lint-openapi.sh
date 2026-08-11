#!/usr/bin/env bash
# Lint docs/openapi.yaml with Spectral via the openapi-tooling Compose service (ABMC-01/02/04).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

# shellcheck disable=SC1091
set -a
# Compose --env-file substitutes image tags; pass PNPM into the container explicitly.
# Keep in sync with docker/versions.env (AD-005).
PNPM_VERSION="${PNPM_VERSION:-11.15.1}"
if [[ -f docker/versions.env ]]; then
  # shellcheck disable=SC1091
  source docker/versions.env
fi
set +a

# Mirror Makefile COMPOSE (docker/versions.env + base + dev overrides).
COMPOSE=(docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml)

"${COMPOSE[@]}" run --rm --no-deps \
  -e "PNPM_VERSION=${PNPM_VERSION}" \
  -e HUSKY=0 \
  openapi-tooling bash -c '
set -euo pipefail
corepack enable
corepack prepare "pnpm@${PNPM_VERSION}" --activate
pnpm install --frozen-lockfile
pnpm exec spectral lint docs/openapi.yaml --ruleset .spectral.yaml
'
