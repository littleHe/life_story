<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\queue\Worker;
use app\service\AiTaskService;

/**
 * `php think ai:work` —— 把 ai 队列里的活干完就退出（**不需要常驻 worker**）
 *
 * 为什么不用 `queue:work`：
 *   - `queue:work`         → 走 Worker::daemon()，**永不退出**，得常驻 + supervisor 守护；
 *   - `queue:work --once`  → 只跑一个任务就退，但配图任务要「release 后隔 15 秒再来问」，
 *                            一次性调用覆盖不了多轮轮询。
 * 所以这里基于同一个 `think\queue\Worker`（复用它的 attempts / available_at / failed 语义）
 * 做一个小循环：队列空了就退出，超时兜底退出。
 *
 * 健壮性增强（解决「定稿后任务中断/超时后孤儿任务无人接管」）：
 *   - 启动时先 AiTaskService::rescue() 自愈卡死任务，再开始处理；
 *   - 每轮写心跳文件，spawn 时据此避免重复拉起；
 *   - 退出时若队列里还有任务（含延迟待执行的），在 30 分钟链预算内自我续跑——
 *     即拉起一个新的 ai:work 继续干，直到队列真正清空或超预算（超预算后交给定时 ai:rescue 兜底）。
 *
 * 一般由 `AiTaskService::kick()` 在后台自动拉起；也可手动执行（前台看输出）：
 *   php think ai:work                 # 干完退出，最长 600 秒
 *   php think ai:work --max-seconds=1800
 */
class AiWork extends Command
{
    protected function configure()
    {
        $this->setName('ai:work')
            ->addOption('max-seconds', null, Option::VALUE_OPTIONAL, '最长运行秒数（兜底，避免卡死）', 600)
            ->addOption('sleep', null, Option::VALUE_OPTIONAL, '队列为空时的轮询间隔（秒）', 2)
            ->addOption('tries', null, Option::VALUE_OPTIONAL, '单任务最大尝试次数', 0)
            ->setDescription('把 ai 队列的活干完自动退出（无需常驻 worker）');
    }

    protected function execute(Input $input, Output $output)
    {
        // 先写心跳，rescue() 的 spawn 守卫才不会误判「没有 worker」而重复拉起
        AiTaskService::touchHeartbeat();

        // 启动自愈：重置被上次运行 kill/超时遗留的孤儿任务
        $rescued = AiTaskService::rescue();
        if ($rescued['requeued'] > 0 || $rescued['kicked']) {
            $output->writeln('[ai:work] 自愈：重置任务=' . $rescued['requeued'] . ' 拉起worker=' . ($rescued['kicked'] ? '是' : '否'));
        }

        // 续跑链起算（一次定稿最多跑 CHAIN_BUDGET_SEC；超预算的旧链在此作废）
        $start = AiTaskService::ensureChain();
        if ($start === null) {
            $start = AiTaskService::startChain();
        }

        $worker  = $this->app->make(Worker::class);
        $conn    = 'database';
        $queue   = 'ai';
        $maxSec  = max(5, (int) $input->getOption('max-seconds'));
        $sleep   = max(1, (int) $input->getOption('sleep'));
        $tries   = (int) $input->getOption('tries');

        $handled = 0;
        $idle    = 0;

        $output->writeln('[ai:work] 开始，队列=' . $queue . '（最长 ' . $maxSec . 's）');

        while (true) {
            AiTaskService::touchHeartbeat();   // 每轮续命，供 spawn 守卫判断

            $pending = (int) Db::name('jobs')->where('queue', $queue)->count();

            if ($pending === 0) {
                // 队列空：再确认一次就退出（避免刚好在两次写入之间误判）
                if (++$idle >= 2) {
                    $output->writeln('[ai:work] 队列已空，退出（处理 ' . $handled . ' 次，用时 ' . (time() - $start) . 's）');
                    break;
                }
                sleep($sleep);
                continue;
            }
            $idle = 0;

            if (time() - $start > $maxSec) {
                $output->writeln('[ai:work] 超时退出（还有 ' . $pending . ' 个待处理，用时 ' . (time() - $start) . 's）');
                break;
            }

            // 队列里还有「延后重试」的任务时，runNextJob 取不到会自己 sleep，
            // 所以这里不需要额外的等待逻辑。
            $worker->runNextJob($conn, $queue, 0, $sleep, $tries);
            $handled++;

            if ($worker->shouldQuit || $worker->paused) {
                $output->writeln('[ai:work] 收到停止信号，退出');
                break;
            }
        }

        // 退出前兜底收尾：把「任务全跑完但仍停在 MAKING」的项目翻 DONE（防个别完成路径漏调）
        $closed = AiTaskService::completeStalledProjects();
        if ($closed > 0) {
            $output->writeln('[ai:work] 收尾：' . $closed . ' 个项目已翻 DONE');
        }

        // 退出前再确认一次队列是否还有任务（含延迟待执行的）：有则自我续跑（受链预算约束）
        $remaining = (int) Db::name('jobs')->where('queue', $queue)->count();
        if ($remaining > 0 && (time() - $start) < AiTaskService::CHAIN_BUDGET_SEC) {
            // 清掉心跳再 spawn，避免 spawn 守卫误以为自己还活着
            $alive = app()->getRuntimePath() . 'ai-work.alive';
            if (is_file($alive)) {
                @unlink($alive);
            }
            $ok = AiTaskService::spawnDirect();
            $output->writeln('[ai:work] 队列仍有 ' . $remaining . ' 个任务，自我续跑=' . ($ok ? '已拉起' : '失败'));
        } else {
            // 真正干完了 / 超预算：清理链标记
            AiTaskService::clearChain();
            $alive = app()->getRuntimePath() . 'ai-work.alive';
            if (is_file($alive)) {
                @unlink($alive);
            }
        }

        return 0;
    }
}
