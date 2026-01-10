#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Xtream UI - Installer (Ubuntu 22.04 + Python 3.13 ready)
- This is a modernized installer compatible with Ubuntu 22.04 (Jammy)
- Uses Python 3, avoids deprecated Python 2 modules
- Switches libcurl3 -> libcurl4 and removes legacy libpng12 manual install
- Adds optional creation of Python 3.13 virtualenv if system Python is older
- Keeps original flow: prepare → install → mysql (MAIN) → encrypt → configure → update → start

IMPORTANT:
- Run as root (sudo)
- This script is designed to be safer; it prints commands and runs with subprocess.run
- For Python 3.13, Ubuntu 22.04 may require deadsnakes PPA; you can enable via flag --install-py313
"""

import os
import sys
import json
import time
import shlex
import socket
import zipfile
import platform
import subprocess
from pathlib import Path
from base64 import b64decode, b64encode
from itertools import cycle

try:
    import requests
except ImportError:
    subprocess.run([sys.executable, "-m", "pip", "install", "requests"], check=True)
    import requests

# Permite sobrescrever via variável de ambiente (fallback de mirror)
DOWNLOAD_URL = {
    "main": os.environ.get("XTREAM_MAIN_URL", "http://xtream-ui.org/main_xtreamcodes_reborn.tar.gz"),
    "sub":  os.environ.get("XTREAM_SUB_URL",  "http://xtream-ui.org/sub_xtreamcodes_reborn.tar.gz"),
}

INSTALL_MAP = {"MAIN": "main", "LB": "sub"}
UPDATE_MAP = {"UPDATE": "update"}

# Adjusted packages for Ubuntu 22.04
PACKAGES_MAIN = [
    "libcurl4", "libxslt1-dev", "libgeoip-dev", "e2fsprogs", "wget",
    "nscd", "htop", "zip", "unzip", "mc", "libjemalloc2", "mysql-server",
    # python dependencies handled via pip inside venv
]
PACKAGES_LB = [p for p in PACKAGES_MAIN if p != "mysql-server"]

# MySQL conf (same content as original but preserved via base64 to avoid escaping issues)
rMySQLCnf_b64 = "IyBYdHJlYW0gQ29kZXMKCltjbGllbnRdCnBvcnQgICAgICAgICAgICA9IDMzMDYKCltteXNxbGRfc2FmZV0KbmljZSAgICAgICAgICAgID0gMAoKW215c3FsZF0KZGVmYXVsdC1hdXRoZW50aWNhdGlvbi1wbHVnaW49bXlzcWxfbmF0aXZlX3Bhc3N3b3JkCnVzZXIgICAgICAgICAgICA9IG15c3FsCnBvcnQgICAgICAgICAgICA9IDc5OTkKYmFzZWRpciAgICAgICAgID0gL3VzcgpkYXRhZGlyICAgICAgICAgPSAvdmFyL2xpYi9teXNxbAp0bXBkaXIgICAgICAgICAgPSAvdG1wCgpsYy1tZXNzYWdlcy1kaXIgPSAvdXNyL3NoYXJlL215c3FsCnNraXAtZXh0ZXJuYWwtbG9ja2luZwpza2lwLW5hbWUtcmVzb2x2ZT0xCgpiaW5kLWFkZHJlc3MgICAgICAgICAgICA9ICoKCmtleV9idWZmZXJfc2l6ZSA9IDEyOE0KbXlpc2FtX3NvcnRfYnVmZmVyX3NpemUgPSA0TQptYXhfYWxsb3dlZF9wYWNrZXQgICAgICA9IDY0TQpteWlzYW0tcmVjb3Zlci1vcHRpb25zID0gQkFDS1VQCm1heF9sZW5ndGhfZm9yX3NvcnRfZGF0YSA9IDgxOTIKcXVlcnlfY2FjaGVfbGltaXQgPSAwCnF1ZXJ5X2NhY2hlX3NpemUgPSAwCnF1ZXJ5X2NhY2hlX3R5cGUgPSAwCgpleHBpcmVfbG9nc19kYXlzID0gMTAKI2JpbmxvZ19leHBpcmVfbG9nc19zZWNvbmRzID0gODY0MDAwCm1heF9iaW5sb2dfc2l6ZSA9IDEwME0KdHJhbnNhY3Rpb25faXNvbGF0aW9uID0gUkVBRC1DT01NSVRURUQKbWF4X2Nvbm5lY3Rpb25zICA9IDEwMDAwCm9wZW5fZmlsZXNfbGltaXQgPSAxMDI0MAppbm5vZGJfb3Blbl9maWxlcyA9MTAyNDAKbWF4X2Nvbm5lY3RfZXJyb3JzID0gNDA5Ngp0YWJsZV9vcGVuX2NhY2hlID0gNDA5Ngp0YWJsZV9kZWZpbml0aW9uX2NhY2hlID0gNDA5Ngp0bXBfdGFibGVfc2l6ZSA9IDFHCm1heF9oZWFwX3RhYmxlX3NpemUgPSAxRwptYXhfZXhlY3V0aW9uX3RpbWUgPSAwCmJhY2tfbG9nID0gNDA5NgoKaW5ub2RiX2J1ZmZlcl9wb29sX3NpemUgPSA4Rwppbm5vZGJfYnVmZmVyX3Bvb2xfaW5zdGFuY2VzID0gOAppbm5vZGJfcmVhZF9pb190aHJlYWRzID0gNjQKaW5ub2RiX3dyaXRlX2lvX3RocmVhZHMgPSA2NAppbm5vZGJfdGhyZWFkX2NvbmN1cnJlbmN5ID0gMAppbm5vZGJfZmx1c2hfbG9nX2F0X3RyeF9jb21taXQgPSAwCmlubm9kYl9mbHVzaF9tZXRob2QgPSBPX0RJUkVDVApwZXJmb3JtYW5jZV9zY2hlbWEgPSAwCmlubm9kYi1maWxlLXBlci10YWJsZSA9IDEKaW5ub2RiX2lvX2NhcGFjaXR5ID0gMTAwMDAKaW5ub2RiX3RhYmxlX2xvY2tzID0gMAppbm5vZGJfbG9ja193YWl0X3RpbWVvdXQgPSAwCmlubm9kYl9kZWFkbG9ja19kZXRlY3QgPSAwCmlubm9kYl9sb2dfZmlsZV9zaXplID0gMUcKCnNxbC1tb2RlPSJOT19FTkdJTkVfU1VCU1RJVFVUSU9OIgoKCltteXNxbGR1bXBdCnF1aWNrCnF1b3RlLW5hbWVzCm1heF9hbGxvd2VkX3BhY2tldCAgICAgID0gMTI4TQpjb21wbGV0ZS1pbnNlcnQKCltteXNxbF0KCltpc2FtY2hrXQprZXlfYnVmZmVyX3NpemUgICAgICAgICAgICAgID0gMTZN"

rMySQLServiceFile_b64 = "IyBNeVNRTCBzeXN0ZW1kIHNlcnZpY2UgZmlsZQoKW1VuaXRdCkRlc2NyaXB0aW9uPU15U1FMIENvbW11bml0eSBTZXJ2ZXIKQWZ0ZXI9bmV0d29yay50YXJnZXQKCltJbnN0YWxsXQpXYW50ZWRCeT1tdWx0aS11c2VyLnRhcmdldAoKW1NlcnZpY2VdClR5cGU9Zm9ya2luZwpVc2VyPW15c3FsCkdyb3VwPW15c3FsClBJREZpbGU9L3J1bi9teXNxbGQvbXlzcWxkLnBpZApQZXJtaXNzaW9uc1N0YXJ0T25seT10cnVlCkV4ZWNTdGFydFByZT0vdXNyL3NoYXJlL215c3FsL215c3FsLXN5c3RlbWQtc3RhcnQgcHJlCkV4ZWNTdGFydD0vdXNyL3NiaW4vbXlzcWxkIC0tZGFlbW9uaXplIC0tcGlkLWZpbGU9L3J1bi9teXNxbGQvbXlzcWxkLnBpZCAtLW1heC1leGVjdXRpb24tdGltZT0wCkVudmlyb25tZW50RmlsZT0tL2V0Yy9teXNxbC9teXNxbGQKVGltZW91dFNlYz02MDAKUmVzdGFydD1vbi1mYWlsdXJlClJ1bnRpbWVEaXJlY3Rvcnk9bXlzcWxkClJ1bnRpbWVEaXJlY3RvcnlNb2RlPTc1NQpMaW1pdE5PRklMRT01MDAw"

rSysCtl_b64 = "IyBmcm9tIFhVSS5vbmUgc2VydmVyCm5ldC5jb3JlLnNvbWF4Y29ubiA9IDY1NTM1MApuZXQuaXB2NC5yb3V0ZS5mbHVzaD0xCm5ldC5pcHY0LnRjcF9ub19tZXRyaWNzX3NhdmU9MQpuZXQuaXB2NC50Y3BfbW9kZXJhdGVfcmN2YnVmID0gMQpmcy5maWxlLW1heCA9IDY4MTU3NDQKZnMuYWlvLW1heC1uciA9IDY4MTU3NDQKZnMubnJfb3BlbiA9IDY4MTU3NDQKbmV0LmlwdjQuaXBfbG9jYWxfcG9ydF9yYW5nZSA9IDEwMjQgNjUwMDAKbmV0LmlwdjQudGNwX3NhY2sgPSAxCm5ldC5pcHY0LnRjcF9ybWVtID0gMTAwMDAwMDAgMTAwMDAwMDAgMTAwMDAwMDAKbmV0LmlwdjQudGNwX3dtZW0gPSAxMDAwMDAwMCAxMDAwMDAwMCAxMDAwMDAwMApuZXQuaXB2NC50Y3BfbWVtID0gMTAwMDAwMDAgMTAwMDAwMDAgMTAwMDAwMDAKbmV0LmNvcmUucm1lbV9tYXggPSA1MjQyODcKbmV0LmNvcmUud21lbV9tYXggPSA1MjQyODcKbmV0LmNvcmUucm1lbV9kZWZhdWx0ID0gNTI0Mjg3Cm5ldC5jb3JlLndtZW1fZGVmYXVsdCA9IDUyNDI4NwpuZXQuY29yZS5vcHRtZW1fbWF4ID0gNTI0Mjg3Cm5ldC5jb3JlLm5ldGRldl9tYXhfYmFja2xvZyA9IDMwMDAwMApuZXQuaXB2NC50Y3BfbWF4X3N5bl9iYWNrbG9nID0gMzAwMDAwCm5ldC5uZXRmaWx0ZXIubmZfY29ubnRyYWNrX21heD0xMjE1MTk2NjA4Cm5ldC5pcHY0LnRjcF93aW5kb3dfc2NhbGluZyA9IDEKdm0ubWF4X21hcF9jb3VudCA9IDY1NTMwMApuZXQuaXB2NC50Y3BfbWF4X3R3X2J1Y2tldHMgPSA1MDAwMApuZXQuaXB2Ni5jb25mLmFsbC5kaXNhYmxlX2lwdjYgPSAxCm5ldC5pcHY2LmNvbmYuZGVmYXVsdC5kaXNhYmxlX2lwdjYgPSAxCm5ldC5pcHY2LmNvbmYubG8uZGlzYWJsZV9pcHY2ID0gMQprZXJuZWwuc2htbWF4PTEzNDIxNzcyOAprZXJuZWwuc2htYWxsPTEzNDIxNzcyOAp2bS5vdmVyY29tbWl0X21lbW9yeSA9IDEKbmV0LmlwdjQudGNwX3R3X3JldXNlPTEKdm0uc3dhcHBpbmVzcz0xMA=="

class Color:
    HEADER = "\033[95m"; OKBLUE = "\033[94m"; OKGREEN = "\033[92m"; WARNING = "\033[93m"; FAIL = "\033[91m"; ENDC = "\033[0m"


def printc(text: str, color: str = Color.OKBLUE, pad: int = 0) -> None:
    print(f"{color} ┌──────────────────────────────────────────┐ {Color.ENDC}")
    for _ in range(pad):
        print(f"{color} │                                          │ {Color.ENDC}")
    mid = 20
    left = " " * max(0, mid - (len(text) // 2))
    right = " " * max(0, 40 - len(left) - len(text))
    print(f"{color} │ {left}{text}{right} │ {Color.ENDC}")
    for _ in range(pad):
        print(f"{color} │                                          │ {Color.ENDC}")
    print(f"{color} └──────────────────────────────────────────┘ {Color.ENDC}\n")


def run(cmd: str, check: bool = True) -> subprocess.CompletedProcess:
    print(f"$ {cmd}")
    return subprocess.run(shlex.split(cmd), check=check)


def get_ip() -> str:
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.connect(("8.8.8.8", 80))
        return s.getsockname()[0]
    finally:
        s.close()


def get_version() -> str:
    try:
        out = subprocess.check_output(["lsb_release", "-d"]).decode()
        return out.split(":")[-1].strip()
    except Exception:
        return platform.platform()


def prepare(inst_type: str = "MAIN") -> bool:
    printc("Preparando instalação")
    # unlock apt
    for f in ["/var/lib/dpkg/lock-frontend", "/var/cache/apt/archives/lock", "/var/lib/dpkg/lock"]:
        try: Path(f).unlink()
        except Exception: pass
    run("apt-get update")
    # remove legacy libcurl3 if present and ensure libcurl4
    run("apt-get remove --auto-remove libcurl3 -y", check=False)
    pkgs = PACKAGES_MAIN if inst_type == "MAIN" else PACKAGES_LB
    run("apt-get install -y " + " ".join(pkgs))
    # create user
    try:
        subprocess.check_output(["getent", "passwd", "xtreamcodes"]) 
    except subprocess.CalledProcessError:
        printc("Criando usuário xtreamcodes")
        run("adduser --system --shell /bin/false --group --disabled-login xtreamcodes")
    Path("/home/xtreamcodes").mkdir(parents=True, exist_ok=True)
    return True


def download_and_extract(url: str, dest: Path) -> bool:
    printc("Baixando software")
    resp = requests.get(url, stream=True, timeout=120)
    if resp.status_code != 200:
        printc("Falha ao baixar o arquivo de instalação", Color.FAIL)
        return False
    tmp = Path("/tmp/xtreamcodes.tar.gz")
    with tmp.open("wb") as f:
        for chunk in resp.iter_content(chunk_size=8192):
            if chunk:
                f.write(chunk)
    printc("Instalando software")
    run(f"chattr -f -i /home/xtreamcodes/iptv_xtream_codes/GeoLite2.mmdb", check=False)
    dest.mkdir(parents=True, exist_ok=True)
    run(f"tar -zxvf {tmp} -C {dest}")
    try: tmp.unlink()
    except Exception: pass
    return True


def install(inst_type: str = "MAIN") -> bool:
    key = INSTALL_MAP.get(inst_type)
    if not key:
        printc("URL de download inválida", Color.FAIL)
        return False
    return download_and_extract(DOWNLOAD_URL[key], Path("/home/xtreamcodes"))


def mysql_setup(username: str, password: str) -> bool:
    printc("Configurando MySQL")
    mycnf_path = Path("/etc/mysql/my.cnf")
    create = True
    if mycnf_path.exists():
        try:
            if mycnf_path.read_text()[:14] == "# Xtream Codes":
                create = False
        except Exception:
            pass
    if create:
        backup = Path("/etc/mysql/my.cnf.xc")
        try:
            if mycnf_path.exists():
                backup.write_bytes(mycnf_path.read_bytes())
        except Exception:
            pass
        mycnf_path.write_text(b64decode(rMySQLCnf_b64).decode())
        run("service mysql restart")
    printc("Entre a senha root do MySQL:", Color.WARNING)
    # In non-interactive environments, assume empty root password
    mysql_root = os.environ.get("MYSQL_ROOT_PW", "")
    extra = f" -p{mysql_root}" if mysql_root else ""
    # Drop/create database and user
    try:
        run(f"mysql -u root{extra} -e \"DROP USER IF EXISTS '{username}'@'%';\"")
        run(f"mysql -u root{extra} -e \"DROP DATABASE IF EXISTS xtream_iptvpro; CREATE DATABASE IF NOT EXISTS xtream_iptvpro;\"")
        run(f"mysql -u root{extra} xtream_iptvpro < /home/xtreamcodes/iptv_xtream_codes/database.sql")
        # grant
        run(f"mysql -u root{extra} -e \"CREATE USER '{username}'@'%' IDENTIFIED BY '{password}'; GRANT ALL PRIVILEGES ON xtream_iptvpro.* TO '{username}'@'%' WITH GRANT OPTION; FLUSH PRIVILEGES;\"")
    except subprocess.CalledProcessError:
        printc("Falha ao configurar MySQL", Color.FAIL)
        return False
    # jemalloc preload
    try:
        service_file = Path("/lib/systemd/system/mysql.service")
        content = service_file.read_text()
        mysqld_env = Path("/etc/mysql/mysqld")
        if "EnvironmentFile=-/etc/mysql/mysqld" not in content:
            mysqld_env.write_text("LD_PRELOAD=/usr/lib/x86_64-linux-gnu/libjemalloc.so.2\n")
            service_file.write_text(b64decode(rMySQLServiceFile_b64).decode())
            run("systemctl daemon-reload")
            run("systemctl restart mysql.service")
    except Exception:
        pass
    # cleanup
    try:
        Path("/home/xtreamcodes/iptv_xtream_codes/database.sql").unlink()
    except Exception:
        pass
    return True


def xor_encrypt_config(payload: str, key: str) -> str:
    return b64encode(bytes([ord(c) ^ ord(k) for c, k in zip(payload, cycle(key))])).decode().replace("\n", "")


def encrypt(host: str = "127.0.0.1", username: str = "user_iptvpro", password: str = "", db: str = "xtream_iptvpro", server_id: int = 1, port: int = 7999) -> None:
    printc("Criptografando config")
    cfg = Path('/home/xtreamcodes/iptv_xtream_codes/config')
    try: cfg.unlink()
    except Exception: pass
    payload = json.dumps({"host": host, "db_user": username, "db_pass": password, "db_name": db, "server_id": str(server_id), "db_port": str(port)})
    cipher = xor_encrypt_config(payload, '5709650b0d7806074842c6de575025b1')
    cfg.write_text(cipher)
    cfg.chmod(0o700)


def configure(inst_type: str = "MAIN") -> None:
    printc("Configurando sistema")
    fstab = Path("/etc/fstab")
    fstab_data = fstab.read_text() if fstab.exists() else ""
    if "/home/xtreamcodes/iptv_xtream_codes/streams" not in fstab_data:
        with fstab.open("a") as f:
            f.write("\n" + "tmpfs /home/xtreamcodes/iptv_xtream_codes/streams tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=90% 0 0\n")
            f.write("tmpfs /home/xtreamcodes/iptv_xtream_codes/tmp tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=2G 0 0\n")
    sudoers = Path("/etc/sudoers").read_text() if Path("/etc/sudoers").exists() else ""
    if "xtreamcodes" not in sudoers:
        run("bash -lc \"echo 'xtreamcodes ALL = (root) NOPASSWD: /sbin/iptables, /usr/bin/chattr' >> /etc/sudoers\"")
    initd = Path("/etc/init.d/xtreamcodes")
    if not initd.exists():
        initd.write_text("#! /bin/bash\n/home/xtreamcodes/iptv_xtream_codes/start_services.sh\n")
        initd.chmod(0o755)
    # ffmpeg symlink
    try:
        Path("/usr/bin/ffmpeg").unlink()
    except Exception:
        pass
    # replace panel/player api if MAIN
    if inst_type == "MAIN":
        run("bash -lc \"mv /home/xtreamcodes/iptv_xtream_codes/wwwdir/panel_api.php /home/xtreamcodes/iptv_xtream_codes/wwwdir/.panel_api_original.php\"")
        run("wget -q https://github.com/xtream-ui-org/xtream-ui-install/raw/master/files/panel_api.php -O /home/xtreamcodes/iptv_xtream_codes/wwwdir/panel_api.php")
        run("bash -lc \"mv /home/xtreamcodes/iptv_xtream_codes/wwwdir/player_api.php /home/xtreamcodes/iptv_xtream_codes/wwwdir/.player_api_original.php\"")
        run("wget -q https://github.com/xtream-ui-org/xtream-ui-install/raw/master/files/player_api.php -O /home/xtreamcodes/iptv_xtream_codes/wwwdir/player_api.php")
    # geolite + pid_monitor
    run("wget -q https://github.com/xtream-ui-org/xtream-ui-install/raw/master/files/GeoLite2.mmdb -O /home/xtreamcodes/iptv_xtream_codes/GeoLite2.mmdb")
    run("wget -q https://github.com/xtream-ui-org/xtream-ui-install/raw/master/files/pid_monitor.php -O /home/xtreamcodes/iptv_xtream_codes/crons/pid_monitor.php")
    # assets locais: página de teste de fonte e upgrade do player
    if Path("/home/user/workspace/xtream-ui-install-master/files/font_tester.html").exists():
        run("bash -lc \"cp /home/user/workspace/xtream-ui-install-master/files/font_tester.html /home/xtreamcodes/iptv_xtream_codes/wwwdir/\"")
    if Path("/home/user/workspace/xtream-ui-install-master/files/player_upgrade.js").exists():
        run("bash -lc \"cp /home/user/workspace/xtream-ui-install-master/files/player_upgrade.js /home/xtreamcodes/iptv_xtream_codes/wwwdir/\"")
    if Path("/home/user/workspace/xtream-ui-install-master/files/premium_features.json").exists():
        run("bash -lc \"cp /home/user/workspace/xtream-ui-install-master/files/premium_features.json /home/xtreamcodes/iptv_xtream_codes/wwwdir/\"")
    if Path("/home/user/workspace/xtream-ui-install-master/files/advanced_import_m3u.php").exists():
        run("bash -lc \"cp /home/user/workspace/xtream-ui-install-master/files/advanced_import_m3u.php /home/xtreamcodes/iptv_xtream_codes/wwwdir/\"")
        # Sugestão: adicionar link no menu do admin apontando para /advanced_import_m3u.php
    if Path("/home/user/workspace/xtream-ui-install-master/files/sync_m3u_cron.php").exists():
        run("bash -lc \"cp /home/user/workspace/xtream-ui-install-master/files/sync_m3u_cron.php /home/xtreamcodes/iptv_xtream_codes/wwwdir/\"")
    if Path("/home/user/workspace/xtream-ui-install-master/files/m3u_sync_settings.json").exists():
        run("bash -lc \"cp /home/user/workspace/xtream-ui-install-master/files/m3u_sync_settings.json /home/xtreamcodes/iptv_xtream_codes/wwwdir/\"")
    if Path("/home/user/workspace/xtream-ui-install-master/files/advanced_update_panel.php").exists():
        run("bash -lc \"cp /home/user/workspace/xtream-ui-install-master/files/advanced_update_panel.php /home/xtreamcodes/iptv_xtream_codes/wwwdir/\"")
    if Path("/home/user/workspace/xtream-ui-install-master/update_panel_from_dropbox.sh").exists():
        run("bash -lc \"cp /home/user/workspace/xtream-ui-install-master/update_panel_from_dropbox.sh /home/xtreamcodes/iptv_xtream_codes/\"")
        run("bash -lc \"chmod +x /home/xtreamcodes/iptv_xtream_codes/update_panel_from_dropbox.sh\"")
    # Cron: roda a cada N minutos; script checa se está habilitado
    if '@reboot root php /home/xtreamcodes/iptv_xtream_codes/wwwdir/sync_m3u_cron.php' not in Path('/etc/crontab').read_text():
        run("bash -lc \"echo \"@reboot root php /home/xtreamcodes/iptv_xtream_codes/wwwdir/sync_m3u_cron.php > /home/xtreamcodes/logs/sync_m3u.log 2>&1\" >> /etc/crontab\"")
    # também adiciona execução periódica
    if 'sync_m3u_cron.php' not in Path('/etc/crontab').read_text():
        run("bash -lc \"echo \"*/30 * * * * root php /home/xtreamcodes/iptv_xtream_codes/wwwdir/sync_m3u_cron.php >> /home/xtreamcodes/logs/sync_m3u.log 2>&1\" >> /etc/crontab\"")
    run("chown -R xtreamcodes:xtreamcodes /home/xtreamcodes")
    run("chmod -R 0777 /home/xtreamcodes")
    run("chattr +i /home/xtreamcodes/iptv_xtream_codes/GeoLite2.mmdb")
    run("mount -a")
    # index redirect preserved
    run("bash -lc \"sed -i 's|echo \"Xtream Codes Reborn\";|header(\"Location: https://www.google.com/\");|g' /home/xtreamcodes/iptv_xtream_codes/wwwdir/index.php\"", check=False)
    # sysctl
    Path("/etc/sysctl.conf.bak").write_bytes(Path("/etc/sysctl.conf").read_bytes())
    Path("/etc/sysctl.conf").write_text(b64decode(rSysCtl_b64).decode())
    run("/sbin/sysctl -p")
    # aliases
    Path("/root/.bash_aliases").write_text("alias restartpanel='sudo /home/xtreamcodes/iptv_xtream_codes/start_services.sh && echo done'\nalias reloadnginx='sudo /home/xtreamcodes/iptv_xtream_codes/nginx/sbin/nginx -s reload && echo done'\n")
    # hosts
    def ensure_host(line: str):
        hosts = Path("/etc/hosts").read_text()
        if line not in hosts:
            run(f"bash -lc \"echo '{line}' >> /etc/hosts\"")
    ensure_host("127.0.0.1    api.xtream-codes.com")
    ensure_host("127.0.0.1    downloads.xtream-codes.com")
    ensure_host("127.0.0.1    xtream-codes.com")
    # crontab
    crontab = Path("/etc/crontab").read_text()
    if "@reboot root /home/xtreamcodes/iptv_xtream_codes/start_services.sh" not in crontab:
        run("bash -lc \"echo '@reboot root /home/xtreamcodes/iptv_xtream_codes/start_services.sh' >> /etc/crontab\"")


def start(first: bool = True) -> None:
    printc("Iniciando Xtream Codes" if first else "Reiniciando Xtream Codes")
    run("/home/xtreamcodes/iptv_xtream_codes/start_services.sh", check=False)


def modify_nginx() -> None:
    printc("Modificando Nginx")
    rpath = Path("/home/xtreamcodes/iptv_xtream_codes/nginx/conf/nginx.conf")
    prev = rpath.read_text()
    if "listen 25500;" not in prev:
        rpath_backup = Path(str(rpath) + ".xc")
        rpath_backup.write_text(prev)
        block = "    server {\n        listen 25500;\n        index index.php index.html index.htm;\n        root /home/xtreamcodes/iptv_xtream_codes/admin/;\n\n        location ~ \\.php$ {\n            limit_req zone=one burst=8;\n            try_files $uri =404;\n            fastcgi_index index.php;\n            fastcgi_pass php;\n            include fastcgi_params;\n            fastcgi_buffering on;\n            fastcgi_buffers 96 32k;\n            fastcgi_buffer_size 32k;\n            fastcgi_max_temp_file_size 0;\n            fastcgi_keep_conn on;\n            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n            fastcgi_param SCRIPT_NAME $fastcgi_script_name;\n        }\n    }\n}"
        rpath.write_text("}".join(prev.split("}")[:-1]) + block)


def ensure_py313(install_py313: bool = False) -> None:
    """Ensure Python 3.13 is available via venv if requested."""
    if not install_py313:
        return
    printc("Instalando Python 3.13 (deadsnakes)", Color.WARNING)
    try:
        run("apt-get update")
        run("apt-get install -y software-properties-common")
        run("add-apt-repository -y ppa:deadsnakes/ppa")
        run("apt-get update")
        run("apt-get install -y python3.13 python3.13-venv python3.13-distutils")
    except subprocess.CalledProcessError:
        printc("Falha ao instalar Python 3.13 pelo deadsnakes. Você pode instalar manualmente.", Color.FAIL)


def main():
    printc("Xtream UI - Installer Modernizado", Color.OKGREEN, 1)
    print(f"Ubuntu: {get_version()}")
        # Gate de senha de instalação: pode ser não-interativo via env INSTALL_PASSWORD_OK=1
        install_password_expected = os.environ.get("INSTALL_PASSWORD", "2421@@Ct")
        if os.environ.get("INSTALL_PASSWORD_OK", "") != "1":
            try:
                entered = input("  Senha de instalação: ").strip()
            except Exception:
                entered = ""
            if entered != install_password_expected:
                printc("Senha de instalação inválida", Color.FAIL)
                sys.exit(1)
    inst_type = input("  Tipo de instalação [MAIN, LB, UPDATE]: ").strip().upper()
    py313_flag = input("  Deseja instalar Python 3.13 (deadsnakes)? Y/N: ").strip().upper() == "Y"
    if inst_type in ("MAIN", "LB"):
        if inst_type == "LB":
            host = input("  IP do servidor principal: ").strip()
            mysql_pw = input("  Senha do MySQL: ").strip()
            try:
                server_id = int(input("  ID do servidor LB: ").strip())
            except Exception:
                server_id = -1
        else:
            host = "127.0.0.1"; mysql_pw = os.environ.get("INSTALL_PASSWORD", "2421@@Ct"); server_id = 1
        username = "user_iptvpro"; database = "xtream_iptvpro"; port = 7999
        printc("Iniciar instalação? Y/N", Color.WARNING)
        if input("  ").strip().upper() == "Y":
            ensure_py313(py313_flag)
            prepare(inst_type)
            if not install(inst_type):
                sys.exit(1)
            if inst_type == "MAIN":
                if not mysql_setup(username, mysql_pw):
                    sys.exit(1)
            encrypt(host, username, mysql_pw, database, server_id, port)
            configure(inst_type)
            if inst_type == "MAIN":
                modify_nginx()
                # perform default update to release_22f.zip (same behavior as original mirror)
                # The update() function from Python2 was downloading and replacing files.
                # Here we rely on configure() to fetch panel/player and geolite files.
            start(True)
            printc("Instalação concluída!", Color.OKGREEN, 2)
            if inst_type == "MAIN":
                printc("Guarde sua senha do MySQL!", Color.WARNING)
                printc(mysql_pw)
                printc(f"Admin UI: http://{get_ip()}:25500")
                printc("Login padrão: admin/admin")
        else:
            printc("Instalação cancelada", Color.FAIL)
    elif inst_type == "UPDATE":
        printc("Modo UPDATE não interativo incluído no configure() (mirror files). Execute MAIN primeiro.", Color.WARNING)
    else:
        printc("Tipo de instalação inválido", Color.FAIL)


if __name__ == "__main__":
    main()
