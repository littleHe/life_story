<?php
namespace app\model;

use think\Model;

class SecurityLockLog extends Model
{
    protected $table = 'ls_security_lock_log';
    protected $pk    = 'id';
    protected $type  = [
        'user_id' => 'integer',
        'ttl_sec' => 'integer',
    ];
}
