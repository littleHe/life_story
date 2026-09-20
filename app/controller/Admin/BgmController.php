<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 背景音乐池管理
 *
 * 用途：翻书预览页「欣赏回忆录」时，从本池随机取一首「启用中」的音乐循环播放。
 * 音频先走 /admin-api/upload 上传，再把返回地址填到「音频地址」。
 */
class BgmController extends AdminBase
{
    /** GET /admin-api/bgm/index */
    public function index()
    {
        $query = Db::name('ls_bgm');
        [$list, $count] = $this->paginate($query, 'sort desc, id asc');
        return $this->tableJson($list, $count);
    }

    /** POST /admin-api/bgm/save（有 id 为改，无 id 为增） */
    public function save()
    {
        $id   = (int) $this->request->post('id/d', 0);
        $file = (string) $this->post('file');
        if ($file === '') {
            return $this->fail('请先上传音频文件');
        }

        $data = [
            'title'      => (string) $this->post('title'),
            'file'       => $file,
            'sort'       => (int) $this->request->post('sort/d', 0),
            'status'     => (int) $this->request->post('status/d', 1) === 1 ? 1 : 0,
            'remark'     => (string) $this->post('remark'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            Db::name('ls_bgm')->where('id', $id)->update($data);
            $this->audit('bgm/save', ['id' => $id]);
            return $this->ok(['id' => $id], '修改成功');
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $newId = Db::name('ls_bgm')->insertGetId($data);
        $this->audit('bgm/save', ['id' => $newId]);

        return $this->ok(['id' => $newId], '新增成功');
    }

    /** POST /admin-api/bgm/delete */
    public function delete()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }
        Db::name('ls_bgm')->where('id', $id)->delete();
        $this->audit('bgm/delete', ['id' => $id]);
        return $this->ok([], '删除成功');
    }
}
