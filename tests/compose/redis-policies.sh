#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE=(docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml)

read_ephemeral_policy() {
  "${COMPOSE[@]}" exec -T redis-ephemeral redis-cli CONFIG GET maxmemory-policy | tail -n 1
}

read_queue_policy() {
  "${COMPOSE[@]}" exec -T redis-queue redis-cli CONFIG GET maxmemory-policy | tail -n 1
}

ephemeral_policy="$(read_ephemeral_policy)"
queue_policy="$(read_queue_policy)"
ephemeral_appendonly="$("${COMPOSE[@]}" exec -T redis-ephemeral redis-cli CONFIG GET appendonly | tail -n 1)"
queue_appendonly="$("${COMPOSE[@]}" exec -T redis-queue redis-cli CONFIG GET appendonly | tail -n 1)"
ephemeral_save="$("${COMPOSE[@]}" exec -T redis-ephemeral redis-cli CONFIG GET save | tail -n 1)"

if [[ "${ephemeral_policy}" != "allkeys-lru" ]]; then
  echo "Expected redis-ephemeral maxmemory-policy=allkeys-lru, got ${ephemeral_policy}" >&2
  exit 1
fi

if [[ "${queue_policy}" != "noeviction" ]]; then
  echo "Expected redis-queue maxmemory-policy=noeviction, got ${queue_policy}" >&2
  exit 1
fi

if [[ "${ephemeral_appendonly}" != "no" ]]; then
  echo "Expected redis-ephemeral appendonly=no, got ${ephemeral_appendonly}" >&2
  exit 1
fi

if [[ "${queue_appendonly}" != "yes" ]]; then
  echo "Expected redis-queue appendonly=yes, got ${queue_appendonly}" >&2
  exit 1
fi

if [[ -n "${ephemeral_save}" && "${ephemeral_save}" != "" ]]; then
  echo "Expected redis-ephemeral save disabled (empty), got ${ephemeral_save}" >&2
  exit 1
fi

echo "OK redis-ephemeral maxmemory-policy=${ephemeral_policy} appendonly=${ephemeral_appendonly}"
echo "OK redis-queue maxmemory-policy=${queue_policy} appendonly=${queue_appendonly}"
