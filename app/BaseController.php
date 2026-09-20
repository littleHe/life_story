<?php
namespace app;

use think\App;
use think\exception\ValidateException;
use think\exception\HttpResponseException;
use think\Response;
use think\Request;

/**
 * 控制器基类（API 场景）
 */
abstract class BaseController
{
    protected $app;
    protected $request;
    protected $view;

    public function __construct(App $app, Request $request)
    {
        $this->app     = $app;
        $this->request = $request;
        $this->view    = $app->make(\think\View::class);
        $this->initialize();
    }

    protected function initialize()
    {
    }

    /** 统一成功返回 */
    protected function ok($data = [], string $msg = 'ok')
    {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    /** 获取当前登录用户 id（Auth 中间件注入） */
    protected function uid(): int
    {
        return (int) ($this->request->uid ?? 0);
    }
}
