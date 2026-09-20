<?php
// 频率限制 + 用户锁定 阈值配置（后台可覆盖）
return [
    'enable'  => true,
    'prefix'  => env('REDIS_PREFIX', 'ls:'),

    // 兜底默认（未匹配 scope 时）
    'default' => ['limit' => 60, 'window' => 60],

    // 全局限流 scope（RL 中间件按 scope 计数）
    'scopes'  => [
        'ip_global'   => ['limit' => 120, 'window' => 60],   // 单 IP 120/分
        'user_global' => ['limit' => 300, 'window' => 60],   // 单用户 300/分
        'ai_submit'   => ['limit' => 10,  'window' => 60],   // 单用户 AI 提交 10/分
        'auth_login'  => ['limit' => 20,  'window' => 60],   // 登录接口 20/分
        'interview'   => ['limit' => 40,  'window' => 60],   // 亲友访谈页（匿名）40/分/IP
    ],

    // 失败即锁定（核销/登录）：达到阈值 -> 锁定
    'fail_lock' => [
        'auth' => [          // 登录失败
            'threshold' => 5, 'window' => 900, 'lock_ttl' => 900, 'scope' => 'auth',
        ],
        'code_verify' => [   // 兑换码核销失败（防爆破付费凭证）
            'threshold' => 5, 'window' => 600, 'lock_ttl' => 1800,
            'scope' => 'code_verify', 'freeze_code' => true,
        ],
    ],

    // 升级锁：24h 内软锁次数达阈值 -> 升级 HARD(需人工解封)
    'upgrade' => ['window' => 86400, 'threshold' => 3],

    // 触发限流时的默认锁定时长(秒)，用于 RL 直接超限场景
    'rate_lock_ttl' => 600,
];
