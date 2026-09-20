<?php
namespace app\middleware;

use app\common\lib\RedisClient;
use app\common\exception\ApiException;
use think\facade\Config;
use think\facade\Log;

/**
 * 频率限制 + 用户锁定 中间件
 * - IP 全局 / 用户全局 始终生效
 * - 路由可叠加 scope（如 ai_submit / auth_login）
 * - 超限或已锁 -> 抛 429 / 423，前端按 retry_after 退避
 *
 * 路由用法（注意：scope 以位置参数传递，不要包成数组）：
 *   ->middleware(\app\middleware\RateLimitLock::class, 'ai_submit')
 */
class RateLimitLock
{
    // 固定窗口 + 锁定原子脚本：返回 0=放行 1=已锁 2=超限已锁
    protected $lua = <<<'LUA'
local cnt = redis.call('INCR', KEYS[1])
if cnt == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
if redis.call('EXISTS', KEYS[2]) == 1 then return 1 end
if cnt > tonumber(ARGV[2]) then
  if redis.call('EXISTS', KEYS[2]) == 0 then
    redis.call('SET', KEYS[2], '1', 'EX', ARGV[3])
  end
  return 2
end
return 0
LUA;

    public function handle($request, \Closure $next, string $scope = 'user_global')
    {
        $cfg = Config::get('ratelimit');
        if (empty($cfg['enable'])) {
            return $next($request);
        }

        $ip  = $request->ip();
        $uid = $request->uid ?? 0;
        $prefix = $cfg['prefix'];

        // Redis 连接失败：fail-open 放行，仅记录
        try {
            $client = RedisClient::client();
        } catch (\Throwable $e) {
            Log::warning('ratelimit redis connect error: ' . $e->getMessage());
            return $next($request);
        }

        try {
            // 1) IP 全局始终校验
            $this->check($client, $prefix, 'ip_global', $ip, $cfg);

            // 2) 已登录：用户全局 + 路由指定 scope
            if ($uid) {
                $this->check($client, $prefix, 'user_global', (string) $uid, $cfg);
                if ($scope !== 'user_global') {
                    $this->check($client, $prefix, $scope, (string) $uid, $cfg);
                }
            }
        } catch (ApiException $e) {
            // 超限 / 锁定：原样抛出，交由全局 ExceptionHandle 转为 423/429（不可吞掉）
            throw $e;
        } catch (\Throwable $e) {
            // 其他 Redis 异常：fail-open 放行，仅记录
            Log::warning('ratelimit redis error: ' . $e->getMessage());
        }

        return $next($request);
    }

    protected function check($client, string $prefix, string $scope, string $id, array $cfg): void
    {
        $def    = $cfg['scopes'][$scope] ?? $cfg['default'];
        $limit  = $def['limit'];
        $window = $def['window'];
        $lockTtl = $cfg['rate_lock_ttl'] ?? 600;

        $rlKey   = $prefix . 'rl:' . $scope . ':' . $id;
        $lockKey = $prefix . 'lock:' . $scope . ':' . $id;

        $res = $client->eval($this->lua, 2, $rlKey, $lockKey, $window, $limit, $lockTtl);

        if ($res == 1) {
            $ttl = $client->ttl($lockKey);
            if ($ttl == -1) {
                throw new ApiException(42301, '账号已被锁定，请联系管理员解封', 423, 0);
            }
            throw new ApiException(42301, '操作被锁定，请于 ' . $ttl . ' 秒后重试', 423, $ttl);
        }
        if ($res == 2) {
            $ttl = $client->ttl($lockKey);
            $ttl = $ttl > 0 ? $ttl : $lockTtl;
            throw new ApiException(42901, '操作过于频繁，请稍后再试', 429, $ttl);
        }
    }
}
