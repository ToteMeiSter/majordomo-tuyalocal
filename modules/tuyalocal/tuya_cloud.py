#!/usr/bin/env python3
# tuya_cloud.py list <region> <key> <secret>
#   Возвращает JSON: {"devices":[{id,name,category,local_key,ip_wan,ip_lan,version,sub,gateway_id,online}]}
#   ip_lan/version берутся из локального скана сети (cloud отдаёт только внешний ip_wan).
import sys, json
try:
    import tinytuya
except Exception as e:
    print(json.dumps({"error": "no tinytuya: %s" % e})); sys.exit(1)
try:
    action = sys.argv[1]
    region, key, secret = sys.argv[2], sys.argv[3], sys.argv[4]
    if action != 'list':
        print(json.dumps({"error": "unknown action"})); sys.exit(1)

    c = tinytuya.Cloud(apiRegion=region, apiKey=key, apiSecret=secret)
    raw = c.getdevices(True)
    if isinstance(raw, dict) and 'result' in raw:
        devs = raw['result']
    elif isinstance(raw, list):
        devs = raw
    else:
        print(json.dumps({"error": "cloud: %s" % json.dumps(raw, ensure_ascii=False)[:200]})); sys.exit(1)

    # Локальный скан: сопоставляем device_id -> реальный LAN-IP и версию протокола
    try:
        scan = tinytuya.deviceScan(False, 18)
    except Exception:
        scan = {}
    byid = {}
    for ip, info in (scan or {}).items():
        gid = info.get('gwId') or info.get('id')
        if gid:
            byid[gid] = {"ip": ip, "version": str(info.get('version') or '3.3')}

    out = []
    for d in devs:
        did = d.get('id')
        s = byid.get(did, {})
        out.append({
            "id": did,
            "name": d.get('name'),
            "category": d.get('category'),
            "local_key": d.get('local_key') or d.get('key') or '',
            "ip_wan": d.get('ip', ''),
            "ip_lan": s.get('ip', ''),
            "version": s.get('version', '3.3'),
            "sub": bool(d.get('sub')),
            "gateway_id": d.get('gateway_id', ''),
            "online": bool(d.get('online')),
        })
    print(json.dumps({"devices": out}, ensure_ascii=False))
except Exception as e:
    print(json.dumps({"error": str(e)})); sys.exit(1)
