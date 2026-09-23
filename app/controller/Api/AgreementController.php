<?php
namespace app\controller\Api;

use app\BaseController;
use think\facade\Db;

/**
 * 公开协议接口（无需登录）
 * 登录页「用户协议 / 隐私政策」弹窗从这里拉取正文。
 */
class AgreementController extends BaseController
{
    /** GET /api/agreement —— 返回用户协议 + 隐私政策（标题与正文） */
    public function list()
    {
        $rows = Db::name('ls_agreement')->where('status', 1)->select()->toArray();
        $map  = [];
        foreach ($rows as $r) {
            $map[$r['type']] = [
                'title'   => $r['title'],
                'content' => $r['content'],
            ];
        }
        return $this->ok([
            'user'    => $map['user'] ?? null,
            'privacy' => $map['privacy'] ?? null,
        ]);
    }
}
