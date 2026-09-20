<?php
namespace app\model;

use think\Model;

class VoiceProfile extends Model
{
    protected $table = 'ls_voice_profile';
    protected $pk    = 'id';
    protected $type  = [
        'user_id' => 'integer',
        'cloned'  => 'integer',
        'consent' => 'integer',
    ];
}
