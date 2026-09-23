<?php
namespace app\service;

use think\facade\Db;
use think\facade\Queue;

/**
 * AI 异步任务：入队（数据库驱动 think-queue）+ 落库追踪 + 收尾/自愈
 *
 * 业务 API 立即返回 task_id，前端可轮询 /tasks/:id 取进度。
 * 任务由队列消费，执行方式见 app/queue/AiJob.php：
 *   默认「不需要常驻 worker」——业务侧调 self::kick() 在后台拉起 `php think ai:work`，干完自动退出。
 *
 * 配套三个关键能力（解决「定稿后任务失败/中断/长期停在 MAKING」）：
 *   1) maybeCompleteProject() —— 某项目所有定稿任务都进入终态（SUCCESS/FAILED）后，把
 *      ls_project.status 从 MAKING 翻成 DONE（并记录 finalize_failed 供前端提示）。
 *   2) rescue() —— 自愈：重置被 worker 中途 kill / 超时遗留的 RUNNING/RETRY 孤儿任务、清理
 *      残留的 jobs 行、必要时重新拉起 worker。可由 ai:work 启动时调用，也可由 cron 定期调用。
 *   3) workerAlive() + spawn() —— 心跳探测 + 进程拉起，避免重复起 worker、也避免无人接管。
 */
class AiTaskService
{
    /** 队列名（与 config/queue.php 的 database 连接保持一致） */
    public const QUEUE = 'ai';

    /** kick 的最小间隔（秒）：避免短时间反复拉进程 */
    protected const KICK_COOLDOWN = 5;

    /** 任务卡死判定阈值（秒）：RUNNING/RETRY 超过此时长没更新，视为 worker 已死，需要重置 */
    protected const RESCUE_STUCK_SECONDS = 300;

    /** worker 心跳存活窗口（秒）：心跳文件 mtime 在此窗口内算「活着」 */
    protected const WORKER_ALIVE_SEC = 15;

    /** 自我续跑链总预算（秒）：单次定稿最多让 worker 链跑这么久，避免极端情况下无限续跑 */
    public const CHAIN_BUDGET_SEC = 1800;

    public static function push(
        string $type,
        int $uid,
        array $payload,
        ?int $chapterId = null,
        ?int $projectId = null
    ): int {
        $taskId = Db::name('ls_ai_task')->insertGetId([
            'user_id'    => $uid,
            'project_id' => $projectId,
            'chapter_id' => $chapterId,
            'task_type'  => $type,
            'status'     => 'PENDING',
            'progress'   => 0,
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            Queue::push(\app\queue\AiJob::class, ['task_id' => $taskId, 'type' => $type], self::QUEUE);
        } catch (\Throwable $e) {
            // 入队失败：绝不能留「PENDING 但 jobs 表无对应行」的孤儿（否则项目永久卡在 MAKING）。
            // 保持 PENDING + 记录错误，交给 ai:rescue 的「孤儿补投」逻辑重试；同时记日志便于排查。
            // 不抛异常：让定稿链路继续，maybeCompleteProject 会因该 PENDING 任务存在而正确地保持 MAKING。
            Db::name('ls_ai_task')->where('id', $taskId)->update([
                'status'     => 'PENDING',
                'error'      => '入队失败（rescue 将自动补投）：' . mb_substr($e->getMessage(), 0, 400),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            error_log('[AiTaskService] push 任务 ' . $taskId . ' 入队失败：' . $e->getMessage());
        }
        return $taskId;
    }

    /**
     * 「踢一脚」：在后台拉起一个 `php think ai:work` 把队列里的活干完（处理完自动退出，不需要常驻 worker）。
     *
     * 设计取舍：
     *   - 起独立进程（proc_open）+ 不等它 → 不拖慢当前接口（定稿接口要立刻返回）；
     *   - 有 PENDING 任务才踢；5 秒内踢过就跳过 —— 用户连点定稿也不会起一堆进程；
     *   - 若已有 worker 在跑（心跳存活），不再重复拉起；
     *   - 输出重定向到 runtime/ai-work.log，出问题能直接看日志。
     *
     * @return bool 是否真的拉起了进程
     */
    public static function kick(): bool
    {
        // 没有待处理任务就不折腾
        if (!(int) Db::name('ls_ai_task')->where('status', 'PENDING')->count()) {
            return false;
        }

        $runtime = app()->getRuntimePath();
        if (!is_dir($runtime)) {
            @mkdir($runtime, 0755, true);
        }

        // 冷却：短时间多次调用（连点定稿、批量章节）只拉一个进程
        $stamp = $runtime . 'ai-work.stamp';
        $last  = is_file($stamp) ? (int) @file_get_contents($stamp) : 0;
        if (time() - $last < self::KICK_COOLDOWN) {
            return false;
        }
        @file_put_contents($stamp, (string) time());

        // 已有 worker 在跑就不重复拉（避免两个进程抢同一批任务）
        if (self::workerAlive()) {
            return false;
        }

        return self::spawn();
    }

    /**
     * 真正拉起 `php think ai:work` 进程（不判重、不判冷却，给 self-reschedule / rescue 用）。
     */
    protected static function spawn(): bool
    {
        if (!function_exists('proc_open')) {
            return false;   // 被 disable_functions 禁掉时静默跳过（可手动跑 php think ai:work）
        }

        $runtime = app()->getRuntimePath();
        if (!is_dir($runtime)) {
            @mkdir($runtime, 0755, true);
        }

        $php = PHP_BINARY ?: 'php';
        $cmd = '"' . $php . '" think ai:work';
        $log = $runtime . 'ai-work.log';

        // 输出重定向到日志文件（用 descriptors 而不是 shell 的 >>，跨平台更稳）
        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes, app()->getRootPath());
        return is_resource($proc);
    }

    /**
     * 公开的直接拉起入口（绕过 workerAlive 守卫），供 ai:work 自我续跑时使用——
     * 此时当前进程即将退出、心跳即将失效，不应被守卫拦截。
     */
    public static function spawnDirect(): bool
    {
        return self::spawn();
    }

    /**
     * worker 是否还活着：靠心跳文件 mtime 判断（跨平台，无需查进程列表）。
     */
    public static function workerAlive(): bool
    {
        $f = app()->getRuntimePath() . 'ai-work.alive';
        if (!is_file($f)) {
            return false;
        }
        return (time() - (int) @filemtime($f)) < self::WORKER_ALIVE_SEC;
    }

    /**
     * 写心跳（由 ai:work 每轮调用）。
     */
    public static function touchHeartbeat(): void
    {
        $f = app()->getRuntimePath() . 'ai-work.alive';
        if (!is_dir(dirname($f))) {
            @mkdir(dirname($f), 0755, true);
        }
        @touch($f);
    }

    /**
     * 全部任务收尾后，把项目状态翻成 DONE。
     *
     * 触发时机：AiJob 每个任务进入终态（SUCCESS / FAILED）后调用；finalize 在无任务可跑时也兜底调用一次。
     * 逻辑：仅当项目处于 MAKING、且该项目已无任何 PENDING/RUNNING/RETRY 任务时，才置 DONE。
     *       ——这样即使有任务还在跑，也不会误翻；多章任务最后一个完成时自然翻 DONE。
     * 失败处理：若有 FAILED 任务，把任务类型记进 finalize_failed（逗号分隔），前端据此提示「部分生成失败，可重试」；
     *       项目仍翻 DONE（拒绝/额度类硬错误重试无意义，回忆录主体已可用）。
     */
    public static function maybeCompleteProject(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        $project = Db::name('ls_project')->where('id', $pid)->field('status')->find();
        if (!$project || ($project['status'] ?? '') !== 'MAKING') {
            return;
        }

        $open = (int) Db::name('ls_ai_task')
            ->where('project_id', $pid)
            ->whereIn('status', ['PENDING', 'RUNNING', 'RETRY'])
            ->count();
        if ($open > 0) {
            return;   // 还有未收尾的任务，先不动
        }

        $failed = Db::name('ls_ai_task')
            ->where('project_id', $pid)
            ->where('status', 'FAILED')
            ->column('task_type');

        Db::name('ls_project')->where('id', $pid)->update([
            'status'         => 'DONE',
            'finalize_failed'=> $failed ? implode(',', array_unique($failed)) : '',
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 兜底收尾：把所有「仍处于 MAKING、但已无任何未终态任务」的项目翻成 DONE。
     *
     * 正常路径下每个任务进入终态都会调 maybeCompleteProject()；这里作为最后一道保险，
     * 由 worker 退出前调用 —— 避免个别完成路径（如任务行不存在被直接 delete）漏调，
     * 导致项目永久卡在 MAKING。幂等：对已 DONE / 仍有未终态任务的项目无副作用。
     *
     * @return int 本次真正翻 DONE 的项目数
     */
    public static function completeStalledProjects(): int
    {
        $ids = Db::name('ls_project')->where('status', 'MAKING')->column('id');
        $n   = 0;
        foreach ($ids as $pid) {
            $before = (string) Db::name('ls_project')->where('id', $pid)->value('status');
            self::maybeCompleteProject((int) $pid);
            $after = (string) Db::name('ls_project')->where('id', $pid)->value('status');
            if ($after !== $before) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * 自愈：修复「worker 中途死亡 / 超时退出」遗留的孤儿任务，并在需要时重新拉起 worker。
     *
     * 这是用户问的「定期监测到已完成定稿、没结束的，再次生成」的核心实现：
     *   - 把卡在 RUNNING/RETRY 且很久没更新的任务视作已死，重置回 PENDING（并清掉残留 jobs 行防重复执行）；
     *   - 【新增】补投「PENDING 但 jobs 表已无对应行」的孤儿任务：入队时 Queue::push 抛异常 /
     *     rescue 重启导致 job 行丢失，这类任务永不消费 → 项目永久卡在 MAKING。60 秒保护窗避免同一次
     *     任务被反复重投。
     *   - 若队列里还有任务（含延迟待执行的）且当前没有活着的 worker，则拉起一个新 worker 继续干；
     *   - 最后把「已无未终态任务」的 MAKING 项目兜底翻 DONE。
     *
     * 可由 ai:work 启动时调用（先自愈再处理），也可由 cron 周期性调用（php think ai:rescue）。
     *
     * @return array ['requeued'=>重置并重投的任务数, 'has_jobs'=>队列剩余任务数, 'kicked'=>是否拉起了 worker, 'completed'=>兜底翻 DONE 的项目数]
     */
    public static function rescue(): array
    {
        $cut = date('Y-m-d H:i:s', time() - self::RESCUE_STUCK_SECONDS);
        $now = time();

        // 取出当前队列里所有 jobs 的 payload 用于精确匹配（避免 like 误删）
        $jobRows = Db::name('jobs')->where('queue', self::QUEUE)->field('id,payload')->select()->toArray();
        $jobByTask = [];
        foreach ($jobRows as $j) {
            $p = json_decode((string) $j['payload'], true) ?: [];
            $tid = (int) (($p['data'] ?? [])['task_id'] ?? 0);
            if ($tid > 0) {
                $jobByTask[$tid][] = (int) $j['id'];
            }
        }

        $requeued = 0;

        // 1) 卡死的 RUNNING/RETRY → 重置回 PENDING，并清理可能残留的 jobs 行（被 kill 时 reserved 的行）。
        //    同时覆盖「RUNNING/RETRY 但 jobs 表已无对应行」的孤儿（被 kill 后 job 行丢失，原逻辑也会漏）。
        $stuck = Db::name('ls_ai_task')
            ->whereIn('status', ['RUNNING', 'RETRY'])
            ->where('updated_at', '<', $cut)
            ->field('id,task_type')
            ->select()
            ->toArray();
        foreach ($stuck as $t) {
            $tid = (int) $t['id'];
            if (!empty($jobByTask[$tid])) {
                Db::name('jobs')->whereIn('id', $jobByTask[$tid])->delete();
            }
            Db::name('ls_ai_task')->where('id', $tid)->update([
                'status'     => 'PENDING',
                'error'      => '',
                'progress'   => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            try {
                Queue::push(\app\queue\AiJob::class, ['task_id' => $tid, 'type' => $t['task_type']], self::QUEUE);
                $requeued++;
            } catch (\Throwable $e) {
                error_log('[AiTaskService] rescue 重投任务 ' . $tid . ' 失败：' . $e->getMessage());
            }
        }

        // 2) 「PENDING 但 jobs 表无对应行」的孤儿：入队时 Queue::push 抛异常 / rescue 重启导致 job 行丢失，
        //    这类任务永远不会被消费 → 项目永久卡在 MAKING。60 秒保护窗避免同一次任务被反复重投。
        $orphans = Db::name('ls_ai_task')
            ->where('status', 'PENDING')
            ->where('updated_at', '<', date('Y-m-d H:i:s', $now - 60))
            ->column('id,task_type', 'id');
        foreach ($orphans as $tid => $type) {
            if (isset($jobByTask[$tid])) {
                continue;   // 已有 job 行，交给队列正常消费，不重复投
            }
            // 刷新保护窗，避免下一轮 rescue 立刻又重投
            Db::name('ls_ai_task')->where('id', $tid)->update(['updated_at' => date('Y-m-d H:i:s')]);
            try {
                Queue::push(\app\queue\AiJob::class, ['task_id' => $tid, 'type' => $type], self::QUEUE);
                $requeued++;
            } catch (\Throwable $e) {
                error_log('[AiTaskService] rescue 补投孤儿任务 ' . $tid . ' 失败：' . $e->getMessage());
            }
        }

        // 3) 队列里还有任务（含延迟待执行的）且没活着的 worker → 拉起
        $hasJobs = (int) Db::name('jobs')->where('queue', self::QUEUE)->count();
        $kicked  = false;
        if ($hasJobs > 0 && !self::workerAlive()) {
            $kicked = self::spawn();
        }

        // 4) 兜底收尾：把「已无未终态任务」的 MAKING 项目翻 DONE（防止个别完成路径漏调）
        $completed = self::completeStalledProjects();

        return ['requeued' => $requeued, 'has_jobs' => $hasJobs, 'kicked' => $kicked, 'completed' => $completed];
    }

    /**
     * 续跑链：返回当前有效链的起点时间戳；若没有链、或链已超预算（CHAIN_BUDGET_SEC），
     * 返回 null（调用方应视为「需要新链」且本次不再续跑旧链）。
     * 不会在超预算时重置，避免无限续跑。
     */
    public static function ensureChain(): ?int
    {
        $f = app()->getRuntimePath() . 'ai-work-chain';
        if (!is_file($f)) {
            return null;
        }
        $start = (int) @file_get_contents($f);
        if ($start <= 0 || (time() - $start) > self::CHAIN_BUDGET_SEC) {
            @unlink($f);   // 旧链作废，不再续跑
            return null;
        }
        return $start;
    }

    /** 开启一条新续跑链，写入起点时间戳，返回该时间戳。 */
    public static function startChain(): int
    {
        $f = app()->getRuntimePath() . 'ai-work-chain';
        $start = time();
        @file_put_contents($f, (string) $start);
        return $start;
    }

    /** 删除续跑链标记（任务全部干完 / 超预算停止时调用）。 */
    public static function clearChain(): void
    {
        $f = app()->getRuntimePath() . 'ai-work-chain';
        if (is_file($f)) {
            @unlink($f);
        }
    }
}
