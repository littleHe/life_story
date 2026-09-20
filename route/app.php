<?php
use think\facade\Route;
use app\middleware\Auth;
use app\middleware\ApiLog;
use app\middleware\AdminAuth;
use app\middleware\RateLimitLock;

// 控制器统一用「完整类名@方法」写法，避免 TP6 对子目录命名空间的解析歧义
$Api   = 'app\controller\Api\\';
$Admin = 'app\controller\Admin\\';

// ===== 公开接口 =====
// 登录页启动参数：微信网页授权是否已配置 + 是否允许测试登录（不暴露 secret）
Route::get('api/auth/wechat/config', $Api . 'AuthController@wechatConfig');
Route::post('api/auth/wechat/login', $Api . 'AuthController@wechatLogin')
    ->middleware(RateLimitLock::class, 'auth_login');
Route::post('api/auth/refresh', $Api . 'AuthController@refresh');
// 测试环境专用：绕过微信，直接登录固定测试账号（生产环境 APP_ENV=production 时自动禁用）
Route::post('api/auth/test/login', $Api . 'AuthController@testLogin');
// 公开站点信息（浏览器标题/公司信息），无需登录
Route::get('api/site/config', $Api . 'SiteController@config');

// ===== 亲友免登录「访谈」录制页（凭 interview_token 访问，公开） =====
// token 形如 bin2hex(random_bytes(16))（纯 hex），但放宽正则让任何非法 token 也路由到控制器并返回干净的 404，避免 500
Route::get('api/interview/:token', $Api . 'InterviewController@show')
    ->pattern(['token' => '[\w-]+']);
Route::post('api/interview/:token/recording', $Api . 'InterviewController@record')
    ->pattern(['token' => '[\w-]+'])
    ->middleware(RateLimitLock::class, 'interview');
// 亲友免登录删除一段录音（录错了可删；音频文件一并清理，口述原文自动重算）
Route::post('api/interview/:token/recording/delete', $Api . 'InterviewController@deleteRecording')
    ->pattern(['token' => '[\w-]+'])
    ->middleware(RateLimitLock::class, 'interview');
Route::post('api/interview/:token/asr', $Api . 'InterviewController@asr')
    ->pattern(['token' => '[\w-]+'])
    ->middleware(RateLimitLock::class, 'interview');
// 亲友免登录录音引导（漫画气泡文案），与用户端 /chapters/:id/guidance 同一套 AI 通道
Route::post('api/interview/:token/guidance', $Api . 'InterviewController@guidance')
    ->pattern(['token' => '[\w-]+'])
    ->middleware(RateLimitLock::class, 'interview');

// ===== 需登录接口 =====
Route::group('api', function () use ($Api) {
    Route::post('auth/logout', $Api . 'AuthController@logout');
    Route::get('projects', $Api . 'ProjectController@index');
    Route::post('projects', $Api . 'ProjectController@save');
    Route::get('projects/:id', $Api . 'ProjectController@read');
    Route::post('projects/:id', $Api . 'ProjectController@update');
    Route::get('projects/:id/chapters', $Api . 'ProjectController@chaptersIndex');
    Route::patch('projects/:id/chapters', $Api . 'ProjectController@chapters')
        ->middleware(RateLimitLock::class, 'user_global');
    Route::post('projects/:id/finalize', $Api . 'ProjectController@finalize');
    Route::post('projects/:id/bind-code', $Api . 'ProjectController@bindCode')
        ->middleware(RateLimitLock::class, 'user_global');
    Route::post('projects/:id/interview-token', $Api . 'InterviewController@generate');
    Route::post('chapters/:id/recording', $Api . 'ChapterController@uploadRecording');
    // 兑换码：激活 / 我的兑换码
    Route::post('redeem/activate', $Api . 'RedeemController@activate');
    Route::get('redeem/codes', $Api . 'RedeemController@codes');
    Route::post('chapters/:id/recording/delete', $Api . 'ChapterController@deleteRecording');
    // 通用上传：传主头像
    Route::post('upload/avatar', $Api . 'UploadController@avatar');
    Route::post('chapters/:id/background', $Api . 'ChapterController@uploadBackground');
    Route::post('chapters/:id/asr', $Api . 'ChapterController@asr')
        ->middleware(RateLimitLock::class, 'ai_submit');
    Route::post('chapters/:id/guidance', $Api . 'ChapterController@guidance')
        ->middleware(RateLimitLock::class, 'ai_submit');
    Route::post('chapters/:id/polish', $Api . 'ChapterController@polish')
        ->middleware(RateLimitLock::class, 'ai_submit');
    Route::post('chapters/:id/illustrate', $Api . 'ChapterController@illustrate')
        ->middleware(RateLimitLock::class, 'ai_submit');
    Route::post('chapters/:id/dub', $Api . 'ChapterController@dub');
    Route::post('chapters/:id/confirm', $Api . 'ChapterController@confirm');
    Route::post('chapters/:id/save', $Api . 'ChapterController@save');
    Route::get('tasks/:task_id', $Api . 'TaskController@show');
})
    // ApiLog 在外层：先记录开始时间，Auth 抛 401 时也能落库（对应用户行为/接口log）
    ->middleware(ApiLog::class)
    ->middleware(Auth::class);

// ===== 后台管理接口（需登录 + 角色校验在控制器内） =====
Route::group('api/admin', function () use ($Admin) {
    Route::post('codes', $Admin . 'CodeController@create');
    Route::get('verification-logs', $Admin . 'CodeController@logs');
})->middleware(Auth::class);

// =====================================================================
// 后台管理面板接口 /admin-api/*
// 鉴权：session（ls_admin 表，独立登录页），由 AdminAuth 中间件统一拦截
// 页面：public/admin/*.html（静态页 + layui/layuimini，复刻 booking 的后台外壳）
// =====================================================================
Route::group('admin-api', function () use ($Admin) {

    // ---- 登录 / 框架初始化 ----
    Route::post('login',  $Admin . 'LoginController@login');
    Route::post('logout', $Admin . 'LoginController@logout');
    Route::get('info',    $Admin . 'LoginController@info');
    Route::get('init',    $Admin . 'AjaxController@init');      // layuimini 菜单初始化
    Route::post('upload', $Admin . 'AjaxController@upload');    // 图片上传
    Route::get('stat',    $Admin . 'IndexController@stat');     // 控制台看板

    // ---- 系统管理：菜单（含图标） ----
    Route::get('menu/index',   $Admin . 'MenuController@index');
    Route::get('menu/options', $Admin . 'MenuController@options');
    Route::post('menu/save',   $Admin . 'MenuController@save');
    Route::post('menu/delete', $Admin . 'MenuController@delete');

    // ---- 系统管理：管理员 ----
    Route::get('adminuser/index',   $Admin . 'AdminUserController@index');
    Route::post('adminuser/save',   $Admin . 'AdminUserController@save');
    Route::post('adminuser/delete', $Admin . 'AdminUserController@delete');

    // ---- 回忆录管理：系统章节 ----
    Route::get('chapter/index',    $Admin . 'ChapterController@index');
    Route::get('chapter/detail',   $Admin . 'ChapterController@detail');
    Route::post('chapter/save',    $Admin . 'ChapterController@save');
    Route::post('chapter/delete',  $Admin . 'ChapterController@delete');

    // ---- 用户管理：授权用户 / 接口日志 ----
    Route::get('user/index',   $Admin . 'UserController@index');
    Route::get('user/detail',  $Admin . 'UserController@detail');
    Route::post('user/save',   $Admin . 'UserController@save');
    Route::post('user/status', $Admin . 'UserController@status');

    Route::get('apilog/index',   $Admin . 'ApiLogController@index');
    Route::get('apilog/stat',    $Admin . 'ApiLogController@stat');
    Route::post('apilog/delete', $Admin . 'ApiLogController@delete');
    Route::post('apilog/clear',  $Admin . 'ApiLogController@clear');

    // ---- 兑换码管理：兑换码 / 兑换码日志 ----
    Route::get('code/index',      $Admin . 'CodeManageController@index');
    Route::post('code/generate',  $Admin . 'CodeManageController@generate');
    Route::post('code/save',      $Admin . 'CodeManageController@save');
    Route::post('code/void',      $Admin . 'CodeManageController@void');
    Route::post('code/delete',    $Admin . 'CodeManageController@delete');
    Route::get('code/export',     $Admin . 'CodeManageController@export');
    Route::post('code/backfill',  $Admin . 'CodeManageController@backfill');   // 历史码回填明文（哈希校验）
    Route::post('code/reissue',   $Admin . 'CodeManageController@reissue');    // 重置明文（重新签发）

    Route::get('codelog/index',   $Admin . 'CodeLogController@index');
    Route::get('codelog/locks',   $Admin . 'CodeLogController@locks');
    Route::post('codelog/unlock', $Admin . 'CodeLogController@unlock');

    // ---- 运营管理：首页幻灯片 ----
    Route::get('slide/index',   $Admin . 'SlideController@index');
    Route::post('slide/save',   $Admin . 'SlideController@save');
    Route::post('slide/delete', $Admin . 'SlideController@delete');

    // ---- 回忆录管理：封面背景图池 ----
    Route::get('coverbg/index',   $Admin . 'CoverBgController@index');
    Route::post('coverbg/save',   $Admin . 'CoverBgController@save');
    Route::post('coverbg/delete', $Admin . 'CoverBgController@delete');

    Route::get('bgm/index',   $Admin . 'BgmController@index');
    Route::post('bgm/save',   $Admin . 'BgmController@save');
    Route::post('bgm/delete', $Admin . 'BgmController@delete');
    // ---- 回忆录管理：默认章节（新建回忆录解锁后自动播种到项目） ----
    Route::get('defaultchapter/index',   $Admin . 'DefaultChapterController@index');
    Route::post('defaultchapter/save',   $Admin . 'DefaultChapterController@save');
    Route::post('defaultchapter/delete', $Admin . 'DefaultChapterController@delete');

    // ---- 回忆录管理：项目（制作信息与状态） ----
    Route::get('project/index',  $Admin . 'ProjectManageController@index');
    Route::get('project/detail', $Admin . 'ProjectManageController@detail');
    Route::post('project/save',  $Admin . 'ProjectManageController@save');
    Route::get('project/export', $Admin . 'ProjectManageController@export');

    // ---- 系统管理：站点参数 ----
    Route::get('config/index',  $Admin . 'ConfigController@index');
    Route::post('config/save',  $Admin . 'ConfigController@save');

})->middleware(AdminAuth::class);

// =====================================================================
// 公开翻书预览页 /preview/{token}（无需登录，凭 token 访问）
// =====================================================================
Route::get('preview/:token', 'app\controller\PreviewController@show');

// (临时调试路由已于排查 admin/api 路由冲突后移除)
