<?php
namespace app\controller\Admin;

use app\BaseController;
use think\facade\Db;

/**
 * 后台：用户协议 / 隐私政策 编辑（后台管理面板，需登录）
 */
class AgreementController extends AdminBase
{
    /** GET /admin-api/agreement/index —— 拉取两份协议当前内容，供编辑表单预填 */
    public function index()
    {
        $rows = Db::name('ls_agreement')->select()->toArray();
        $map  = [];
        foreach ($rows as $r) {
            $map[$r['type']] = ['title' => $r['title'], 'content' => $r['content']];
        }
        return $this->ok([
            'user'    => $map['user'] ?? ['title' => '用户协议', 'content' => ''],
            'privacy' => $map['privacy'] ?? ['title' => '隐私政策', 'content' => ''],
        ]);
    }

    /**
     * POST /admin-api/agreement/save
     * body: { user: {title, content}, privacy: {title, content} }
     */
    public function save()
    {
        $user    = (array) $this->request->post('user/a', []);
        $privacy = (array) $this->request->post('privacy/a', []);
        $now     = date('Y-m-d H:i:s');

        $spec = [
            'user'    => ['default' => '用户协议'],
            'privacy' => ['default' => '隐私政策'],
        ];

        foreach ($spec as $type => $cfg) {
            $item   = $type === 'user' ? $user : $privacy;
            $title  = isset($item['title']) ? trim((string) $item['title']) : '';
            $content = isset($item['content']) ? trim((string) $item['content']) : '';
            if ($title === '') {
                $title = $cfg['default'];
            }
            $exist = Db::name('ls_agreement')->where('type', $type)->find();
            if ($exist) {
                Db::name('ls_agreement')->where('type', $type)->update([
                    'title'      => $title,
                    'content'    => $content,
                    'updated_at' => $now,
                ]);
            } else {
                Db::name('ls_agreement')->insert([
                    'type'       => $type,
                    'title'      => $title,
                    'content'    => $content,
                    'status'     => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->audit('agreement/save', []);
        return $this->ok([], '保存成功');
    }
}
