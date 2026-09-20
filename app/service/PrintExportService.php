<?php
namespace app\service;

use think\facade\Db;

/**
 * 回忆录「汇出印刷包」—— 后台一键打包，供印刷厂取素材。
 *
 * 打包内容（与磁盘归档目录一致）：
 *   {user_id}_{project_id}/
 *     bgm/        章节配图 + 封面背景图 + 传主头像（背景图素材）
 *     audio/      章节旁白 + 封面书封语 + 尾页结束语（AI 录音）
 *     人物基础信息.txt   传主姓名/性别/出生/籍贯/简介 + 头像文件名
 *     章节内容.txt       逐章：标题 + 正文（润色稿优先）+ 对应图/音文件名
 *     manifest.json      元数据
 *
 * 素材来源：优先取定稿后落盘在 {YYYYMM}/{user}_{id}/bgm|audio 的文件；
 *          也兼容历史旧路径（chapter-bg / tts），统一按「DB 记录的 URL → 磁盘文件」解析。
 */
class PrintExportService
{
    /**
     * 生成印刷包 zip，返回可下载的相对 URL（/uploads/export/...）。
     *
     * @param int $projectId 回忆录 id
     * @return string 形如 /uploads/export/2_20_print_20260920xxxx.zip
     * @throws \RuntimeException
     */
    public static function export(int $projectId): string
    {
        $project = Db::name('ls_project')->where('id', $projectId)->find();
        if (!$project) {
            throw new \RuntimeException('项目不存在：' . $projectId);
        }
        $userId = (int) ($project['user_id'] ?? 0);
        $mid    = $userId . '_' . $projectId;

        $work    = app()->getRootPath() . 'runtime/export/' . $projectId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $bgmDir  = $work . '/bgm';
        $audioDir = $work . '/audio';
        foreach ([$work, $bgmDir, $audioDir] as $d) {
            if (!is_dir($d)) {
                mkdir($d, 0777, true);
            }
        }

        // ---- 1) 素材收集（DB 记录的 URL → 磁盘绝对路径） ----
        $coverImg = self::diskOf((string) ($project['cover_bg'] ?? ''));
        $coverAu  = self::diskOf((string) ($project['cover_audio'] ?? ''));
        $endAu    = self::diskOf((string) ($project['ending_audio'] ?? ''));
        $avatar   = self::diskOf((string) ($project['avatar'] ?? ''));

        $chapters = Db::name('ls_chapter')
            ->where('project_id', $projectId)
            ->order('sort asc, id asc')
            ->select()
            ->toArray();

        $map = [];
        foreach ($chapters as $c) {
            $cid = (int) $c['id'];
            $map[$cid] = [
                'title'      => (string) ($c['title'] ?? ''),
                'img'        => self::diskOf(ChapterIllustrationService::currentImage($cid)),
                'au'         => self::diskOf(VoiceService::currentAudio($cid)),
                'polished'   => self::assetText($cid, 'POLISHED_TEXT'),
                'transcript' => self::assetText($cid, 'TRANSCRIPT'),
            ];
        }

        // ---- 2) 复制进工作目录（保留原扩展名，记录实际文件名） ----
        $bgmFiles   = [];
        $audioFiles = [];

        if ($coverImg !== '') {
            $bgmFiles['cover'] = basename(self::copyToExt($coverImg, $bgmDir . '/cover'));
        }
        if ($avatar !== '') {
            $bgmFiles['avatar'] = basename(self::copyToExt($avatar, $bgmDir . '/avatar'));
        }
        if ($coverAu !== '') {
            $audioFiles['cover'] = basename(self::copyToExt($coverAu, $audioDir . '/cover'));
        }
        if ($endAu !== '') {
            $audioFiles['ending'] = basename(self::copyToExt($endAu, $audioDir . '/ending'));
        }
        foreach ($map as $cid => $m) {
            if ($m['img'] !== '') {
                $bgmFiles['chapter_' . $cid] = basename(self::copyToExt($m['img'], $bgmDir . '/chapter_' . $cid));
            }
            if ($m['au'] !== '') {
                $audioFiles['chapter_' . $cid] = basename(self::copyToExt($m['au'], $audioDir . '/chapter_' . $cid));
            }
        }

        // ---- 3) 人物基础信息 ----
        $person = [
            '书名'     => (string) ($project['name'] ?? ''),
            '传主姓名' => (string) ($project['real_name'] ?? ''),
            '性别'     => ($project['gender'] ?? '') === 'female' ? '女' : '男',
            '出生日期' => (string) ($project['birth'] ?? ''),
            '籍贯'     => (string) ($project['native_place'] ?? ''),
            '简介'     => (string) ($project['description'] ?? ''),
            '头像文件' => $bgmFiles['avatar'] ?? '（无）',
        ];
        file_put_contents($work . '/人物基础信息.txt', self::kvText($person));

        // ---- 4) 章节及对应信息 ----
        $chText = '';
        foreach ($chapters as $i => $c) {
            $cid    = (int) $c['id'];
            $m      = $map[$cid];
            $body   = $m['polished'] !== '' ? $m['polished'] : $m['transcript'];
            $kind   = $m['polished'] !== '' ? '（润色稿）' : ($m['transcript'] !== '' ? '（口述原文）' : '（无正文）');
            $imgRef = $bgmFiles['chapter_' . $cid] ?? '（未生成配图）';
            $auRef  = $audioFiles['chapter_' . $cid] ?? '（未生成配音）';

            $chText .= '第' . ($i + 1) . '章 ' . ($m['title'] ?: '未命名') . $kind . "\n";
            $chText .= "配图：bgm/" . $imgRef . "\n";
            $chText .= "配音：audio/" . $auRef . "\n\n";
            $chText .= ($body !== '' ? $body : '（本章暂无正文）') . "\n\n";
            $chText .= "--------------------------------------------------\n\n";
        }
        if ($chText === '') {
            $chText = '（该项目暂无章节）';
        }
        file_put_contents($work . '/章节内容.txt', $chText);

        // ---- 5) manifest ----
        $hasCover = isset($bgmFiles['cover']) ? 1 : 0;
        file_put_contents($work . '/manifest.json', json_encode([
            'project_id'     => $projectId,
            'user_id'        => $userId,
            'name'           => (string) ($project['name'] ?? ''),
            'real_name'      => (string) ($project['real_name'] ?? ''),
            'exported_at'    => date('Y-m-d H:i:s'),
            'chapters'       => count($chapters),
            'bgm_count'      => count($bgmFiles),
            'audio_count'    => count($audioFiles),
            'has_cover'      => $hasCover,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // ---- 6) 打包 zip ----
        $zipRel = '/uploads/export/' . $mid . '_print_' . date('YmdHis') . '.zip';
        $zipAbs = app()->getRootPath() . 'public' . $zipRel;
        if (!is_dir(dirname($zipAbs))) {
            mkdir(dirname($zipAbs), 0777, true);
        }
        self::zipDir($work, $zipAbs);

        // 清理临时工作目录
        self::delDir($work);

        return $zipRel;
    }

    /** 相对/绝对 URL → 磁盘绝对路径（不存在返回空串） */
    private static function diskOf(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $path = stripos($url, 'http') === 0 ? (string) parse_url($url, PHP_URL_PATH) : $url;
        $abs  = app()->getRootPath() . 'public' . $path;
        return is_file($abs) ? $abs : '';
    }

    /** 取章节资源文本（POLISHED_TEXT / TRANSCRIPT 的 meta.text） */
    private static function assetText(int $chapterId, string $type): string
    {
        $row = Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', $type)
            ->order('id', 'desc')
            ->find();
        if (!$row) {
            return '';
        }
        $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
        return trim((string) ($meta['text'] ?? ''));
    }

    /** 复制文件到 $dstPrefix（不含扩展名），保留源扩展名，返回目标完整路径 */
    private static function copyToExt(string $src, string $dstPrefix): string
    {
        $ext = strtolower((string) pathinfo($src, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'bin';
        }
        $dst = $dstPrefix . '.' . $ext;
        @copy($src, $dst);
        return $dst;
    }

    /** 键值对 → 可读文本 */
    private static function kvText(array $kv): string
    {
        $out = '';
        foreach ($kv as $k => $v) {
            $out .= $k . '：' . $v . "\n";
        }
        return $out;
    }

    /** 目录打包为 zip（PHP ZipArchive） */
    private static function zipDir(string $dir, string $zipPath): void
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('服务器未启用 ZipArchive 扩展，无法打包');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建 zip 文件：' . $zipPath);
        }
        $base = strlen(rtrim($dir, '/') . '/');
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $real = $file->getRealPath();
            $zip->addFile($real, substr($file->getPathname(), $base));
        }
        $zip->close();
    }

    /** 递归删除目录 */
    private static function delDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $it) {
            $p = $dir . '/' . $it;
            is_dir($p) ? self::delDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
