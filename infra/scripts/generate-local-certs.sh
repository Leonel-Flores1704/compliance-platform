#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CERTS_DIR="$SCRIPT_DIR/../certs"

mkdir -p "$CERTS_DIR"

CERT_FILE="$CERTS_DIR/cert.pem"
KEY_FILE="$CERTS_DIR/key.pem"

DOMAIN_NAMES="localhost 127.0.0.1 ::1 compliance-platform.online *.compliance-platform.online"

if command -v mkcert >/dev/null 2>&1; then
    echo "Generating local TLS certificates using mkcert..."
    mkcert -install
    mkcert -cert-file "$CERT_FILE" -key-file "$KEY_FILE" $DOMAIN_NAMES
    echo "mkcert certificates generated at $CERTS_DIR"
elif command -v openssl >/dev/null 2>&1; then
    echo "mkcert not found, generating self-signed development certificates using openssl..."
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "$KEY_FILE" \
        -out "$CERT_FILE" \
        -subj "/C=MX/ST=NL/L=Monterrey/O=CompliancePlatform/CN=compliance-platform.online" \
        -addext "subjectAltName=DNS:localhost,DNS:compliance-platform.online,DNS:*.compliance-platform.online,IP:127.0.0.1,IP:::1"
    echo "Development self-signed certificates generated at $CERTS_DIR"
else
    echo "Error: Neither mkcert nor openssl is available to generate TLS certificates."
    exit 1
fi

chmod 644 "$CERT_FILE"
chmod 600 "$KEY_FILE"
echo "TLS certificates are ready for Nginx."
