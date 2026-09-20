<?php
namespace app\model;

use think\Model;

class User extends Model
{
    protected $table = 'ls_user';
    protected $pk    = 'id';
    protected $type  = [
        'role'   => 'integer',
        'status' => 'integer',
    ];
}
