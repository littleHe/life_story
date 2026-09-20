<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 封面背景图池管理
 *
 * 用途：用户未上传封面时，定稿阶段从本图池中随机抽取一张作为回忆录封面背景。
 */
class CoverBgController extends AdminBase
{
    /** GET /admin-api/coverbg/index */
    public function index()
    {
        $query = Db::name('ls_cover_bg');
        [$list, $count] = $this->paginate($query, 'sort desc, id desc');
        return $this->tableJson($list, $count);
    }

    /** POST /admin-api/coverbg/save */
    public function save()
    {
        $id    = (int) $this->request->post('id/d', 0);
        $image = (string) $this->post('image');
        if ($image === '') {
            return $this->fail('请先上传背景图片');
        }

        $data = [
            'title'      => (string) $this->post('title'),
            'image'      => $image,
            'sort'       => (int) $this->request->post('sort/d', 0),
            'status'     => (int) $this->request->post('status/d', 1) === 1 ? 1 : 0,
            'remark'     => (string) $this->post('remark'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            Db::name('ls_cover_bg')->where('id', $id)->update($data);
            $this->audit('coverbg/save', ['id' => $id]);
            return $this->ok(['id' => $id], '修改成功');
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $newId = Db::name('ls_cover_bg')->insertGetId($data);
        $this->audit('coverbg/save', ['id' => $newId]);

        return $this->ok(['id' => $newId], '新增成功');
    }

    /** POST /admin-api/coverbg/delete */
    public function delete()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }
        Db::name('ls_cover_bg')->where('id', $id)->delete();
        $this->audit('coverbg/delete', ['id' => $id]);
        return $this->ok([], '删除成功');
    }
}
