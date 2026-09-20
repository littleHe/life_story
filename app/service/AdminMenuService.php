<?php
namespace app\service;

use think\facade\Db;

/**
 * 后台菜单服务（数据库驱动）
 *
 * 参考 booking 的做法：菜单存在表里，`icon` 字段直接存 Font Awesome 类名
 * （如 "fa fa-book"），前端 layuimini 的 miniMenu 会把 icon 原样输出为
 * <i class="fa fa-book"></i>，因此菜单/列表/图标选择器三处共用同一套值。
 */
class AdminMenuService
{
    /** 后台首页（欢迎页） */
    public function getHomeInfo(): array
    {
        return [
            'title' => '控制台',
            'icon'  => 'fa fa-home',
            'href'  => '/admin/welcome.html',
        ];
    }

    /** 左上角 Logo 信息 */
    public function getLogoInfo(): array
    {
        return [
            'title' => '回忆录后台',
            'image' => '/static/admin/images/logo.svg',
            'href'  => '/admin/index.html',
        ];
    }

    /**
     * 菜单树：供 layuimini miniMenu 渲染
     * 输出结构 [{id,title,icon,href,target,child:[...]}]
     */
    public function getMenuTree(): array
    {
        $rows = Db::name('ls_admin_menu')
            ->field('id,pid,title,icon,href,target')
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->buildTree(0, $rows);
    }

    /** 全部菜单（菜单管理页使用，含禁用项） */
    public function getAll(): array
    {
        return Db::name('ls_admin_menu')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /** 顶级菜单选项（供"上级菜单"下拉使用） */
    public function getTopOptions(): array
    {
        return Db::name('ls_admin_menu')
            ->field('id,title')
            ->where('pid', 0)
            ->order('sort', 'desc')
            ->select()
            ->toArray();
    }

    private function buildTree(int $pid, array $rows): array
    {
        $tree = [];
        foreach ($rows as $row) {
            if ((int) $row['pid'] !== $pid) {
                continue;
            }
            $node = [
                'id'     => (int) $row['id'],
                'title'  => (string) $row['title'],
                'icon'   => (string) $row['icon'],
                'href'   => (string) $row['href'],
                'target' => $row['target'] ?: '_self',
            ];
            $child = $this->buildTree((int) $row['id'], $rows);
            if (!empty($child)) {
                $node['child'] = $child;
                $node['href']  = ''; // 有子节点时父级只作展开，不跳转
            }
            $tree[] = $node;
        }
        return $tree;
    }
}
