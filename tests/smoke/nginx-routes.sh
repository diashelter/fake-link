#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

curl_https() {
  local method="$1"
  local host="$2"
  local path="$3"
  shift 3

  curl -sS -k \
    --resolve "${host}:443:127.0.0.1" \
    -X "${method}" \
    -o /tmp/fake-link-smoke-body \
    -w "%{http_code}" \
    "$@" \
    "https://${host}${path}"
}

assert_status_body() {
  local method="$1"
  local host="$2"
  local path="$3"
  local expected_status="$4"
  local expected_body="$5"
  local status body

  status="$(curl_https "${method}" "${host}" "${path}")"
  body="$(cat /tmp/fake-link-smoke-body)"

  if [[ "${status}" != "${expected_status}" ]]; then
    echo "Expected ${method} https://${host}${path} → ${expected_status}, got ${status}" >&2
    echo "Body: ${body}" >&2
    exit 1
  fi

  if [[ -n "${expected_body}" && "${body}" != *"${expected_body}"* ]]; then
    echo "Unexpected body from ${method} https://${host}${path}: ${body}" >&2
    exit 1
  fi

  echo "OK ${method} https://${host}${path} → ${status}"
}

# DOCKER-04 — app.localhost routing
assert_status_body GET app.localhost /api/v1/health 200 '{"status":"ok"}'
assert_status_body GET app.localhost / 200 'Fake Link'

# DOCKER-05 — go.localhost allowlist + method rejection
assert_status_body GET go.localhost /robots.txt 200 'Disallow: /'
assert_status_body POST go.localhost / 405 ''

echo "OK nginx route smoke assertions passed"
