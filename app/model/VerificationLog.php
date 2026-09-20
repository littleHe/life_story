<?php
namespace app\model;

use think\Model;

class VerificationLog extends Model
{
    protected $table = 'ls_verification_log';
    protected $pk    = 'id';
    protected $type  = [
        'code_id'     => 'integer',
        'user_id'     => 'integer',
        'project_id'  => 'integer',
    ];
}
