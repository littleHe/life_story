<?php
namespace app\model;

use think\Model;

class AiTask extends Model
{
    protected $table = 'ls_ai_task';
    protected $pk    = 'id';
    protected $type  = [
        'user_id'    => 'integer',
        'project_id' => 'integer',
        'chapter_id' => 'integer',
        'progress'   => 'integer',
    ];
    protected $json = ['payload', 'result'];
}
