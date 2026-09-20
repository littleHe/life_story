<?php
namespace app\common\lib;

use think\facade\Config;
use think\facade\Db;

/**
 * 安全锁定管理：失败计数 -> 软锁 -> 升级硬锁 -> 解封
 * 锁定状态存 Redis（带 TTL），审计落 ls_security_lock_log
 */
class LockManager
{
    /** 查询是否处于锁定，返回剩余秒(0=未锁, -1=永久/人工) */
    public static function isLocked(string $scope, $uid): int
    {
        $key = Config::get('ratelimit.prefix') . 'lock:' . $scope . ':' . $uid;
        $ttl = RedisClient::client()->ttl($key);
        return $ttl; // -1 表示无过期(持久锁), -2 表示不存在
    }

    /** 写入锁定 + 审计 + 升级检测 */
    public static function lock(string $scope, $uid, int $ttl, string $reason, string $type = 'SOFT'): void
    {
        $prefix = Config::get('ratelimit.prefix');
        $key    = $prefix . 'lock:' . $scope . ':' . $uid;
        $client = RedisClient::client();

        if ($ttl > 0) {
            $client->setex($key, $ttl, $type);
        } else {
            $client->set($key, $type); // 持久锁(硬锁)，待人工解封
        }

        Db::name('security_lock_log')->insert([
            'user_id'    => $uid,
            'scope'      => $scope,
            'lock_type'  => $type,
            'reason'     => $reason,
            'ttl_sec'    => $ttl,
            'unlocked_at'=> null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 升级检测：24h 内软锁次数达阈值 -> 升级全局硬锁(需人工)
        if ($type === 'SOFT') {
            $up   = Config::get('ratelimit.upgrade');
            $ckey = $prefix . 'softlock:' . $uid;
            $cnt  = $client->incr($ckey);
            if ($cnt == 1) {
                $client->expire($ckey, $up['window']);
            }
            if ($cnt >= $up['threshold']) {
                $hkey = $prefix . 'lock:global:' . $uid;
                $client->set($hkey, 'HARD');
                Db::name('security_lock_log')->insert([
                    'user_id'    => $uid,
                    'scope'      => 'global',
                    'lock_type'  => 'HARD',
                    'reason'     => '24h 内多次软锁，自动升级为人工解封',
                    'ttl_sec'    => 0,
                    'unlocked_at'=> null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /**
     * 登记一次失败，达阈值则锁定
     * @return array ['locked'=>bool,'remaining'=>int]
     */
    public static function registerFail(string $action, $uid, ?string $codeHash = null): array
    {
        $cfg = Config::get('ratelimit.fail_lock.' . $action);
        if (empty($cfg)) {
            return ['locked' => false, 'remaining' => 0];
        }
        $prefix = Config::get('ratelimit.prefix');
        $fkey   = $prefix . 'fail:' . $action . ':' . $uid;
        $client = RedisClient::client();

        $cnt = $client->incr($fkey);
        if ($cnt == 1) {
            $client->expire($fkey, $cfg['window']);
        }

        if ($cnt >= $cfg['threshold']) {
            self::lock($cfg['scope'], $uid, $cfg['lock_ttl'], '失败次数超限:' . $action, 'SOFT');
            // 兑换码核销失败 -> 临时冻结该码，阻断爆破
            if (!empty($cfg['freeze_code']) && $codeHash) {
                Db::name('redemption_code')->where('code_hash', $codeHash)
                    ->update(['frozen_until' => date('Y-m-d H:i:s', time() + $cfg['lock_ttl'])]);
            }
            return ['locked' => true, 'remaining' => $cfg['lock_ttl']];
        }
        return ['locked' => false, 'remaining' => 0];
    }

    /** 管理员解封 */
    public static function unlock(string $scope, $uid): void
    {
        $key = Config::get('ratelimit.prefix') . 'lock:' . $scope . ':' . $uid;
        RedisClient::client()->del([$key]);
        Db::name('security_lock_log')->where(['user_id' => $uid, 'scope' => $scope])
            ->where('unlocked_at', null)
            ->update(['unlocked_at' => date('Y-m-d H:i:s')]);
    }
}
