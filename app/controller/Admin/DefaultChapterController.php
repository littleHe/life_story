<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 回忆录管理 · 默认章节
 *
 * 用途：新建回忆录（兑换解锁）成功后，系统按本列表「启用中」的条目自动播种到该项目的章节里，
 *     用户可在此基础上增删改；管理员在此统一维护「默认章节模板」。
 * 与「系统章节」的区别：系统章节管理的是 ls_chapter 全量章节（含用户项目的章节）；
 * 本模块只管一份「新建时套用的模板」，与任何项目无关。
 */
class DefaultChapterController extends AdminBase
{
    /** GET /admin-api/defaultchapter/index */
    public function index()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));

        $query = Db::name('ls_default_chapter');
        if ($keyword !== '') {
            $query->where('title', 'like', '%' . $keyword . '%');
        }

        // 数字越大越靠前，与前台表单提示一致
        [$list, $count] = $this->paginate($query, 'sort desc, id asc');

        foreach ($list as &$row) {
            $row['id']     = (int) $row['id'];
            $row['sort']   = (int) $row['sort'];
            $row['status'] = (int) $row['status'];
        }

        return $this->tableJson($list, $count);
    }

    /** POST /admin-api/defaultchapter/save（有 id 改，无 id 增） */
    public function save()
    {
        $id    = (int) $this->request->post('id/d', 0);
        $title = (string) $this->post('title');
        if ($title === '') {
            return $this->fail('章节标题不能为空');
        }

        $data = [
            'title'      => $title,
            'sort'       => (int) $this->request->post('sort/d', 0),
            'status'     => (int) $this->request->post('status/d', 1) === 1 ? 1 : 0,
            'remark'     => (string) $this->post('remark'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            if (empty(Db::name('ls_default_chapter')->where('id', $id)->find())) {
                return $this->fail('条目不存在');
            }
            Db::name('ls_default_chapter')->where('id', $id)->update($data);
            $this->audit('defaultchapter/save', ['id' => $id]);
            return $this->ok(['id' => $id], '修改成功');
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $newId = Db::name('ls_default_chapter')->insertGetId($data);
        $this->audit('defaultchapter/save', ['id' => $newId]);

        return $this->ok(['id' => $newId], '新增成功');
    }

    /** POST /admin-api/defaultchapter/delete */
    public function delete()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }
        Db::name('ls_default_chapter')->where('id', $id)->delete();
        $this->audit('defaultchapter/delete', ['id' => $id]);
        return $this->ok([], '删除成功');
    }
}
