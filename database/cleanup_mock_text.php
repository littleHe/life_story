<?php
// 清理章节文本中的历史 mock 前缀（曾由 mock 转写/润色写入，成书文本不应带这些标记）
// 用法：php backend/database/cleanup_mock_text.php [--dry]
$dry = in_array('--dry', $argv, true);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=life_story;charset=utf8mb4', 'root', 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$patterns = [
    '/^（AI\s*模拟(?:润色|语音转写)）/u',
    '/^关于「[^」]{0,50}」的口述内容[：:]\s*/u',
];

function clean(string $t, array $patterns): string
{
    $out = trim($t);
    do {
        $prev = $out;
        foreach ($patterns as $p) {
            $out = preg_replace($p, '', $out);
        }
        $out = trim($out);
    } while ($out !== $prev);
    return $out;
}

$rows = $pdo->query("SELECT id, meta FROM ls_chapter_asset WHERE asset_type IN ('TRANSCRIPT','POLISHED_TEXT')")->fetchAll(PDO::FETCH_ASSOC);
$fixed = 0;
foreach ($rows as $r) {
    $meta = json_decode((string) $r['meta'], true);
    if (!is_array($meta) || !isset($meta['text'])) {
        continue;
    }
    $text = (string) $meta['text'];
    $new = clean($text, $patterns);
    if ($new === $text) {
        continue;
    }
    echo "asset {$r['id']}: [{$text}] => [{$new}]\n";
    if (!$dry) {
        $meta['text'] = $new;
        $st = $pdo->prepare('UPDATE ls_chapter_asset SET meta = ? WHERE id = ?');
        $st->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $r['id']]);
    }
    $fixed++;
}
echo ($dry ? '[dry-run] ' : '') . "cleaned {$fixed} / " . count($rows) . " assets\n";
