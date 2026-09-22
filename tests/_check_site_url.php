<?php
/**
 * SiteUrlService 逻辑自检（不依赖框架启动，用 env() 桩）
 * 运行：php tests/_check_site_url.php
 */

// --- env() 桩：读真实 .env 的值 ---
$ENVV = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $ENVV[trim($k)] = trim($v);
}
function env($k, $d = null) { global $ENVV; return array_key_exists($k, $ENVV) ? $ENVV[$k] : $d; }

require __DIR__ . '/../app/service/SiteUrlService.php';
use app\service\SiteUrlService;

$fail = 0;
function check(string $name, $got, $want) {
    global $fail;
    $ok = $got === $want;
    if (!$ok) $fail++;
    echo ($ok ? '  ✅ ' : '  ❌ ') . str_pad($name, 46) . ' => ' . var_export($got, true) . ($ok ? '' : '   期望: ' . var_export($want, true)) . PHP_EOL;
}

$APPURL = trim((string) env('APP_URL', ''));
// 可选：`php tests/_check_site_url.php https://域名` 模拟线上已配 APP_URL 的场景
if (!empty($argv[1])) {
    $ENVV['APP_URL'] = $APPURL = $argv[1];
}
echo "============================================================\n";
echo " SiteUrlService 自检   APP_URL=" . ($APPURL === '' ? '<空，将回落请求域名>' : $APPURL) . "\n";
echo "============================================================\n";

$TOK = '98ab34a6d0af7e34f02f8a1e';
$REQ = 'http://127.0.0.1:9411';

echo "【1】本机地址识别\n";
check('isLocal(127.0.0.1:9411)', SiteUrlService::isLocal("http://127.0.0.1:9411/preview/x"), true);
check('isLocal(localhost)',       SiteUrlService::isLocal('http://localhost:8001/preview/x'), true);
check('isLocal(真实域名)',         SiteUrlService::isLocal("https://hsm.example.com/preview/x"), false);
check('isLocal(空)',              SiteUrlService::isLocal(''), false);

// 期望基准：配了 APP_URL 就用它，否则用下方传的请求域名
$EXPBASE = $APPURL !== '' ? rtrim($APPURL, '/') : 'https://hsm.example.com';

echo "\n【2】自愈：库里的本机地址 -> 当前站点\n";
$healed = SiteUrlService::healPreview("http://127.0.0.1:9411/preview/$TOK", $TOK, 'https://hsm.example.com');
check('本机地址被替换', $healed, "$EXPBASE/preview/$TOK");
check('真实域名原样保留', SiteUrlService::healPreview("https://cdn.example.com/preview/$TOK", $TOK, 'https://hsm.example.com'), "https://cdn.example.com/preview/$TOK");
check('空值 + 有 token -> 生成', SiteUrlService::healPreview('', $TOK, 'https://hsm.example.com'), "$EXPBASE/preview/$TOK");
check('空值 + 无 token -> 空', SiteUrlService::healPreview('', '', 'https://hsm.example.com'), '');
check('无 token 的本机地址 -> 只换域名', SiteUrlService::healPreview('http://127.0.0.1:9411/preview/abc', '', 'https://hsm.example.com'), "$EXPBASE/preview/abc");

echo "\n【3】生成\n";
$gen = SiteUrlService::preview($TOK, $REQ);
if ($APPURL !== '') {
    check('生成用 APP_URL 优先', $gen, "$EXPBASE/preview/$TOK");
    check('APP_URL 优先于请求域名', SiteUrlService::preview($TOK, 'http://127.0.0.1:9999'), "$EXPBASE/preview/$TOK");
} else {
    check('未配 APP_URL -> 回落请求域名', $gen, "http://127.0.0.1:9411/preview/$TOK");
}
check('token 非法字符被清洗', SiteUrlService::preview('98ab-34a6/d0af', 'https://hsm.example.com'), "$EXPBASE/preview/98ab34a6d0af");
check('token 全非法 -> 空', SiteUrlService::preview('!!!', 'https://hsm.example.com'), '');

echo "\n============================================================\n";
echo $fail === 0 ? " 全部通过 ✅\n" : " 失败 $fail 项 ❌\n";
echo "============================================================\n";
exit($fail === 0 ? 0 : 1);
