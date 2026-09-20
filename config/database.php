<?php
// 数据库配置（表前缀留空，表名已在 SQL 中带 ls_ 前缀）
return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'type'     => 'mysql',
            'hostname' => env('DB_HOST', '127.0.0.1'),
            'database' => env('DB_NAME', 'life_story'),
            'username' => env('DB_USER', 'root'),
            'password' => env('DB_PASS', ''),
            'hostport' => env('DB_PORT', '3306'),
            'charset'  => 'utf8mb4',
            'prefix'   => env('DB_PREFIX', ''),
            'params'   => [],
            'debug'    => true,
        ],
    ],
];
