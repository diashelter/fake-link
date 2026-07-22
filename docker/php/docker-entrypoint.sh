#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "php-fpm" ]]; then
  exec docker-php-entrypoint php-fpm
fi

exec "$@"
