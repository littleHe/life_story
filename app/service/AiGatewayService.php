<?php
namespace app\service;

use app\common\exception\ApiException;

/**
 * AI 能力网关（通用可配置适配器）
 *
 * 定位：把「语音转写(ASR)」「文本润色(LLM)」两类 AI 能力统一收口到本服务。
 * 约定：
 *   - 配置齐全（config/ai.php 的 api 非空且 AI_MOCK=false）→ 走第三方 HTTP 接口；
 *   - 未配置 → 自动回退 mock，保证零配置也能跑通完整业务流程；
 *   - 第三方返回文本的解析支持 data.text / result / transcript / choices[0].message.content 等常见结构，
 *     也支持用 AI_ASR_TEXT_PATH / AI_LLM_TEXT_PATH 指定精确路径（如 data.result.text）。
 *
 * 之所以不写死某一家云厂商：需求方尚未确定服务商，先提供可配置的通用适配器，
 * 定好服务商后只需在 .env 填 AI_ASR_API / AI_ASR_KEY / AI_ASR_MODEL（及对应 LLM 变量）即可切换。
 */
class AiGatewayService
{
    /** 是否处于 mock 模式：总开关开启，或该通道未配置齐全 */
    public static function isMock(string $channel): bool
    {
        if (config('ai.mock')) {
            return true;
        }
        // ASR 用腾讯驱动时，凭据看 appid/secret_key，与通用通道的 api 无关
        if ($channel === 'asr' && (string) config('ai.asr.driver', 'generic') === 'tencent') {
            $t = (array) config('ai.asr.tencent', []);
            return trim((string) ($t['appid'] ?? '')) === '' || trim((string) ($t['secret_key'] ?? '')) === '';
        }
        // 配图用腾讯混元生图时，凭据看腾讯云密钥
        if ($channel === 'image' && (string) config('ai.image.driver', 'tencent_hunyuan') === 'tencent_hunyuan') {
            return !TencentImageService::configured();
        }
        // 语音合成走腾讯 TTS 时，凭据看腾讯云密钥
        if ($channel === 'tts' && (string) config('ai.tts.driver', 'tencent') === 'tencent') {
            return !TencentTtsService::configured();
        }
        return (string) config("ai.{$channel}.api", '') === '';
    }

    /**
     * 语音识别：读取本地音频文件，返回识别文字。
     *
     * @param string $absolutePath 音频文件绝对路径
     * @param string $ext          扩展名（webm/m4a/ogg/mp3/wav…）
     * @param string $title        章节标题（仅 mock 文案使用）
     */
    public static function asr(string $absolutePath, string $ext = 'webm', string $title = ''): string
    {
        if (self::isMock('asr')) {
            return self::mockAsr($title, $absolutePath);
        }

        $cfg = config('ai.asr');

        // ---- 腾讯云「录音文件识别极速版」：同步返回，一次 HTTP 搞定 ----
        if ((string) ($cfg['driver'] ?? 'generic') === 'tencent') {
            $src = self::prepareForTencent($absolutePath, $ext);
            try {
                return TencentAsrService::recognize($src['path'], $src['format']);
            } finally {
                if (!empty($src['tmp']) && is_file($src['path'])) {
                    @unlink($src['path']);
                }
            }
        }

        // ---- 通用 JSON 协议（任意 OpenAI 兼容 / 自建网关）----
        $bin = @file_get_contents($absolutePath);
        if ($bin === false || $bin === '') {
            throw new ApiException(50002, '录音文件读取失败', 500);
        }

        $raw = self::post(
            (string) $cfg['api'],
            (string) $cfg['key'],
            [
                'model'        => (string) ($cfg['model'] ?: 'asr'),
                'format'       => $ext,
                'audio_ext'    => $ext,
                'audio_base64' => base64_encode($bin),
            ],
            (int) ($cfg['timeout'] ?: 30)
        );

        $text = self::extractText($raw, (string) $cfg['text_path']);
        if (trim($text) === '') {
            throw new ApiException(50003, '第三方语音识别未返回有效文本', 502);
        }
        return trim($text);
    }

    /**
     * 腾讯通道的音频准备：容器受支持则原样送；否则（典型是浏览器默认的 webm）
     * 若本机有 ffmpeg 就转成 16k 单声道 wav，再送腾讯。
     *
     * @return array{path:string,format:string,tmp:bool}
     */
    private static function prepareForTencent(string $absolutePath, string $ext): array
    {
        $ext = strtolower($ext);
        if (TencentAsrService::supportsExt($ext)) {
            return ['path' => $absolutePath, 'format' => $ext, 'tmp' => false];
        }

        $ffmpeg = self::ffmpegPath();
        if ($ffmpeg === '') {
            throw new ApiException(
                50006,
                "腾讯云 ASR 不支持 {$ext} 格式，且本机未找到 ffmpeg。"
                . '解决方式：① 用支持 audio/mp4 的浏览器重新录制（新版 Chrome/微信/Safari 已是 m4a）；'
                . '② 安装 ffmpeg 或设置 AI_ASR_FFMPEG 指定路径，后端会自动转码',
                422
            );
        }

        $tmp = dirname(__DIR__, 2) . '/runtime/tmp/asr_' . uniqid() . '.wav';
        $dir = dirname($tmp);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // -ar 16000 -ac 1：腾讯所有引擎都吃的规格，同时显著减小体积
        $cmd = escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($absolutePath)
            . ' -vn -ar 16000 -ac 1 -c:a pcm_s16le ' . escapeshellarg($tmp) . ' 2>&1';
        @exec($cmd, $out, $rc);
        if ($rc !== 0 || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            throw new ApiException(
                50006,
                '音频转码失败（ffmpeg）：' . mb_substr(implode(' ', (array) $out), 0, 200),
                500
            );
        }
        return ['path' => $tmp, 'format' => 'wav', 'tmp' => true];
    }

    /** 探测 ffmpeg：显式配置 → PATH（Windows 的 where / *nix 的 which） */
    public static function ffmpegPath(): string
    {
        $configured = trim((string) config('ai.asr.ffmpeg', ''));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        $probe = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where ffmpeg 2>NUL' : 'command -v ffmpeg 2>/dev/null';
        @exec($probe, $out, $rc);
        foreach ((array) $out as $line) {
            $line = trim((string) $line);
            if ($line !== '' && is_file($line)) {
                return $line;
            }
        }
        return '';
    }

    /**
     * 文本润色：把口述原文润色成通顺、温暖的回忆文字。
     *
     * @param string $title   章节标题
     * @param string $text    本章口述原文
     * @param string $context 全书上下文（各章标题 + 内容开头，见 bookContext()），
     *                        用于统一人称、称谓、时间线；可空
     */
    public static function polish(string $title, string $text, string $context = ''): string
    {
        if (self::isMock('llm')) {
            return self::mockPolish($title, $text);
        }

        $cfg    = config('ai.llm');
        $prompt = "请把下面这段老人回忆录的口述原文，润色成通顺、温暖、第一人称的书面叙事文字。"
            . "要求：保留原意与所有事实细节；不要编造未出现的人名、地名、事件；不要添加标题或解释；"
            . "只输出本章润色后的正文。\n";

        $context = trim($context);
        if ($context !== '') {
            $prompt .= "\n本书各章概览（只用来保持人物称谓、亲属关系、时间线、语气前后一致，"
                . "不要复述、不要把其它章节的内容写进本章正文）：\n{$context}\n";
        }

        $prompt .= "\n章节标题：《{$title}》\n本章口述原文：\n{$text}";

        $raw = self::post(
            (string) $cfg['api'],
            (string) $cfg['key'],
            [
                'model'       => (string) ($cfg['model'] ?: 'llm'),
                'temperature' => 0.7,
                'messages'    => [
                    ['role' => 'system', 'content' => '你是一位资深回忆录编辑，擅长把口语化的讲述润色成温暖、克制的书面回忆文字。'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ],
            (int) ($cfg['timeout'] ?: 60)
        );

        $out = self::extractText($raw, (string) $cfg['text_path']);
        if (trim($out) === '') {
            throw new ApiException(50003, '第三方润色接口未返回有效文本', 502);
        }
        return trim($out);
    }

    /**
     * 拼装「全书上下文」：各章标题 + 口述原文开头（长度受控），
     * 供逐章润色时统一人物称谓与时间线，避免各章各说各话。
     *
     * @param array $items [['title' => string, 'text' => string], ...]（按章节顺序）
     * @param int   $limit 单章摘录的字符数上限
     */
    public static function bookContext(array $items, int $limit = 240): string
    {
        $lines = [];
        $i     = 0;
        foreach ($items as $it) {
            $title = trim((string) ($it['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $i++;
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($it['text'] ?? '')));
            if ($text === '') {
                $lines[] = "{$i}. 《{$title}》：（本章暂无口述内容）";
                continue;
            }
            $snip = mb_substr($text, 0, $limit);
            if (mb_strlen($text) > $limit) {
                $snip .= '…';
            }
            $lines[] = "{$i}. 《{$title}》：{$snip}";
        }
        return implode("\n", $lines);
    }

    /**
     * 章节录音引导（引导式口述提示，漫画式对话框文案）：
     *  - 本章还没录音（seed）：按章节标题给出温暖、具体的「可以聊些什么」切入点或举例；
     *  - 本章已有录音（continue）：把该章**已录的全部文案**交给模型，生成「接着往下聊什么」的引导。
     * 文案风格口语化、短句、像一位耐心的访谈者，适配漫画气泡展示。
     *
     * @param string $title      章节标题
     * @param string $transcript 该章已录录音的转写文字（多段拼接后的整章文案；空 = 尚无录音）
     */
    public static function guidance(string $title, string $transcript = ''): string
    {
        if (self::isMock('guidance')) {
            return self::mockGuidance($title, $transcript);
        }

        $cfg = config('ai.guidance');
        $hasContent = trim($transcript) !== '';
        // 整章文案可能很长，只保留尾段（最贴近「刚讲完」的上下文）以免 prompt 过大拖慢响应
        $context = $hasContent ? mb_substr(trim($transcript), -1500) : '';
        $system = '你是为回忆录采集服务的「口述引导助手」。你像一位耐心、温和的晚辈，'
            . '正在陪一位长辈聊他/她的人生故事。请用简体中文、口语化、温暖的语气给出 2-4 句极简引导，'
            . '形式像是漫画里的对话气泡：可以是具体的回忆切入点，或一个开放式小问题。'
            . '不要写长段落，不要使用 Markdown 标题或列表符号，不要重复用户已经讲过的内容，'
            . '只给「接下来可以聊的方向」。';

        $user = $hasContent
            ? "章节标题：《{$title}》。\n长辈在这一章已经讲过的内容（可能有多段）：\n\"{$context}\"\n\n"
                . '请顺着上面这些内容自然衔接，给他一个「接下来可以接着聊什么」的引导（2-4 句）；'
                . '只引导还没讲到的部分，不要重复他已经说过的内容。'
            : "章节标题：《{$title}》。\n长辈还没有开始讲这一章。\n"
                . '请给一些「这一章可以从哪些具体小事聊起」的引导或举例（2-4 句，口语、温暖）。';

        try {
            $raw = self::post(
                (string) $cfg['api'],
                (string) $cfg['key'],
                [
                    'model'       => (string) ($cfg['model'] ?: 'deepseek-chat'),
                    'temperature' => 0.85,
                    'messages'    => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ],
                (int) ($cfg['timeout'] ?: 30)
            );
        } catch (\Throwable $e) {
            // 引导是辅助功能：第三方不可用（key 错 / 超时 / 网络）时退回本地文案，
            // 不让录音流程因为「提示拿不到」而报错中断
            return self::mockGuidance($title, $transcript);
        }

        // 返回为空同样兜底
        $out = self::extractText($raw, (string) $cfg['text_path']);
        return trim($out) !== '' ? trim($out) : self::mockGuidance($title, $transcript);
    }

    /**
     * 人物外观基线：把传主档案（性别/出生年月/简介）压成一句「AI 绘画可用的外貌描述」，
     * 让各章插图里的人物长相保持一致。
     *
     * @param int $ageOverride 指定年龄（章节配图用「该章所处年代对应的年龄」：
     *                         童年章写成小孩、晚年章才是老人）；0 = 按出生年月算当前年龄
     */
    public static function personBrief(array $profile, int $ageOverride = 0): string
    {
        $gender = ((string) ($profile['gender'] ?? '')) === 'female' ? '女性' : '男性';
        $birth  = trim((string) ($profile['birth'] ?? ''));
        $desc   = trim((string) ($profile['description'] ?? ''));
        $age    = $ageOverride > 0 ? (string) $ageOverride : self::ageFromBirth($birth);
        $fallback = '一位' . ($age !== '' ? $age . '岁左右的' : '年长的') . $gender . '长辈，神态温和，衣着朴素整洁';

        if (self::isMock('llm')) {
            return $fallback;
        }

        $user = "请根据下面的长辈信息，写一句不超过 80 字的中文「外貌描述」，用于 AI 绘画提示词。\n"
            . "只写视觉可见的特征：年龄段、面部神态、发型、衣着风格、整体气质；\n"
            . '年龄必须以给出的「年龄」为准（这是画这一章时他/她的年纪，照此写脸部与体态，不要写成别的年龄段）；'
            . "不要出现姓名、不要评价性语言、不要换行、不要加任何前后缀。\n\n"
            . "性别：{$gender}\n"
            . ($age !== '' ? "年龄：约 {$age} 岁\n" : '')
            . ($birth !== '' ? "出生年月：{$birth}\n" : '')
            . ($desc !== '' ? "简介：{$desc}\n" : '');

        try {
            $cfg = config('ai.llm');
            $raw = self::post(
                (string) $cfg['api'],
                (string) $cfg['key'],
                [
                    'model'       => (string) ($cfg['model'] ?: 'llm'),
                    'temperature' => 0.5,
                    'messages'    => [
                        ['role' => 'system', 'content' => '你是插画师的美术助理，擅长把人物档案转写成精准的绘画外貌描述。'],
                        ['role' => 'user', 'content' => $user],
                    ],
                ],
                (int) ($cfg['timeout'] ?: 60)
            );
            $out = trim(self::extractText($raw, (string) $cfg['text_path']));
            return $out !== '' ? $out : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * 年代 → 画风表（**本地决定画风**，不交给模型自由发挥）
     *
     * 一本回忆录横跨几十年：童年是民国旧事，青年是建设年代，晚年是当代生活，
     * 若全书一个画风，读起来像同一时刻的拼贴；按「本章内容所处的年代」选画风，
     * 才像同一个人一生的不同阶段。模型只负责判断年代与描述场景。
     */
    protected const ERA_STYLES = [
        '1930' => '民国年代怀旧插画，水墨淡彩与宣纸质感，低饱和茶褐、黛青为主，老照片般柔和颗粒',
        '1940' => '四十年代旧时光怀旧插画，素雅水墨淡彩，灰蓝与土黄，纸张泛黄质感',
        '1950' => '新中国初期年画（宣传画）风格插画，红黄暖色为主，线条朴素有力，年代气息浓厚',
        '1960' => '六十年代怀旧宣传画风格插画，暖红与军绿为主，构图质朴，年代感强',
        '1970' => '七十年代知青岁月淡彩写实插画，军绿、土黄与灰蓝，胶片颗粒质感',
        '1980' => '八十年代怀旧水彩插画，暖黄与青灰调，衣着与器物有明确年代细节，轻微褪色的胶片感',
        '1990' => '九十年代怀旧插画，暖橙与青绿撞色，老式家电与街景细节，年代质感丰富',
        '2000' => '两千年以后明亮通透的写实水彩插画，自然光，色彩干净清新',
        '2010' => '当代写实水彩插画，明亮通透，色彩干净清新',
    ];

    /** 通用（年代不明）画风 */
    protected const ERA_STYLE_DEFAULT = '中国风写实水彩插画，怀旧暖色调，纸张质感';

    /** 所有年代画面共有的硬约束（避免出字、出水印、出商标） */
    protected const IMAGE_RULES = '；画面中不出现任何文字、字幕、水印与品牌标识';

    /**
     * 章节配图的「提示词方案」：模型判断本章内容所处**年代**与场景，
     * **画风由本地年代表决定**（见 ERA_STYLES），保证同一本书风格稳定、可预期。
     *
     * 注意：这里**不传人物外观**——因为「该章所处年代」决定人物当时的年龄（童年章是小孩、
     * 晚年章才是老人），年龄要等年代判断出来才能算。调用顺序：
     *   plan = imagePlanForChapter(...)  →  personBrief($profile, $plan['age_hint'])  →  composeImagePrompt($plan, $brief)
     * 图省事也可以直接用 imagePromptForChapter()（内部就是这个顺序）。
     *
     * @param string $title      章节标题
     * @param string $text       该章口述原文（多段拼接后的整章文案）
     * @param int    $birthYear  传主出生年份（模型判断不出年代时按出生年兜底）
     * @param string $bookTitle  书名/传主名（仅用于提示词上下文）
     *
     * @return array{era:string,era_label:string,style:string,scene:string,age_hint:int,prompt:string}
     */
    public static function imagePlanForChapter(
        string $title,
        string $text,
        int $birthYear = 0,
        string $bookTitle = ''
    ): array {
        $title = trim($title);
        $text  = trim((string) preg_replace('/\s+/u', ' ', $text));

        // 兜底年代：内容判断不出来时，按「出生年 + 25 岁」当作本章大致所处年代
        $fallbackEra = $birthYear > 0 && $birthYear <= (int) date('Y')
            ? self::eraFromYear($birthYear + 25)
            : '';

        $era     = $fallbackEra;
        $scene   = '';
        $ageHint = 0;

        if (!self::isMock('llm')) {
            $user = "下面是长辈回忆录的某一章。请判断这一章讲的事情大致发生在哪个年代、"
                . "讲述人当时大约多大年纪，并写一句画面描述。\n"
                . "只输出 JSON，不要任何解释、不要代码块标记，格式严格如下：\n"
                . '{"era":"1980年代","age":12,"scene":"用一句不超过 80 字的中文描述一个可画的场景（地点、光线、器物、氛围、人物动作），不要出现姓名"}'
                . "\n\n要求：\n"
                . "1. era 只能填「1930年代」～「2020年代」这种十年一档的写法；确实判断不出就填空字符串；\n"
                . "2. age 填讲述人（传主本人）在这一章当时的大致年龄，如「我七岁那年」就填 7；判断不出填 0；\n"
                . "3. scene 必须来自本章内容里的具体细节，不要编造人名、地名；\n"
                . "4. 画面里不要出现文字、招牌、书本封面上的字。\n\n"
                . ($bookTitle !== '' ? "书名：《{$bookTitle}》\n" : '')
                . "章节标题：《{$title}》\n"
                . ($text !== '' ? "本章内容：\n" . mb_substr($text, 0, 800) . "\n" : "（本章暂无口述内容）\n");

            try {
                $cfg = config('ai.llm');
                $raw = self::post(
                    (string) $cfg['api'],
                    (string) $cfg['key'],
                    [
                        'model'       => (string) ($cfg['model'] ?: 'llm'),
                        'temperature' => 0.4,
                        'messages'    => [
                            ['role' => 'system', 'content' => '你是插画师的前期策划，负责判断回忆录章节的年代与讲述人年龄，并给出可画场景。只输出 JSON。'],
                            ['role' => 'user', 'content' => $user],
                        ],
                    ],
                    (int) ($cfg['timeout'] ?: 60)
                );
                $json = self::extractJson(self::extractText($raw, (string) $cfg['text_path']));
                if (is_array($json)) {
                    $era   = self::normalizeEra((string) ($json['era'] ?? '')) ?: $fallbackEra;
                    $scene = trim((string) ($json['scene'] ?? ''));
                    $a     = (int) ($json['age'] ?? 0);
                    if ($a >= 3 && $a <= 110) {
                        $ageHint = $a;
                    }
                }
            } catch (\Throwable $e) {
                // 判断年代是增强项：失败就用兜底年代 + 章节标题凑场景，不阻断出图
            }
        }

        // 「年龄」比「年代」可靠得多（原文常直接写「我七岁那年」），二者都能算时以年龄反推年代：
        // 出生年 + 当时年龄 = 那年 → 取十年一档 → 画风也随之校正（童年章就该是民国/建国初的样子）
        if ($ageHint > 0 && $birthYear > 0) {
            $byAge = self::eraFromYear($birthYear + $ageHint);
            if ($byAge !== '') {
                $era = $byAge;
            }
        }
        if ($ageHint <= 0) {
            $ageHint = self::ageAtEra($era, $birthYear);
        }

        if ($scene === '') {
            $scene = '《' . $title . '》的生活场景'
                . ($text !== '' ? '：' . mb_substr($text, 0, 60) : '');
        }
        $scene = rtrim($scene, '。.；;，, ');

        return [
            'era'       => $era,
            'era_label' => self::eraLabel($era),
            'style'     => self::styleForEra($era),
            'scene'     => $scene,
            // 该章年代对应的人物年龄（算不出给 0 → 用当前年龄）
            'age_hint'  => $ageHint,
            'prompt'    => self::composeImagePrompt(
                ['style' => self::styleForEra($era), 'scene' => $scene]
            ),
        ];
    }

    /** 把「画风 + 场景 + 人物外观」拼成最终提示词（人物外观可空） */
    public static function composeImagePrompt(array $plan, string $personBrief = ''): string
    {
        $style = trim((string) ($plan['style'] ?? ''));
        $scene = rtrim(trim((string) ($plan['scene'] ?? '')), '。.；;，, ');
        $brief = trim($personBrief);

        return $style . '。画面：' . $scene
            . ($brief !== '' ? '；画面中的核心人物：' . $brief : '')
            . self::IMAGE_RULES;
    }

    /** 该年代 + 出生年 → 人物当时的年龄（取年代中段；无法计算返回 0） */
    public static function ageAtEra(string $eraKey, int $birthYear): int
    {
        if ($eraKey === '' || $birthYear <= 0) {
            return 0;
        }
        $year = (int) $eraKey + 5;
        $age  = $year - $birthYear;
        return ($age >= 3 && $age <= 110) ? $age : 0;
    }

    /**
     * 封面主视觉的「提示词方案」：以传主形象与一生气质为主，画风按出生年代定。
     *
     * ⚠️ 刻意**不让画面出现文字**：AI 写中文必乱；书名等信息由翻书页排版叠加。
     *
     * @return array{era:string,era_label:string,style:string,scene:string,age_hint:int,prompt:string}
     */
    public static function imagePlanForCover(array $profile, string $bookTitle = ''): array
    {
        $desc  = trim((string) ($profile['description'] ?? ''));
        $birth = trim((string) ($profile['birth'] ?? ''));
        $brief = self::personBrief($profile);
        $year  = (int) ($profile['birth_year'] ?? 0);
        if ($year <= 0 && $birth !== '') {
            $ts   = strtotime($birth);
            $year = $ts ? (int) date('Y', $ts) : 0;
        }
        // 封面的画风锚定「传主出生年代」：一本回忆录的视觉气质，来自他/她出发的那个年代
        $era   = $year > 0 && $year <= (int) date('Y') ? self::eraFromYear($year) : '';
        // 场景里不重复写人物外观（由 composeImagePrompt 统一拼「画面中的核心人物」，避免一句话说两遍）
        $scene = '半身肖像，背景是其生活年代的居所环境'
            . ($desc !== '' ? '（' . mb_substr($desc, 0, 60) . '）' : '')
            . '，画面留出上方与下方空白，便于叠加书名';

        $style = self::styleForEra($era);

        return [
            'era'       => $era,
            'era_label' => self::eraLabel($era),
            'style'     => $style,
            'scene'     => $scene,
            'age_hint'  => 0,   // 封面人物按当前年龄（见 personBrief 默认行为）
            'prompt'    => self::composeImagePrompt(['style' => $style, 'scene' => $scene], $brief),
        ];
    }

    /** 年代键（'1980'）→ 画风描述；未知年代给通用怀旧画风 */
    public static function styleForEra(string $eraKey): string
    {
        $k = self::normalizeEra($eraKey);
        return self::ERA_STYLES[$k] ?? self::ERA_STYLE_DEFAULT;
    }

    /** 归一化年代写法：'1980年代' / '80年代' / '1980s' / '1985-08' → '1980'（识别不出返回 ''） */
    public static function normalizeEra(string $era): string
    {
        $era = trim($era);
        if ($era === '') {
            return '';
        }
        if (preg_match('/(1[89]\d{2}|20\d{2}|21\d{2})/u', $era, $m)) {
            return (string) (((int) floor(((int) $m[1]) / 10)) * 10);
        }
        // 两位年份：'80年代' / '50s'
        if (preg_match('/(\d{2})\s*(?:年代|s)/u', $era, $m2)) {
            $n = (int) $m2[1];
            return $n >= 30 ? (string) (1900 + $n) : (string) (2000 + $n);
        }
        return '';
    }

    /** 年份 → 年代键（'1985' → '1980'） */
    public static function eraFromYear(int $year): string
    {
        if ($year < 1900 || $year > (int) date('Y')) {
            return '';
        }
        return (string) ((int) floor($year / 10) * 10);
    }

    /** 年代键 → 展示标签（'1980' → '1980年代'；空 → ''） */
    public static function eraLabel(string $eraKey): string
    {
        return $eraKey !== '' ? $eraKey . '年代' : '';
    }

    /** 从模型返回里抠出 JSON 对象（容忍 ```json 包裹与前后废话） */
    private static function extractJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $raw = (string) preg_replace('/^```(?:json)?|```$/m', '', $raw);
        $s   = strpos($raw, '{');
        $e   = strrpos($raw, '}');
        if ($s === false || $e === false || $e <= $s) {
            return [];
        }
        $json = json_decode(substr($raw, $s, $e - $s + 1), true);
        return is_array($json) ? $json : [];
    }

    /**
     * 章节配图提示词（一行搞定：判断年代 → 按年代算人物年龄 → 拼提示词）。
     * 需要中间结果（era/style/age_hint）时请分两步用 imagePlanForChapter + composeImagePrompt。
     */
    public static function imagePromptForChapter(
        string $title,
        string $text,
        string $personBrief = '',
        int $birthYear = 0
    ): string {
        $plan = self::imagePlanForChapter($title, $text, $birthYear);
        return self::composeImagePrompt($plan, $personBrief);
    }

    /** 由出生年月推算年龄（空值/非法值返回空串） */
    public static function ageFromBirth(string $birth): string
    {
        $ts = strtotime($birth);
        if (!$ts) {
            return '';
        }
        $y = (int) date('Y', $ts);
        if ($y < 1900 || $y > (int) date('Y')) {
            return '';
        }
        return (string) max(0, (int) date('Y') - $y);
    }

    /** 通用 JSON POST（curl，带连接/读取超时） */
    private static function post(string $url, string $key, array $payload, int $timeout): array
    {
        $headers = ['Content-Type: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ];
        if (stripos($url, 'https://') === 0) {
            if (config('ai.ssl_verify') === false) {
                // 仅本机排障：关闭证书校验（AI_SSL_VERIFY=false），生产禁止
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
                $opts[CURLOPT_SSL_VERIFYHOST] = 0;
            } elseif (($ca = self::caFile()) !== '') {
                $opts[CURLOPT_CAINFO] = $ca;
            }
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new ApiException(50004, 'AI 接口请求失败：' . $err, 502);
        }
        if ($http >= 400) {
            throw new ApiException(50004, "AI 接口返回 HTTP {$http}", 502);
        }

        $json = json_decode((string) $body, true);
        return is_array($json) ? $json : ['text' => trim((string) $body)];
    }

    /**
     * HTTPS 根证书路径：env 指定 → php.ini(curl.cainfo / openssl.cafile) → 环境变量 → 项目内置
     *
     * 本机 phpstudy 的 PHP 常常没配 curl.cainfo，导致所有 https 请求直接失败；
     * 项目自带一份 Mozilla CA 包（backend/runtime/ca/cacert.pem）作为最后兜底，开箱即可用。
     */
    public static function caFile(): string
    {
        $candidates = [
            (string) config('ai.ssl_ca', ''),
            (string) ini_get('curl.cainfo'),
            (string) ini_get('openssl.cafile'),
            (string) getenv('CURL_CA_BUNDLE'),
            dirname(__DIR__, 2) . '/runtime/ca/cacert.pem',
        ];
        foreach ($candidates as $p) {
            if ($p !== '' && is_file($p)) {
                return $p;
            }
        }
        return '';
    }

    /** 从第三方返回中解析文本：优先显式路径，其次常见字段兜底 */
    private static function extractText(array $json, string $path = ''): string
    {
        if ($path !== '') {
            $cur = $json;
            foreach (explode('.', $path) as $k) {
                if (is_array($cur) && array_key_exists($k, $cur)) {
                    $cur = $cur[$k];
                } else {
                    $cur = null;
                    break;
                }
            }
            if (is_string($cur) && trim($cur) !== '') {
                return $cur;
            }
        }

        foreach (['text', 'result', 'transcript', 'content'] as $k) {
            if (isset($json[$k]) && is_string($json[$k]) && trim($json[$k]) !== '') {
                return $json[$k];
            }
        }
        foreach (['data', 'output', 'result'] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                foreach (['text', 'result', 'transcript', 'content'] as $k2) {
                    if (isset($json[$k][$k2]) && is_string($json[$k][$k2]) && trim($json[$k][$k2]) !== '') {
                        return $json[$k][$k2];
                    }
                }
            }
        }
        // OpenAI Chat Completions 兼容
        if (isset($json['choices'][0]['message']['content']) && is_string($json['choices'][0]['message']['content'])) {
            return $json['choices'][0]['message']['content'];
        }
        if (isset($json['choices'][0]['text']) && is_string($json['choices'][0]['text'])) {
            return $json['choices'][0]['text'];
        }
        return '';
    }

    /**
     * mock 转写文案（未接第三方时使用）。
     *
     * $seed 传音频路径：同一章节录多段时，每段按文件散列取不同模板，
     * 否则 mock 会把同一句话拼三遍，联调时看起来像 bug（真实 ASR 上线后不受影响）。
     */
    private static function mockAsr(string $title, string $seed = ''): string
    {
        $t = $title !== '' ? $title : '这段往事';
        $variants = [
            "关于「{$t}」，那时候的日子过得慢，一件小事都能记很久。"
                . '现在回想起来，画面还是清清楚楚的——当时的天气、身边的人、心里那点说不上来的滋味，都想讲给你们听。',
            "要说「{$t}」，得从家里那间老屋讲起。天还没大亮，灶膛里的火就红了，"
                . '锅盖一掀，白气腾起来，满院子都是饭香。那时候东西少，可人心是满的。',
            "「{$t}」里最忘不掉的，是那年秋天。地里的活儿刚忙完，晒场上堆着新收的谷子，"
                . '大人们坐在门槛上说话，我们几个孩子在旁边跑来跑去，一直玩到月亮出来。',
            "提起「{$t}」，我总想起母亲。她话不多，手上的活却从来没停过。"
                . '那时候不懂，等到自己也做了爹娘，才知道那些沉默里头装了多少东西。',
        ];
        $i = $seed !== '' ? (crc32($seed) % count($variants)) : 0;
        return $variants[$i];
    }

    /** mock 润色文案（不加任何「（AI 模拟…）」前缀，成书文本保持干净） */
    private static function mockPolish(string $title, string $text): string
    {
        $t = $title !== '' ? $title : '这段记忆';
        $seed = trim($text) !== '' ? trim($text) : "提起「{$t}」，总有说不完的故事。";
        return "{$seed}\n\n"
            . "时光把往事酿成了酒。这些朴素的日子，被认真地记下来之后，"
            . "反倒显出分量来——那些走过的路、遇见的人，都是生命里最诚恳的注脚。";
    }

    /** mock 录音引导文案（seed = 无录音给切入点；continue = 有录音给续接方向） */
    private static function mockGuidance(string $title, string $lastTranscript): string
    {
        $t = $title !== '' ? $title : '这段回忆';
        if (trim($lastTranscript) !== '') {
            return "您刚才讲到「{$t}」里的事，讲得真好。\n接下来可以顺着这个话题，多说说当时的心情，"
                . "或者这件事后来怎样了？想到哪儿说到哪儿，慢慢来。";
        }
        return "提到「{$t}」，可以从这些小事聊起：\n那时候的一天是怎样开始的？身边都有谁？"
            . "有没有哪件事让您至今记得清清楚楚？随便挑一个说说就好。";
    }
}
