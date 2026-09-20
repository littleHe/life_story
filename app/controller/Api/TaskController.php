<?php
namespace app\controller\Api;

use app\BaseController;
use think\facade\Db;

class TaskController extends BaseController
{
    /** 查询 AI 任务进度 */
    public function show($taskId)
    {
        $task = Db::name('ls_ai_task')
            ->where('id', $taskId)
            ->where('user_id', $this->uid())
            ->find();
        return $this->ok($task ?: (object) []);
    }
}
