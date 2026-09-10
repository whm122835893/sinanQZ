-- ============================================================================
-- F7-D8 升级脚本：nft_verification_codes.scene ENUM → VARCHAR(32)（幂等）
--
-- 背景：scene 建表时为 C 端三场景 ENUM('register','login','reset_password')，
--       管理端敏感操作验证码（SmsService::sendAdminCode，如 platform_cleanup）
--       值域超出 ENUM，插入报 SQLSTATE 01000 / 1265 Data truncated，
--       导致平台清库第四重确认（短信验证码）完全不可用。
-- 方案：scene 扩为 VARCHAR(32)，存量 C 端场景值不受影响，
--       后续管理端新增敏感操作场景（large_refund / asset_modify 等）不再漂移。
-- 同步：init.sql 建表定义已同步为 VARCHAR(32)（v2.2.2）。
-- ============================================================================

SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'nft_verification_codes'
    AND COLUMN_NAME  = 'scene');
SET @ddl := IF(@t = 'varchar(32)',
  'SELECT 1',
  'ALTER TABLE `nft_verification_codes` MODIFY `scene` VARCHAR(32) NOT NULL COMMENT ''使用场景：C端 register/login/reset_password；管理端敏感操作（platform_cleanup 等）''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 校验：扩容后管理端场景可写入（仅报告，不落测试数据）
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'nft_verification_codes'
    AND COLUMN_NAME  = 'scene';
