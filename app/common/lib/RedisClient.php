<?php
namespace app\common\lib;

use Predis\Client;

/**
 * Predis 单例封装（纯 PHP，无需 phpredis 扩展）
 * 仅用于限流计数 / 锁定状态 / 任务去重等高频原子操作
 */
class RedisClient
{
    protected static $client;

    public static function client(): Client
    {
        if (self::$client === null) {
            $cfg = config('redis');
            self::$client = new Client([
                'scheme'               => $cfg['scheme'] ?? 'tcp',
                'host'                 => $cfg['host'] ?? '127.0.0.1',
                'port'                 => $cfg['port'] ?? 6379,
                'password'             => !empty($cfg['password']) ? $cfg['password'] : null,
                'database'             => $cfg['database'] ?? 0,
                // 连接/读写超时务必设小：Redis 不可用时快速失败，
                // 让 RateLimitLock 中间件的 try/catch 立即 fail-open，避免请求永久挂起
                'timeout'              => $cfg['timeout'] ?? 0.8,
                'read_write_timeout'   => $cfg['read_write_timeout'] ?? 1.0,
            ]);
        }
        return self::$client;
    }
}
