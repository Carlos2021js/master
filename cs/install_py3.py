#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import subprocess
import shlex
from pathlib import Path

class Color:
    OK = '\033[92m'
    FAIL = '\033[91m'
    WARN = '\033[93m'
    END = '\033[0m'

def printc(msg, color=Color.OK):
    print(color + msg + Color.END)

def run(cmd, check=True):
    return subprocess.run(shlex.split(cmd), check=check)

def prepare():
    printc("Preparando sistema...")

    run("apt update")
    run(
        "apt install -y "
        "wget unzip curl git sudo "
        "mysql-server "
        "libcurl4 libxslt1-dev libgeoip-dev "
        "e2fsprogs htop zip mc"
    )

    run("systemctl enable mysql", check=False)
    run("systemctl start mysql", check=False)

def download_and_extract():
    printc("Instalando arquivos Xtream UI")

    base = Path.cwd()

    if not Path("files").exists():
        printc("ERRO: pasta 'files' não encontrada. Execute dentro do diretório correto.", Color.FAIL)
        sys.exit(1)

    dest = Path("/home/xtreamcodes/iptv_xtream_codes")
    dest.mkdir(parents=True, exist_ok=True)

    # copiar arquivos principais
    run(f"cp -r {base}/files/* {dest}")
    run(f"cp -f {base}/balancer.py {dest}", check=False)
    run(f"cp -f {base}/update_geolite2.sh {dest}", check=False)

    # permissões
    run("useradd -r -s /bin/false xtreamcodes", check=False)
    run("chown -R xtreamcodes:xtreamcodes /home/xtreamcodes")

    printc("Arquivos instalados com sucesso")

def finalize():
    printc("Instalação finalizada com sucesso!")
    printc("Painel: http://SEU_IP:25500")
    printc("Login padrão: admin / admin", Color.WARN)

def main():
    printc("=== XTREAM UI INSTALLER ===")

    prepare()
    download_and_extract()
    finalize()

if __name__ == "__main__":
    main()
