#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CERTS_DIR="${SCRIPT_DIR}/../nginx/certs"
CA_CERT="${CERTS_DIR}/ca.pem"

"${SCRIPT_DIR}/generate-dev-certs.sh"

if [[ ! -f "${CA_CERT}" ]]; then
  echo "CA certificate not found at ${CA_CERT}" >&2
  exit 1
fi

case "$(uname -s)" in
  Darwin)
    echo "macOS — import the dev CA (requires admin password once):"
    echo "  sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ${CA_CERT}"
    echo
    echo "Verify with:"
    echo "  security find-certificate -c 'Fake Link Dev CA' /Library/Keychains/System.keychain"
    ;;
  Linux)
    echo "Linux — import the dev CA into the system trust store:"
    echo "  sudo cp ${CA_CERT} /usr/local/share/ca-certificates/fake-link-dev-ca.crt"
    echo "  sudo update-ca-certificates"
    echo
    echo "For browsers that use their own store (Firefox), import ${CA_CERT} manually."
    ;;
  *)
    echo "Import ${CA_CERT} into your OS trust store to avoid browser TLS warnings."
    ;;
esac

echo
echo "Smoke without trust store (CI only): curl -k https://app.localhost/health"
