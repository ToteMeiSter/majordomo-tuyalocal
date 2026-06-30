#!/usr/bin/env python3
# tuya_helper.py status <ip> <id> <key> <ver>
# tuya_helper.py set    <ip> <id> <key> <ver> <dp> <value> <type:bool|int|str|raw>
#   raw: <value> = hex-строка, отправляется как сырые байты (для DP типа raw, напр. ручной запуск полива)
import sys, json, base64
try:
    import tinytuya
except Exception as e:
    print(json.dumps({"error": "no tinytuya: %s" % e})); sys.exit(1)
try:
    action, ip, did, key, ver = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5]
    d = tinytuya.Device(did, ip, key, version=float(ver)); d.set_socketTimeout(6)
    if action == 'status':
        print(json.dumps(d.status()))
    elif action == 'set':
        dp = int(sys.argv[6]); raw = sys.argv[7]; typ = sys.argv[8] if len(sys.argv) > 8 else 'str'
        if typ == 'bool':
            val = raw.lower() in ('1', 'true', 'on')
        elif typ == 'int':
            val = int(raw)
        elif typ == 'raw':
            # raw-DP в локальном протоколе передаётся base64 (вход — hex-строка)
            val = base64.b64encode(bytes.fromhex(raw)).decode()
        else:
            val = raw
        print(json.dumps(d.set_value(dp, val)))
    else:
        print(json.dumps({"error": "unknown action"}))
except Exception as e:
    print(json.dumps({"error": str(e)})); sys.exit(1)
