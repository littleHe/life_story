<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// 应用入口文件（开发服务器路由重写）
//
// 注意：这里必须剥离 URL 查询串再判断静态文件是否存在。
// 直接用 $_SERVER['REQUEST_URI'] 会带上 "?v=1.0.0" 之类的参数
// （RequireJS / layui 加载资源时会自动追加版本号），
// 导致 is_file() 恒为 false，静态资源被错误地转发给 index.php 而返回 404。

if (PHP_SAPI == 'cli-server') {
    // 只取路径部分，丢弃 ?query
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = urldecode($path);

    // 命中真实存在的静态文件时返回 false，交给 PHP 内置服务器直接输出
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }

    // 前端 SPA 部署在 /h5/（见 front/scripts/deploy-h5.mjs）：
    // 非真实文件路径（如 /h5/memoirs/72 刷新）回退到 /h5/index.html，
    // 与线上 .htaccess / nginx try_files 行为一致，方便本地先验一遍再上线。
    if (strpos($path, '/h5/') === 0 || $path === '/h5') {
        $spa = __DIR__ . '/h5/index.html';
        if (is_file($spa)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($spa);
            return true;
        }
    }
}

require __DIR__ . '/index.php';
