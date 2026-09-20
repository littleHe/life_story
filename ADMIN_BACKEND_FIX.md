# 回忆录系统 · 后台管理接口路由修复与端到端验证

## 一、本次解决的问题

后台管理接口 `/admin/api/*` 在本机 `php think run`（PHP 内置开发服务器）下**全部 404/500**，
导致上一轮已建好的 layuimini 后台前端（含菜单图标功能）无法联调。

### 根因（两层）
1. **静态目录吞掉路由（开发服务器特有）**
   `public/admin/` 是真实存在的静态目录（后台 HTML 外壳）。PHP 内置开发服务器对
   "首段路径命中真实目录" 的请求，会拦截其下所有子路径、**不再交给 ThinkPHP 路由**，
   于是 `/admin/api/login` 永远落不到注册路由，被默认分发误当成控制器 `Api` → `控制器不存在`。
   - 已用实验坐实：把 `public/admin` 临时改名 → `/admin/api/login` 立刻 200。
   - 自定义 router 脚本也绕不过：首段目录存在时，内置服务器对不存在的子路径直接返回 404，不调用 router。
   - 生产（宝塔/nginx）用 `try_files` 回退 `index.php`，**无此问题**。
2. **`life_story` 数据库不存在**
   `mysql` 里没有该库（之前"已建表"未持久化），login 查询 `ls_admin` 报
   `SQLSTATE[HY000][1049] Unknown database 'life_story'`。

## 二、修复方案

### 1) 接口前缀 `admin/api` → `admin-api`（连字符）
保留后台页面在 `/admin/`（符合 booking 习惯，菜单 href 不变），仅把接口前缀换成 `admin-api`。
因为 `public/admin-api` 不存在，开发服务器不再拦截，开发/生产皆可正常路由。
- `route/app.php`：`Route::group('admin-api', ...)`
- `app/middleware/AdminAuth.php`：`$except` 同步改为 `admin-api/login`
- 前端 11 个 HTML + 12 个 Admin 控制器 docblock 中 `/admin/api/` 批量替换为 `/admin-api/`
- 移除排查期遗留的 debug 路由（debuglogin/backapi/admintest 等）

### 2) 建库并导入 schema
```
CREATE DATABASE life_story;
mysql life_story < database/install.sql
mysql life_story < database/admin_install.sql
php database/seed_admin.php        # admin/admin888，写入 13 条带 Font Awesome 图标的菜单
```

## 三、端到端验证结果（全部 PASS）

| 项 | 结果 |
|---|---|
| `POST /admin-api/login` | 200，返回 `{code:0, 登录成功}` |
| `GET /admin-api/init`（菜单+图标） | 200，menuInfo 带回 `fa fa-book` / `fa fa-list-ol` / `fa fa-users` 等图标 |
| 10 个 list 接口（menu/chapter/user/apilog/code/codelog/slide/adminuser/index/stat） | 全部 200 |
| 静态页面 `/admin/index.html`、`/admin/page/menu.html` | 200 |
| 图标数据源 `/static/plugs/font-awesome-4.7.0/less/variables.less` | 200 |
| 图标写回读（`menu/save` 存 `fa fa-star` 再 `menu/index` 读回） | 通过 |
| 路由文件 debug 残留 | 已清除 |

> 图标功能说明：`iconPickerFa` 自行注入 CSS，菜单 `icon` 字段直存 `fa fa-xxx`，
> miniMenu 原样输出 `<i class="fa fa-xxx">`；菜单管理页 `menu.html` 已集成可视化图标选择器。

## 四、本地启动顺序（备忘）
phpStudy 启 MySQL → `php think run -p 9411`（backend 目录）→ 前端 `npm run dev`（5173）。
后台访问：`/admin/index.html`（账号 admin / admin888）。

## 五、注意事项
- ⚠️ **后台接口前缀固定用 `/admin-api/`**，不要再改回 `/admin/api/`，否则本机 `php think run` 下路由再次被吞。
- 设计文档若仍写 `/admin/api/`，以本仓库代码为准（实际已为 `/admin-api/`）。
