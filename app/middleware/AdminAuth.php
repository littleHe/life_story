<?php
namespace app\middleware;

/**
 * 后台登录校验（session 驱动，与前台 JWT 鉴权完全隔离）
 *
 * 未登录时返回 HTTP 401 + JSON，前端 js/app.js 注册的全局 ajaxError
 * 会捕获 401 并跳转到 /admin/login.html。
 */
class AdminAuth
{
    /**
     * 无需登录即可访问的后台接口（pathinfo，小写，无前后斜杠）
     */
    protected $except = [
        'admin-api/login',
    ];

    public function handle($request, \Closure $next)
    {
        $path = strtolower(trim((string) $request->pathinfo(), '/'));

        if (in_array($path, $this->except, true)) {
            return $next($request);
        }

        $admin = session('admin');
        if (empty($admin) || empty($admin['id'])) {
            return json([
                'code' => 401,
                'msg'  => '登录已失效，请重新登录',
                'data' => null,
            ], 401);
        }

        // 每次请求刷一次有效期，避免操作中途掉线
        session('admin', $admin);

        return $next($request);
    }
}
