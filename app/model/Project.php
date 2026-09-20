<?php
namespace app\model;

use think\Model;

class Project extends Model
{
    protected $table = 'ls_project';
    protected $pk    = 'id';
    protected $type  = [
        'user_id'        => 'integer',
        'chapter_locked' => 'integer',
    ];
}
