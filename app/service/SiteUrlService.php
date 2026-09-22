<?php
namespace app\service;

/**
 * 站点绝对地址工具：统一生成 / 自愈「预览地址」这类写库的绝对链接。
 *
 * 背景（实踩）：定稿接口早期用 `$request->domain()` 生成 preview_url 并**存进库**，
 * 本地开发时请求域名是 http://127.0.0.1:9411，于是这个本机地址被永久写进
 * ls_project.preview_url；数据导到线上后，后台「预览」自然打不开。
 *
 * 两条对策：
 *  1) 生成时优先取 .env 的 APP_URL（站点正式域名），没配才回落当前请求域名；
 *  2) 展示时把库里的「回环/本机」地址自愈成当前站点地址，历史脏数据无需改库即可打开。
 */
class SiteUrlService
{
    /** 回环 / 本机主机名：写进库就是 bug，展示时一律替换 */
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '0.0.0.0', '::1'];

    /** 站点根地址（不带结尾斜杠）：优先 APP_URL，其次当前请求域名 */
    public static function base(string $requestDomain = ''): string
    {
        $appUrl = trim((string) env('APP_URL', ''));
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }
        return rtrim($requestDomain, '/');
    }

    /** 生成预览地址 */
    public static function preview(string $token, string $requestDomain = ''): string
    {
        $token = preg_replace('/[^a-f0-9]/i', '', $token);
        if ($token === '') {
            return '';
        }
        return self::base($requestDomain) . '/preview/' . $token;
    }

    /** 是否为回环/本机地址 */
    public static function isLocal(?string $url): bool
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        return $host !== '' && in_array($host, self::LOCAL_HOSTS, true);
    }

    /**
     * 自愈预览地址：
     *  - 库里的值是回环/本机地址（或为空）→ 用 token 重新生成；
     *  - 有 token 时始终信任 token（保证与当前库中的 preview_token 一致）；
     *  - 否则原样返回（尊重后台手工填的正式域名）。
     */
    public static function healPreview(?string $stored, string $token = '', string $requestDomain = ''): string
    {
        $stored = trim((string) $stored);
        $token  = preg_replace('/[^a-f0-9]/i', '', (string) $token);

        $needRebuild = $stored === '' || self::isLocal($stored);
        if (!$needRebuild) {
            return $stored;
        }
        if ($token !== '') {
            return self::preview($token, $requestDomain);
        }
        if ($stored === '') {
            return '';
        }
        // 无 token 兜底：只把域名换掉，保留原路径
        $path = (string) parse_url($stored, PHP_URL_PATH);
        return self::base($requestDomain) . ($path !== '' ? $path : '');
    }
}
