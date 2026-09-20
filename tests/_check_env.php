<?php
/**
 * .env 自检（很重要，30 秒排掉一大类「配置明明填了却不生效」）
 *
 * 背景：ThinkPHP 用 PHP 原生 parse_ini_file 解析 .env，**只要有一行语法不被接受，
 * 整个文件都会被丢掉（返回 false）**，所有环境变量静默回落到默认值——
 * 症状是「AI 一直走 mock」「数据库连的好像是别的库」「JWT 变了」，但页面上完全看不出报错。
 *
 * 已知最容易踩的坑：**注释行或值里出现 INI 保留字符** → 整份 .env 解析失败。
 * 实测会让 parse_ini_file 直接认输的一组半角字符：  | ( ) { } & ~ ! [ ^ " $
 * （中文全角形式或改写成文字描述即可避开；注意 .env 里的中文括号是安全的）
 * 其次：引号未闭合、键名带空格。
 *
 * 用法：php tests/_check_env.php
 */

$root    = dirname(__DIR__);
$envFile = $root . '/.env';

require $root . '/vendor/autoload.php';

$line = str_repeat('=', 64);
echo "\n{$line}\n .env 自检\n{$line}\n";

if (!is_file($envFile)) {
    echo "❌ 未找到 {$envFile}（可复制 .env.example 起步）\n\n";
    exit(1);
}

// ---- 1) 整份文件能否解析 ----
$parsed = parse_ini_file($envFile, true, INI_SCANNER_RAW);
if ($parsed === false) {
    echo "❌ .env 解析失败（parse_ini_file 返回 false）：所有环境变量都会丢失！\n\n";
    // 逐行二分定位第一处出问题的行
    $lines = file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
    $keep  = [];
    foreach ($lines as $i => $ln) {
        $keep[] = $ln;
        $tmp = tempnam(sys_get_temp_dir(), 'envchk');
        file_put_contents($tmp, implode(PHP_EOL, $keep));
        $ok = parse_ini_file($tmp, true, INI_SCANNER_RAW) !== false;
        @unlink($tmp);
        if (!$ok) {
            $no = $i + 1;
            echo "  第 {$no} 行是首个出问题的行：\n    {$ln}\n\n";
            $bad = [];
            foreach (['|', '(', ')', '{', '}', '&', '~', '!', '[', '^', '"', '$'] as $ch) {
                if (strpos($ln, $ch) !== false) {
                    $bad[] = $ch;
                }
            }
            if ($bad) {
                echo '  → 该行含 INI 保留字符：' . implode('  ', $bad) . "\n";
                echo '     PHP 的 parse_ini_file 碰到这组半角字符会直接放弃整份文件（实测：| ( ) { } & ~ ! [ ^ " $）。' . "\n";
                echo '     改法：换成全角写法或改写成文字描述。' . "\n\n";
            } else {
                echo "  → 该行未见保留字符：请检查引号是否成对、键名是否带空格。\n\n";
            }
            break;
        }
    }
    echo "  修好后重跑：php tests/_check_env.php\n\n";
    exit(1);
}
echo "✅ 语法正常，共 " . count($parsed[''] ?? $parsed) . " 个键\n";

// ---- 2) 应用实际读到的值（经过 config() 之后）----
$app = new \think\App($root);
$app->initialize();

use think\facade\Config;

$mask = static function ($v): string {
    $s = (string) $v;
    if ($s === '') {
        return '(空)';
    }
    return strlen($s) <= 10 ? $s : substr($s, 0, 4) . str_repeat('*', 6) . substr($s, -4);
};

$rows = [
    'AI_MOCK（AI 总开关）'        => var_export(Config::get('ai.mock'), true),
    'ai.asr.driver'              => (string) Config::get('ai.asr.driver'),
    '腾讯 AppID'                  => (string) Config::get('ai.asr.tencent.appid'),
    '腾讯 SecretId'               => $mask(Config::get('ai.asr.tencent.secret_id')),
    '腾讯 SecretKey'              => $mask(Config::get('ai.asr.tencent.secret_key')),
    '腾讯 engine'                 => (string) Config::get('ai.asr.tencent.engine'),
    '引导 LLM api'                => (string) Config::get('ai.guidance.api'),
    '引导 LLM key'                => $mask(Config::get('ai.guidance.key')),
    'DB host/name'               => (string) Config::get('database.connections.mysql.hostname') . ' / ' . (string) Config::get('database.connections.mysql.database'),
    'DB user'                    => (string) Config::get('database.connections.mysql.username'),
    'JWT secret'                 => $mask(Config::get('jwt.secret')),
    'sess/jwt 是否来自 .env'      => (Config::get('jwt.secret') === 'd636c165b0e2bbc73de10200d06a4bda' ? '.env 值已生效' : '⚠️ 非 .env 中的值，请检查'),
];

echo "\n{$line}\n config() 实际读到的值\n{$line}\n";
foreach ($rows as $k => $v) {
    echo ' ' . str_pad($k, 26) . ': ' . $v . "\n";
}
echo "\n";
