<?php
namespace app\common\lib;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use think\facade\Config;

/**
 * JWT 签发/解析/吊销
 * - access token: 短效(15min)
 * - refresh token: 长效(7天)，存 Redis 便于吊销
 */
class JwtAuth
{
    public static function issue(int $userId): array
    {
        $cfg    = Config::get('jwt');
        $now    = time();
        $access = JWT::encode([
            'uid'  => $userId,
            'type' => 'access',
            'iat'  => $now,
            'exp'  => $now + $cfg['access_ttl'],
        ], $cfg['secret'], $cfg['algo']);

        $refresh = JWT::encode([
            'uid'  => $userId,
            'type' => 'refresh',
            'iat'  => $now,
            'exp'  => $now + $cfg['refresh_ttl'],
        ], $cfg['secret'], $cfg['algo']);

        // refresh 存 Redis，便于主动吊销
        RedisClient::client()->setex('ls:refresh:' . $userId, $cfg['refresh_ttl'], $refresh);

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'expires_in'    => $cfg['access_ttl'],
        ];
    }

    public static function parse(string $token): object
    {
        $cfg = Config::get('jwt');
        return JWT::decode($token, new Key($cfg['secret'], $cfg['algo']));
    }

    public static function revoke(int $userId): void
    {
        RedisClient::client()->del(['ls:refresh:' . $userId]);
    }
}
