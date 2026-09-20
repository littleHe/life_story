<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

/**
 * `php think ls:reset` —— 上线前初始化：清空全部「业务数据」与「数据关联的上传文件」
 *
 * 设计原则：
 *   - 默认只**预演**（列清单，不做任何改动），必须显式 --force 才真正执行；
 *   - 保留「运营/系统配置类」数据，避免上完线发现后台菜单、站点参数、封面图池全没了：
 *       ls_admin 管理员、ls_admin_menu 菜单、ls_config 站点参数、
 *       ls_slide 首页幻灯片、ls_cover_bg 封面背景图池、ls_default_chapter 默认章节、ls_bgm 背景音乐池；
 *   - 清库前默认先 mysqldump 备份到 runtime/db_backup/（--no-backup 可跳过）；
 *   - 文件只删「业务产生的」：录音、用户头像、AI 章节图、导出包、按年月归档目录；
 *     封面池 / BGM 池 / 章节兜底图池（uploads/chapter-bg/bg*.png）一律保留。
 *
 * 用法：
 *   php think ls:reset                    # 预演：打印将清空的表与目录
 *   php think ls:reset --force            # 执行：备份 + 清库 + 清文件
 *   php think ls:reset --force --db-only  # 只清库，不动文件
 *   php think ls:reset --force --no-backup
 */
class LsReset extends Command
{
    /** 业务数据表（清空 + 重置自增） */
    private const PURGE_TABLES = [
        'ls_chapter_asset',   // 章节素材（录音/转写/配图/配音）：先删子表
        'ls_chapter',         // 章节
        'ls_ai_task',         // AI 任务流水
        'ls_project',         // 回忆录项目
        'ls_voice_profile',   // 声音复刻档案
        'ls_redemption_code', // 兑换码（上线后由后台录入真实码）
        'ls_verification_log',// 兑换码校验日志
        'ls_security_lock_log',// 风控锁定日志
        'ls_user',            // 用户（微信授权用户）
        'ls_api_log',         // 接口日志
    ];

    /** 队列表（think-queue；列名以 yunwuxin 版为准：reserve_time/available_time/create_time） */
    private const QUEUE_TABLES = ['jobs', 'failed_jobs'];

    /** 保留（不清）的表，仅用于输出提示 */
    private const KEEP_TABLES = [
        'ls_admin', 'ls_admin_menu', 'ls_config', 'ls_slide',
        'ls_cover_bg', 'ls_default_chapter', 'ls_bgm',
    ];

    /** 业务产生的上传目录（相对 backend/，逐个清空内容但保留目录本身） */
    private const PURGE_DIRS = [
        'public/uploads/audio',        // 章节录音
        'public/uploads/avatar/user',  // 用户头像
        'public/uploads/chapter-bg/ai',   // AI 生成章节配图
        'public/uploads/chapter-bg/user', // 用户手动上传的章节背景
        'public/uploads/tts',          // 历史 TTS 音频
        'public/uploads/export',       // 印刷包 zip
    ];

    protected function configure()
    {
        $this->setName('ls:reset')
            ->addOption('force', null, Option::VALUE_NONE, '真正执行（不加只预演，不做任何改动）')
            ->addOption('db-only', null, Option::VALUE_NONE, '只清数据库，不删上传文件')
            ->addOption('no-backup', null, Option::VALUE_NONE, '不备份数据库（不建议）')
            ->setDescription('上线前初始化：清空业务数据与关联上传文件，保留管理员/菜单/站点参数/封面池等配置');
    }

    protected function execute(Input $input, Output $output)
    {
        $root    = app()->getRootPath();
        $force   = (bool) $input->getOption('force');
        $dbOnly  = (bool) $input->getOption('db-only');
        $noBk    = (bool) $input->getOption('no-backup');

        $tables    = array_merge(self::PURGE_TABLES, self::QUEUE_TABLES);
        $dirs      = $dbOnly ? [] : $this->collectDirs($root);
        $rowCounts = $this->rowCounts($tables);

        // ---------- 预演清单 ----------
        $output->writeln('===== ls:reset 初始化计划 =====');
        $output->writeln('');
        $output->writeln('【将清空的数据表】（括号内为当前行数）');
        foreach ($tables as $t) {
            $output->writeln(sprintf('  - %-22s %s', $t, isset($rowCounts[$t]) ? "($rowCounts[$t] 行)" : '(表不存在，跳过)'));
        }
        $output->writeln('');
        $output->writeln('【将保留的配置表】' . implode('、', self::KEEP_TABLES));
        $output->writeln('');
        if (!$dbOnly) {
            $output->writeln('【将清空的文件目录】（保留目录本身）');
            foreach ($dirs as $d) {
                $output->writeln(sprintf('  - %-46s (%d 个文件)', str_replace($root, '', $d), $this->fileCount($d)));
            }
            $output->writeln('');
            $output->writeln('【将保留的文件】uploads/cover-bg（封面图池）、uploads/bgm（背景音乐池）、');
            $output->writeln('                uploads/chapter-bg/bg*.png（章节兜底图池）、uploads/slide');
        }
        $output->writeln('');

        if (!$force) {
            $output->writeln('<comment>以上仅为预演，未做任何改动。</comment>');
            $output->writeln('<comment>确认无误后执行：php think ls:reset --force</comment>');
            return 0;
        }

        // ---------- 1. 备份 ----------
        if (!$noBk) {
            $dump = $this->backup($root, $output);
            if ($dump === '') {
                $output->writeln('<error>数据库备份失败。若确实不需要备份，请加 --no-backup 重新执行。</error>');
                return 1;
            }
        } else {
            $output->writeln('<comment>[跳过] 按要求未备份数据库</comment>');
        }

        // ---------- 2. 清库 ----------
        Db::execute('SET FOREIGN_KEY_CHECKS=0');
        $cleared = 0;
        foreach ($tables as $t) {
            if (!isset($rowCounts[$t])) {
                continue;
            }
            // TRUNCATE：清空并重置 AUTO_INCREMENT，避免新数据 id 从几千开始
            Db::execute("TRUNCATE TABLE `{$t}`");
            $cleared++;
        }
        Db::execute('SET FOREIGN_KEY_CHECKS=1');
        $output->writeln(sprintf('<info>[清库] 已清空 %d 张表</info>', $cleared));

        // ---------- 3. 清文件 ----------
        if (!$dbOnly) {
            $n = 0;
            foreach ($dirs as $d) {
                $n += $this->wipeDir($d);
            }
            $output->writeln(sprintf('<info>[清文件] 已删除 %d 个文件</info>', $n));
        }

        // ---------- 4. 清理运行时残留（避免旧的 worker 心跳让 kick() 误判） ----------
        foreach (['runtime/ai-work.alive', 'runtime/ai-work.log'] as $f) {
            if (is_file($root . $f)) {
                @unlink($root . $f);
                $output->writeln('[运行态] 已删除 ' . $f);
            }
        }

        $output->writeln('');
        $output->writeln('<info>初始化完成。数据库与业务文件已是「全新」状态。</info>');
        $output->writeln('后续：后台录入真实兑换码 → 上传封面图池/BGM（如需）→ 重新生成小程序/公众号链接。');
        return 0;
    }

    /** 表是否存在 + 行数；不存在的表返回时会被 unset，后续自动跳过 */
    private function rowCounts(array $tables): array
    {
        $map = [];
        foreach ($tables as $t) {
            try {
                $map[$t] = (int) Db::name($t)->count();
            } catch (\Throwable $e) {
                // 表不存在（如未装 think-queue 的 jobs 表）→ 跳过
            }
        }
        return $map;
    }

    /** 需清空的目录 = 固定清单 + uploads 下所有「6 位年月」归档目录（202609 这种） */
    private function collectDirs(string $root): array
    {
        $dirs = [];
        foreach (self::PURGE_DIRS as $rel) {
            $dirs[] = $root . $rel;
        }
        foreach ((array) glob($root . 'public/uploads/[0-9][0-9][0-9][0-9][0-9][0-9]', GLOB_ONLYDIR) as $d) {
            $dirs[] = $d;
        }
        return array_values(array_unique($dirs));
    }

    private function fileCount(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $n++;
            }
        }
        return $n;
    }

    /** 清空目录内容但保留目录自身（缺失则补建），返回删除的文件数 */
    private function wipeDir(string $dir): int
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            return 0;
        }
        $n = 0;
        foreach ((array) glob($dir . '/*') as $p) {
            if (is_file($p)) {
                @unlink($p) && $n++;
            } elseif (is_dir($p)) {
                $n += $this->fileCount($p);
                $this->removeTree($p);
            }
        }
        return $n;
    }

    private function removeTree(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $p) {
            if (is_dir($p)) {
                $this->removeTree($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    /**
     * mysqldump 备份到 runtime/db_backup/，返回备份文件绝对路径；失败返回空串。
     * 服务器（宝塔）上 mysqldump 通常已在 PATH；找不到时回退到本目录同级常见路径。
     */
    private function backup(string $root, Output $output): string
    {
        $dir = $root . 'runtime/db_backup';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/life_story_reset_' . date('Ymd_His') . '.sql';

        $host = (string) env('DB_HOST', '127.0.0.1');
        $port = (string) env('DB_PORT', '3306');
        $name = (string) env('DB_NAME', '');
        $user = (string) env('DB_USER', 'root');
        $pass = (string) env('DB_PASS', '');

        $bin = $this->findMysqldump();
        if ($bin === '') {
            $output->writeln('<comment>[备份] 未找到 mysqldump，跳过备份（如需请手动导出后再执行）</comment>');
            return '';
        }

        // --password= 后面不留空格：否则密码以空格开头时会被截断
        $cmd = sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --default-character-set=utf8mb4 %s > %s 2>&1',
            escapeshellarg($bin),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($name),
            escapeshellarg($file)
        );
        @exec($cmd, $out, $code);

        if ($code !== 0 || !is_file($file) || filesize($file) < 100) {
            @unlink($file);
            $output->writeln('<comment>[备份] mysqldump 执行失败：' . implode(' ', (array) $out) . '</comment>');
            return '';
        }
        $output->writeln(sprintf('<info>[备份] %s （%.1f KB）</info>', $file, filesize($file) / 1024));
        return $file;
    }

    private function findMysqldump(): string
    {
        $isWin = stripos(PHP_OS_FAMILY, 'Windows') !== false;
        $names = $isWin ? ['mysqldump.exe', 'mysqldump'] : ['mysqldump'];

        foreach ($names as $n) {
            @exec($isWin ? "where {$n} 2>NUL" : "command -v {$n}", $out, $code);
            if ($code === 0 && !empty($out[0]) && is_file(trim($out[0]))) {
                return trim($out[0]);
            }
            $out = [];
        }

        // 兜底：phpStudy / 宝塔常见安装位置
        $candidates = $isWin
            ? (array) glob('D:/phpstudy_pro/Extensions/MySQL*/*/bin/mysqldump.exe')
            : [
                '/www/server/mysql/bin/mysqldump',
                '/usr/bin/mysqldump',
                '/usr/local/mysql/bin/mysqldump',
            ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return '';
    }
}
