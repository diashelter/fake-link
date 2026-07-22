#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

WORKFLOW=".github/workflows/backend-quality.yml"
if [[ ! -f "${WORKFLOW}" ]]; then
  echo "FAIL: missing ${WORKFLOW}" >&2
  exit 1
fi

if ! grep -qE '^lint:' Makefile; then
  echo "FAIL: Makefile has no lint target" >&2
  exit 1
fi

# Fail if make lint is still a placeholder (echo-only stub) instead of a real pipeline.
LINT_BODY="$(awk '/^lint:/{flag=1; next} flag && /^[^[:space:]#]/{exit} flag{print}' Makefile)"
if [[ -z "${LINT_BODY}" ]]; then
  echo "FAIL: Makefile lint target has an empty body" >&2
  exit 1
fi

if printf '%s\n' "${LINT_BODY}" | grep -qiE 'echo[[:space:]]+"lint targets'; then
  echo "FAIL: make lint is still a placeholder" >&2
  exit 1
fi

if ! printf '%s\n' "${LINT_BODY}" | grep -qE 'lint-backend'; then
  echo "FAIL: make lint does not invoke lint-backend" >&2
  exit 1
fi

make lint-backend

echo "OK backend quality gates (workflow present, lint pipeline real, lint-backend exit 0)"
