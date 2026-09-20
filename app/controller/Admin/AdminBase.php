<?php
namespace app\controller\Admin;

use app\BaseController;
use think\facade\Db;

/**
 * 后台控制器基类（session 登录态）
 *
 * 注意：本基类服务于 /admin-api/* 这套「后台管理接口」，
 * 与 app\controller\Admin\CodeController（旧的 JWT 开放接口）不是同一套鉴权体系。
 */
abstract class AdminBase extends BaseController
{
    /** 当前登录管理员（来自 session） */
    protected function admin(): array
    {
        $admin = session('admin');
        return is_array($admin) ? $admin : [];
    }

    protected function adminId(): int
    {
        return (int) ($this->admin()['id'] ?? 0);
    }

    // ------------------------------------------------------------------
    // 统一响应
    // ------------------------------------------------------------------

    /** 普通成功响应 {code:0,msg,data} */
    protected function ok($data = [], string $msg = 'ok')
    {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    /** 失败响应 */
    protected function fail(string $msg, int $code = 1, int $http = 200)
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => null], $http);
    }

    /** layui table 专用格式 {code:0,msg,count,data} */
    protected function tableJson($list, int $count, string $msg = '')
    {
        return json([
            'code'  => 0,
            'msg'   => $msg,
            'count' => $count,
            'data'  => array_values($list ?: []),
        ]);
    }

    // ------------------------------------------------------------------
    // 请求参数
    // ------------------------------------------------------------------

    /** 分页参数（对齐 layui table 的 page/limit） */
    protected function pageParams(): array
    {
        $page  = max(1, (int) $this->request->param('page/d', 1));
        $limit = (int) $this->request->param('limit/d', 10);
        if ($limit <= 0 || $limit > 200) {
            $limit = 10;
        }
        return [$page, $limit];
    }

    /** 取 post 字段并 trim */
    protected function post(string $key, $default = '')
    {
        $value = $this->request->post($key, $default);
        return is_string($value) ? trim($value) : $value;
    }

    /** 分页 + 排序的通用列表查询 */
    protected function paginate($query, string $order = 'id desc')
    {
        [$page, $limit] = $this->pageParams();
        $count = (clone $query)->count();
        $list  = $query->order($order)->page($page, $limit)->select()->toArray();
        return [$list, (int) $count];
    }

    /** 写后台操作审计（复用 ls_api_log，标记为后台来源） */
    protected function audit(string $action, array $extra = [])
    {
        try {
            Db::name('ls_api_log')->insert([
                'user_id'      => 0,
                'method'       => 'ADMIN',
                'path'         => $action,
                'ip'           => (string) $this->request->ip(),
                'user_agent'   => 'admin#' . $this->adminId(),
                'status_code'  => 200,
                'duration_ms'  => 0,
                'request_body' => json_encode($extra, JSON_UNESCAPED_UNICODE),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 审计失败不影响主流程
        }
    }
}
