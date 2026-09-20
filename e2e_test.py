#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""回忆录系统 - 端到端流程验证脚本 (测试号 + 测试兑换码 666666)"""
import json
import urllib.request
import urllib.error

BASE = "http://127.0.0.1:9411"

def call(method, path, token=None, body=None):
    url = BASE + path
    data = None
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    if body is not None:
        data = json.dumps(body).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            raw = r.read().decode("utf-8")
            return r.status, json.loads(raw)
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8")
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, {"raw": raw[:300]}
    except Exception as e:
        return 0, {"error": str(e)}

def show(label, status, resp):
    print(f"\n=== {label} ===")
    print(f"HTTP {status}")
    print(json.dumps(resp, ensure_ascii=False, indent=2))

def main():
    print("BASE:", BASE)

    # STEP 1: 测试登录
    s1, r1 = call("POST", "/api/auth/test/login")
    show("STEP1 测试登录 (test/login)", s1, r1)
    token = r1.get("data", {}).get("access_token")
    uid = r1.get("data", {}).get("uid")
    print(f">>> token={token[:20]}... uid={uid} is_test={r1.get('data',{}).get('is_test')}")
    if not token:
        print("!!! 未拿到 token，终止"); return

    # STEP 2: 创建项目 (字段名 = name)
    s2, r2 = call("POST", "/api/projects", token=token,
                  body={"name": "测试回忆录项目", "real_name": "张三", "native_place": "广东清远"})
    show("STEP2 创建项目 (POST /api/projects name=...)", s2, r2)
    pid = r2.get("data", {}).get("id")
    print(f">>> project id={pid}")

    if not pid:
        # 取已有项目
        s2b, r2b = call("GET", "/api/projects", token=token)
        rows = r2b.get("data") or []
        if rows:
            pid = rows[0].get("id")
            print(f">>> 使用已有项目 id={pid}")

    if not pid:
        print("!!! 无项目可用，终止"); return

    # STEP 3: 绑定测试兑换码 666666
    s3, r3 = call("POST", f"/api/projects/{pid}/bind-code", token=token,
                  body={"code": "666666"})
    show("STEP3 绑定测试码 (POST /api/projects/{id}/bind-code code=666666)", s3, r3)

    # STEP 4: 查询项目状态
    s4, r4 = call("GET", "/api/projects", token=token)
    rows = r4.get("data") or []
    cur = next((x for x in rows if str(x.get("id")) == str(pid)), None)
    status_now = cur.get("status") if cur else "N/A"
    show("STEP4 查询项目状态 (GET /api/projects)", s4, {"data": cur})
    print(f">>> 项目 {pid} 当前 status={status_now}")

    # STEP 5: 再次绑定同一测试码（验证可无限复用）
    s5, r5 = call("POST", f"/api/projects/{pid}/bind-code", token=token,
                  body={"code": "666666"})
    show("STEP5 再次绑定同一测试码（验证可复用）", s5, r5)

    # 验证：再次查询状态是否仍 EDITABLE
    s6, r6 = call("GET", "/api/projects", token=token)
    rows = r6.get("data") or []
    cur2 = next((x for x in rows if str(x.get("id")) == str(pid)), None)
    print(f">>> 二次绑定后 status={cur2.get('status') if cur2 else 'N/A'}")

    print("\n=== 汇总 ===")
    print(f"login ok:   {token is not None} (uid={uid})")
    print(f"project:    id={pid}")
    print(f"bind(1):    {s3} {r3.get('msg','') if isinstance(r3,dict) else ''}")
    print(f"status now: {status_now}")
    print(f"bind(2):    {s5} {r5.get('msg','') if isinstance(r5,dict) else ''}")
    ok = (s3 == 200 and status_now == "EDITABLE" and s5 == 200)
    print("RESULT:", "PASS ✅" if ok else "FAIL ❌")

if __name__ == "__main__":
    main()
