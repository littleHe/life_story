<?php
// 路由配置（仅覆盖必要项，其余继承框架默认）
return [
    // 控制器类名带 Controller 后缀（与本项目 XxxController 约定一致）
    // 注意：controller_suffix 是“路由配置”项，必须放此处而非 config/app.php
    'controller_suffix' => 'Controller',

    // 默认控制器 / 操作
    'default_controller' => 'Index',
    'default_action'     => 'index',

    // 路由是否完全匹配（true=必须整段精确匹配，避免 /api/projects 误匹配 /api/projects/:id/bind-code）
    'route_complete_match' => true,
];
