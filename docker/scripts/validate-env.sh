#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
ENV_FILE="${ENV_FILE:-${REPO_ROOT}/.env}"

REQUIRED_VARS=(
  COMPOSE_PROJECT_NAME
  NGINX_HTTPS_PORT
  POSTGRES_USER
  POSTGRES_PASSWORD
  POSTGRES_DB
  APP_KEY
  APP_ENV
  APP_DEBUG
  APP_URL
  SHORT_HOST
  NEXT_PUBLIC_APP_URL
  REDIS_HOST
  REDIS_PORT
  REDIS_QUEUE_HOST
  REDIS_QUEUE_PORT
  POSTGRES_PUBLISH_PORT
  REDIS_EPHEMERAL_PUBLISH_PORT
  REDIS_QUEUE_PUBLISH_PORT
)

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing environment file: ${ENV_FILE}" >&2
  echo "Copy .env.example to .env and fill required values." >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

missing=()
for var in "${REQUIRED_VARS[@]}"; do
  if [[ -z "${!var:-}" ]]; then
    missing+=("${var}")
  fi
done

if ((${#missing[@]} > 0)); then
  echo "Missing required environment variables:" >&2
  for var in "${missing[@]}"; do
    echo "  - ${var}" >&2
  done
  exit 1
fi

echo "Environment validation passed (${ENV_FILE})."
