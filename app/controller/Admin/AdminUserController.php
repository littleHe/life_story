<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 系统管理 · 管理员账号（ls_admin）
 */
class AdminUserController extends AdminBase
{
    /** GET /admin-api/adminuser/index */
    public function index()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));

        $query = Db::name('ls_admin')
            ->field('id,username,nickname,avatar,status,login_num,last_login_at,last_login_ip,remark,created_at');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', '%' . $keyword . '%')
                  ->whereOr('nickname', 'like', '%' . $keyword . '%');
            });
        }

        [$list, $count] = $this->paginate($query, 'id asc');

        $currentId = $this->adminId();
        foreach ($list as &$row) {
            $row['is_self'] = (int) $row['id'] === $currentId ? 1 : 0;
        }

        return $this->tableJson($list, $count);
    }

    /** POST /admin-api/adminuser/save —— 新增/编辑（密码留空则不修改） */
    public function save()
    {
        $id       = (int) $this->request->post('id/d', 0);
        $username = (string) $this->post('username');
        $password = (string) $this->request->post('password', '');

        if ($username === '' || !preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
            return $this->fail('账号需为 3-50 位字母、数字或下划线');
        }

        $data = [
            'username'   => $username,
            'nickname'   => (string) $this->post('nickname'),
            'status'     => (int) $this->request->post('status/d', 1) === 1 ? 1 : 0,
            'remark'     => (string) $this->post('remark'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $exists = Db::name('ls_admin')->where('username', $username)->where('id', '<>', $id)->find();
        if (!empty($exists)) {
            return $this->fail('该账号已存在');
        }

        if ($id > 0) {
            if ($password !== '') {
                if (strlen($password) < 6) {
                    return $this->fail('密码长度不能少于 6 位');
                }
                $data['password'] = password_hash($password, PASSWORD_BCRYPT);
            }
            Db::name('ls_admin')->where('id', $id)->update($data);
            $this->audit('adminuser/save', ['id' => $id]);
            return $this->ok(['id' => $id], '修改成功');
        }

        if (strlen($password) < 6) {
            return $this->fail('密码长度不能少于 6 位');
        }

        $data['password']   = password_hash($password, PASSWORD_BCRYPT);
        $data['created_at'] = date('Y-m-d H:i:s');
        $newId = Db::name('ls_admin')->insertGetId($data);
        $this->audit('adminuser/save', ['id' => $newId]);

        return $this->ok(['id' => $newId], '新增成功');
    }

    /** POST /admin-api/adminuser/delete */
    public function delete()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }
        if ($id === $this->adminId()) {
            return $this->fail('不能删除当前登录的账号');
        }
        if ((int) Db::name('ls_admin')->count() <= 1) {
            return $this->fail('至少需要保留一个管理员账号');
        }

        Db::name('ls_admin')->where('id', $id)->delete();
        $this->audit('adminuser/delete', ['id' => $id]);

        return $this->ok([], '删除成功');
    }
}
