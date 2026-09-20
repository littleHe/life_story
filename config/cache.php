<?php
// 框架 cache 默认用 file；限流/锁定相关 Redis 走 app/common/lib/RedisClient(纯 predis)
return [
    'default' => env('CACHE_TYPE', 'file'),
    'stores'  => [
        'file' => [
            'type'   => 'File',
            'path'   => app()->getRuntimePath() . 'cache/',
            'expire' => 0,
        ],
    ],
];
