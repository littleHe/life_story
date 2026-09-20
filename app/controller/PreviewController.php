<?php
namespace app\controller;

use app\service\SiteConfigService;
use think\facade\Db;
use think\Response;

/**
 * 公开翻书预览页：GET /preview/{token}（无需登录，凭 token 访问）
 *
 * - 第一页为「封面」（用户基本信息 + 简介 + 封面背景）
 * - 每章一页（标题 + 润色/口述正文 + 章节背景）
 * - 左上角关闭（返回上一页）
 * - 自动翻页（间隔由后台「参数设置」配置，默认 30 秒，0=关闭）
 * - 有声朗读：原音讲述（该章多段录音顺序播放）/ AI 润声（优先后台配音，否则浏览器语音合成）
 * - 页脚展示公司信息
 */
class PreviewController
{
    /** 允许的图片/音频地址：本站相对路径或 http(s) 外链 */
    private function safeUrl(string $u): string
    {
        $u = trim($u);
        if ($u === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//#i', $u) || strpos($u, '/') === 0) {
            return $u;
        }
        return '';
    }

    public function show($token)
    {
        $token = preg_replace('/[^a-f0-9]/i', '', (string) $token);
        $project = $token
            ? Db::name('ls_project')->where('preview_token', $token)->find()
            : null;

        if (!$project) {
            return Response::create(
                '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>预览不存在</title></head>'
                . '<body style="font-family:system-ui;display:flex;height:100vh;align-items:center;justify-content:center;margin:0;color:#8a6d3b;background:#faf6ef">'
                . '<div style="text-align:center"><h2>预览不存在或已失效</h2><p>请回到详情页重新提交定稿。</p></div></body></html>',
                'html',
                404
            );
        }

        $chapters = Db::name('ls_chapter')
            ->where('project_id', $project['id'])
            ->order('sort asc, id asc')
            ->select()
            ->toArray();

        $ids = array_column($chapters, 'id');
        $texts = [];
        $bg = [];
        $audio = [];
        $dubbed = [];
        if ($ids) {
            $assets = Db::name('ls_chapter_asset')
                ->where('chapter_id', 'in', $ids)
                ->where('asset_type', 'in', ['TRANSCRIPT', 'POLISHED_TEXT', 'AI_IMAGE', 'USER_IMAGE', 'RECORDING', 'AUDIO_DUBBED'])
                ->select()
                ->toArray();
            foreach ($assets as $a) {
                $meta = json_decode((string) $a['meta'], true) ?: [];
                $cid = $a['chapter_id'];
                switch ($a['asset_type']) {
                    case 'AI_IMAGE':
                    case 'USER_IMAGE':
                        $bg[$cid][$a['asset_type']] = $meta['url'] ?? $a['oss_key'] ?? '';
                        break;
                    case 'RECORDING':
                        $audio[$cid][] = [
                            'seq' => (int) ($meta['seq'] ?? 0),
                            'url' => $this->safeUrl((string) $a['oss_key']),
                        ];
                        break;
                    case 'AUDIO_DUBBED':
                        $u = $this->safeUrl((string) ($meta['url'] ?? $a['oss_key'] ?? ''));
                        if ($u !== '') {
                            $dubbed[$cid] = $u;
                        }
                        break;
                    default:
                        $texts[$cid][$a['asset_type']] = $meta['text'] ?? '';
                }
            }
        }

        // 组装页面：封面 + 章节
        $pages = [];
        $pages[] = [
            'type'         => 'cover',
            'bg'           => $this->safeUrl((string) $project['cover_bg']),
            'title'        => (string) $project['name'],
            'real_name'    => (string) $project['real_name'],
            'birth'        => $project['birth'] ? (string) $project['birth'] : '',
            'native_place' => (string) $project['native_place'],
            'description'  => (string) $project['description'],
            'dubbed'       => $this->safeUrl((string) $project['cover_audio']),
        ];
        foreach ($chapters as $c) {
            $cid = $c['id'];
            // 两套文案各自保留：原声（口述原文）与 AI 润色，由前端按朗读模式切换展示
            $orig     = trim((string) ($texts[$cid]['TRANSCRIPT'] ?? ''));
            $polished = trim((string) ($texts[$cid]['POLISHED_TEXT'] ?? ''));
            $urls = [];
            if (!empty($audio[$cid])) {
                usort($audio[$cid], fn($x, $y) => $x['seq'] <=> $y['seq']);
                foreach ($audio[$cid] as $seg) {
                    if ($seg['url'] !== '') {
                        $urls[] = $seg['url'];
                    }
                }
            }
            $pages[] = [
                'type'   => 'chapter',
                'bg'     => $this->safeUrl($bg[$cid]['USER_IMAGE'] ?? ($bg[$cid]['AI_IMAGE'] ?? '')),
                'title'  => (string) $c['title'],
                // 缺失时互相回退，保证任何情况下都有正文可读
                'orig'   => $orig !== '' ? $orig : $polished,
                'ai'     => $polished !== '' ? $polished : $orig,
                'audio'  => $urls,
                'dubbed' => $dubbed[$cid] ?? '',
            ];
        }

        // 尾页：结束语（后台「参数设置 → 尾页结束文案」可编辑；留空则不显示尾页）
        $endText = trim(SiteConfigService::get('preview_end_text', SiteConfigService::DEFAULT_PREVIEW_END_TEXT));
        if ($endText !== '') {
            $pages[] = [
                'type'   => 'ending',
                'text'   => $endText,
                'dubbed' => $this->safeUrl((string) $project['ending_audio']),
            ];
        }

        $total = count($pages);
        $pagesHtml = '';
        $bookData = [];
        foreach ($pages as $i => $p) {
            $pagesHtml .= $this->pageHtml($p, $i + 1, $total);
            $isCover  = ($p['type'] ?? '') === 'cover';
            $isEnding = ($p['type'] ?? '') === 'ending';
            $bookData[] = [
                'audio'    => $p['audio'] ?? [],
                'dubbed'   => $p['dubbed'] ?? '',
                'origText' => ($isCover || $isEnding) ? $this->speakText($p) : (string) ($p['orig'] ?? ''),
                'aiText'   => ($isCover || $isEnding) ? $this->speakText($p) : (string) ($p['ai'] ?? ''),
            ];
        }

        // 后台参数：自动翻页秒数 + 公司信息
        $auto = (int) SiteConfigService::get('preview_auto_flip_seconds', '30');
        // 背景音乐：从后台「背景音乐」池随机取一首启用中的（没配则为空，页面不播放）
        $bgmRow = Db::name('ls_bgm')->where('status', 1)->orderRaw('rand()')->limit(1)->find();
        $bgmUrl = $bgmRow ? (string) $bgmRow['file'] : '';
        $site = [];
        foreach (['company_name' => '公司', 'company_email' => '邮箱', 'service_phone' => '客服电话', 'company_address' => '地址'] as $k => $label) {
            $v = trim(SiteConfigService::get($k, ''));
            if ($v !== '') {
                $site[] = $label . '：' . $v;
            }
        }
        $siteHtml = $site
            ? '<footer class="siteinfo">' . implode('<span class="dot">·</span>', array_map(fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'), $site)) . '</footer>'
            : '';

        /* 进门蒙层：浏览器自动播放策略要求「先有用户手势」，且「点击任意位置」对长辈不够明确，
           所以把这一次必要点击做成一张「即将进入」的封面卡，点「开始欣赏」= 翻书 + 朗读 + 配乐一起启动。 */
        $chapPages = array_values(array_filter($pages, fn($p) => ($p['type'] ?? '') === 'chapter'));
        $chapChars = 0;
        foreach ($chapPages as $cp) {
            $chapChars += mb_strlen((string) ($cp['orig'] ?? ''));
        }
        $gateSub = [];
        if ((string) $project['real_name'] !== '') {
            $gateSub[] = '传主 · ' . (string) $project['real_name'];
        }
        if ($project['birth']) {
            $gateSub[] = (string) $project['birth'];
        }
        if ((string) $project['native_place'] !== '') {
            $gateSub[] = (string) $project['native_place'];
        }
        $gateTips = [];
        if ($auto > 0 && $total > 1) {
            $gateTips[] = '自动翻书';
        }
        $gateTips[] = '朗读';
        if ($bgmUrl !== '') {
            $gateTips[] = '配乐';
        }
        $gateMeta = '共 ' . count($chapPages) . ' 章';
        if ($chapChars > 0) {
            $gateMeta .= ' · 朗读约 ' . max(1, (int) round($chapChars / 220)) . ' 分钟';
        }
        $esc     = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $coverBg = $this->safeUrl((string) $project['cover_bg']);
        $gateHtml = '<div class="gate" id="gate">'
            . ($coverBg !== '' ? '<div class="bgimg" style="background-image:url(\'' . $esc($coverBg) . '\')"></div>' : '')
            . '<div class="veil"></div>'
            . '<div class="card">'
            . '<div class="frame"></div>'
            . '<button class="skip" id="gateSkip" type="button">暂不欣赏 ✕</button>'
            . '<div class="seal">序</div>'
            . '<div class="gtitle">' . $esc((string) $project['name']) . '</div>'
            . ($gateSub ? '<div class="gsub">' . $esc(implode(' · ', $gateSub)) . '</div>' : '')
            . '<div class="gdiv"></div>'
            . '<div class="gmeta">' . $esc($gateMeta) . '</div>'
            . '<button class="gbtn" id="gateBtn" type="button">开始欣赏</button>'
            . '<div class="gtip">点击后 ' . $esc(implode(' · ', $gateTips)) . ' 同时开启；请确认手机音量已打开</div>'
            . '</div></div>';

        $tpl = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>__TITLE__ · 翻书欣赏</title>
<style>
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html,body{height:100%}
  body{margin:0;background:radial-gradient(130% 100% at 50% 0%,#5a4632 0%,#40311f 55%,#2c2114 100%);
       font-family:"Songti SC","STSong",Georgia,"Noto Serif SC",serif;color:#f3e7d3;
       display:flex;flex-direction:column;align-items:center;min-height:100vh;padding:14px 12px 8px;overflow-x:hidden}
  .iconbtn{position:fixed;top:14px;left:14px;z-index:99;width:40px;height:40px;border-radius:50%;
           border:1px solid rgba(243,231,211,.35);background:rgba(0,0,0,.28);color:#f3e7d3;font-size:17px;cursor:pointer;transition:.2s}
  .iconbtn:hover{background:rgba(0,0,0,.45)}
  .topbar{position:fixed;top:14px;right:14px;z-index:99;display:flex;gap:10px;align-items:center}
  .chip{font-family:inherit;font-size:13px;color:#f3e7d3;background:rgba(0,0,0,.28);border:1px solid rgba(243,231,211,.35);
        border-radius:999px;padding:9px 16px;cursor:pointer;display:flex;align-items:center;gap:6px;transition:.2s}
  .chip:hover{background:rgba(0,0,0,.45)}
  .chip.on{background:#c98a3d;border-color:#c98a3d;color:#2c2114;font-weight:600}
  .narr{position:relative}
  .menu{position:absolute;top:46px;right:0;min-width:150px;background:#fdf8ef;border-radius:10px;overflow:hidden;
        box-shadow:0 14px 34px rgba(0,0,0,.4);display:none;z-index:100}
  .menu.open{display:block}
  .menu div{padding:11px 16px;font-size:13.5px;color:#5a4632;cursor:pointer;border-bottom:1px solid rgba(150,110,50,.15)}
  .menu div:last-child{border-bottom:none}
  .menu div:hover,.menu div.active{background:#f5ead6;color:#a4641f}
  .stage{flex:1;width:100%;display:flex;align-items:center;justify-content:center;perspective:2400px;min-height:0;padding:8px 0}
  .book{position:relative;width:min(92vw,440px);max-height:100%;aspect-ratio:3/4.15;transform-style:preserve-3d}
  .page{position:absolute;inset:0;border-radius:10px;overflow:hidden;transform-origin:left center;
        transition:transform .4s cubic-bezier(.3,.7,.3,1),opacity .4s;box-shadow:0 22px 50px rgba(0,0,0,.5);
        background:#f7f1e6;visibility:hidden;z-index:30;will-change:transform}
  .page.first{visibility:visible;z-index:50}
  .page .spine{position:absolute;left:0;top:0;bottom:0;width:10px;background:linear-gradient(90deg,rgba(60,40,15,.35),rgba(60,40,15,.05))}
  .page.cover{background-size:cover;background-position:center}
  .page .veil{position:absolute;inset:0;background:linear-gradient(180deg,rgba(30,20,10,.45),rgba(30,20,10,.32) 40%,rgba(30,20,10,.75))}
  .page .inner{position:absolute;inset:0;padding:26px 24px;display:flex;flex-direction:column}
  .cover .inner{color:#fff8ec;justify-content:flex-end;text-shadow:0 2px 12px rgba(0,0,0,.5)}
  .cover .badge{align-self:flex-start;font-size:12px;letter-spacing:3px;border:1px solid rgba(255,248,236,.7);border-radius:999px;padding:4px 12px;margin-bottom:auto}
  .cover .ctitle{font-size:29px;font-weight:700;letter-spacing:2px;margin:0 0 10px;line-height:1.35}
  .cover .cmeta{font-size:13.5px;line-height:1.9;opacity:.95;margin-bottom:10px}
  .cover .cdesc{font-size:13px;line-height:1.85;opacity:.92;border-top:1px solid rgba(255,248,236,.35);padding-top:10px}
  .cover .cfoot{margin-top:14px;font-size:11.5px;letter-spacing:2px;opacity:.75;text-align:center}
  .page-bg{position:absolute;inset:0;background-size:cover;background-position:center;opacity:.28}
  .chapter .inner{position:absolute;inset:0;padding:30px 26px 44px;display:flex;flex-direction:column}
  .chapter h2{font-size:20px;letter-spacing:1px;margin:0 0 14px;padding-bottom:10px;border-bottom:2px solid rgba(150,110,50,.35);color:#6a4f2a}
  .chapter .body{font-size:15px;line-height:2.05;color:#4a3b2a;overflow:auto;flex:1;white-space:pre-wrap;word-break:break-word;text-align:justify}
  .chapter .body[hidden]{display:none}
  .chapter .body.empty{color:#a89676;font-style:italic;text-align:center;margin-top:40%}
  .audioflag{position:absolute;top:16px;right:16px;font-family:inherit;font-size:11px;color:#a4641f;background:rgba(201,138,61,.15);
             border:1px solid rgba(164,100,31,.35);border-radius:999px;padding:4px 11px;cursor:pointer;transition:.2s;z-index:2}
  .audioflag:hover{background:rgba(201,138,61,.3)}
  .audioflag.on{background:#c98a3d;border-color:#c98a3d;color:#fff8ec;font-weight:600}
  /* 尾页：结束语 */
  .page.ending{background:linear-gradient(165deg,#fbf6ec 0%,#f3e7d3 58%,#e9d8bb 100%)}
  .ending .inner{align-items:center;justify-content:center;text-align:center;padding:44px 30px}
  .ending .endtitle{font-size:27px;font-weight:700;letter-spacing:8px;color:#6a4f2a;text-indent:8px;text-shadow:0 1px 0 rgba(255,255,255,.6)}
  .ending .endline{width:54px;height:2px;background:rgba(150,110,50,.38);margin:18px 0 22px}
  .ending .endtext{font-size:14.5px;line-height:2.1;color:#6b5946;max-width:300px;margin:0 auto;white-space:pre-wrap;text-align:justify;text-align-last:center}
  .ending .endmark{margin-top:32px;font-size:11.5px;letter-spacing:4px;color:#a89676}
  .pageno{position:absolute;bottom:16px;left:0;right:0;text-align:center;font-size:11.5px;color:#9c896b;letter-spacing:1px}
  .corner{position:absolute;right:0;bottom:0;width:46px;height:46px;background:linear-gradient(135deg,transparent 50%,rgba(150,110,50,.22) 50%);border-bottom-right-radius:10px}
  .hud{display:flex;align-items:center;gap:16px;margin:10px 0 4px}
  .hud button{font-family:inherit;font-size:13.5px;color:#f3e7d3;background:rgba(0,0,0,.28);border:1px solid rgba(243,231,211,.3);
              border-radius:999px;padding:9px 18px;cursor:pointer;transition:.2s}
  .hud button:hover{background:rgba(0,0,0,.45)}
  .hud button:disabled{opacity:.3;cursor:not-allowed}
  .counter{font-size:13px;color:#e8d8bd;min-width:64px;text-align:center;letter-spacing:1px}
  .hint{font-size:11.5px;color:rgba(232,216,189,.55);text-align:center;margin-bottom:4px}
  .siteinfo{font-size:11.5px;color:rgba(232,216,189,.6);text-align:center;padding:6px 0 4px;letter-spacing:.5px}
  .siteinfo .dot{margin:0 8px;opacity:.5}
  /* ---------- 进门蒙层（点一次才允许出声，把这次点击做成封面卡） ---------- */
  .gate{position:fixed;inset:0;z-index:500;display:none;align-items:center;justify-content:center;opacity:0;
        transition:opacity .5s ease;cursor:pointer;background:#1c1409;overflow:hidden}
  .gate.in{display:flex}
  .gate.show{opacity:1}
  .gate .bgimg{position:absolute;inset:0;background-size:cover;background-position:center;
        filter:blur(16px) saturate(.8);opacity:.3;transform:scale(1.1)}
  .gate .veil{position:absolute;inset:0;
        background:radial-gradient(120% 85% at 50% 10%,rgba(96,74,48,.5) 0%,rgba(30,22,12,.86) 58%,rgba(20,14,8,.96) 100%)}
  .gate .card{position:relative;z-index:2;width:min(88vw,432px);padding:40px 26px 32px;text-align:center;
        border:1px solid rgba(226,196,140,.4);border-radius:16px;
        background:linear-gradient(180deg,rgba(60,46,28,.74) 0%,rgba(34,25,14,.88) 100%);
        box-shadow:0 26px 64px rgba(0,0,0,.6),inset 0 0 0 1px rgba(255,238,205,.07);
        animation:gateIn .75s cubic-bezier(.22,.9,.3,1) both}
  .gate .frame{position:absolute;inset:11px;border:1px solid rgba(226,196,140,.18);border-radius:10px;pointer-events:none}
  .gate .skip{position:absolute;top:14px;right:16px;z-index:3;font-family:inherit;font-size:12px;color:rgba(232,214,180,.6);
        background:none;border:none;cursor:pointer;padding:6px 4px;letter-spacing:.5px}
  .gate .skip:hover{color:#e8d6b4}
  .gate .seal{font-size:12px;letter-spacing:9px;color:#d3b782;text-indent:9px;margin-bottom:22px}
  .gate .gtitle{font-size:33px;font-weight:700;letter-spacing:4px;color:#fbeed6;line-height:1.38;
        text-shadow:0 2px 12px rgba(0,0,0,.65)}
  .gate .gsub{margin-top:15px;font-size:13.5px;color:rgba(240,224,194,.8);letter-spacing:1.5px}
  .gate .gdiv{position:relative;width:74px;height:1px;margin:24px auto;overflow:hidden;
        background:linear-gradient(90deg,transparent,rgba(226,196,140,.5),transparent)}
  .gate .gdiv:after{content:'';position:absolute;top:-1px;left:0;width:30px;height:3px;border-radius:3px;
        background:linear-gradient(90deg,transparent,#f5e2b4,transparent);animation:shine 2.6s linear infinite}
  .gate .gmeta{font-size:12.5px;color:rgba(232,214,180,.62);letter-spacing:1px}
  .gate .gbtn{margin:28px auto 0;display:inline-block;font-family:inherit;font-size:19px;letter-spacing:4px;
        text-indent:4px;color:#3a2a12;background:linear-gradient(180deg,#f8e6bd,#dcba79);border:none;
        border-radius:999px;padding:15px 44px;cursor:pointer;box-shadow:0 10px 26px rgba(0,0,0,.42),inset 0 1px 0 rgba(255,255,255,.55);
        animation:gatePulse 2.1s ease-in-out infinite}
  .gate .gtip{margin-top:17px;font-size:12px;color:rgba(232,214,180,.55);letter-spacing:.5px}
  @keyframes gateIn{from{opacity:0;transform:translateY(20px) scale(.965)}to{opacity:1;transform:none}}
  @keyframes shine{0%{transform:translateX(-8px)}100%{transform:translateX(80px)}}
  @keyframes gatePulse{0%,100%{transform:scale(1);box-shadow:0 10px 26px rgba(0,0,0,.42)}
        50%{transform:scale(1.035);box-shadow:0 15px 36px rgba(226,196,140,.34)}}
  @media (max-width:420px){.cover .ctitle{font-size:24px}.chip{padding:8px 12px;font-size:12px}.chapter .body{font-size:14.5px;line-height:1.95}
        .gate .card{padding:34px 20px 28px}.gate .gtitle{font-size:27px}.gate .gbtn{font-size:17px;padding:14px 36px}}
</style>
</head>
<body data-auto="__AUTO__">
  <!-- 背景音乐：必须放在下方 <script> 之前，否则脚本执行时 document.getElementById 取不到它
       （曾经把它放在脚本之后 → bgmEl 为 null → 音乐永远不出声，且不报任何错） -->
  <audio id="bgmAudio" loop preload="metadata" src="__BGM__"></audio>
  <button class="iconbtn" id="btnClose" title="关闭（返回上一页）">✕</button>
  <div class="topbar">
    <button class="chip" id="btnAuto" title="自动翻页">⏱ 自动翻页</button>
    <button class="chip" id="btnBgm" title="背景音乐">🎵 音乐</button>
    <div class="narr">
      <button class="chip" id="btnNarr">🔊 朗读 ▾</button>
      <div class="menu" id="narrMenu">
        <div data-mode="original">原音讲述</div>
        <div data-mode="ai">AI 润声</div>
        <div data-mode="off">关闭朗读</div>
      </div>
    </div>
  </div>
  __GATE__
  <div class="stage"><div class="book" id="book">__PAGES__</div></div>
  <div class="hud">
    <button id="btnPrev" type="button">⏮ 上一页</button>
    <span class="counter" id="counter"></span>
    <button id="btnCover" type="button">封面</button>
    <button id="btnNext" type="button">下一页 ⏭</button>
  </div>
  <div class="hint">点击书本右侧翻页 / 左侧回退 · ← → 键 · 左右滑动</div>
  __SITEINFO__
<script type="application/json" id="bookData">__DATA__</script>
<script>
(function(){
  var DATA = JSON.parse(document.getElementById('bookData').textContent);
  var pages = [].slice.call(document.querySelectorAll('.page'));
  var total = pages.length;
  var cur = 0, animating = false;
  var AUTO_SEC = parseInt(document.body.getAttribute('data-auto') || '30', 10);
  if (isNaN(AUTO_SEC) || AUTO_SEC < 0) AUTO_SEC = 30;
  // 默认进入即「原音讲述」（原声文案 + 自动朗读）；加 ?narr=off 可关闭默认朗读（嵌入/自动化验证用）
  var NARR_DEFAULT = /(\?|&)narr=off(&|$)/.test(window.location.search) ? null : 'original';

  var book = document.getElementById('book');
  var counter = document.getElementById('counter');
  var prevBtn = document.getElementById('btnPrev');
  var nextBtn = document.getElementById('btnNext');
  var coverBtn = document.getElementById('btnCover');
  var btnClose = document.getElementById('btnClose');
  var btnAuto = document.getElementById('btnAuto');
  var btnNarr = document.getElementById('btnNarr');
  var narrMenu = document.getElementById('narrMenu');
  var hintEl = document.querySelector('.hint');
  var HINT_RAW = hintEl ? hintEl.textContent : '';

  /* ---------- 翻页（当前页绕书脊转出，下一页转入；不依赖 backface，任意页数可靠） ---------- */
  function updateHud(){
    counter.textContent = (cur + 1) + ' / ' + total;
    prevBtn.disabled = cur <= 0;
    nextBtn.disabled = cur >= total - 1;
  }
  function show(idx, dir){
    idx = Math.max(0, Math.min(total - 1, idx));
    if (animating || idx === cur) return;
    animating = true;
    var out = pages[cur], inn = pages[idx];
    inn.style.visibility = 'visible';
    inn.style.zIndex = 30;
    inn.style.transform = 'rotateY(' + (dir > 0 ? 92 : -92) + 'deg)';
    inn.style.opacity = '0.35';
    out.style.zIndex = 40;
    void inn.offsetWidth;
    out.style.transform = 'rotateY(' + (dir > 0 ? -92 : 92) + 'deg)';
    out.style.opacity = '0.15';
    window.setTimeout(function(){
      out.style.visibility = 'hidden';
      out.style.transform = 'rotateY(0deg)';
      out.style.opacity = '1';
      cur = idx;
      inn.style.zIndex = 50;
      inn.style.transform = 'rotateY(0deg)';
      inn.style.opacity = '1';
      updateHud();
      onEnterPage(cur);
      window.setTimeout(function(){ inn.style.zIndex = 30; animating = false; }, 420);
    }, 400);
  }
  function next(){ if (animating) return; manualNav = true; if (cur >= total - 1) { show(0, -1); return; } show(cur + 1, 1); }
  function prev(){ if (animating || cur <= 0) return; manualNav = true; show(cur - 1, -1); }
  function goCover(){ if (!animating) { manualNav = true; show(0, -1); } }

  /* ---------- 自动翻页（与朗读互斥：朗读进行中不启动计时器） ---------- */
  var autoTimer = null, autoWanted = false, narrMode = NARR_DEFAULT;
  var narrToken = 0;     // 每次进入页面自增；朗读结束回调据此忽略被取消/覆盖的旧朗读
  var manualNav = false;  // 用户手动翻页后，本次朗读结束不再自动翻页（避免接管节奏失控）
  var narrArmed = false;  // 已选中朗读但尚未出声（等首次用户交互，规避浏览器自动播放限制）
  var narrSound = false;  // 当前是否真的在出声（用于「可朗读」按钮文案）
  var narrBlocked = false; // 出声被浏览器自动播放策略拦截（仅用于提示，不致命）
  function applyTimers(){
    if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
    // 只有「朗读正在出声」时才让位给朗读节奏（读完由 advanceAfterNarration 翻页）；
    // 仅仅「已选中朗读、还没出声」不该挡住自动翻书，否则用户会以为自动翻书没开启
    if (autoWanted && !narrSound && AUTO_SEC > 0) autoTimer = window.setInterval(next, AUTO_SEC * 1000);
    btnAuto.classList.toggle('on', !!autoWanted);
  }
  btnAuto.addEventListener('click', function(){
    autoWanted = !autoWanted;
    if (autoWanted && AUTO_SEC <= 0) {
      autoWanted = false; applyTimers();
      btnAuto.title = '后台未启用自动翻页'; return;
    }
    // 手动开自动翻书时不再强制关闭朗读（两者协同：读完翻页）
    btnAuto.title = '自动翻页';
    applyTimers();
  });

  /* ---------- 有声朗读：原音讲述（多段录音）/ AI 润声（配音或浏览器合成） ---------- */
  var audioEl = null, queue = [], queueErr = false;
  function ensureAudio(){
    if (audioEl) return;
    audioEl = new Audio();
    audioEl.addEventListener('ended', function(){ playNextInQueue(); });
    audioEl.addEventListener('error', function(){ queueErr = true; playNextInQueue(); });
  }
  function stopNarr(){
    queue = [];
    queueErr = false;
    narrSound = false;
    if (audioEl) { try { audioEl.pause(); } catch(e){} }
    try { window.speechSynthesis && window.speechSynthesis.cancel(); } catch(e){}
    updateNarrUI();
    applyTimers();   // 朗读停止 → 恢复自动翻书计时
  }
  function advanceAfterNarration(){
    if (!narrMode) return;
    if (manualNav) { manualNav = false; stopNarr(); return; } // 用户手动翻页后不再自动翻页
    if (cur >= total - 1) { stopNarr(); return; }   // 读完全书最后一页：停止朗读，但保持当前文案模式
    show(cur + 1, 1);
  }
  function playNextInQueue(){
    if (narrMode === null) return;
    if (!queue.length) {
      // 音频不可播（文件损坏/被拦截）时安静停止，不自动翻页，避免整本书被瞬间翻过
      if (queueErr) { queueErr = false; stopNarr(); } else { advanceAfterNarration(); }
      return;
    }
    var url = queue.shift();
    ensureAudio();
    audioEl.src = url;
    audioEl.play().catch(function(err){ playReject(err); });
  }
  function speak(text, done, fail){
    try {
      if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) { done(); return; }
      var u = new SpeechSynthesisUtterance(text || '');
      u.lang = 'zh-CN'; u.rate = 0.95;
      var settled = false;
      u.onend = function(){ if (!settled) { settled = true; done(); } };
      u.onerror = function(){ if (!settled) { settled = true; (fail || done)(); } };
      window.speechSynthesis.speak(u);
      // 兜底：若浏览器自动播放策略拦截语音合成，500ms 后仍无声 → 退回等待手势
      window.setTimeout(function(){
        if (settled || !narrMode || !window.speechSynthesis) return;
        if (!window.speechSynthesis.speaking) { settled = true; speechBlocked(); }
      }, 500);
    } catch(e){ (fail || done)(); }
  }
  /* 正文切换：默认显示原声（口述原文）文案；只有选中「AI 润声」才显示 AI 润色文案 */
  function applyText(i){
    var page = pages[i];
    if (!page) return;
    var ob = page.querySelector('.body.orig'), ab = page.querySelector('.body.ai');
    if (!ob || !ab) return;                       // 封面页无正文
    var wantAi = (narrMode === 'ai');
    if (!!ob.hidden === wantAi && !!ab.hidden === !wantAi) return;
    ob.hidden = wantAi;
    ab.hidden = !wantAi;
  }
  function onEnterPage(i){
    applyText(i);
    if (narrMode === null) return;
    stopNarr();
    narrSound = true;
    updateNarrUI();
    applyTimers();   // 开始出声 → 暂停自动翻书计时（本页读完由 advanceAfterNarration 翻页）
    var token = ++narrToken;
    var done = function(){ if (token === narrToken) advanceAfterNarration(); };
    var fail = function(){ if (token === narrToken) stopNarr(); };   // 无法朗读：安静停止，不翻页
    queueErr = false;
    var d = DATA[i] || {};
    if (narrMode === 'original') {
      queue = (d.audio || []).slice();
      if (queue.length) { playNextInQueue(); }
      // 封面 / 结束页没有「原声录音」：直接用 AI 旁白音频（满足「封面与结束页也用 AI 音」）
      else if (d.dubbed) { queue = [d.dubbed]; playNextInQueue(); }
      else { speak(d.origText || d.aiText || '', done, fail); }
    } else {
      if (d.dubbed) { queue = [d.dubbed]; playNextInQueue(); }
      else { speak(d.aiText || d.origText || '', done, fail); }
    }
  }
  /* 朗读菜单选中态 + 章节「可朗读」入口文案，与当前朗读模式保持同步 */
  function updateNarrUI(){
    [].forEach.call(narrMenu.querySelectorAll('div'), function(n){ n.classList.toggle('active', n.getAttribute('data-mode') === String(narrMode)); });
    btnNarr.classList.toggle('on', !!narrMode);
    var playing = narrSound;   // 正在出声（原音讲述/AI 润声）
    [].forEach.call(document.querySelectorAll('.audioflag'), function(f){
      f.classList.toggle('on', playing);
      f.textContent = playing ? '⏸ 停止朗读' : '🔊 可朗读';
    });
    if (hintEl) hintEl.textContent = (narrArmed && narrMode) ? '🔈 轻触屏幕任意处即可开启声音' : HINT_RAW;
  }
  function setNarr(mode){
    narrMode = mode;
    narrArmed = false;             // 用户已显式选择，无需再等首次手势
    manualNav = false;
    // 自动翻书与朗读共存：选朗读不再关掉自动翻书（朗读出声时计时器让位，读完自动翻页）
    stopNarr();
    updateNarrUI();
    applyTimers();
    onEnterPage(cur);
  }
  /* 章节右上角「可朗读」：点击播放/停止本章朗读，且不触发翻页。
     判定必须用「是否正在出声」而不是「是否已选中朗读模式」：
     否则静音状态下按钮写着「可朗读」、点下去执行的却是「关闭朗读」，
     用户看到的是「点了没反应」，且之后自动翻书还会重新朗读本章。 */
  function togglePageNarration(){
    if (narrSound) { stopNarr(); return; }   // 正在出声 → 停止
    narrArmed = false;                        // 有手势了，直接进入
    setNarr('original');
  }
  /* 首次用户交互后再出声（浏览器自动播放限制）；点「可朗读」按钮本身不算，交给该按钮处理 */
  function startNarration(){
    if (!narrArmed) return;
    narrArmed = false;
    narrBlocked = false;
    updateNarrUI();
    if (narrMode) onEnterPage(cur);
  }
  function armNarrationStart(){
    var once = function(e){
      var t = e.target;
      if (t && t.closest && t.closest('.audioflag')) return;   // 交给「可朗读」按钮自己处理
      document.removeEventListener('pointerdown', once);
      document.removeEventListener('touchstart', once);
      document.removeEventListener('keydown', once);
      startNarration();
    };
    document.addEventListener('pointerdown', once, { passive: true });
    document.addEventListener('touchstart', once, { passive: true });
    document.addEventListener('keydown', once);
  }
  /* 载入即尝试出声（免点击直接开始）；被浏览器自动播放策略拦截时，由 playReject/speechBlocked 退回等手势 */
  function startNarrationNow(){
    narrArmed = false;
    narrBlocked = false;
    updateNarrUI();
    if (!narrMode) return;
    onEnterPage(cur);
  }
  /* 音频播放被自动播放策略拦截：恢复自动翻书、退回等首次手势、给出轻提示 */
  function playReject(err){
    if (narrMode && err && err.name === 'NotAllowedError') {
      narrBlocked = true;
      narrArmed = true;
      stopNarr();
      armNarrationStart();
      updateNarrUI();
      return;
    }
    queueErr = true;
    playNextInQueue();
  }
  function speechBlocked(){
    if (!narrMode) return;
    narrBlocked = true;
    narrArmed = true;
    stopNarr();
    armNarrationStart();
    updateNarrUI();
  }
  btnNarr.addEventListener('click', function(e){ e.stopPropagation(); narrMenu.classList.toggle('open'); });
  document.addEventListener('click', function(){ narrMenu.classList.remove('open'); });
  narrMenu.addEventListener('click', function(e){
    var m = e.target.getAttribute('data-mode');
    if (!m) return;
    setNarr(m === 'off' ? null : m);
  });

  /* ---------- 交互 ---------- */
  prevBtn.addEventListener('click', prev);
  nextBtn.addEventListener('click', next);
  coverBtn.addEventListener('click', goCover);
  btnClose.addEventListener('click', function(){
    if (window.history.length > 1) window.history.back(); else window.close();
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'ArrowRight') next();
    else if (e.key === 'ArrowLeft') prev();
    else if (e.key === 'Escape') btnClose.click();
  });
  book.addEventListener('click', function(e){
    var t = e.target;
    if (t && t.closest && t.closest('.audioflag')) { togglePageNarration(); return; }   // 点「可朗读」只控制朗读，不翻页
    var r = book.getBoundingClientRect();
    if (e.clientX - r.left < r.width * 0.28) prev(); else next();
  });
  var sx = null;
  book.addEventListener('touchstart', function(e){ sx = e.touches[0].clientX; }, {passive:true});
  book.addEventListener('touchend', function(e){
    if (sx === null) return;
    var dx = e.changedTouches[0].clientX - sx;
    if (Math.abs(dx) > 40) { dx < 0 ? next() : prev(); }
    sx = null;
  }, {passive:true});

  updateHud();
  /* ---------- 背景音乐（后台音乐池随机一首；默认播放，音量 0.22 循环） ----------
     浏览器要求「用户手势」之后才允许出声，所以策略是：
       ① 载入即先尝试播一次（策略宽松的环境可立刻响）；
       ② 否则挂上任意首次交互（点击/触摸/按键/滚轮），一有手势就起播，成功后卸掉监听；
          ⚠️ 不能再用 { once:true } —— 若第一次手势时播放仍被拦（音频还没加载完等），监听会被消耗掉，从此再也不播。
       ③ 「音乐」按钮只负责播放/静音切换，高亮由真实的 play/pause 事件驱动，
          而不是「配了 src 就算开」——否则按钮一开始就亮着、用户一点反而把它关成静音。 */
  var bgmEl = document.getElementById('bgmAudio'), btnBgm = document.getElementById('btnBgm');
  var bgmWanted = !!(bgmEl && bgmEl.getAttribute('src'));   // 用户意图：默认想听
  var bgmArmed = [];                                        // 待卸的交互监听类型
  function syncBgmUI(){
    if (!btnBgm) return;
    var playing = !!(bgmEl && !bgmEl.paused && !bgmEl.ended);
    btnBgm.classList.toggle('on', playing);
    if (!bgmEl || !bgmEl.getAttribute('src')) btnBgm.title = '未配置背景音乐';
    else if (!bgmWanted) btnBgm.title = '背景音乐已关闭，点击开启';
    else if (playing) btnBgm.title = '背景音乐播放中，点击静音';
    else btnBgm.title = '点击播放背景音乐';
  }
  function onBgmGesture(){ tryPlayBgm(); }
  function detachBgmArmed(){
    for (var i = 0; i < bgmArmed.length; i++) window.removeEventListener(bgmArmed[i], onBgmGesture);
    bgmArmed = [];
  }
  function tryPlayBgm(){
    if (!bgmEl || !bgmWanted) return;
    if (!bgmEl.getAttribute('src')) { detachBgmArmed(); return; }
    bgmEl.volume = 0.22;
    var pr = bgmEl.play();
    if (!pr || !pr.then) { detachBgmArmed(); syncBgmUI(); return; }
    pr.then(function(){ detachBgmArmed(); syncBgmUI(); })
      .catch(function(){ syncBgmUI(); });   // 仍被自动播放策略拦住：保留监听，等下一次手势
  }
  if (bgmEl) {
    bgmEl.addEventListener('play', syncBgmUI);
    bgmEl.addEventListener('pause', syncBgmUI);
    // 音频就绪后再试一次，提升「载入即直接播放」的成功率
    bgmEl.addEventListener('canplay', function(){ if (bgmEl.paused) tryPlayBgm(); });
  }
  if (btnBgm) {
    btnBgm.addEventListener('click', function(e){
      e.stopPropagation();
      if (!bgmEl || !bgmEl.getAttribute('src')) return;
      if (bgmEl.paused) { bgmWanted = true; tryPlayBgm(); }
      else { bgmWanted = false; bgmEl.pause(); }
      syncBgmUI();
    });
    ['pointerdown', 'touchstart', 'keydown', 'wheel'].forEach(function(t){
      window.addEventListener(t, onBgmGesture, { passive: true });
      bgmArmed.push(t);
    });
    tryPlayBgm();
    syncBgmUI();
  }
  /* ---------- 进门蒙层 ----------
     浏览器要求「先有用户手势」才允许出声；与其让长辈去猜「点哪里」，不如把这次点击做成
     一张封面卡：点「开始欣赏」= 朗读 + 配乐 + 自动翻书一起启动。
     - 蒙层期间不朗读、不计时翻页，全部推迟到点击之后；
     - ?gate=off 关闭蒙层（嵌入/自动化验证用），?narr=off 且无配乐时也不再显示（没有可播放内容）。 */
  var gate = document.getElementById('gate');
  var gateOff = /(\?|&)gate=off(&|$)/.test(window.location.search);
  var gateOn = !!(gate && !gateOff && (narrMode || bgmWanted));
  var gateClosed = false;
  function closeGateLayer(){
    gateClosed = true;
    gate.classList.remove('show');
    window.setTimeout(function(){ gate.classList.remove('in'); }, 520);
  }
  function dismissGate(){
    if (!gateOn || gateClosed) return;
    closeGateLayer();
    if (narrMode) startNarrationNow();   // 此刻有手势，出声不会被拦
    tryPlayBgm();
    applyTimers();                       // 点击后才开始自动翻书计时
  }
  /* 「暂不欣赏」：只收起蒙层，不主动出声；退回「轻触任意处开始」的老兜底，保留访客的退出路径 */
  function skipGate(){
    if (!gateOn || gateClosed) return;
    closeGateLayer();
    if (narrMode) { narrArmed = true; armNarrationStart(); updateNarrUI(); }
    applyTimers();
  }
  if (gateOn) {
    gate.classList.add('in');
    window.requestAnimationFrame(function(){ gate.classList.add('show'); });
    gate.addEventListener('click', dismissGate);
    var skipBtn = document.getElementById('gateSkip');
    if (skipBtn) skipBtn.addEventListener('click', function(e){ e.stopPropagation(); skipGate(); });
    document.addEventListener('keydown', function(e){
      if (!gateClosed && (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar')) dismissGate();
      else if (!gateClosed && e.key === 'Escape') skipGate();
    });
  }
  // 默认：**自动翻书 + 原声朗读同时开启**（?narr=off 时只保留自动翻书）
  // 两者不再互斥：朗读出声期间计时器让位，读完由 advanceAfterNarration() 翻到下一页接着读
  if (AUTO_SEC > 0 && total > 1) { autoWanted = true; }
  // 免点击直接出声；有蒙层时推迟到点击那一刻（dismissGate 里启动）
  if (narrMode && !gateOn) { startNarrationNow(); }
  updateNarrUI();
  applyText(cur);
  if (!gateOn) applyTimers();
})();
</script>
</body>
</html>
HTML;

        $html = str_replace(
            ['__TITLE__', '__PAGES__', '__DATA__', '__AUTO__', '__SITEINFO__', '__BGM__', '__GATE__'],
            [
                htmlspecialchars((string) $project['name'], ENT_QUOTES, 'UTF-8'),
                $pagesHtml,
                json_encode($bookData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                (string) $auto,
                $siteHtml,
                htmlspecialchars($bgmUrl, ENT_QUOTES, 'UTF-8'),
                $gateHtml,
            ],
            $tpl
        );

        return Response::create($html, 'html', 200)
            ->header(['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** 朗读用的纯文本（封面/尾页旁白；章节页直接用 orig/ai 文案） */
    private function speakText(array $p): string
    {
        if (($p['type'] ?? '') === 'ending') {
            $t = trim(preg_replace('/\s+/u', ' ', (string) ($p['text'] ?? '')));
            return $t !== '' ? $t : '感谢欣赏';
        }
        if (($p['type'] ?? '') === 'cover') {
            $parts = [(string) $p['title']];
            if (!empty($p['real_name'])) {
                $parts[] = '传主' . $p['real_name'];
            }
            if (!empty($p['description'])) {
                $parts[] = (string) $p['description'];
            }
            return implode('。', $parts);
        }
        $text = trim((string) ($p['orig'] ?? ($p['ai'] ?? '')));
        return ($text !== '' ? $text : '本章暂无内容');
    }

    /** 渲染单页 HTML */
    private function pageHtml(array $p, int $no, int $total): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $first = $no === 1 ? ' first' : '';

        if ($p['type'] === 'cover') {
            $bgStyle = $p['bg'] ? ' style="background-image:url(\'' . $e($p['bg']) . '\')"' : '';
            $meta = [];
            if ($p['real_name'] !== '') {
                $meta[] = '传主：' . $e($p['real_name']);
            }
            if ($p['birth'] !== '') {
                $meta[] = '出生于 ' . $e($p['birth']);
            }
            if ($p['native_place'] !== '') {
                $meta[] = '籍贯：' . $e($p['native_place']);
            }
            $metaHtml = $meta ? '<div class="cmeta">' . implode(' · ', $meta) . '</div>' : '';
            $desc = trim((string) $p['description']);
            $descHtml = $desc !== ''
                ? '<div class="cdesc">' . nl2br($e($desc)) . '</div>'
                : '<div class="cdesc">岁月无声，故事有痕。愿这些文字，替 TA 记住来时的路。</div>';
            $flag = !empty($p['dubbed'])
                ? '<button class="audioflag" type="button" title="播放 AI 旁白">🔊 可朗读</button>'
                : '';

            return '<div class="page cover' . $first . '"' . $bgStyle . '>'
                . '<div class="veil"></div><div class="spine"></div>'
                . $flag
                . '<div class="inner">'
                . '<div class="badge">回 忆 录</div>'
                . '<h1 class="ctitle">' . $e($p['title']) . '</h1>'
                . $metaHtml . $descHtml
                . '<div class="cfoot">—— 翻阅此页，走进 TA 的一生 ——</div>'
                . '</div></div>';
        }

        if ($p['type'] === 'ending') {
            $lines = preg_split('/\r\n|\r|\n/', trim((string) $p['text'])) ?: [];
            $head  = trim((string) array_shift($lines));
            $rest  = trim(implode("\n", $lines));
            $flag = !empty($p['dubbed'])
                ? '<button class="audioflag" type="button" title="播放 AI 旁白">🔊 可朗读</button>'
                : '';
            return '<div class="page ending">'
                . '<div class="spine"></div>'
                . $flag
                . '<div class="inner">'
                . '<div class="endtitle">' . $e($head !== '' ? $head : '感谢欣赏') . '</div>'
                . '<div class="endline"></div>'
                . ($rest !== '' ? '<div class="endtext">' . nl2br($e($rest)) . '</div>' : '')
                . '<div class="endmark">—— 全 书 完 ——</div>'
                . '</div>'
                . '<div class="pageno">' . $no . ' / ' . $total . '</div>'
                . '</div>';
        }

        $bgHtml = $p['bg'] ? '<div class="page-bg" style="background-image:url(\'' . $e($p['bg']) . '\')"></div>' : '';
        // 正文两套：orig = 原声（口述原文）默认可见；ai = AI 润色，选「AI 润声」时才显示
        $orig = trim((string) ($p['orig'] ?? ''));
        $ai   = trim((string) ($p['ai'] ?? ''));
        if ($orig === '' && $ai === '') {
            $body = '<div class="body orig empty">（本章暂无内容）</div>';
        } else {
            $body = '<div class="body orig">' . nl2br($e($orig)) . '</div>'
                . '<div class="body ai" hidden>' . nl2br($e($ai)) . '</div>';
        }
        // 本章有原声录音/配音时，右上角给出可点击的「可朗读」入口（点击播放/停止本章原声，不翻页）
        $flag = !empty($p['audio']) || !empty($p['dubbed'])
            ? '<button class="audioflag" type="button" title="播放本章原声">🔊 可朗读</button>'
            : '';

        return '<div class="page chapter' . $first . '">' . $bgHtml . '<div class="spine"></div>'
            . '<div class="inner">'
            . '<h2>' . $e($p['title']) . '</h2>'
            . $flag . $body
            . '</div>'
            . '<div class="corner"></div>'
            . '<div class="pageno">' . $no . ' / ' . $total . '</div>'
            . '</div>';
    }
}
