#!/usr/bin/env python3
import os, subprocess, shlex, sys, pathlib

def run(cmd):
    print(f"$ {cmd}")
    subprocess.run(shlex.split(cmd), check=True)

def prepare():
    run("apt update")
    run("apt install -y libcurl4 libxslt1-dev libgeoip-dev e2fsprogs wget nscd htop zip unzip mc libjemalloc2 mysql-server")

    if subprocess.run(["id", "xtreamcodes"], stdout=subprocess.DEVNULL).returncode != 0:
        run("adduser --system --shell /bin/false --group --disabled-login xtreamcodes")

    pathlib.Path("/home/xtreamcodes").mkdir(exist_ok=True)

def install_files():
    # copia arquivos EXTRAÍDOS do zip (não cs/*)
    for item in os.listdir("."):
        if item in ["install_py3.py", "install.py"]:
            continue
        run(f"cp -r {item} /home/xtreamcodes/")

    run("chown -R xtreamcodes:xtreamcodes /home/xtreamcodes")

def mysql_setup():
    run("systemctl enable mysql")
    run("systemctl start mysql")

def main():
    print("=== XTREAM UI INSTALLER | UBUNTU 22.04 ===")
    prepare()
    install_files()
    mysql_setup()
    print("INSTALAÇÃO CONCLUÍDA")

if __name__ == "__main__":
    main()
