#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
回忆录系统 · 后台接口回归测试
    python admin_e2e_test.py [base_url]

覆盖：
  1. 未登录访问 /admin/api/init 应返回 401
  2. 管理员登录（session cookie）
  3. 菜单初始化（校验 icon 字段存在）
  4. 控制台统计
  5. 菜单管理 CRUD（含图标写入/回读）
  6. 幻灯片 CRUD
  7. 章节/用户/接口日志/兑换码/兑换码日志/管理员 列表接口
  8. 退出登录后再次访问应 401
"""
import json
import sys
import urllib.request
import urllib.error
import http.cookiejar

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:9411"
USERNAME = "admin"
PASSWORD = "admin888"

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

passed, failed = [], []


def call(method, path, body=None, expect_http=None, raw=False):
    url = BASE + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Content-Type", "application/json")
    try:
        with opener.open(req, timeout=15) as resp:
            status, text = resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        status, text = e.code, e.read().decode("utf-8", "replace")
    try:
        parsed = json.loads(text)
    except Exception:
        parsed = None
    if raw:
        return status, parsed, text
    return status, parsed


def check(name, cond, detail=""):
    (passed if cond else failed).append(name)
    print(("  [PASS] " if cond else "  [FAIL] ") + name + (("  -> " + str(detail)) if detail else ""))


print("BASE =", BASE)

# ---------- 1. 未登录 ----------
print("\n[1] 未登录访问")
st, js = call("GET", "/admin/api/init")
check("未登录 /admin/api/init 返回 401", st == 401, f"http={st}")
check("返回 JSON 提示", isinstance(js, dict) and js.get("code") == 401, js)

# ---------- 2. 登录 ----------
print("\n[2] 管理员登录")
st, js = call("POST", "/admin/api/login", {"username": USERNAME, "password": PASSWORD})
check("登录成功", st == 200 and isinstance(js, dict) and js.get("code") == 0, js)
check("写入 session cookie", any(c.name == "LSSESSID" for c in jar), [c.name for c in jar])

st, js = call("POST", "/admin/api/login", {"username": USERNAME, "password": "wrong-pass"})
check("错误密码被拒绝", isinstance(js, dict) and js.get("code") != 0, js)

# ---------- 3. 菜单初始化（图标） ----------
print("\n[3] 菜单初始化 /admin/api/init")
st, js = call("GET", "/admin/api/init")
ok = st == 200 and isinstance(js, dict) and "menuInfo" in js and "logoInfo" in js and "homeInfo" in js
check("init 返回 logoInfo/homeInfo/menuInfo", ok, list(js.keys()) if isinstance(js, dict) else js)

menus = (js or {}).get("menuInfo") or []
check("菜单树非空", len(menus) > 0, f"top-level={len(menus)}")

icons = []
def walk(nodes):
    for n in nodes:
        if n.get("icon"):
            icons.append(n["icon"])
        if n.get("child"):
            walk(n["child"])
walk(menus)
check("菜单带 icon 字段（fa 类名）", len(icons) > 0, icons[:6])
check("icon 均为 fa 前缀", all(i.startswith("fa") for i in icons), [i for i in icons if not i.startswith("fa")])

titles = [m.get("title") for m in menus]
check("包含业务顶级菜单", any("回忆录" in (t or "") for t in titles), titles)

# ---------- 4. 控制台统计 ----------
print("\n[4] 控制台统计 /admin/api/stat")
st, js = call("GET", "/admin/api/stat")
d = (js or {}).get("data") or {}
check("stat 返回概览", st == 200 and "overview" in d, list(d.keys()))
check("含近7天趋势", len(d.get("week") or []) == 7, len(d.get("week") or []))

# ---------- 5. 菜单管理 CRUD ----------
print("\n[5] 菜单管理（含图标）")
st, js = call("POST", "/admin/api/menu/save", {
    "pid": 0, "title": "回归测试菜单", "icon": "fa fa-flask",
    "href": "/admin/welcome.html", "target": "_self", "sort": 1, "status": 1,
})
new_id = ((js or {}).get("data") or {}).get("id")
check("新增菜单", st == 200 and (js or {}).get("code") == 0 and new_id, js)

st, js = call("GET", "/admin/api/menu/index")
rows = (js or {}).get("data") or []
row = next((r for r in rows if r.get("id") == new_id), None)
check("列表中能查到新菜单", row is not None)
check("图标被正确保存", (row or {}).get("icon") == "fa fa-flask", (row or {}).get("icon"))

st, js = call("POST", "/admin/api/menu/save", {"id": new_id, "pid": 0, "title": "回归测试菜单改",
                                              "icon": "fa fa-star", "href": "/admin/welcome.html",
                                              "target": "_self", "sort": 2, "status": 1})
check("编辑菜单", (js or {}).get("code") == 0, js)

st, js = call("POST", "/admin/api/menu/save", {"id": new_id, "pid": 0, "title": "非法图标",
                                              "icon": "<script>alert(1)</script>"})
check("非法图标被拒绝", (js or {}).get("code") != 0, js)

st, js = call("GET", "/admin/api/menu/options")
check("上级菜单下拉可用", st == 200 and isinstance((js or {}).get("data"), dict), js)

st, js = call("POST", "/admin/api/menu/delete", {"id": new_id})
check("删除菜单", (js or {}).get("code") == 0, js)

st, js = call("POST", "/admin/api/menu/delete", {"id": 1})
check("有子菜单时拒绝删除", (js or {}).get("code") != 0, js)

# ---------- 6. 幻灯片 CRUD ----------
print("\n[6] 幻灯片管理")
st, js = call("POST", "/admin/api/slide/save", {"title": "回归测试幻灯片", "image": "/uploads/slide/test.png",
                                               "link": "", "sort": 9, "status": 1, "remark": "auto"})
sid = ((js or {}).get("data") or {}).get("id")
check("新增幻灯片", (js or {}).get("code") == 0 and sid, js)

st, js = call("GET", "/admin/api/slide/index")
check("幻灯片列表", st == 200 and (js or {}).get("count", 0) >= 1, js)

st, js = call("POST", "/admin/api/slide/save", {"title": "缺图", "image": ""})
check("缺图片时拒绝保存", (js or {}).get("code") != 0, js)

st, js = call("POST", "/admin/api/slide/delete", {"id": sid})
check("删除幻灯片", (js or {}).get("code") == 0, js)

# ---------- 7. 各列表接口 ----------
print("\n[7] 业务列表接口")
for name, path in [
    ("章节管理", "/admin/api/chapter/index"),
    ("授权用户", "/admin/api/user/index"),
    ("接口日志", "/admin/api/apilog/index"),
    ("接口日志统计", "/admin/api/apilog/stat"),
    ("兑换码管理", "/admin/api/code/index"),
    ("兑换码日志", "/admin/api/codelog/index"),
    ("安全锁定日志", "/admin/api/codelog/locks"),
    ("管理员列表", "/admin/api/adminuser/index"),
]:
    st, js = call("GET", path)
    good = st == 200 and isinstance(js, dict) and js.get("code") == 0
    check(f"{name} 接口可用", good, (js or {}).get("msg") if isinstance(js, dict) else st)

st, js = call("GET", "/admin/api/apilog/index")
check("接口日志已开始记录（ApiLog 中间件生效）",
      isinstance(js, dict) and js.get("code") == 0 and "count" in js,
      (js or {}).get("count"))

# ---------- 8. 退出登录 ----------
print("\n[8] 退出登录")
st, js = call("POST", "/admin/api/logout")
check("退出成功", (js or {}).get("code") == 0, js)
st, js = call("GET", "/admin/api/init")
check("退出后访问被拦截", st == 401, f"http={st}")

# ---------- 汇总 ----------
print("\n" + "=" * 56)
print(f"PASS {len(passed)} / FAIL {len(failed)}")
if failed:
    print("失败项：")
    for f in failed:
        print("  -", f)
    sys.exit(1)
print("后台接口全部通过 ✅")
