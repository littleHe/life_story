<?php
namespace app\model;

use think\Model;

class ChapterAsset extends Model
{
    protected $table = 'ls_chapter_asset';
    protected $pk    = 'id';
    protected $type  = [
        'chapter_id' => 'integer',
    ];
    // JSON 字段
    protected $json = ['meta'];
}
