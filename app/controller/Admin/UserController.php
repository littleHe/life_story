<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 用户管理 · 授权用户
 *
 * 「授权用户」= 已成功核销兑换码、项目被解锁的用户；
 * 这里同时把未授权的注册用户一并列出，用 authorized 字段区分。
 *
 * 注意：ls_project / ls_chapter 对 ls_user 有外键约束，因此不提供物理删除，
 * 只提供「禁用/启用」（status）。
 */
class UserController extends AdminBase
{
    /** GET /admin-api/user/index */
    public function index()
    {
        $keyword    = trim((string) $this->request->param('keyword', ''));
        $status     = $this->request->param('status', '');
        $authorized = $this->request->param('authorized', '');

        $query = Db::name('ls_user')
            ->alias('u')
            ->leftJoin('ls_project p', 'p.user_id = u.id')
            ->field('u.id,u.unionid,u.openid,u.nickname,u.avatar,u.role,u.status,u.created_at,'
                . 'COUNT(p.id) AS project_count,'
                . "SUM(CASE WHEN p.status = 'EDITABLE' THEN 1 ELSE 0 END) AS editable_count,"
                . "SUM(CASE WHEN p.status = 'DONE' THEN 1 ELSE 0 END) AS done_count")
            ->group('u.id');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('u.nickname', 'like', '%' . $keyword . '%')
                  ->whereOr('u.unionid', 'like', '%' . $keyword . '%')
                  ->whereOr('u.openid', 'like', '%' . $keyword . '%');
            });
        }
        if ($status !== '' && $status !== null) {
            $query->where('u.status', (int) $status);
        }

        [$list, $count] = $this->paginate($query, 'u.id desc');

        foreach ($list as &$row) {
            $row['project_count']  = (int) $row['project_count'];
            $row['editable_count'] = (int) $row['editable_count'];
            $row['done_count']     = (int) $row['done_count'];
            $row['authorized']     = $row['editable_count'] > 0 ? 1 : 0;
            $row['authorized_text'] = $row['authorized'] ? '已授权' : '未授权';
            $row['role_text']      = (int) $row['role'] === 1 ? '管理员' : '普通用户';
        }

        // authorized 过滤放在结果集上处理，避免 HAVING 与分页冲突
        if ($authorized !== '' && $authorized !== null) {
            $want = (int) $authorized;
            $list = array_values(array_filter($list, function ($row) use ($want) {
                return (int) $row['authorized'] === $want;
            }));
        }

        return $this->tableJson($list, $count);
    }

    /** GET /admin-api/user/detail?id= —— 用户 + 其项目 + 核销记录 */
    public function detail()
    {
        $id   = (int) $this->request->param('id/d', 0);
        $user = Db::name('ls_user')->where('id', $id)->find();
        if (empty($user)) {
            return $this->fail('用户不存在');
        }

        $projects = Db::name('ls_project')
            ->where('user_id', $id)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $logs = Db::name('ls_verification_log')
            ->where('user_id', $id)
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();

        return $this->ok(['user' => $user, 'projects' => $projects, 'logs' => $logs]);
    }

    /** POST /admin-api/user/save —— 改昵称 / 角色 / 状态 */
    public function save()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];

        $nickname = (string) $this->post('nickname');
        if ($nickname !== '') {
            $data['nickname'] = $nickname;
        }
        if ($this->request->has('status')) {
            $data['status'] = (int) $this->request->post('status/d', 1) === 1 ? 1 : 0;
        }
        if ($this->request->has('role')) {
            $data['role'] = (int) $this->request->post('role/d', 0) === 1 ? 1 : 0;
        }

        Db::name('ls_user')->where('id', $id)->update($data);
        $this->audit('user/save', ['id' => $id]);

        return $this->ok([], '保存成功');
    }

    /** POST /admin-api/user/status —— 快捷启用/禁用 */
    public function status()
    {
        $id     = (int) $this->request->post('id/d', 0);
        $status = (int) $this->request->post('status/d', 1) === 1 ? 1 : 0;
        if ($id <= 0) {
            return $this->fail('参数错误');
        }

        Db::name('ls_user')->where('id', $id)->update([
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('user/status', ['id' => $id, 'status' => $status]);

        return $this->ok([], $status === 1 ? '已启用' : '已禁用');
    }
}
