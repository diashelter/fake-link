#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CERTS_DIR="${SCRIPT_DIR}/../nginx/certs"
CA_KEY="${CERTS_DIR}/ca.key"
CA_CERT="${CERTS_DIR}/ca.pem"
DAYS_VALID=825

mkdir -p "${CERTS_DIR}"

generate_ca() {
  if [[ -f "${CA_CERT}" && -f "${CA_KEY}" ]]; then
    return 0
  fi

  openssl req -x509 -newkey rsa:4096 -sha256 -days "${DAYS_VALID}" -nodes \
    -keyout "${CA_KEY}" \
    -out "${CA_CERT}" \
    -subj "/CN=Fake Link Dev CA/O=Fake Link/C=BR"
}

generate_leaf() {
  local name="$1"
  local key="${CERTS_DIR}/${name}.key"
  local csr="${CERTS_DIR}/${name}.csr"
  local cert="${CERTS_DIR}/${name}.crt"
  local ext="${CERTS_DIR}/${name}.ext"

  if [[ -f "${cert}" && -f "${key}" ]]; then
    return 0
  fi

  cat > "${ext}" <<EOF
subjectAltName = DNS:${name}
extendedKeyUsage = serverAuth
EOF

  openssl req -newkey rsa:2048 -nodes \
    -keyout "${key}" \
    -out "${csr}" \
    -subj "/CN=${name}/O=Fake Link/C=BR"

  openssl x509 -req -in "${csr}" \
    -CA "${CA_CERT}" -CAkey "${CA_KEY}" -CAcreateserial \
    -out "${cert}" -days "${DAYS_VALID}" -sha256 \
    -extfile "${ext}"

  rm -f "${csr}" "${ext}"
}

generate_ca
generate_leaf "app.localhost"
generate_leaf "go.localhost"

echo "Dev TLS assets ready in ${CERTS_DIR}"
echo "Import ${CA_CERT} into your system trust store (see trust-ca.sh)."
