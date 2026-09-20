<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 兑换码日志：核销/绑定记录 + 安全锁定记录
 */
class CodeLogController extends AdminBase
{
    /** GET /admin-api/codelog/index —— 核销日志 */
    public function index()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));
        $method  = trim((string) $this->request->param('method', ''));

        $query = Db::name('ls_verification_log')
            ->alias('v')
            ->leftJoin('ls_redemption_code c', 'c.id = v.code_id')
            ->leftJoin('ls_user u', 'u.id = v.user_id')
            ->leftJoin('ls_project p', 'p.id = v.project_id')
            ->field('v.id,v.code_id,v.user_id,v.project_id,v.method,v.device_info,v.created_at,'
                . 'c.code_mask,c.status AS code_status,'
                . 'u.nickname,u.unionid,'
                . 'p.name AS project_name');

        if ($method !== '') {
            $query->where('v.method', $method);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('c.code_mask', 'like', '%' . $keyword . '%')
                  ->whereOr('u.nickname', 'like', '%' . $keyword . '%')
                  ->whereOr('u.unionid', 'like', '%' . $keyword . '%');
            });
        }

        [$list, $count] = $this->paginate($query, 'v.id desc');

        foreach ($list as &$row) {
            $row['method_text'] = $row['method'] === 'SELECT' ? '选码' : '输入码';
        }

        return $this->tableJson($list, $count);
    }

    /** GET /admin-api/codelog/locks —— 安全锁定日志 */
    public function locks()
    {
        $query = Db::name('ls_security_lock_log');
        [$list, $count] = $this->paginate($query, 'id desc');

        foreach ($list as &$row) {
            $row['lock_type_text'] = $row['lock_type'] === 'HARD' ? '硬锁定' : '软锁定';
            $row['ttl_text']       = (int) $row['ttl_sec'] === 0 ? '需人工解封' : $row['ttl_sec'] . ' 秒';
        }

        return $this->tableJson($list, $count);
    }

    /** POST /admin-api/codelog/unlock —— 人工解封 */
    public function unlock()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }

        Db::name('ls_security_lock_log')->where('id', $id)->update([
            'unlocked_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('codelog/unlock', ['id' => $id]);

        return $this->ok([], '已标记解封');
    }
}
