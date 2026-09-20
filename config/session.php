<?php
// 后台管理登录态会话配置
// 说明：独立 cookie 名，避免与前台 H5（JWT）或其他同域项目冲突
return [
    // 会话名（浏览器 cookie 名）
    'name'           => 'LSSESSID',
    // 驱动类型：file / cache / redis
    'type'           => 'file',
    // 存储连接（type=file 时留空）
    'store'          => null,
    // 有效期（秒），后台登录态保持 1 天
    'expire'         => 86400,
    'prefix'         => '',
    'var_session_id' => '',
    // file 驱动存放目录（留空则用 runtime/session）
    'path'           => '',
    'serialize'      => [],
];
