<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\service\AiTaskService;

/**
 * `php think ai:rescue` —— 定期监测并修复卡住的定稿任务（建议配 cron，如每 5 分钟一次）
 *
 * 解决用户关心的「已完成定稿但任务中断/失败，没人再管」问题：
 *   - 重置被 worker kill / 超时遗留的 RUNNING/RETRY 孤儿任务；
 *   - 若队列里还有任务（含延迟待执行的）且当前没有活着的 worker，则重新拉起 ai:work 续跑；
 *   - 不影响正在正常运行的 worker。
 *
 * 装在 cron 里即可形成「跨进程兜底」：即使 ai:work 因超预算停止，下一轮 rescue 也会继续。
 */
class AiRescue extends Command
{
    protected function configure()
    {
        $this->setName('ai:rescue')
            ->setDescription('修复卡住的定稿任务：重置孤儿任务、拉起 worker（可配 cron 定期执行）');
    }

    protected function execute(Input $input, Output $output)
    {
        $r = AiTaskService::rescue();
        $output->writeln(sprintf(
            '[ai:rescue] 重置重试任务=%d 队列剩余任务=%d 已拉起worker=%s',
            $r['requeued'],
            $r['has_jobs'],
            $r['kicked'] ? '是' : '否'
        ));
        return 0;
    }
}
