-- ============================================================================
-- sinanQZ 融合版最终升级脚本（fusion_final_upgrade.sql）
-- 内容：
--   1. 用户实名审核工作流（realname_status：0未提交 1待审核 2已通过 3已驳回）
--   2. 存量已实名用户回填 realname_status=2
--   3. 新权限点种子补充
--   4. 收件箱表 nft_inbox（代码已引用但原仓库漏建，在此补入）
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

-- ---------------------------------------------------------------------------
-- 4. 收件箱表 nft_inbox
-- 用途：C 端空投/转赠到达弹窗数据源 + 标记已读
-- 代码引用：app/controller/Inbox.php / Transfers.php / CollectibleController.php
-- 说明：原仓库漏建，在此补入。CREATE TABLE IF NOT EXISTS 幂等安全。
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_inbox` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`          BIGINT UNSIGNED NOT NULL                COMMENT '收件用户ID，FK→nft_users.id',
  `type`             ENUM('airdrop','transfer') NOT NULL      COMMENT '通知类型：airdrop空投/transfer转赠',
  `ref_id`           BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '关联业务ID（airdrop_task.id 或 transfers.id）',
  `title`            VARCHAR(100)    NOT NULL                COMMENT '通知标题，如"恭喜你收到空投藏品"',
  `collectible_id`   INT UNSIGNED    NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id',
  `name`             VARCHAR(100)    NOT NULL                COMMENT '藏品名称快照',
  `image`            VARCHAR(500)    NULL DEFAULT NULL       COMMENT '藏品图片URL快照',
  `extra`            JSON            NULL DEFAULT NULL       COMMENT '扩展字段：空投含{quantity,reason,adminName}；转赠含{fromNickname}',
  `status`           TINYINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '状态：0未读/1已读/2已确认',
  `read_at`          DATETIME(3)     NULL DEFAULT NULL       COMMENT '已读时间',
  `confirmed_at`     DATETIME(3)     NULL DEFAULT NULL       COMMENT '确认时间',
  `created_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_type_ref` (`type`, `ref_id`),
  KEY `idx_collectible` (`collectible_id`),
  CONSTRAINT `fk_inbox_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_inbox_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收件箱：空投/转赠到达通知，支持未读弹窗与已读标记';
