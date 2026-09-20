<?php
namespace app\controller\Api;

use app\BaseController;
use app\common\lib\WechatOAuth;
use app\common\lib\JwtAuth;
use app\common\lib\LockManager;
use app\common\lib\RedisClient;
use app\common\exception\ApiException;
use think\facade\Config;
use think\facade\Db;

class AuthController extends BaseController
{
    /**
     * 前端登录页启动参数（公开）：
     *  - enabled：微信网页授权是否已配置（appid + secret 齐全）
     *  - appid / scope：前端据此拼 authorize 跳转地址（appid 属公开信息，可下发）
     *  - test_login：是否允许「一键登录测试号」（生产环境一律 false）
     *
     * 前端不硬编码 appid，换公众号只改后端 .env，无需重新打包前端。
     */
    public function wechatConfig()
    {
        $cfg     = (array) Config::get('wechat');
        $appid   = trim((string) ($cfg['appid'] ?? ''));
        $secret  = trim((string) ($cfg['secret'] ?? ''));
        $enabled = $appid !== '' && $secret !== '';

        return $this->ok([
            'enabled'    => $enabled,
            // 未配置时不回传空 appid 之外的信息，避免前端误拼地址
            'appid'      => $enabled ? $appid : '',
            'scope'      => (string) ($cfg['scope'] ?? 'snsapi_userinfo'),
            'test_login' => env('APP_ENV') !== 'production',
        ]);
    }

    /** 微信登录：code 换 unionid + 发 JWT */
    public function wechatLogin()
    {
        $code = input('post.code/s', '');
        if (!$code) {
            throw new ApiException(42201, '缺少 code 参数', 422);
        }
        // 未登录场景以 IP 作为失败计数维度，防爆破
        $failId = request()->ip();

        try {
            $info = WechatOAuth::getUserInfo($code);
        } catch (\Throwable $e) {
            LockManager::registerFail('auth', $failId);
            throw new ApiException(40101, '微信授权失败：' . $e->getMessage(), 401);
        }

        $user = Db::name('ls_user')->where('unionid', $info['unionid'])->find();
        if (!$user) {
            $uid = Db::name('ls_user')->insertGetId([
                'unionid'    => $info['unionid'],
                'openid'     => $info['openid'] ?? '',
                'nickname'   => $info['nickname'] ?? '',
                'avatar'     => $info['headimgurl'] ?? '',
                'role'       => 0,
                'status'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $uid = $user['id'];
        }

        $token = JwtAuth::issue($uid);
        return $this->ok([
            'access_token'  => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_in'    => $token['expires_in'],
            'uid'           => $uid,
            // 回传昵称/头像：前端个人中心直接从登录响应落地，省一次拉取
            'nickname'      => $info['nickname'] ?? '',
            'avatar'        => $info['headimgurl'] ?? '',
            'is_test'       => false,
        ], '登录成功');
    }

    /**
     * 测试环境专用登录：绕过微信授权，直接登录固定测试账号
     * - 仅当 APP_ENV != production 时可用（生产环境自动禁用，返回 403）
     * - 测试账号 unionid = TEST_ACCOUNT_LIFE_STORY，find-or-create
     */
    public function testLogin()
    {
        if (env('APP_ENV') === 'production') {
            throw new ApiException(40301, '测试登录在生产环境不可用', 403);
        }
        $unionid = 'TEST_ACCOUNT_LIFE_STORY';
        $user = Db::name('ls_user')->where('unionid', $unionid)->find();
        if (!$user) {
            $uid = Db::name('ls_user')->insertGetId([
                'unionid'    => $unionid,
                'openid'     => '',
                'nickname'   => '测试账号',
                'avatar'     => '',
                'role'       => 0,
                'status'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $nickname = '测试账号';
            $avatar   = '';
        } else {
            $uid      = (int) $user['id'];
            $nickname = $user['nickname'] ?: '测试账号';
            $avatar   = $user['avatar'] ?: '';
        }

        $token = JwtAuth::issue($uid);
        return $this->ok([
            'access_token'  => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_in'    => $token['expires_in'],
            'uid'           => $uid,
            'nickname'      => $nickname,
            'avatar'        => $avatar,
            'is_test'       => true,
        ], '测试登录成功');
    }

    /** refresh token 换发新 access */
    public function refresh()
    {
        $rt = input('post.refresh_token/s', '');
        try {
            $p = JwtAuth::parse($rt);
        } catch (\Throwable $e) {
            throw new ApiException(40102, 'refresh 令牌无效', 401);
        }
        if (empty($p->type) || $p->type !== 'refresh') {
            throw new ApiException(40103, '令牌类型错误', 401);
        }
        // 校验服务端存储（支持主动吊销）
        $stored = RedisClient::client()->get('ls:refresh:' . $p->uid);
        if (!$stored || $stored !== $rt) {
            throw new ApiException(40104, 'refresh 已失效，请重新登录', 401);
        }
        $token = JwtAuth::issue((int) $p->uid);
        return $this->ok([
            'access_token'  => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_in'    => $token['expires_in'],
        ]);
    }

    /** 退出登录：吊销服务端 refresh 令牌（access 短时效，过期即失效） */
    public function logout()
    {
        $uid = $this->uid();
        if ($uid > 0) {
            try {
                RedisClient::client()->del(['ls:refresh:' . $uid]);
            } catch (\Throwable $e) {
                // 忽略：Redis 不可用不应阻塞登出
            }
        }
        return $this->ok(null, '已退出登录');
    }
}
