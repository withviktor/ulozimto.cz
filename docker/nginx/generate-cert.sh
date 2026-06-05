#!/bin/sh
# Vygeneruje self-signed TLS certifikát pro dev prostředí
# Použití: ./docker/nginx/generate-cert.sh

set -e

CERT_DIR="$(dirname "$0")/certs"
mkdir -p "$CERT_DIR"

if [ -f "$CERT_DIR/cert.pem" ] && [ -f "$CERT_DIR/key.pem" ]; then
    echo "Certifikáty již existují v $CERT_DIR — přeskakuji."
    exit 0
fi

echo "Generuji self-signed TLS certifikát pro localhost..."

openssl req -x509 -nodes -days 3650 \
    -newkey rsa:2048 \
    -keyout "$CERT_DIR/key.pem" \
    -out    "$CERT_DIR/cert.pem" \
    -subj   "/C=CZ/ST=Prague/L=Prague/O=ulozimto.cz dev/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"

echo "Hotovo: $CERT_DIR/cert.pem  +  $CERT_DIR/key.pem"
echo ""
echo "Aby Chrome certifikátu důvěřoval lokálně, přidej cert.pem do systémových certifikátů"
echo "nebo spusť Chrome s --ignore-certificate-errors."
