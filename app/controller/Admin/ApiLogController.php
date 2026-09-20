<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 用户行为 · 接口日志（ls_api_log）
 */
class ApiLogController extends AdminBase
{
    /** GET /admin-api/apilog/index */
    public function index()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));
        $method  = trim((string) $this->request->param('method', ''));
        $userId  = (int) $this->request->param('user_id/d', 0);
        $range   = trim((string) $this->request->param('date_range', ''));

        $query = Db::name('ls_api_log');

        if ($keyword !== '') {
            $query->where('path', 'like', '%' . $keyword . '%');
        }
        if ($method !== '') {
            $query->where('method', $method);
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($range !== '' && strpos($range, ' - ') !== false) {
            [$start, $end] = array_map('trim', explode(' - ', $range, 2));
            $query->whereBetweenTime('created_at', $start . ' 00:00:00', $end . ' 23:59:59');
        }

        [$list, $count] = $this->paginate($query, 'id desc');

        foreach ($list as &$row) {
            $row['duration_text'] = $row['duration_ms'] . ' ms';
            $row['status_text']   = $row['status_code'] >= 200 && $row['status_code'] < 300 ? '成功' : '异常';
        }

        return $this->tableJson($list, $count);
    }

    /** POST /admin-api/apilog/delete */
    public function delete()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }
        Db::name('ls_api_log')->where('id', $id)->delete();
        return $this->ok([], '已删除');
    }

    /** POST /admin-api/apilog/clear —— 清空（可按天数保留） */
    public function clear()
    {
        $keepDays = (int) $this->request->post('keep_days/d', 0);

        if ($keepDays > 0) {
            $before = date('Y-m-d H:i:s', strtotime("-{$keepDays} day"));
            $deleted = Db::name('ls_api_log')->where('created_at', '<', $before)->delete();
            $this->audit('apilog/clear', ['keep_days' => $keepDays, 'deleted' => $deleted]);
            return $this->ok(['deleted' => $deleted], "已清理 {$keepDays} 天前的日志");
        }

        $deleted = Db::name('ls_api_log')->where('id', '>', 0)->delete();
        $this->audit('apilog/clear', ['deleted' => $deleted]);

        return $this->ok(['deleted' => $deleted], '已清空全部日志');
    }

    /** GET /admin-api/apilog/stat —— 概览（最近24小时请求量/平均耗时） */
    public function stat()
    {
        $since = date('Y-m-d H:i:s', strtotime('-24 hour'));

        return $this->ok([
            'last24h'    => (int) Db::name('ls_api_log')->where('created_at', '>=', $since)->count(),
            'avg_ms'     => (int) Db::name('ls_api_log')->where('created_at', '>=', $since)->avg('duration_ms'),
            'error_24h'  => (int) Db::name('ls_api_log')
                ->where('created_at', '>=', $since)
                ->where('status_code', '>=', 400)
                ->count(),
        ]);
    }
}
