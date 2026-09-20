<?php
// think-queue：使用 database 驱动（MySQL），无需 phpredis 扩展
return [
    'default'     => 'database',
    'connections' => [
        'database' => [
            'type'       => 'database',
            'queue'      => 'ai',
            'table'      => 'jobs',
            'connection' => 'mysql',
        ],
        'sync' => [
            'type' => 'sync',
        ],
    ],
    'failed' => [
        'type'       => 'database',
        'table'      => 'failed_jobs',
        'connection' => 'mysql',
    ],
];
