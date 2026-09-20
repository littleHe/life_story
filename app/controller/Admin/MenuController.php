<?php
namespace app\controller\Admin;

use app\service\AdminMenuService;
use think\facade\Db;

/**
 * 后台菜单管理（图标字段 icon 存 Font Awesome 类名，表单用图标选择器选）
 */
class MenuController extends AdminBase
{
    /** GET /admin-api/menu/index —— 平铺列表（含上级菜单名，便于表格展示） */
    public function index()
    {
        $service = new AdminMenuService();
        $rows    = $service->getAll();

        // pid => title 映射
        $titleMap = [];
        foreach ($rows as $row) {
            $titleMap[(int) $row['id']] = $row['title'];
        }

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id'           => (int) $row['id'],
                'pid'          => (int) $row['pid'],
                'parent_title' => (int) $row['pid'] === 0 ? '顶级菜单' : ($titleMap[(int) $row['pid']] ?? '—'),
                'title'        => $row['title'],
                'icon'         => $row['icon'],
                'href'         => $row['href'],
                'target'       => $row['target'],
                'sort'         => (int) $row['sort'],
                'status'       => (int) $row['status'],
                'remark'       => $row['remark'],
                'created_at'   => $row['created_at'],
            ];
        }

        return $this->tableJson($list, count($list));
    }

    /** GET /admin-api/menu/options —— 上级菜单下拉选项 */
    public function options()
    {
        $options = [['id' => 0, 'title' => '顶级菜单']];
        foreach ((new AdminMenuService())->getTopOptions() as $item) {
            $options[] = ['id' => (int) $item['id'], 'title' => $item['title']];
        }
        return $this->ok(['options' => $options]);
    }

    /** POST /admin-api/menu/save —— 新增 / 编辑 */
    public function save()
    {
        $id    = (int) $this->request->post('id/d', 0);
        $title = (string) $this->post('title');
        if ($title === '') {
            return $this->fail('菜单名称不能为空');
        }

        $pid  = (int) $this->request->post('pid/d', 0);
        $icon = (string) $this->post('icon');

        // 图标必须落在白名单里（fa 类名或空），避免写入任意 HTML 属性
        if ($icon !== '' && !preg_match('/^fa[a-z0-9\- ]*$/i', $icon)) {
            return $this->fail('图标格式不正确，应为 fa fa-xxx');
        }

        $data = [
            'pid'        => $pid,
            'title'      => $title,
            'icon'       => $icon,
            'href'       => (string) $this->post('href'),
            'target'     => in_array($this->post('target'), ['_self', '_blank', '_parent', '_top'], true)
                ? $this->post('target') : '_self',
            'sort'       => (int) $this->request->post('sort/d', 0),
            'status'     => (int) $this->request->post('status/d', 1) === 1 ? 1 : 0,
            'remark'     => (string) $this->post('remark'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            if ($id === $pid) {
                return $this->fail('上级菜单不能是自己');
            }
            if ($this->isDescendant($pid, $id)) {
                return $this->fail('上级菜单不能选择自己的子菜单');
            }
            Db::name('ls_admin_menu')->where('id', $id)->update($data);
            $this->audit('menu/save', ['id' => $id, 'title' => $title]);
            return $this->ok(['id' => $id], '修改成功');
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $newId = Db::name('ls_admin_menu')->insertGetId($data);
        $this->audit('menu/save', ['id' => $newId, 'title' => $title]);

        return $this->ok(['id' => $newId], '新增成功');
    }

    /** POST /admin-api/menu/delete */
    public function delete()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }

        $childCount = Db::name('ls_admin_menu')->where('pid', $id)->count();
        if ($childCount > 0) {
            return $this->fail('请先删除该菜单下的 ' . $childCount . ' 个子菜单');
        }

        Db::name('ls_admin_menu')->where('id', $id)->delete();
        $this->audit('menu/delete', ['id' => $id]);

        return $this->ok([], '删除成功');
    }

    /** 判断 $pid 是否是 $id 的后代（防止把父级挂到自己子级下形成环） */
    private function isDescendant(int $pid, int $id): bool
    {
        if ($pid === 0) {
            return false;
        }
        $current = $pid;
        $guard   = 0;
        while ($current > 0 && $guard++ < 50) {
            if ($current === $id) {
                return true;
            }
            $parent = Db::name('ls_admin_menu')->where('id', $current)->value('pid');
            $current = (int) $parent;
        }
        return false;
    }
}
