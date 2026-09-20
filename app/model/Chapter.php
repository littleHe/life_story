<?php
namespace app\model;

use think\Model;

class Chapter extends Model
{
    protected $table = 'ls_chapter';
    protected $pk    = 'id';
    protected $type  = [
        'project_id' => 'integer',
        'sort'       => 'integer',
    ];
}
