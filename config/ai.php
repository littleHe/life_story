<?php
// AI 能力网关配置（全部来自环境变量）
// 设计：未配置对应 api 或 AI_MOCK=true 时，自动回退本地 mock，保证零配置也能跑通全流程。
return [
    // 总开关：true = 全部走 mock（本地联调默认）；false = 按下方 api 配置调用第三方 HTTP 接口
    'mock' => env('AI_MOCK', true),

    // HTTPS 证书校验（AI 通道全部为 curl 请求）
    // - 默认 true（安全）：若 php.ini 的 curl.cainfo 为空，会自动探测 backend/runtime/ca/cacert.pem
    // - AI_SSL_VERIFY=false 可关闭校验：仅限本机开发排障，生产环境禁止
    'ssl_verify' => env('AI_SSL_VERIFY', true),
    // 指定 CA 根证书路径（一般不用填，留空则按 php.ini → 项目 runtime/ca/cacert.pem 顺序自动探测）
    'ssl_ca' => env('AI_SSL_CA', ''),

    // 语音识别（录音转文字）
    // driver = tencent → 走腾讯云「录音文件识别极速版」（同步、推荐，见 service/TencentAsrService.php）
    // driver = generic → 走下方 api 的通用 JSON 协议（{ model, format, audio_base64 } + Bearer key）
    // 通用协议返回文本解析顺序：AI_ASR_TEXT_PATH 指定路径 → data.text/result/transcript → choices[0].message.content
    'asr' => [
        'driver'    => env('AI_ASR_DRIVER', 'generic'),
        'api'       => env('AI_ASR_API', ''),
        'key'       => env('AI_ASR_KEY', ''),
        'model'     => env('AI_ASR_MODEL', ''),
        'timeout'   => (int) env('AI_ASR_TIMEOUT', 30),
        'text_path' => env('AI_ASR_TEXT_PATH', ''),
        // ffmpeg 可执行文件路径：仅当录音容器腾讯不支持（如 webm）时用于自动转 wav；
        // 留空则自动探测 PATH 里的 ffmpeg，探测不到就走「前端换容器」方案（m4a / ogg-opus）
        'ffmpeg'    => env('AI_ASR_FFMPEG', ''),

        // 腾讯云 ASR（录音文件识别极速版）
        // 凭证获取：语音识别控制台 → API 密钥管理（= 访问管理 → API 密钥管理），新建后得到 SecretId / SecretKey
        // engine：16k_zh 中文通用；16k_zh_en 大模型1.0版（中英粤+9 种方言，口音重的老人更准）；16k_zh_dialect 多方言
        'tencent' => [
            'appid'       => env('AI_ASR_TENCENT_APPID', ''),
            'secret_id'   => env('AI_ASR_TENCENT_SECRET_ID', ''),
            'secret_key'  => env('AI_ASR_TENCENT_SECRET_KEY', ''),
            'engine'      => env('AI_ASR_TENCENT_ENGINE', '16k_zh'),
            'hotword_id'  => env('AI_ASR_TENCENT_HOTWORD_ID', ''),
            // 临时热词表（人名/地名命中率提升明显）：「何天霸|11,清远|5」
            'hotwords'    => env('AI_ASR_TENCENT_HOTWORDS', ''),
            'filter_modal' => (int) env('AI_ASR_TENCENT_FILTER_MODAL', 1),
            'filter_dirty' => (int) env('AI_ASR_TENCENT_FILTER_DIRTY', 0),
            'filter_punc'  => (int) env('AI_ASR_TENCENT_FILTER_PUNC', 0),
            'timeout'     => (int) env('AI_ASR_TENCENT_TIMEOUT', 120),
        ],
    ],

    // 文本润色（LLM）：POST 到 AI_LLM_API，OpenAI Chat Completions 兼容格式
    'llm' => [
        'api'       => env('AI_LLM_API', ''),
        'key'       => env('AI_LLM_KEY', ''),
        'model'     => env('AI_LLM_MODEL', ''),
        'timeout'   => (int) env('AI_LLM_TIMEOUT', 60),
        'text_path' => env('AI_LLM_TEXT_PATH', ''),
    ],

    // 章节录音引导（LLM，引导式口述提示）：OpenAI Chat Completions 兼容格式
    // 对接 DeepSeek 示例：
    //   AI_GUIDANCE_API=https://api.deepseek.com/chat/completions
    //   AI_GUIDANCE_KEY=sk-xxxx
    //   AI_GUIDANCE_MODEL=deepseek-chat
    // 未配置或 AI_MOCK=true 时自动回退本地 mock。
    'guidance' => [
        'api'       => env('AI_GUIDANCE_API', ''),
        'key'       => env('AI_GUIDANCE_KEY', ''),
        'model'     => env('AI_GUIDANCE_MODEL', 'deepseek-chat'),
        'timeout'   => (int) env('AI_GUIDANCE_TIMEOUT', 30),
        'text_path' => env('AI_GUIDANCE_TEXT_PATH', ''),
    ],

    // 章节配图（腾讯云「混元生图」，异步任务：提交 + 查询）
    // 凭证：默认复用 AI_ASR_TENCENT_SECRET_ID / SECRET_KEY（同账号云 API 密钥），可用 AI_IMAGE_TENCENT_* 单独覆盖。
    //
    // ⚠️ 产品线选择（2026-09 实测结论）：
    //   · aiart（默认，可用）—— aiart.tencentcloudapi.com / 2022-12-29，即控制台「腾讯混元生图」那一支，
    //     免费资源包（资源包管理页）就挂在这里，实测 SubmitTextToImageJob 可正常出图。
    //   · hunyuan（**旧链路，已不可用**）—— hunyuan.tencentcloudapi.com / 2023-09-01 的
    //     SubmitHunyuanImageJob 现在恒报 ResourceUnavailable.NotExist（"计费状态未知，服务未开通"），
    //     即使控制台显示已开通、资源包也有余额。该产品线正整体迁移到 TokenHub，不要再切回去。
    //   判别脚本：php tests/_probe_hunyuan2.php（只读看两条产品线差异）、php tests/_probe_params.php（参数契约）。
    'image' => [
        'driver'     => env('AI_IMAGE_DRIVER', 'tencent_hunyuan'),
        // generic 通道（自建/其它厂商文生图网关，OpenAI images 风格）时才用
        'api'        => env('AI_IMAGE_API', ''),
        'key'        => env('AI_IMAGE_KEY', ''),
        'model'      => env('AI_IMAGE_MODEL', ''),

        'tencent' => [
            // 产品线：aiart（推荐，默认）| hunyuan（旧，已报计费未开通）
            'product'    => env('AI_IMAGE_TENCENT_PRODUCT', 'aiart'),
            'endpoint'   => env('AI_IMAGE_TENCENT_ENDPOINT', 'aiart.tencentcloudapi.com'),
            'version'    => env('AI_IMAGE_TENCENT_VERSION', '2022-12-29'),
            // 换产品/版本只需改这几项，不用动代码（两套产品线的请求/响应结构在 TencentImageService 里已适配）：
            //   aiart  ：endpoint=aiart.tencentcloudapi.com       version=2022-12-29  service=aiart
            //            submit_action=SubmitTextToImageJob      query_action=QueryTextToImageJob
            //   hunyuan：endpoint=hunyuan.tencentcloudapi.com     version=2023-09-01   service=hunyuan
            //            submit_action=SubmitHunyuanImageJob     query_action=QueryHunyuanImageJob（当前报计费未开通）
            'service'       => env('AI_IMAGE_TENCENT_SERVICE', 'aiart'),
            'submit_action' => env('AI_IMAGE_TENCENT_SUBMIT_ACTION', 'SubmitTextToImageJob'),
            'query_action'  => env('AI_IMAGE_TENCENT_QUERY_ACTION', 'QueryTextToImageJob'),
            'region'     => env('AI_IMAGE_TENCENT_REGION', 'ap-guangzhou'),
            'secret_id'  => env('AI_IMAGE_TENCENT_SECRET_ID', env('AI_ASR_TENCENT_SECRET_ID', '')),
            'secret_key' => env('AI_IMAGE_TENCENT_SECRET_KEY', env('AI_ASR_TENCENT_SECRET_KEY', '')),
            'style'      => env('AI_IMAGE_TENCENT_STYLE', ''),
            'resolution' => env('AI_IMAGE_TENCENT_RESOLUTION', '1024:768'),
            // prompt 扩写：开启效果更好，但每张多约 20 秒
            'revise'     => (int) env('AI_IMAGE_TENCENT_REVISE', 1),
            // 结果图右下角「图片由 AI 生成」水印（1 加 / 0 不加）
            'logo_add'   => (int) env('AI_IMAGE_TENCENT_LOGO_ADD', 1),
            // 是否把传主头像作为参考图（引导人物长相）
            // ⚠️ 仅 hunyuan 产品线的 ContentImage 参数支持；aiart 的文生图接口没有参考图入参
            //    （其 ImageToImage 是「按原图重绘」，会保留原图构图与尺寸，不适合做章节插图），
            //    故 aiart 下该开关不生效，人物一致性改由提示词里的人 物外观基线 保证。
            'use_avatar' => (int) env('AI_IMAGE_TENCENT_USE_AVATAR', 1),
            'timeout'    => (int) env('AI_IMAGE_TENCENT_TIMEOUT', 60),
            // 单次请求内等待出图的上限（秒）；超时返回 pending，由前端稍后重试续跑
            'wait_max'   => (int) env('AI_IMAGE_TENCENT_WAIT_MAX', 90),
            'poll_interval' => (int) env('AI_IMAGE_TENCENT_POLL_INTERVAL', 3),
        ],
    ],

    // 章节朗读配音（腾讯云语音合成 TTS）：把 AI 润色文案读成有声书
    // 凭证默认复用 AI_ASR_TENCENT_SECRET_ID / SECRET_KEY（同账号云 API 密钥）。
    // voice_type 现在填系统音色；将来换成「声音复刻」产出的 VoiceId 即完成音色复刻，整条链路不用改。
    'tts' => [
        'driver' => env('AI_TTS_DRIVER', 'tencent'),
        'api'    => env('AI_TTS_API', ''),
        'key'    => env('AI_TTS_KEY', ''),
        'tencent' => [
            'endpoint'    => env('AI_TTS_TENCENT_ENDPOINT', 'tts.tencentcloudapi.com'),
            'version'     => env('AI_TTS_TENCENT_VERSION', '2019-08-23'),
            'region'      => env('AI_TTS_TENCENT_REGION', 'ap-guangzhou'),
            'secret_id'   => env('AI_TTS_TENCENT_SECRET_ID', env('AI_ASR_TENCENT_SECRET_ID', '')),
            'secret_key'  => env('AI_TTS_TENCENT_SECRET_KEY', env('AI_ASR_TENCENT_SECRET_KEY', '')),
            // 音色：先用系统音色；换成复刻 VoiceId 即「声音复刻」。php tests/_check_tts.php --voices 可列出可用音色
            'voice_type'  => (int) env('AI_TTS_TENCENT_VOICE_TYPE', 1001),
            // 按传主性别分派的标准音色（还没做声音复刻时的默认音色）
            // 男 501006 千嶂（沉稳大气）；女 601010 爱小娇（温柔亲和）—— 音色 ID 见语音合成控制台「音色列表」
            'voice_male'   => (int) env('AI_TTS_VOICE_MALE', 501006),
            'voice_female' => (int) env('AI_TTS_VOICE_FEMALE', 601010),
            'codec'       => env('AI_TTS_TENCENT_CODEC', 'mp3'),
            'sample_rate' => (int) env('AI_TTS_TENCENT_SAMPLE_RATE', 16000),
            'speed'       => (float) env('AI_TTS_TENCENT_SPEED', 0),
            'volume'      => (float) env('AI_TTS_TENCENT_VOLUME', 0),
            'timeout'     => (int) env('AI_TTS_TENCENT_TIMEOUT', 60),
        ],
    ],

    // 声音复刻（腾讯云 VRS，vrs.tencentcloudapi.com）—— 用传主自己的录音训练专属音色
    // 流程：GetTrainingText → DetectEnvAndSoundQuality（音质检测，得到 AudioId）
    //      → CreateVRSTask（一句话复刻 TaskType=5）→ DescribeVRSTaskStatus 轮询 → VoiceType
    // 拿到 VoiceType 写回 ls_project.voice_type 后，TTS 自动改用它朗读，整条链路不用改。
    // ⚠️ 默认关闭：需先在「语音合成控制台 → 声音复刻」开通（按音色计费）。
    //      AI_VRS_ENABLED=1 开启；未开启时按传主性别用标准音色（见上方 voice_male / voice_female）。
    'vrs' => [
        'enabled'     => (bool) env('AI_VRS_ENABLED', false),
        'endpoint'    => env('AI_VRS_TENCENT_ENDPOINT', 'vrs.tencentcloudapi.com'),
        'version'     => env('AI_VRS_TENCENT_VERSION', '2020-08-24'),
        'region'      => env('AI_VRS_TENCENT_REGION', 'ap-guangzhou'),
        'secret_id'   => env('AI_VRS_TENCENT_SECRET_ID', env('AI_ASR_TENCENT_SECRET_ID', '')),
        'secret_key'  => env('AI_VRS_TENCENT_SECRET_KEY', env('AI_ASR_TENCENT_SECRET_KEY', '')),
        // 训练样本：从一段录音里截取的时长（秒）。一句话复刻要求 5s < 时长 < 15s
        'sample_sec'  => (int) env('AI_VRS_SAMPLE_SEC', 12),
        'sample_rate' => (int) env('AI_VRS_SAMPLE_RATE', 24000),
        'timeout'     => (int) env('AI_VRS_TENCENT_TIMEOUT', 60),
    ],
];
