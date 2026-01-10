#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os, sys, subprocess, shlex, json, socket, time
from pathlib import Path
from base64 import b64encode
from itertools import cycle

DOWNLOAD_ZIP = "https://raw.githubusercontent.com/Carlos2021js/master/main/cs.zip"

PACKAGES = [
    "libcurl4",
    "libxslt1-dev",
    "libgeoip-dev",
    "e2fsprogs",
    "wget",
    "nscd",
    "htop",
    "zip",
    "unzip",
    "mc",
    "libjemalloc2",
    "mysql-server"
]

def run(cmd, check=True):
    print(f"$ {cmd}")
    return subprocess.run(shlex.split(cmd), check=check)

def prepare():
    for f in [
        "/var/lib/dpkg/lock",
        "/var/lib/dpkg/lock-frontend",
        "/var/cache/apt/archives/lock"
    ]:
        try: os.remove(f)
        except: pass

    run("apt-get update")
    run("apt-get install -y " + " ".join(PACKAGES))

    if subprocess.run(["id", "xtreamcodes"], stdout=subprocess.DEVNULL).returncode != 0:
        run("adduser --system --group --disabled-login xtreamcodes")

    Path("/home/xtreamcodes").mkdir(exist_ok=True)

def download_and_extract():
    os.chdir("/tmp")
    run(f"wget -O cs.zip {DOWNLOAD_ZIP}")
    run("unzip -o cs.zip")
    run("cp -r cs/* /home/xtreamcodes/")
    run("chown -R xtreamcodes:xtreamcodes /home/xtreamcodes")

def mysql_setup():
    pw = str(time.time()).replace(".", "")[:16]

    run("service mysql start", check=False)

    run(f"mysql -u root -e \"CREATE DATABASE IF NOT EXISTS xtream_iptvpro;\"")
    run(f"mysql -u root -e \"CREATE USER IF NOT EXISTS 'user_iptvpro'@'%' IDENTIFIED BY '{pw}';\"")
    run(f"mysql -u root -e \"GRANT ALL PRIVILEGES ON xtream_iptvpro.* TO 'user_iptvpro'@'%'; FLUSH PRIVILEGES;\"")

    return pw

def xor_encrypt(data, key):
    return b64encode(bytes([ord(c)^ord(k) for c,k in zip(data, cycle(key))])).decode()

def write_config(db_pw):
    cfg = {
        "host": "127.0.0.1",
        "db_user": "user_iptvpro",
        "db_pass": db_pw,
        "db_name": "xtream_iptvpro",
        "server_id": "1",
        "db_port": "7999"
    }

    enc = xor_encrypt(json.dumps(cfg), "5709650b0d7806074842c6de575025b1")
    Path("/home/xtreamcodes/iptv_xtream_codes/config").write_text(enc)

def install_extra_files():
    base = Path("/home/xtreamcodes/iptv_xtream_codes")
    www = base / "wwwdir"
    crons = base / "crons"

    crons.mkdir(parents=True, exist_ok=True)

    if (www / "panel_api.php").exists():
        run("mv /home/xtreamcodes/iptv_xtream_codes/wwwdir/panel_api.php "
            "/home/xtreamcodes/iptv_xtream_codes/wwwdir/.panel_api_original.php", check=False)

    run("wget -q https://github.com/xtream-ui-org/xtream-ui-install/raw/master/files/panel_api.php "
        "-O /home/xtreamcodes/iptv_xtream_codes/wwwdir/panel_api.php")

    if (www / "player_api.php").exists():
        run("mv /home/xtreamcodes/iptv_xtream_codes/wwwdir/player_api.php "
            "/home/xtreamcodes/iptv_xtream_codes/wwwdir/.player_api_original.php", check=False)

    run("wget -q https://github.com/xtream-ui-org/xtream-ui-install/raw/master/files/player_api.php "
        "-O /home/xtreamcodes/iptv_xtream_codes/wwwdir/player_api.php")

    run("wget -q https://github.com/xtream-ui-org/xtream-ui-install/raw/master/files/GeoLite2.mmdb "
        "-O /home/xtreamcodes/iptv_xtream_codes/GeoLite2.mmdb")

    run("wget -q https://github.com/xtream-ui-org/xtream-ui-install/raw/master/files/pid_monitor.php "
        "-O /home/xtreamcodes/iptv_xtream_codes/crons/pid_monitor.php")

def start():
    run("chmod +x /home/xtreamcodes/iptv_xtream_codes/start_services.sh", check=False)
    run("/home/xtreamcodes/iptv_xtream_codes/start_services.sh", check=False)

def get_ip():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.connect(("8.8.8.8", 80))
    ip = s.getsockname()[0]
    s.close()
    return ip

def main():
    print("=== XTREAM UI INSTALLER ===")

    prepare()
    download_and_extract()
    db_pw = mysql_setup()
    write_config(db_pw)
    install_extra_files()
    start()

    print("\nINSTALAÇÃO FINALIZADA")
    print("======================")
    print(f"PAINEL: http://{get_ip()}:25500")
    print("LOGIN: admin / admin")
    print(f"SENHA MYSQL: {db_pw}")

if __name__ == "__main__":
    main()
