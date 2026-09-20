<?php
// 微信公众号「网页授权」(H5) 配置
//
// 上线前在 backend/.env 填：
//   WECHAT_APPID=wx........      （公众号 → 设置与开发 → 基本配置 → 公众号开发信息）
//   WECHAT_SECRET=....
//   WECHAT_SCOPE=snsapi_userinfo （默认：首次弹授权页拿昵称/头像；想静默只拿 openid 可用 snsapi_base）
//
// ⚠️ 必须先到「公众号 → 设置与开发 → 公众号设置 → 功能设置 → 网页授权域名」
//    把线上域名填进去（不带 http://、不带路径），否则授权页会报 redirect_uri 参数错误。
// ⚠️ 只有「已认证的服务号」才有 snsapi_userinfo 网页授权权限；订阅号没有该接口权限。
return [
    'appid'  => env('WECHAT_APPID', ''),
    'secret' => env('WECHAT_SECRET', ''),
    'scope'  => env('WECHAT_SCOPE', 'snsapi_userinfo'),
];
