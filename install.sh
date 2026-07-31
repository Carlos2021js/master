#!/bin/bash

# ==========================================================================
# Script de Instalação Automatizada - Xtream Codes 2.9 (Ubuntu 24.04 LTS)
# ==========================================================================

if [ "$EUID" -ne 0 ]; then
  echo "ERRO: Por favor, execute como root (sudo ./instalar_xtream.sh)"
  exit 1
fi

NEW_LINK="https://www.dropbox.com/scl/fi/gqrjwq3vdzshyzzzdc9e4/xtream_completo.zip?rlkey=bcu554ard80ua2b5eus3qszxp&st=k0xze6lu&dl=1"
INSTALL_DIR="/home/xtreamcodes"
TMP_ZIP="/tmp/xtream_install.zip"

echo "--- 1. Preparando o Ambiente ---"
apt update && apt upgrade -y
apt install -y libxml2 libcurl4 libxslt1.1 libbz2-1.0 libsqlite3-0 libonig5 libicu-dev libssh2-1 librtmp1 libidn2-0 libpsl5 mariadb-server unzip wget psmisc curl

echo "--- 2. Corrigindo libssl1.1 (Ubuntu 24.04) ---"
if [ ! -f "/usr/lib/x86_64-linux-gnu/libssl.so.1.1" ]; then
    wget http://archive.ubuntu.com/ubuntu/pool/main/o/openssl/libssl1.1_1.1.1f-1ubuntu2_amd64.deb
    dpkg -i libssl1.1_1.1.1f-1ubuntu2_amd64.deb
    rm libssl1.1_1.1.1f-1ubuntu2_amd64.deb
fi

echo "--- 3. Baixando Pacote de Instalação ---"
wget -O "$TMP_ZIP" "$NEW_LINK"

if [ ! -f "$TMP_ZIP" ]; then
    echo "ERRO: Falha ao baixar o arquivo. Verifique o link."
    exit 1
fi

echo "--- 4. Configurando Usuário e Arquivos ---"
if ! id "xtreamcodes" &>/dev/null; then
    useradd -m -s /bin/bash xtreamcodes
fi

mkdir -p "$INSTALL_DIR"
unzip -o "$TMP_ZIP" -d "/tmp/xtream_extracted"
cp -r /tmp/xtream_extracted/xtreamcodes/* "$INSTALL_DIR/"
chown -R xtreamcodes:xtreamcodes "$INSTALL_DIR"

echo "--- 5. Configurando Banco de Dados ---"
systemctl start mariadb
systemctl enable mariadb

DB_NAME="xtream_iptv"
DB_USER="xtreamcodes"
DB_PASS="xtreamcodes"

mysql -u root -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"
mysql -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -u root -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';"
mysql -u root -e "FLUSH PRIVILEGES;"

if [ -f "$INSTALL_DIR/banco_xtream.sql" ]; then
    echo "Importando banco de dados..."
    mysql -u root $DB_NAME < "$INSTALL_DIR/banco_xtream.sql"
fi

echo "--- 6. Permissões e Inicialização ---"
chmod +x "$INSTALL_DIR/iptv_xtream_codes/permissions.sh"
cd "$INSTALL_DIR/iptv_xtream_codes/" && ./permissions.sh

cp "$INSTALL_DIR/iptv_xtream_codes/xtreamcodes" /etc/init.d/xtreamcodes
chmod +x /etc/init.d/xtreamcodes
update-rc.d xtreamcodes defaults

echo "--- Limpando Arquivos Temporários ---"
rm -rf /tmp/xtream_extracted
rm "$TMP_ZIP"

echo "=========================================================================="
echo " INSTALAÇÃO CONCLUÍDA COM SUCESSO!"
echo "=========================================================================="
echo " Para iniciar o painel: sudo /etc/init.d/xtreamcodes start"
echo " Para verificar o status: ps aux | grep nginx"
echo "=========================================================================="
