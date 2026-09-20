<?php
// 应用配置（仅覆盖必要项，其余继承框架默认）
return [
    'app_debug'         => env('APP_DEBUG', false),
    'app_trace'         => false,
    'default_timezone'  => 'Asia/Shanghai',
    // 控制器类名带 Controller 后缀（与本项目 XxxController 约定一致）
    'controller_suffix' => 'Controller',
    // 统一异常处理：TP6.1 已不读取本键，实际绑定见 app/provider.php
    // （'think\exception\Handle' => \app\ExceptionHandle::class）

    // AI 能力开关：本地联调为 true（同步返回 mock 结果，无需队列 worker）；
    // 接入真实 AI 后改为 false，走 think-queue 异步任务。
    'ai_mock' => env('AI_MOCK', true),
];
