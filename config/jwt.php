<?php
return [
    'secret'      => env('JWT_SECRET', 'change_me_to_a_long_random_secret_string'),
    // 用户要求「登录后不主动退出」：access 长效（30 天），前端在 401 时还会用 refresh 静默续期，
    // refresh 180 天，基本上只有长时间不打开才会需要重新登录。
    'access_ttl'  => 2592000,   // access token 30 天
    'refresh_ttl' => 15552000,  // refresh token 180 天
    'algo'        => 'HS256',
];
