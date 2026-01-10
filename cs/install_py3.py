#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import subprocess
import shlex
from pathlib import Path
from itertools import cycle
import requests

# URLs de download (mirror GitHub RAW)
DOWNLOAD_URL = {
    "main": "https://raw.githubusercontent.com/Carlos2021js/master/main/cs.zip",
    "sub":  "https://raw.githubusercontent.com/Carlos2021js/master/main/cs.zip",
}

INSTALL_MAP = {"MAIN": "main", "LB": "sub"}

# Pacotes ajustados para Ubuntu 22.04
PACKAGES_MAIN = [
    "libcurl4", "libxslt1-dev", "libgeoip-dev", "e2fsprogs", "wget",
    "nscd", "htop", "zip", "unzip", "mc", "libjemalloc2", "mysql-server"
]
PACKAGES_LB = [p for p in PACKAGES_MAIN if p != "mysql-server"]

class Color:
    OK = "\033[92m"; WARNING = "\033[93m"; FAIL = "\033[91m"; END = "\033[0m"

def printc(text: str, color: str = Color.OK) -> None:
    print(f"{color}{text}{Color.END}")

def run(cmd: str, check: bool = True):
    print(f"$ {cmd}")
    return subprocess.run(shlex.split(cmd), check=check)

def prepare():
    printc("Preparando instalação…")
    # desbloqueia apt
    for f in ["/var/lib/dpkg/lock-frontend", "/var/cache/apt/archives/lock", "/var/lib/dpkg/lock"]:
        try: Path(f).unlink()
        except Exception: pass
    run("apt-get update")

    pkgs = PACKAGES_MAIN
    run("apt-get install -y " + " ".join(pkgs))

    try:
        subprocess.check_output(["getent", "passwd", "xtreamcodes"])
    except subprocess.CalledProcessError:
        printc("Criando usuário xtreamcodes…")
        run("adduser --system --shell /bin/false --group --disabled-login xtreamcodes")
    Path("/home/xtreamcodes").mkdir(parents=True, exist_ok=True)

def download_and_extract():
    printc("Baixando installer zip…")
    url = DOWNLOAD_URL["main"]
    tmp_zip = Path("/tmp/xtreamcodes_cs.zip")

    with requests.get(url, stream=True, timeout=60) as r:
        if r.status_code != 200:
            printc("Falha no download.", Color.FAIL)
            return False
        with tmp_zip.open("wb") as f:
            for chunk in r.iter_content(8192):
                if chunk:
                    f.write(chunk)

    printc("Extraindo arquivos…")
    dest = Path("/home/xtreamcodes")
    dest.mkdir(parents=True, exist_ok=True)

    # descompactar diretamente no destino
    run(f"unzip -o {tmp_zip} -d {dest}", check=True)

    run(f"chown -R xtreamcodes:xtreamcodes {dest}", check=False)
    return True

def main():
    printc("Xtream UI Installer Correto para Ubuntu 22.04", Color.WARNING)
    prepare()
    if not download_and_extract():
        printc("Falha crítica no download/extração.", Color.FAIL)
        sys.exit(1)

    printc("Extração concluída!")
    printc("Instalação base pronta. Proceda com configuração adicional se necessário.", Color.OK)

if __name__ == "__main__":
    main()
