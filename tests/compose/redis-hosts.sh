#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE=(docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml)

redis_host="$("${COMPOSE[@]}" exec -T backend printenv REDIS_HOST)"
redis_queue_host="$("${COMPOSE[@]}" exec -T backend printenv REDIS_QUEUE_HOST)"

if [[ "${redis_host}" != "redis-ephemeral" ]]; then
  echo "Expected REDIS_HOST=redis-ephemeral, got ${redis_host}" >&2
  exit 1
fi

if [[ "${redis_queue_host}" != "redis-queue" ]]; then
  echo "Expected REDIS_QUEUE_HOST=redis-queue, got ${redis_queue_host}" >&2
  exit 1
fi

if [[ "${redis_host}" == "${redis_queue_host}" ]]; then
  echo "REDIS_HOST and REDIS_QUEUE_HOST must be distinct instances" >&2
  exit 1
fi

"${COMPOSE[@]}" exec -T backend php -r '
$ephemeral = new Redis();
$ephemeral->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
$queue = new Redis();
$queue->connect(getenv("REDIS_QUEUE_HOST"), (int) getenv("REDIS_QUEUE_PORT"));
if ($ephemeral->ping() !== true && $ephemeral->ping() !== "+PONG") {
    fwrite(STDERR, "Failed to ping redis-ephemeral\n");
    exit(1);
}
if ($queue->ping() !== true && $queue->ping() !== "+PONG") {
    fwrite(STDERR, "Failed to ping redis-queue\n");
    exit(1);
}
echo "OK backend connects to distinct Redis hosts\n";
'
