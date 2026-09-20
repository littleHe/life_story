<?php
// 自定义命令行注册
//
// ai:work —— 把 ai 队列「干完就退出」的 worker（无需常驻），
//            由 AiTaskService::kick() 在业务侧（如定稿）后台自动拉起。
// ai:rescue —— 定时兜底：重置孤儿任务并续跑未完成的定稿流水线。
// ls:reset —— 上线前初始化：清空业务数据与关联上传文件（默认预演，--force 才执行）。
return [
    'commands' => [
        'ai:work'   => \app\command\AiWork::class,
        'ai:rescue' => \app\command\AiRescue::class,
        'ls:reset'  => \app\command\LsReset::class,
    ],
];
