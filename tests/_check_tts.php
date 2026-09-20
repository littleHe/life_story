<?php
/**
 * 章节朗读配音自检（腾讯云语音合成 TTS）
 *
 * 用法：
 *   php tests/_check_tts.php            # 用配置里的音色合成一句，落盘到 uploads/tts
 *   php tests/_check_tts.php --voices   # 探测一批常见音色编号，列出哪些可用（便于挑音色 / 排查音色 ID）
 *
 * 覆盖链路：文案 → TC3 签名 → TextToVoice → mp3（超长自动切段拼接）→ 落盘 → 返回可访问 URL
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;
use app\service\TencentTtsService;

$cfg = (array) config('ai.tts.tencent', []);
$mask = static function (string $s): string {
    return $s === '' ? '(未配置)' : substr($s, 0, 6) . '******' . substr($s, -4);
};

echo "\n================================================================\n";
echo " 章节朗读配音自检 · 腾讯云语音合成 TTS\n";
echo "================================================================\n";
echo ' driver        : ' . (string) config('ai.tts.driver') . "\n";
echo ' endpoint      : ' . (string) ($cfg['endpoint'] ?? '') . ' / ' . (string) ($cfg['version'] ?? '') . "\n";
echo ' SecretId      : ' . $mask((string) ($cfg['secret_id'] ?? '')) . "\n";
echo ' SecretKey     : ' . $mask((string) ($cfg['secret_key'] ?? '')) . "\n";
echo ' VoiceType     : ' . (int) ($cfg['voice_type'] ?? 0) . '  Codec: ' . (string) ($cfg['codec'] ?? '') . "\n";
echo ' isMock(tts)   : ' . (AiGatewayService::isMock('tts') ? 'true → 未配置，不会调用' : 'false → 真实调用 TTS') . "\n";

$sample = '一九五三年，我七岁，第一次走进村里的小学。先生姓陈，手里总拿着一根戒尺。';
echo "\n① 分段检查（单次合成约 150 字上限，超长自动切段）\n";
$chunks = TencentTtsService::split(str_repeat($sample, 6));
echo '   输入 ' . mb_strlen(str_repeat($sample, 6)) . ' 字 → 切成 ' . count($chunks) . " 段：\n";
foreach ($chunks as $i => $c) {
    echo '   [' . ($i + 1) . '] ' . mb_strlen($c) . ' 字：' . mb_substr($c, 0, 24) . "…\n";
}

if (in_array('--voices', ($argv ?? []), true)) {
    echo "\n② 探测常见音色编号（每个 1 次极短合成）\n";
    $cands = [1001, 1002, 1003, 1004, 1005, 1006, 1007, 1008, 1010, 1015, 1016, 1017, 1050, 101001, 101002];
    foreach ($cands as $v) {
        \think\facade\Config::set(['voice_type' => $v], 'ai.tts.tencent');
        try {
            $u = TencentTtsService::synthesize('你好。', 'probe' . $v);
            echo "   ✅ {$v} 可用 → {$u}\n";
        } catch (\Throwable $e) {
            echo "   ❌ {$v}：" . mb_substr((string) $e->getMessage(), 0, 70) . "\n";
            if (stripos($e->getMessage(), 'ResourceUnavailable') !== false
                || stripos($e->getMessage(), 'AuthFailure') !== false
                || stripos($e->getMessage(), 'PkgExhausted') !== false) {
                echo "   → 先解决「未开通 / 鉴权 / 资源包额度」再探测音色，后续编号跳过。\n";
                break;
            }
        }
    }
    echo "\n（挑好音色后，把编号填进 .env 的 AI_TTS_TENCENT_VOICE_TYPE）\n\n";
    exit(0);
}

echo "\n② 合成示例音频\n";
$t0 = microtime(true);
try {
    $url = TencentTtsService::synthesize($sample, 'check');
    $abs = dirname(__DIR__) . '/public' . $url;
    printf("   ✅ 完成，耗时 %d 秒\n   URL : %s\n   文件: %s（%s KB）\n",
        (int) round(microtime(true) - $t0), $url, $abs,
        is_file($abs) ? round(filesize($abs) / 1024, 1) : '?');
    echo "\n   预览页的「AI 润声」会优先播放该章 AUDIO_DUBBED 资源；\n";
    echo "   端到端验证：POST /api/chapters/:id/dub （登录态）\n\n";
} catch (\Throwable $e) {
    echo '   ❌ ' . $e->getMessage() . "\n\n";
    exit(1);
}
