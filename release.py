#!/usr/bin/python3
import PTN, sys, json

if __name__ == "__main__":
    try: print(json.dumps(PTN.parse(str(sys.argv[1]))))

    except: print(json.dumps({}))