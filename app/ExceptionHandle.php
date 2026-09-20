<?php
namespace app;

use think\exception\Handle;
use think\Response;
use Throwable;
use app\common\exception\ApiException;

/**
 * 统一异常响应：API 一律返回 JSON {code,msg,data}
 * - ApiException 携带业务码与 HTTP 状态码(含 429/423)
 * - 其余异常兜底为 500，调试模式附带定位信息
 */
class ExceptionHandle extends Handle
{
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof ApiException) {
            $data = ['code' => $e->getCode(), 'msg' => $e->getMessage(), 'data' => (object) []];
            if ($e->retryAfter !== null) {
                $data['retry_after'] = $e->retryAfter;
            }
            $resp = json($data, $e->httpCode);
            if ($e->httpCode === 429 && $e->retryAfter !== null) {
                $resp->header(['Retry-After' => (string) $e->retryAfter]);
            }
            return $resp;
        }

        // 兜底：避免泄露堆栈给前端
        $code = $e->getCode() ?: 500;
        $msg  = $e->getMessage() ?: '系统错误';
        if (app()->isDebug()) {
            $msg .= "\n" . get_class($e) . ': ' . $e->getFile() . ':' . $e->getLine();
        }
        return json(['code' => $code, 'msg' => $msg, 'data' => (object) []], 200);
    }
}
