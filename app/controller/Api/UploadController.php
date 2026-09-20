<?php
namespace app\controller\Api;

use app\BaseController;
use app\common\exception\ApiException;

/**
 * 通用上传（需登录）：目前提供传主头像上传
 */
class UploadController extends BaseController
{
    /**
     * POST /api/upload/avatar  body: { image_base64, image_ext }
     * 存储到 public/uploads/avatar/user/{uid}/，返回可长期访问的图片地址
     */
    public function avatar()
    {
        $uid = $this->uid();
        $b64 = input('post.image_base64/s', '');
        if ($b64 === '') {
            throw new ApiException(42201, '缺少图片数据', 422);
        }
        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', input('post.image_ext/s', 'jpg') ?: 'jpg') ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new ApiException(42202, '仅支持 jpg/png/webp 图片', 422);
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            throw new ApiException(42201, '图片数据解码失败', 422);
        }
        if (strlen($bin) > 8 * 1024 * 1024) {
            throw new ApiException(42202, '图片过大（限 8MB）', 422);
        }

        $dir = app()->getRootPath() . 'public/uploads/avatar/user/' . $uid;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $name = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        file_put_contents($dir . '/' . $name, $bin);

        return $this->ok([
            'url' => '/uploads/avatar/user/' . $uid . '/' . $name,
        ]);
    }
}
