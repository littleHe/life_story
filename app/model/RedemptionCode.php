<?php
namespace app\model;

use think\Model;

class RedemptionCode extends Model
{
    protected $table = 'ls_redemption_code';
    protected $pk    = 'id';
    protected $type  = [
        'bound_project_id' => 'integer',
        'bound_user_id'    => 'integer',
        'generated_by'     => 'integer',
    ];
}
