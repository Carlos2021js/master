#!/usr/bin/python3
import paramiko, os, socket, time, json, urllib.request, urllib.error, urllib.parse
from config import decrypt
from urllib.request import Request, urlopen
from urllib.error import URLError, HTTPError
# Status: 0 - Not Started       1 - Started         2 - Done
    
def getIP():
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        return s.getsockname()[0]
    except: return None

rDownloadURL = "https://raw.githubusercontent.com/xoceunder/Xtream-UI-install/master/balancer.py"

rBreload = "https://raw.githubusercontent.com/xoceunder/Xtream-UI-install/master/fbreload.py"
rSreload = "/home/xtreamcodes/iptv_xtream_codes/pytools/sreload.py"

rFbremake = "https://raw.githubusercontent.com/xoceunder/Xtream-UI-install/master/fbremake.py"
rFsremake = "/home/xtreamcodes/iptv_xtream_codes/pytools/fsremake.py"

rUgeolite = "https://raw.githubusercontent.com/xoceunder/Xtream-UI-install/master/updategeolite.py"
rYoutube = "https://raw.githubusercontent.com/xoceunder/Xtream-UI-install/master/updateyoutube.py"

rPath = "/home/xtreamcodes/iptv_xtream_codes/adtools/balancer/"
rConfig = decrypt()
rIP = getIP()
rIPlocal = "127.0.0.1"
rTime = time.time()

def writeDetails(rDetails):
    global rPath
    rFile = open("%s%d.json" % (rPath, int(rDetails["id"])), "w")
    rFile.write(json.dumps(rDetails))
    rFile.close()

def installBalancer(rDetails):
    global rPath, rDownloadURL, rIP, rConfig

    rDetails["status"] = 1
    writeDetails(rDetails)

    rClient = paramiko.SSHClient()
    rClient.set_missing_host_key_policy(paramiko.AutoAddPolicy())

    try:
        rClient.connect(rDetails["host"], rDetails["port"], "root", rDetails["password"])
    except: return False

    def run_cmd(cmd):
        try:
            stdin, stdout, stderr = rClient.exec_command(cmd)
            stdout.channel.recv_exit_status()
        except: pass

    try:
        try:
            version = run_cmd("lsb_release -rs || cat /etc/os-release | grep VERSION_ID | cut -d'\"' -f2")
        except: version = None

        run_cmd("apt-get update -y && apt-get full-upgrade -y")
        run_cmd("apt-get install -y python3 python3-pip wget curl")
        run_cmd("ln -sf /usr/bin/python3 /usr/bin/python")

        try:
            if version and float(version) >= 22.0:
                run_cmd("apt-get install -y python3-venv")
        except: pass

        run_cmd(f"wget -q \"{rDownloadURL}\" -O \"/tmp/balancer.py\"")
        run_cmd(
            "python /tmp/balancer.py "
            f"\"{rIP}\" \"{rConfig['db_port']}\" \"{rConfig['db_user']}\" "
            f"\"{rConfig['db_pass']}\" \"{rConfig['db_name']}\" "
            f"{int(rDetails['id'])} {int(rDetails['http_broadcast_port'])} "
            f"{int(rDetails['https_broadcast_port'])} {int(rDetails['rtmp_port'])}"
        )

        rDetails["status"] = 2
        try:
            os.remove(f"{rPath}{int(rDetails['id'])}.json")
        except: pass

    except:
        rDetails["status"] = 0
        writeDetails(rDetails)

    try: rClient.close()
    except: pass
    return True

def restartServices(rDetails):
    global rPath
    rClient = paramiko.SSHClient()
    rClient.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try: rClient.connect(rDetails["host"], rDetails["port"], "root", rDetails["password"])
    except: return False
    rClient.exec_command("sudo systemctl restart xtreamcodes")
    try: os.remove("%s%d.json" % (rPath, int(rDetails["id"])))
    except: pass
    try: rClient.close()
    except: pass
    return True

def rebootServer(rDetails):
    global rPath
    rClient = paramiko.SSHClient()
    rClient.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try: rClient.connect(rDetails["host"], rDetails["port"], "root", rDetails["password"])
    except: return False
    rClient.exec_command("sudo reboot")
    try: os.remove("%s%d.json" % (rPath, int(rDetails["id"])))
    except: pass
    try: rClient.close()
    except: pass
    return True
    
def fbReload(rDetails):
    global rPath, rBreload
    rDetails["status"] = 1
    writeDetails(rDetails)
    rClient = paramiko.SSHClient()
    rClient.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try: rClient.connect(rDetails["host"], rDetails["port"], "root", rDetails["password"])
    except: return False
    try:
        rClient.exec_command("rm -rf /tmp/fbreload.py && sudo wget -q \"%s\" -O \"/tmp/fbreload.py\"" % rBreload)
        rClient.exec_command("sudo python /tmp/fbreload.py \"%s\" \"%s\" \"%s\" \"%s\" \"%s\" %d" % (rIP, rConfig["db_port"], rConfig["db_user"], rConfig["db_pass"], rConfig["db_name"], int(rDetails["id"])))
    except: pass
    rDetails["status"] = 2
    try: os.remove("%s%d.json" % (rPath, int(rDetails["id"])))
    except: pass
    try: rClient.close()
    except: pass
    return True
    
def fsReload(rDetails):
    global rPath, rSreload
    rClient = paramiko.SSHClient()
    rClient.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try: rClient.connect(rDetails["host"], rDetails["port"], "root", rDetails["password"])
    except: return False
    try:
        rClient.exec_command("rm -rf /tmp/sreload.py && sudo cp \"%s\" \"/tmp/sreload.py\"" % rSreload)
        rClient.exec_command("sudo python /tmp/sreload.py \"%s\" \"%s\" \"%s\" \"%s\" \"%s\" %d" % (rIPlocal, rConfig["db_port"], rConfig["db_user"], rConfig["db_pass"], rConfig["db_name"], int(rDetails["id"])))
    except: pass
    try: os.remove("%s%d.json" % (rPath, int(rDetails["id"])))
    except: writeDetails(rDetails)
    try: rClient.close()
    except: pass
    return True
    
def fbRemake(rDetails):
    global rPath, rFbremake
    rClient = paramiko.SSHClient()
    rClient.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try: rClient.connect(rDetails["host"], rDetails["port"], "root", rDetails["password"])
    except: return False
    try:
        rClient.exec_command("rm -rf /tmp/fbremake.py && sudo wget -q \"%s\" -O \"/tmp/fbremake.py\"" % rFbremake)
        rClient.exec_command("sudo python /tmp/fbremake.py \"%s\" \"%s\" \"%s\" \"%s\" \"%s\" %d" % (rIP, rConfig["db_port"], rConfig["db_user"], rConfig["db_pass"], rConfig["db_name"], int(rDetails["id"])))
    except: pass
    try: os.remove("%s%d.json" % (rPath, int(rDetails["id"])))
    except: pass
    try: rClient.close()
    except: pass
    return True
    
def fsRemake(rDetails):
    global rPath, rFsremake
    rClient = paramiko.SSHClient()
    rClient.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try: rClient.connect(rDetails["host"], rDetails["port"], "root", rDetails["password"])
    except: return False
    try:
        rClient.exec_command("rm -rf /tmp/sreload.py && sudo cp \"%s\" \"/tmp/fsremake.py\"" % rFsremake)
        rClient.exec_command("sudo python /tmp/fsremake.py \"%s\" \"%s\" \"%s\" \"%s\" \"%s\" %d" % (rIPlocal, rConfig["db_port"], rConfig["db_user"], rConfig["db_pass"], rConfig["db_name"], int(rDetails["id"])))
    except: pass
    try: os.remove("%s%d.json" % (rPath, int(rDetails["id"])))
    except: pass
    try: rClient.close()
    except: pass
    return True

def Ugeolite(rDetails):
    global rPath, rUgeolite
    rDetails["status"] = 1
    writeDetails(rDetails)
    rClient = paramiko.SSHClient()
    rClient.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try: rClient.connect(rDetails["host"], rDetails["port"], "root", rDetails["password"])
    except: return False
    try:
        rClient.exec_command(f"rm -rf /tmp/ugeolite.py && wget -q {rUgeolite} -O /tmp/ugeolite.py && sudo python3 /tmp/ugeolite.py")
    except: pass
    rDetails["status"] = 2
    try: os.remove(f"{rPath}{int(rDetails['id'])}.json")
    except: pass
    try: rClient.close()
    except: pass
    return True
    
def Uyoutube(rDetails):
    global rPath, rYoutube
    rDetails["status"] = 1
    writeDetails(rDetails)
    rClient = paramiko.SSHClient()
    rClient.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try: rClient.connect(rDetails["host"], rDetails["port"], "root", rDetails["password"])
    except: return False
    try:
        rClient.exec_command(f"rm -rf /tmp/uyoutube.py && wget -q {rYoutube} -O /tmp/uyoutube.py && sudo python3 /tmp/uyoutube.py")
    except: pass
    rDetails["status"] = 2
    try: os.remove(f"{rPath}{int(rDetails['id'])}.json")
    except: pass
    try: rClient.close()
    except: pass
    return True

if __name__ == "__main__":
    if rIP and rIPlocal and rConfig:
        for rFile in os.listdir(rPath):
            try: rDetails = json.loads(open(rPath + rFile).read())
            except: rDetails = {"status": -1}
            try: rType = rDetails["type"]
            except: rType = None
            if rType == "restart": restartServices(rDetails)
            elif rType == "reboot": rebootServer(rDetails)
            elif rType == "breload": fbReload(rDetails)
            elif rType == "sreload": fsReload(rDetails)
            elif rType == "fbremake": fbRemake(rDetails)
            elif rType == "fsremake": fsRemake(rDetails)
            elif rType == "ugeolite": Ugeolite(rDetails)
            elif rType == "uyoutube": Uyoutube(rDetails)
            elif rType == "urelease": Urelease(rDetails)
            else:
                if rDetails["status"] == 0: installBalancer(rDetails)