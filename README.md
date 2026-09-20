# 回忆录系统 · 后端（ThinkPHP6 + MySQL + Redis）

> 技术栈：ThinkPHP6（PHP） + MySQL(InnoDB) + Predis(Redis 纯 PHP) + think-queue(数据库驱动) + firebase/php-jwt + Guzzle
> 前端：Vue3 H5（后续可 web-view 壳 / uni-app 打包小程序）。本目录仅后端。

## 目录结构
```
backend/
├─ app/
│  ├─ middleware/        RateLimitLock(限流+锁定) / Auth(JWT)
│  ├─ common/lib/        RedisClient / JwtAuth / LockManager / WechatOAuth
│  ├─ common/exception/  ApiException(统一业务异常)
│  ├─ service/           RedemptionService(核销) / AiTaskService(入队)
│  ├─ model/             9 张表模型
│  ├─ controller/api/    Auth / Project / Chapter / Task
│  ├─ controller/admin/  Code(兑换码管理+审计)
│  ├─ queue/AiJob.php    AI 异步任务 Worker
│  ├─ ExceptionHandle.php 统一 JSON 响应
│  └─ BaseController.php
├─ config/               app/database/cache/log/ratelimit/redis/jwt/queue
├─ database/install.sql  建表脚本(含 think-queue 队列表)
├─ public/              入口 index.php + .htaccess
├─ route/app.php         API 路由 + 中间件分组
└─ composer.json
```

## 快速开始
```bash
cd backend
cp .env.example .env          # 填入 DB / Redis / JWT_SECRET / 微信 appid 等

# 安装依赖：务必加 php 的 -d memory_limit=-1（默认 256M 易触发内存耗尽报错）
# 若系统无全局 composer 命令，改用 phpstudy 自带的 composer.phar：
php -d memory_limit=-1 composer.phar install
# 或： php -d memory_limit=-1 /d/phpstudy_pro/Extensions/php/php8.2.9nts/composer.phar install
# 已有全局 composer 时也可： COMPOSER_MEMORY_LIMIT=-1 composer install

# 依赖装完后，手动触发服务发现（注册 think-queue 等服务的命令）
php think service:discover

# 导入数据库（需本地有 mysql 客户端；或用 Navicat/Adminer 导入 install.sql）
mysql -u<user> -p <db> < database/install.sql

# 启动（开发）
php think run                 # 内置服务器，默认 http://127.0.0.1:8000

# 启动 AI 异步队列 Worker（常驻）
php think queue:work --queue ai
```

> **常见问题**
> - `composer: 不是内部或外部命令` → 没有全局 composer。改用 phpstudy 目录里的 `composer.phar`：`php composer.phar install`（phpstudy 自带，路径如 `D:\phpstudy_pro\Extensions\php\php8.2.9nts\composer.phar`）。
> - `Allowed memory size ... exhausted` → 加 `-d memory_limit=-1`。
> - `Composer 2 才支持` / 老版本报错 → 先 `php composer.phar self-update` 升级到 v2。
> - `Script @php think service:discover ... returned error code 1` → 已将该步骤移出 composer 自动脚本（见上），请改为装完后手动执行 `php think service:discover`，并确保已 `cp .env.example .env`。

## 核心设计落地
1. **频率限制 + 用户锁定**：`config/ratelimit.php` 配置阈值；`RateLimitLock` 中间件用 Redis+Lua 原子计数（IP 全局 / 用户全局 / 路由 scope）。超限返回 `429`（带 `Retry-After`），已锁定返回 `423`。
2. **失败即锁定（防爆破）**：登录失败 5/15min、兑换码核销失败 5/10min 触发 `LockManager::registerFail` → 锁用户 +（核销场景）冻结该码；24h 内 ≥3 次软锁自动升级 HARD（人工解封）。审计落 `ls_security_lock_log`。
3. **兑换码防双花**：`RedemptionService::bind` 用 MySQL 行锁 `lock(true)` + 事务，保证 `ADDED/GENERATED → BOUND` 原子转移；码只存 `sha256` 哈希。
4. **微信登录**：H5 公众号网页授权 `code` → 后端换 `unionid` → 发 JWT（access 15min + refresh 7d 存 Redis 可吊销）。**注意**：unionid 需公众号绑定微信开放平台；否则回退 openid 作主键（单端可用）。
5. **AI 异步**：ASR/润色/配图/配音全部 `AiTaskService::push` 入 think-queue，Worker(`AiJob`) 调用云厂商 HTTP API（Guzzle），仅定稿时生成一次配音。云 API 调用为占位 TODO，按实际厂商替换。

## 待补（下一轮）
- 前端 Vue3 API 客户端 + 锁定页组件
- 云 AI 各厂商具体调用（ASR/LLM/文生图/声音克隆+TTS）
- OSS/COS 分片直传 + 回调校验
- 后台 EasyAdmin 菜单接入（兑换码/审计/AI 参数）
- 导出 PDF 图文册（视频合成二期）
