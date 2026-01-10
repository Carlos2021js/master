#!/usr/bin/env bash
# update_panel_from_dropbox.sh
# Uso: sudo ./update_panel_from_dropbox.sh "LINK_DO_DROPBOX"
# Baixa o ZIP da pasta do painel no Dropbox, valida estrutura e aplica update seguro.
set -euo pipefail

LINK="${1:-}"
if [[ -z "$LINK" ]]; then
  echo "[ERRO] Informe o link do Dropbox como primeiro argumento." >&2
  exit 1
fi

LOG_FILE="/home/xtreamcodes/logs/update_panel_cli.log"
mkdir -p "/home/xtreamcodes/logs"
log(){ echo "[$(date +'%F %T')] $*" | tee -a "$LOG_FILE"; }

TMP_ZIP="/tmp/panel_update.zip"
TMP_DIR="/tmp/panel_update"
TARGET="/home/xtreamcodes/iptv_xtream_codes"

# Força download direto
LINK_DL="$LINK"
if [[ "$LINK_DL" == *"dl=0"* ]]; then
  LINK_DL="${LINK_DL/dl=0/dl=1}"
fi

log "Baixando: $LINK_DL"
curl -fL --retry 3 -o "$TMP_ZIP" "$LINK_DL" || { log "Falha ao baixar o ZIP"; exit 1; }

log "Validando ZIP"
if ! unzip -l "$TMP_ZIP" | grep -E "(admin/|wwwdir/)" >/dev/null; then
  log "ZIP inválido: não contém admin/ ou wwwdir/"
  exit 1
fi

log "Extraindo ZIP"
rm -rf "$TMP_DIR" && mkdir -p "$TMP_DIR"
unzip -q "$TMP_ZIP" -d "$TMP_DIR" || { log "Falha ao extrair"; exit 1; }

backup_dir="$TARGET/.backup/$(date +'%Y%m%d%H%M%S')"
mkdir -p "$backup_dir"

for p in admin wwwdir pytools; do
  if [[ -d "$TMP_DIR/$p" ]]; then
    log "Backup de $p"
    cp -rf "$TARGET/$p" "$backup_dir/" || true
    log "Atualizando $p"
    cp -rf "$TMP_DIR/$p"/* "$TARGET/$p/" || true
  fi
done

log "Ajustando permissões"
chown -R xtreamcodes:xtreamcodes /home/xtreamcodes || true
chmod -R 0777 /home/xtreamcodes || true

log "Reiniciando serviços do painel"
bash -lc "/home/xtreamcodes/iptv_xtream_codes/start_services.sh" || true

log "Concluído"
echo "Update concluído. Logs: $LOG_FILE"
