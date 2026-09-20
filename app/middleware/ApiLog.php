<?php
namespace app\middleware;

use think\facade\Db;

/**
 * 前台 API 访问日志（对应用户行为 / 接口 log）
 *
 * 设计要点：
 * - 失败不影响主流程（全部 try/catch 兜底）
 * - 只在 APP_ENV != production 时默认开启；生产可通过 .env 的 API_LOG 显式开关
 * - 请求体只截断保存，避免日志表膨胀 & 记录敏感数据
 */
class ApiLog
{
    public function handle($request, \Closure $next)
    {
        // 开关：.env 里 API_LOG=true/false；未配置时非生产环境默认开启
        $flag = env('API_LOG', null);
        $enabled = is_null($flag) ? (env('APP_ENV', 'local') !== 'production') : (bool) $flag;

        if (!$enabled) {
            return $next($request);
        }

        $start = microtime(true);
        $status = 0;

        try {
            $response = $next($request);
            $status   = (int) $response->getCode();
            return $response;
        } catch (\Throwable $e) {
            $status = 500;
            throw $e;
        } finally {
            $this->write($request, $status, $start);
        }
    }

    protected function write($request, int $status, float $start): void
    {
        try {
            $body = $request->post();
            if (is_array($body) && !empty($body)) {
                $body = json_encode($body, JSON_UNESCAPED_UNICODE);
                // 敏感字段脱敏
                $body = preg_replace('/"(password|code|access_token|refresh_token|secret)"\s*:\s*"[^"]*"/i', '"$1":"***"', (string) $body);
                $body = mb_substr((string) $body, 0, 1000);
            } else {
                $body = null;
            }

            Db::name('ls_api_log')->insert([
                'user_id'      => (int) ($request->uid ?? 0),
                'method'       => strtoupper((string) $request->method()),
                'path'         => mb_substr('/' . trim((string) $request->pathinfo(), '/'), 0, 191),
                'ip'           => (string) $request->ip(),
                'user_agent'   => mb_substr((string) $request->header('user-agent', ''), 0, 255),
                'status_code'  => $status,
                'duration_ms'  => (int) round((microtime(true) - $start) * 1000),
                'request_body' => $body,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志失败绝不影响业务
        }
    }
}
