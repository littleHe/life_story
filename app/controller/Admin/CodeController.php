<?php
namespace app\controller\Admin;

use app\BaseController;
use app\common\exception\ApiException;
use think\facade\Db;

/**
 * 后台：兑换码管理 + 核销/锁定审计（复用 EasyAdmin 时对应到菜单）
 * 访问前需登录且角色为管理员（role=1）
 */
class CodeController extends BaseController
{
    protected function checkAdmin()
    {
        $user = Db::name('ls_user')->where('id', $this->uid())->find();
        if (empty($user) || $user['role'] != 1) {
            throw new ApiException(40301, '无权限', 403);
        }
    }

    /** 批量生成兑换码（只存哈希，返回明文仅此一次） */
    public function create()
    {
        $this->checkAdmin();
        $count = (int) input('post.count/d', 1);
        $count = min($count, 100);
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw  = $this->genCode();
            $hash = hash('sha256', $raw);
            Db::name('ls_redemption_code')->insert([
                'code_hash'   => $hash,
                'code_mask'   => substr($raw, -4),
                'status'      => 'GENERATED',
                'generated_by'=> $this->uid(),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            $codes[] = $raw; // 明文仅此刻返回
        }
        return $this->ok(['codes' => $codes]);
    }

    /** 核销 / 锁定审计 */
    public function logs()
    {
        $this->checkAdmin();
        $vlogs = Db::name('ls_verification_log')->order('id', 'desc')->limit(50)->select();
        $locks = Db::name('ls_security_lock_log')->order('id', 'desc')->limit(50)->select();
        return $this->ok(['verifications' => $vlogs, 'locks' => $locks]);
    }

    protected function genCode(): string
    {
        return strtoupper(substr(md5(uniqid('', true) . random_bytes(8)), 0, 12));
    }
}
