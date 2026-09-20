<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 后台登录（独立 ls_admin 表 + password_hash，与前台微信/JWT 体系隔离）
 */
class LoginController extends AdminBase
{
    /** POST /admin-api/login */
    public function login()
    {
        $username = (string) $this->post('username');
        $password = (string) $this->request->post('password', '');

        if ($username === '' || $password === '') {
            return $this->fail('请输入账号和密码');
        }

        $admin = Db::name('ls_admin')->where('username', $username)->find();
        if (empty($admin) || !password_verify($password, (string) $admin['password'])) {
            return $this->fail('账号或密码错误');
        }
        if ((int) $admin['status'] !== 1) {
            return $this->fail('该账号已被禁用，请联系管理员');
        }

        session('admin', [
            'id'       => (int) $admin['id'],
            'username' => (string) $admin['username'],
            'nickname' => (string) $admin['nickname'],
            'avatar'   => (string) $admin['avatar'],
        ]);

        Db::name('ls_admin')->where('id', $admin['id'])->update([
            'login_num'     => Db::raw('login_num + 1'),
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => (string) $this->request->ip(),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        return $this->ok([
            'username' => $admin['username'],
            'nickname' => $admin['nickname'],
        ], '登录成功');
    }

    /** POST /admin-api/logout */
    public function logout()
    {
        session('admin', null);
        return $this->ok([], '已退出登录');
    }

    /** GET /admin-api/info —— 当前登录管理员信息 */
    public function info()
    {
        $admin = $this->admin();
        return $this->ok([
            'id'       => (int) ($admin['id'] ?? 0),
            'username' => (string) ($admin['username'] ?? ''),
            'nickname' => (string) ($admin['nickname'] ?? ''),
            'avatar'   => (string) ($admin['avatar'] ?? ''),
        ]);
    }
}
