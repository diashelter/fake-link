#!/usr/bin/env bash
# Build amd64 + arm64 images via buildx (DOCKER-25).
# Same Dockerfiles for every architecture — no manual Dockerfile edits.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

# shellcheck disable=SC1091
source "${REPO_ROOT}/docker/versions.env"

PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
REGISTRY="${REGISTRY:-}"
TAG="${TAG:-local}"
PUSH="${PUSH:-false}"

builder_name="${BUILDX_BUILDER:-fake-link-multiarch}"
if ! docker buildx inspect "${builder_name}" >/dev/null 2>&1; then
  docker buildx create --name "${builder_name}" --use
else
  docker buildx use "${builder_name}"
fi

build_image() {
  local context="$1"
  local dockerfile="$2"
  local target="$3"
  local name="$4"
  shift 4

  local image="${name}:${TAG}"
  if [[ -n "${REGISTRY}" ]]; then
    image="${REGISTRY%/}/${image}"
  fi

  local -a args=(
    docker buildx build
    --platform "${PLATFORMS}"
    --file "${dockerfile}"
    --target "${target}"
    --tag "${image}"
  )

  while [[ $# -gt 0 ]]; do
    args+=(--build-arg "$1")
    shift
  done

  if [[ "${PUSH}" == "true" ]]; then
    args+=(--push)
  else
    args+=(--load)
  fi

  # --load only supports a single platform; multi-platform local builds use --push or omit load.
  if [[ "${PUSH}" != "true" && "${PLATFORMS}" == *","* ]]; then
    echo "Multi-platform local build cannot --load; building without loading into docker (use PUSH=true to push)." >&2
    args=("${args[@]/--load}")
  fi

  args+=("${context}")
  echo "+ ${args[*]}"
  "${args[@]}"
}

build_image \
  "${REPO_ROOT}/docker/php" \
  "${REPO_ROOT}/docker/php/Dockerfile" \
  prod \
  fake-link-backend \
  "PHP_VERSION=${PHP_VERSION}" \
  "COMPOSER_VERSION=${COMPOSER_VERSION}"

build_image \
  "${REPO_ROOT}" \
  "${REPO_ROOT}/docker/node/Dockerfile" \
  prod \
  fake-link-frontend \
  "NODE_VERSION=${NODE_VERSION}" \
  "PNPM_VERSION=${PNPM_VERSION}" \
  "NEXT_VERSION=${NEXT_VERSION}"

echo "OK multiarch build requested for platforms: ${PLATFORMS}"
