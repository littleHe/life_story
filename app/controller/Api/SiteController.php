<?php
namespace app\controller\Api;

use app\BaseController;
use app\service\SiteConfigService;

/**
 * 公开站点信息（无需登录）：浏览器标题、公司信息等
 */
class SiteController extends BaseController
{
    /** GET /api/site/config */
    public function config()
    {
        return $this->ok([
            'app_name'        => SiteConfigService::get('app_name', '人生回忆录'),
            'company_name'    => SiteConfigService::get('company_name', ''),
            'company_email'   => SiteConfigService::get('company_email', ''),
            'service_phone'   => SiteConfigService::get('service_phone', ''),
            'company_address' => SiteConfigService::get('company_address', ''),
        ]);
    }
}
