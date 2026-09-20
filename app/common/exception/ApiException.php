<?php
namespace app\common\exception;

/**
 * API 业务异常：携带业务码 code、HTTP 状态码 httpCode、可选重试秒 retryAfter
 */
class ApiException extends \Exception
{
    public $httpCode;
    public $retryAfter;

    public function __construct($code, string $msg, int $httpCode = 200, $retryAfter = null)
    {
        parent::__construct($msg, (int) $code);
        $this->httpCode   = $httpCode;
        $this->retryAfter = $retryAfter;
    }
}
