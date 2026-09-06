-- ============================================================================
-- sinanQZ 融合版最终升级脚本（fusion_final_upgrade.sql）
-- 内容：
--   1. 用户实名审核工作流（realname_status：0未提交 1待审核 2已通过 3已驳回）
--   2. 存量已实名用户回填 realname_status=2
--   3. 新权限点种子补充
-- 特性：幂等，可重复执行（MySQL 5.7+ / MariaDB 10.4+）
-- 用法：mysql -uroot -p sinan_nft < database/fusion_final_upgrade.sql
-- ============================================================================

USE `sinan_nft`;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. 用户表：实名审核工作流字段
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `proc_fusion_final`;
DELIMITER $$
CREATE PROCEDURE `proc_fusion_final`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_users' AND COLUMN_NAME = 'realname_status') THEN
    ALTER TABLE `nft_users`
      ADD COLUMN `realname_status` TINYINT NOT NULL DEFAULT 0 COMMENT '实名审核状态：0未提交 1待审核 2已通过 3已驳回' AFTER `is_realname`,
      ADD COLUMN `realname_submitted_at` DATETIME NULL DEFAULT NULL COMMENT '最近提交实名时间' AFTER `realname_status`,
      ADD COLUMN `realname_reject_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT '最近驳回原因' AFTER `realname_submitted_at`;
  END IF;
END$$
DELIMITER ;

CALL `proc_fusion_final`();
DROP PROCEDURE IF EXISTS `proc_fusion_final`;

-- ---------------------------------------------------------------------------
-- 2. 存量已实名用户回填为「已通过」
-- ---------------------------------------------------------------------------
UPDATE `nft_users` SET `realname_status` = 2 WHERE `is_realname` = 1 AND `realname_status` = 0;

-- ---------------------------------------------------------------------------
-- 3. 权限点种子（realname:audit 已存在则跳过）
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `nft_admin_permissions` (`id`, `name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`) VALUES
(302, '实名审核操作', 'realname:audit', 'realname', 2, 300, '', '', 2);

INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `nft_admin_permissions`;
INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`) VALUES
(2, 302),  -- 运营：实名审核
(4, 302);  -- 风控：实名审核
