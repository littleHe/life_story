# 后台首页白屏卡加载动画 — 修复说明

> 现象：打开 `http://127.0.0.1:9411/admin/index.html` 后整页空白，只有蓝色加载圈一直转；
> DevTools 里 `favicon.ico` 报红。
> 修复日期：2026-09-14

---

## 一、排查方式（值得保留的经验）

`curl` 只能证明**接口**通不通，证明不了**前端 JS 是否真的执行成功**。本轮改为用真实浏览器：

用 Node 内置 `WebSocket` 直连 **Chrome DevTools Protocol**（零第三方依赖、无需下载 Chromium），
注入登录 Cookie 打开页面，采集 `Runtime.exceptionThrown`（JS 异常）、
`Network.responseReceived`（失败请求）并断言关键 DOM。

第一次运行就抓到决定性证据：

```
404  /static/plugs/jquery-3.4.1/jquery-3.4.1.min.js?v=1.0.0
404  /static/plugs/lay-module/layuimini/miniAdmin.js?v=1.0.0
Error: Script error for "jquery"
DOM:  makeDisplay=none(实际上应为 block 遮罩可见)  loaderDisplay=block  sideMenuItems=0
```

> 注：`favicon.ico` 报红只是"顺带"的 404，**不是**本次故障原因，但它确实缺失，已一并修好。

---

## 二、根因（两层，都是真实 bug）

### 根因 1（主因）：`public/router.php` 用带查询串的 `REQUEST_URI` 判断静态文件

原代码：

```php
if (PHP_SAPI == 'cli-server') {
    if (is_file(__DIR__ . $_SERVER['REQUEST_URI'])) {   // ← REQUEST_URI 含 "?v=1.0.0"
        return false;
    }
}
require __DIR__ . '/index.php';
```

RequireJS / layui 会给每个资源自动追加版本号（`config-admin.js` 里的 `urlArgs: "v=1.0.0"`），
于是 `is_file('/static/.../jquery-3.4.1.min.js?v=1.0.0')` **恒为 false**
→ 静态资源被转发给 `index.php`
→ ThinkPHP 返回 404
→ `require(['jquery','miniAdmin'], ...)` 依赖加载失败，**回调永不执行**
→ `miniAdmin.render()` 没跑 → `.layuimini-make`（白遮罩）+ `.layuimini-loader`（转圈）永不消失 = **整页白屏卡转圈**

**判别特征（一眼定位）**：同一个文件**不带** `?query` 是 200，**带** `?v=` 就是 404。
浏览器里所有 CSS/JS/字体/图标全都会 404，然而手敲地址栏访问却正常 —— 见到这个组合就是这个 bug。

**修复**：先剥离查询串再判断。

```php
if (PHP_SAPI == 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = urldecode($path);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}
```

### 根因 2（次因，RequireJS 一通就会立刻暴露）：对 layuimini 模块的无参调用

`index.html` 里原本多写了两行：

```js
require(['jquery','miniAdmin','miniTab','miniTheme'], function ($, miniAdmin, miniTab, miniTheme) {
    $.getJSON('/admin-api/init', function () {
        miniTheme.render();      // ← 无参
        miniAdmin.render(options);
        miniTab.listen();        // ← 无参
    });
});
```

而 `miniAdmin.render()` **内部已经**用完整参数调用过这两者：

```js
// miniAdmin.js:54-74
miniMenu.render({...});
miniTab.render({...});   // 内部会 miniTab.listen(options)
miniTheme.render({...}); // 内部会 miniTheme.listen(options)
```

`miniTheme.js:273` 的实现**没有参数保护**：

```js
render: function (options) {
    options.bgColorDefault = options.bgColorDefault || false;  // options 为 undefined → TypeError
```

所以 `miniTheme.render()` 必抛 `TypeError`，**中断 `$.getJSON` 回调**，
后面的 `miniAdmin.render(options)` 再也执行不到 —— 与根因 1 症状一模一样。

（`miniTab.listen(options)` 首行是 `options = options || {}`，有保护，属冗余但不致命。）

**修复**：删掉这两个外部调用；并把 `.fail()` 补上非 401/403 的错误提示，
避免以后再出现"静默卡死、只留一个永不消失的加载圈"。

### 根因 3（顺带）：`public/favicon.ico` 缺失

浏览器默认请求 `/favicon.ico` → 404。已用纯 Python 标准库手写 16+32 双尺寸 ICO
（品牌蓝圆角方块 + 白色书本），现在返回 200。

---

## 三、改动文件清单

| 文件 | 改动 |
| --- | --- |
| `public/router.php` | **核心修复**：剥离查询串再 `is_file()` |
| `public/admin/index.html` | 删除无参 `miniTheme.render()` / `miniTab.listen()`；预检接口 `init` → `info`（顺带取用户名，避免 `init` 请求两次）；`.fail()` 补非 401/403 错误提示 |
| `public/admin/page/menu.html` | 新增 `setIconPreview()`：纠正 `checkIcon` 回显时预览 class 变成 `fa fa fa-book` 的问题 |
| `public/favicon.ico` | 新增（原缺失） |
| `tests/admin-e2e.js` | 新增：自包含浏览器回归测试 |
| `screenshots/*.png` | 新增：验收截图 |

> 说明：`route/app.php`、`AdminAuth.php` 及全部 `*-api` 前缀**未改动**（沿用上一轮的 `admin-api` 约定）。

### 为什么 `menu.html` 不能"顺手消重"

`iconPickerFa.checkIcon(filter, c)` 内部同时做两件事：

```js
p.html('').attr('class', 'fa ' + c);   // 预览图标
el.attr('value', c).val(c);            // 表单值
```

传入完整值 `fa fa-book` → 预览 class 变成 `fa fa fa-book`（多一个 `fa`，仅外观冗余）。

但**不能**为了消重而改传 `fa-book` —— 那样表单值会变成 `fa-book`，
保存进库后 `miniMenu` 渲染成 `<i class="fa-book">`，**Font Awesome 4 缺少基础类 `fa`，图标会直接不显示**。
所以正确做法是：**照旧传完整值**，另写 `setIconPreview()` 在回显后纠正预览那一处的 class。

---

## 四、验收结果（真实 Chrome，CDP 自动断言）

```
PASS 登录成功 (session 27acacb6...)
PASS 后台框架         /admin/index.html  图标: fa fa-book fa fa-list-ol fa fa-users
PASS 控制台           /admin/welcome.html
PASS 系统章节         /admin/page/chapter.html
PASS 授权用户         /admin/page/user.html
PASS 接口日志         /admin/page/apilog.html
PASS 兑换码           /admin/page/code.html
PASS 兑换码日志        /admin/page/codelog.html
PASS 首页幻灯片        /admin/page/slide.html
PASS 管理员           /admin/page/adminuser.html
PASS 菜单管理          /admin/page/menu.html
PASS 图标选择器   图标数=786 搜索=786 -> 9 编辑回显=fa fa-book 预览=fa fa-book
--------------------------------------------------
结果: 全部通过
```

- 11 个后台页面：**0 JS 异常、0 失败请求**
- 侧栏菜单 Font Awesome 图标正常（`fa fa-book` / `fa fa-list-ol` / `fa fa-users` …）
- 图标选择器：`variables.less` → 解析 **786** 个图标；面板可展开、分页 `1/66 (786)`；
  点选写回 `#icon` 输入框；搜索 `book` 过滤 786 → 9；编辑回显值格式正确

---

## 五、如何运行回归测试

前置：后端已启动（`backend` 目录下 `php think run -p 9411 -H 127.0.0.1`），
数据库已导入并 seed（admin / admin888）。脚本会自己登录。

```bash
cd D:/Work/2026/AiWork/life_story/backend
node tests/admin-e2e.js
```

可用环境变量覆盖：`CHROME_PATH`、`ADMIN_BASE`、`ADMIN_USER`、`ADMIN_PASS`。
退出码 `0` = 全部通过，`1` = 有失败项。

---

## 六、遗留 / 下一步

1. **浏览器缓存**：修复后第一次访问请按 `Ctrl + F5` 强制刷新一次。
2. `backend/` 根目录下并存 `admin_e2e_test.py`、`e2e_test.py`（早前的接口级测试）
   与新的 `tests/admin-e2e.js`，建议后续统一收进 `tests/` 并规范命名。
3. 生产部署（宝塔/nginx）不走 `router.php`，根因 1 不会出现；
   但仍需确认 nginx 对 `/admin-api/*` 走 `try_files ... /index.php?$query_string`。
