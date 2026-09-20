<?php
// Predis(纯 PHP) 连接参数，供 app/common/lib/RedisClient 使用
return [
    'scheme'   => 'tcp',
    'host'     => env('REDIS_HOST', '127.0.0.1'),
    'port'     => env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD', ''),
    'database' => env('REDIS_DB', 0),
    'read_write_timeout' => 0,
];
