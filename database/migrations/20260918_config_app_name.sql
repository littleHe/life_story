-- =====================================================================
-- 2026-09-18 补齐后台「参数设置」缺失的 app_name 键
--
-- 背景：20260915_admin_config_project.sql 建 ls_config 时只种了
--   preview_auto_flip_seconds / company_* / service_phone 等 5 个键，
--   漏了白名单里的 app_name。ConfigController@index 遍历白名单时裸取
--   $map['app_name'] → PHP 8 报 Undefined array key（被 TP 升级为
--   ErrorException）→ 后台「参数设置」页整体 500。
--
-- 说明：代码侧已改为 null 合并兜底（任何新增白名单键都不会再炸），
--   本迁移负责让库中真的有这一行，后台首次进入即显示默认值。
--
-- 执行：mysql -u root -proot life_story < database/migrations/20260918_config_app_name.sql
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO `ls_config` (`config_key`, `config_value`, `remark`, `created_at`, `updated_at`)
SELECT 'app_name', '人生回忆录', '站点名称（浏览器标题）', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_config` WHERE `config_key` = 'app_name');
