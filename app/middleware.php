<?php
// 全局中间件（按需在路由/控制器层单独挂载 RateLimitLock / Auth）
return [
    // 后台管理使用 session 登录态（/admin-api/*），需全局初始化 Session。
    // 前台 H5 走 JWT，不依赖 session，此中间件对其无副作用。
    \think\middleware\SessionInit::class,
];
