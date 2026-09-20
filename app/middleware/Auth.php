<?php
namespace app\middleware;

use app\common\lib\JwtAuth;
use app\common\exception\ApiException;

/**
 * JWT 鉴权中间件：解析 Authorization: Bearer <token>
 * 成功后将 uid 注入请求，供业务层与限流中间件使用
 */
class Auth
{
    public function handle($request, \Closure $next)
    {
        $header = $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            throw new ApiException(40101, '未授权，请先登录', 401);
        }
        try {
            $payload = JwtAuth::parse(trim($m[1]));
        } catch (\Throwable $e) {
            throw new ApiException(40102, '令牌无效或已过期', 401);
        }
        if (empty($payload->type) || $payload->type !== 'access') {
            throw new ApiException(40103, '令牌类型错误', 401);
        }
        $request->uid    = (int) $payload->uid;
        $request->jwt    = $payload;
        return $next($request);
    }
}
