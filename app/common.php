<?php
// 应用公共文件（全局函数，按需补充）

if (!function_exists('ls_now')) {
    function ls_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
