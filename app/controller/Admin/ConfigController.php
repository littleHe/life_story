<?php
namespace app\controller\Admin;

use app\service\SiteConfigService;
use think\facade\Db;

/**
 * 站点参数设置（键值白名单）：自动翻页间隔、预览尾页文案、公司信息等
 */
class ConfigController extends AdminBase
{
    /** 允许后台维护的参数（白名单 + 默认值 + 说明） */
    private array $defs = [
        'app_name'                  => ['人生回忆录', '站点名称（浏览器标题）'],
        'preview_auto_flip_seconds' => ['30', '翻书预览自动翻页间隔（秒），0=关闭'],
        'preview_end_text'          => [SiteConfigService::DEFAULT_PREVIEW_END_TEXT, '翻书预览尾页结束文案（首行为大标题，其余为正文；留空则不显示尾页）'],
        'company_name'              => ['',  '公司名称'],
        'company_email'             => ['',  '公司邮箱'],
        'service_phone'             => ['',  '客服电话'],
        'company_address'           => ['',  '联系地址'],
    ];

    /** GET /admin-api/config/index */
    public function index()
    {
        $rows = Db::name('ls_config')->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['config_key']] = $r['config_value'];
        }
        $list = [];
        foreach ($this->defs as $key => [$default, $label]) {
            // 注意：白名单里的键未必都已在库中（如 app_name 早期漏种）。
            // PHP 8 下裸取 $map[$key] 会触发 Undefined array key warning，
            // 被 ThinkPHP 的错误处理升级成 ErrorException → 整个接口 500。
            $v = $map[$key] ?? null;
            $list[] = [
                'key'    => $key,
                'label'  => $label,
                'value'  => ($v !== null && $v !== '') ? (string) $v : $default,
            ];
        }
        return $this->ok(['list' => $list]);
    }

    /** POST /admin-api/config/save  body: { config: { key: value, ... } } */
    public function save()
    {
        $data = (array) $this->request->post('config/a', []);
        $saved = 0;
        foreach ($data as $key => $value) {
            if (!isset($this->defs[$key])) {
                continue; // 白名单外直接忽略
            }
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($key === 'preview_auto_flip_seconds' && $value !== '' && ((int) $value < 0 || (int) $value > 3600)) {
                return $this->fail('自动翻页间隔需在 0-3600 秒之间');
            }
            if ($key === 'preview_end_text' && mb_strlen($value, 'UTF-8') > 300) {
                return $this->fail('尾页结束文案请控制在 300 字以内');
            }
            $exist = Db::name('ls_config')->where('config_key', $key)->find();
            if ($exist) {
                Db::name('ls_config')->where('config_key', $key)->update([
                    'config_value' => $value,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            } else {
                Db::name('ls_config')->insert([
                    'config_key'   => $key,
                    'config_value' => $value,
                    'remark'       => $this->defs[$key][1],
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }
            $saved++;
        }
        $this->audit('config/save', $data);
        return $this->ok(['saved' => $saved], '保存成功');
    }
}
