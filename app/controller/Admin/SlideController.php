<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 首页幻灯片管理（H5 首页轮播）
 */
class SlideController extends AdminBase
{
    /** GET /admin-api/slide/index */
    public function index()
    {
        $query = Db::name('ls_slide');
        [$list, $count] = $this->paginate($query, 'sort desc, id desc');
        return $this->tableJson($list, $count);
    }

    /** POST /admin-api/slide/save */
    public function save()
    {
        $id    = (int) $this->request->post('id/d', 0);
        $image = (string) $this->post('image');
        if ($image === '') {
            return $this->fail('请先上传幻灯片图片');
        }

        $data = [
            'title'      => (string) $this->post('title'),
            'image'      => $image,
            'link'       => (string) $this->post('link'),
            'sort'       => (int) $this->request->post('sort/d', 0),
            'status'     => (int) $this->request->post('status/d', 1) === 1 ? 1 : 0,
            'remark'     => (string) $this->post('remark'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            Db::name('ls_slide')->where('id', $id)->update($data);
            $this->audit('slide/save', ['id' => $id]);
            return $this->ok(['id' => $id], '修改成功');
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $newId = Db::name('ls_slide')->insertGetId($data);
        $this->audit('slide/save', ['id' => $newId]);

        return $this->ok(['id' => $newId], '新增成功');
    }

    /** POST /admin-api/slide/delete */
    public function delete()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }
        Db::name('ls_slide')->where('id', $id)->delete();
        $this->audit('slide/delete', ['id' => $id]);
        return $this->ok([], '删除成功');
    }
}
