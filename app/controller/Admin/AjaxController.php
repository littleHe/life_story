<?php
namespace app\controller\Admin;

use app\service\AdminMenuService;

/**
 * 后台框架初始化 + 通用上传
 *
 * init 接口的输出结构直接对应 layuimini 的 miniAdmin.render()，
 * 顶层不能再包 {code,msg,data}，否则菜单渲染不出来。
 */
class AjaxController extends AdminBase
{
    /** GET /admin-api/init —— 返回 logoInfo / homeInfo / menuInfo */
    public function init()
    {
        $menuService = new AdminMenuService();

        return json([
            'logoInfo' => $menuService->getLogoInfo(),
            'homeInfo' => $menuService->getHomeInfo(),
            'menuInfo' => $menuService->getMenuTree(),
        ]);
    }

    /**
     * POST /admin-api/upload —— 图片上传（幻灯片用）
     * 依赖框架自带 think\File::move()，用 public/uploads 做本地存储
     */
    public function upload()
    {
        $file = $this->request->file('file');
        if (empty($file)) {
            return $this->fail('未接收到上传文件');
        }

        try {
            validate(['file' => 'fileExt:jpg,jpeg,png,gif,webp,bmp'])
                ->check(['file' => $file]);
        } catch (\Throwable $e) {
            return $this->fail('文件校验失败：' . $e->getMessage());
        }

        try {
            $info = $file->move(public_path() . 'uploads' . DIRECTORY_SEPARATOR . 'slide');
        } catch (\Throwable $e) {
            return $this->fail('上传失败：' . $e->getMessage());
        }

        if (empty($info)) {
            return $this->fail('上传失败');
        }

        $relative = str_replace('\\', '/', $info->getSaveName());
        $url      = '/uploads/slide/' . $relative;

        return $this->ok(['url' => $url, 'src' => $url], '上传成功');
    }
}
