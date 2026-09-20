# 回忆录系统 · 线上部署手册（宝塔 / 通用 LNMP）

> 适用：前后台同机部署，前端构建产物放在 `backend/public/h5/`，对外访问 `https://域名/h5/`。

## 0. 架构与目录约定
- **站点根目录（web root）= `backend/public`**：PHP 入口 `index.php` 在此；`/api`、`/admin-api`、`/uploads`、`/h5` 均在根路径下。
- **前端产物**：构建后落在 `backend/public/h5/`，访问 `https://域名/h5/`。
- **后台静态页**：`backend/public/admin/`，访问 `https://域名/admin/`（默认 admin/admin888，上线务必改密）。

## 1. 服务器环境要求
- PHP ≥ 8.2（本项目用 8.2.9），扩展：`curl`、`openssl`、`gd`、`mbstring`、`fileinfo`、`zip`，队列驱动按需开 `redis`（或 database）。
- MySQL ≥ 5.7（库名 `life_story`）。
- Composer 2.x。
- Node.js ≥ 20（仅**构建前端**时需要；线上服务器也可在本地构建完把 `h5` 整目录传上去）。
- 走腾讯云 AI（ASR/生图/TTS）需服务器能访问外网；HTTPS 调用报证书错误时配置 `AI_SSL_CA`（见 §6）。

## 2. 部署步骤
### 2.1 传代码
把整个项目放到服务器，例如 `/www/wwwroot/life_story/`，确保 `backend/public` 作为站点根目录。

### 2.2 后端依赖与库初始化
```bash
cd /www/wwwroot/life_story/backend
composer install --no-dev -o
cp .env.example .env        # 然后按 §6 填真实值
# 首次建库：install.sql + admin_install.sql → migrations/*.php → seed_admin.php(admin/admin888) → seed_cover_bg.sql
# （具体脚本以 database/ 目录为准；migrations 用 php think migrate 或手动执行）
```

### 2.3 构建并部署前端
在含 node 的环境（本地或服务器）执行：
```bash
cd front
npm ci                        # 或 npm install
npm run build:deploy          # tsc 校验 + vite 构建 + 拷贝到 ../backend/public/h5
```
`build:deploy` 会：清空 `dist` → 构建 → 清洗 `index.html` 平台占位符 → 标题改写为「人生回忆录」→ 资源前缀改为 `/h5/assets/` → 剔除 sourcemap → 写入 `public/h5/.htaccess` 做 SPA 回退。
> 线上 base 路径由 `CLIENT_BASE_PATH=/h5` 决定（已内置在 `front/vite.config.ts`，只在 production 生效；本地 `npm run dev` 仍是根路径）。

### 2.4 Web 服务器（宝塔）
- 网站「根目录」设为 `backend/public`；PHP 选 8.2，开启 §1 扩展。
- 伪静态：选宝塔自带「ThinkPHP」规则；再手动补 `/h5/` 的 SPA 回退（见 §3）。

## 3. 前端 SPA 路由回退（关键，不配会白屏）
刷新 `/h5/xxx` 子路由必须回退到 `index.html`。
- **Apache**：已自动写入 `backend/public/h5/.htaccess`；根 `.htaccess` 已加 `RewriteCond %{REQUEST_URI} !^/h5/` 排除 SPA 目录，避免与 ThinkPHP 路由冲突。
- **Nginx**：在 `server` 块加：
```nginx
location /h5/ {
    try_files $uri $uri/ /h5/index.html;
}
```

## 4. 异步任务（AI 生成 / think-queue）
定稿后的增值任务（配图 / 朗读 / 封面 / 声音复刻）由 think-queue + 自定义 worker 执行。
- **常驻兜底（推荐）**：每 5 分钟：
```cron
*/5 * * * * cd /www/wwwroot/life_story/backend && php think ai:rescue >> runtime/cron.log 2>&1
```
`ai:rescue` 会：自愈卡死的孤儿任务，并在「队列有活且没活着的 worker」时拉起 `php think ai:work`（worker 干完即退，30 分钟预算内自我续跑）。
- 首次手动触发：`php think ai:work`（后台运行），或交由上述 cron 自动接管。

## 5. 微信登录（真实环境）
- 公众号后台 → 设置与开发 → 公众号设置 → 功能设置 → **网页授权域名**：填 `你的域名`（不含 `http(s)://`，不含 `/h5`）。
- `.env` 配置：`WECHAT_APPID`、`WECHAT_SECRET`、`WECHAT_SCOPE=snsapi_userinfo`。
- 前端 `LoginPage` 自动流程：拉 `/api/auth/wechat/config` → 若 `enabled` 跳转微信授权 → 回调带 `?code=` → 调 `/api/auth/wechat/login` 换 JWT；`APP_ENV=production` 时自动关闭测试登录入口。
- 验证端点：`GET /api/auth/wechat/config` 返回 `{enabled:true, appid, scope}`（未配 appid/secret 时为 `false`）。

## 6. .env 生产关键项
```dotenv
APP_ENV=production
APP_DEBUG=false
DATABASE_HOST=...
DATABASE_NAME=life_story
DATABASE_USER=...
DATABASE_PASSWORD=...

WECHAT_APPID=真实公众号AppID
WECHAT_SECRET=真实公众号AppSecret
WECHAT_SCOPE=snsapi_userinfo

JWT_SECRET=至少32位随机串
CODE_CIPHER_KEY=兑换码加解密密钥（须与生成端一致）

# AI 能力（按需填写，留空则静默走兜底/mock）
AI_LLM_API_KEY / AI_LLM_BASE_URL / AI_LLM_MODEL
AI_GUIDANCE_*
AI_IMAGE_*           # 腾讯混元生图走 aiart 产品线
AI_ASR_TENCENT_APPID / SECRET_ID / SECRET_KEY
AI_TTS_*             # 音色 501006 男 / 601010 女
AI_VRS_ENABLED=0     # 声音复刻默认关，未开通不阻塞交付
AI_SSL_CA=/path/to/cacert.pem   # HTTPS 报证书错时填
```

## 7. 上线前数据清理与初始化
- 清空测试数据 + 关联文件：`php think ls:reset --force`（默认 dry-run，加 `--force` 才真执行；会自动 `mysqldump` 备份到 `runtime/db_backup/`）。
- 仅清库不清文件：`php think ls:reset --force --db-only`。
- 不备份：`--no-backup`（不建议）。
- 清完后到后台「参数设置」录入**真实兑换码**；封面背景 / BGM / 默认章节等**种子数据不会被 reset 删除**，无需重导。

## 8. 验证清单
- [ ] `https://域名/h5/` 首页正常，刷新子路由不白屏。
- [ ] `https://域名/admin/` 能登录后台。
- [ ] `GET /api/auth/wechat/config` 返回 `enabled=true`（已配微信）。
- [ ] 后台录入兑换码后，前端用码可解锁创建。
- [ ] 新建回忆录默认出生日期 `1945-08-15`。
- [ ] 定稿后任务在 5 分钟内被 `ai:rescue` 接管并生成（看 `runtime/ai-work.log`）。

## 9. 常见坑
- 本地调试后端必须用 **9411** 端口，否则 `WSAEACCES`；线上由 web 服务器处理，无需此端口。
- 后台接口前缀是 **`/admin-api/`**（连字符），`/admin/api/*` 会 404。
- 前端 dev 走 8001 + vite 代理到 9411；线上 base 由 `CLIENT_BASE_PATH=/h5` 决定。
- 若更换微信网页授权域名，旧授权 token 失效属正常，重新授权即可。
- `.gitignore` 已忽略 `vendor/`、`runtime/`、`.env`、`public/uploads/`、`public/h5/` 等，勿误提交密钥与构建产物。
