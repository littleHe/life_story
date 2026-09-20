<?php
// 服务注册 / 容器绑定
//
// 注意：ThinkPHP 6.1 不会再读取 config('app.exception_handle')，
// 统一异常处理必须在此绑定 think\exception\Handle，否则 ApiException
// 会退化为框架默认的 500 错误页（HTML），破坏 API 的 {code,msg,data} 契约。
return [
    'think\exception\Handle' => \app\ExceptionHandle::class,
];
