<?php
namespace app\service;

use think\facade\Db;

/**
 * 站点参数读取（后台「参数设置」维护，见 ls_config 表）
 */
class SiteConfigService
{
    /** 翻书预览尾页「结束文案」默认值（后台参数设置可改；留空则不显示尾页） */
    public const DEFAULT_PREVIEW_END_TEXT = "感谢欣赏\n一页页翻过的是岁月，留下的是牵挂。愿这段记忆，长暖人心。";

    /** 读取单个参数；未配置时返回默认值 */
    public static function get(string $key, string $default = ''): string
    {
        try {
            $row = Db::name('ls_config')->where('config_key', $key)->find();
            if ($row && $row['config_value'] !== null && $row['config_value'] !== '') {
                return (string) $row['config_value'];
            }
        } catch (\Throwable $e) {
            // 表不存在等异常时回退默认值，不影响预览页渲染
        }
        return $default;
    }

    /** 批量读取 */
    public static function all(): array
    {
        try {
            $rows = Db::name('ls_config')->select()->toArray();
            $map = [];
            foreach ($rows as $r) {
                $map[$r['config_key']] = (string) $r['config_value'];
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
