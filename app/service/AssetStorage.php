<?php
namespace app\service;

/**
 * 回忆录资源落盘目录（按 年月 / 用户_回忆录 分桶）。
 *
 * 目录约定（与后台「汇出印刷包」一致）：
 *   public/uploads/{YYYYMM}/{user_id}_{project_id}/bgm/   ← AI 生图 / 封面背景图
 *   public/uploads/{YYYYMM}/{user_id}_{project_id}/audio/ ← AI 旁白录音
 *
 * 生成（定稿时的 ILLUSTRATE / COVER / NARRATE 任务）与后台汇出都走这里，
 * 保证「磁盘目录结构」与「打包结构」一致，便于印刷厂直接取素材。
 */
class AssetStorage
{
    /** 背景图（章节配图 / 封面主视觉） */
    public const KIND_BGM = 'bgm';
    /** AI 旁白录音（章节 / 封面 / 尾页） */
    public const KIND_AUDIO = 'audio';

    /**
     * 取某项目某类资源的绝对目录（不存在则自动创建）。
     *
     * @param int    $userId    用户 id（ls_project.user_id）
     * @param int    $projectId 回忆录 id
     * @param string $kind      self::KIND_BGM | self::KIND_AUDIO
     */
    public static function projectDir(int $userId, int $projectId, string $kind): string
    {
        $dir = app()->getRootPath() . 'public/uploads/' . date('Ym') . '/'
            . $userId . '_' . $projectId . '/' . $kind;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * 由绝对目录反推站点相对 URL 前缀（不含文件名）。
     * 例：.../public/uploads/202609/2_20/bgm → /uploads/202609/2_20/bgm
     */
    public static function dirToUrl(string $absDir): string
    {
        $root = app()->getRootPath() . 'public';
        $rel  = (string) substr($absDir, strlen($root));
        $rel  = str_replace('\\', '/', $rel);
        return '/' . ltrim($rel, '/');
    }
}
