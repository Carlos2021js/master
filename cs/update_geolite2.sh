#!/usr/bin/env bash
# Atualiza GeoLite2.mmdb usando credenciais MaxMind
# Requer: MAXMIND_ACCOUNT_ID e MAXMIND_LICENSE_KEY definidos no ambiente
# Uso: MAXMIND_ACCOUNT_ID=123456 MAXMIND_LICENSE_KEY=abcdef ./update_geolite2.sh
set -euo pipefail

DEST_DIR="/home/xtreamcodes/iptv_xtream_codes"
TMP_DIR="/tmp/geolite2"
mkdir -p "$TMP_DIR"

if [[ -z "${MAXMIND_ACCOUNT_ID:-}" || -z "${MAXMIND_LICENSE_KEY:-}" ]]; then
  echo "[ERRO] Defina MAXMIND_ACCOUNT_ID e MAXMIND_LICENSE_KEY antes de executar." >&2
  exit 1
fi

# URL MaxMind: precisa de login. Usamos endpoints fornecidos pela documentação:
# https://download.maxmind.com/geoip/databases/GeoLite2-City/download?signed_url=...
# A forma suportada sem UI é via mmdb-resolver com token assinado. Como alternativa,
# usamos o endpoint 'tar.gz' com account+key via URL autenticação básica.

echo "[INFO] Baixando GeoLite2-City.mmdb (tar.gz) ..."
curl -fsSL -o "$TMP_DIR/GeoLite2-City.tar.gz" \
  "https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=${MAXMIND_LICENSE_KEY}&suffix=tar.gz"

echo "[INFO] Extraindo mmdb ..."
rm -rf "$TMP_DIR/extract" && mkdir -p "$TMP_DIR/extract"
tar -xzf "$TMP_DIR/GeoLite2-City.tar.gz" -C "$TMP_DIR/extract"
MMDB_PATH=$(find "$TMP_DIR/extract" -name '*.mmdb' | head -n 1)
if [[ -z "$MMDB_PATH" ]]; then
  echo "[ERRO] Não foi possível localizar o arquivo .mmdb dentro do tar.gz" >&2
  exit 1
fi

echo "[INFO] Copiando para $DEST_DIR/GeoLite2.mmdb ..."
install -m 0644 "$MMDB_PATH" "$DEST_DIR/GeoLite2.mmdb"
chown xtreamcodes:xtreamcodes "$DEST_DIR/GeoLite2.mmdb" || true
chattr +i "$DEST_DIR/GeoLite2.mmdb" || true

echo "[OK] GeoLite2 atualizado com sucesso."
