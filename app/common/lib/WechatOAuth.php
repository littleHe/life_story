<?php
namespace app\common\lib;

use think\facade\Config;
use think\facade\Log;
use GuzzleHttp\Client;

/**
 * 微信公众号 H5 网页授权（sns/oauth2）
 * 流程：前端拿 code -> 后端换 access_token -> 取 unionid/openid
 * 注意：unionid 仅在公众号绑定微信开放平台后返回；否则回退 openid 作主键(单端可用)
 */
class WechatOAuth
{
    public static function getUserInfo(string $code): array
    {
        $cfg  = Config::get('wechat');
        $http = new Client(['timeout' => 8]);

        $tokenUrl = 'https://api.weixin.qq.com/sns/oauth2/access_token?' . http_build_query([
            'appid'     => $cfg['appid'],
            'secret'    => $cfg['secret'],
            'code'      => $code,
            'grant_type'=> 'authorization_code',
        ]);
        $r1 = json_decode((string) $http->get($tokenUrl)->getBody(), true);
        if (empty($r1['access_token'])) {
            // 带上 errcode 一起抛出：40163=code 已被使用、40029=code 无效、
            // 40125=appsecret 错、40013=appid 错。前端会把这段原文透出到错误提示里，
            // 线上排查不必再靠猜（此前只给 errmsg，`invalid code` 分不清是失效还是被复用）。
            $ec = (string) ($r1['errcode'] ?? '');
            $em = (string) ($r1['errmsg'] ?? 'wechat token fail');
            Log::error('[wechat-oauth] code 换 token 失败: ' . trim($ec . ' ' . $em));
            throw new \RuntimeException(trim($ec . ' ' . $em));
        }

        $userUrl = 'https://api.weixin.qq.com/sns/userinfo?' . http_build_query([
            'access_token' => $r1['access_token'],
            'openid'       => $r1['openid'],
            'lang'         => 'zh_CN',
        ]);
        $r2 = json_decode((string) $http->get($userUrl)->getBody(), true);

        // 无 unionid 时回退 openid（单端 H5 可接受；后续如需跨端再引导绑定开放平台）
        if (empty($r2['unionid'])) {
            $r2['unionid'] = $r2['openid'];
        }
        return $r2;
    }
}
