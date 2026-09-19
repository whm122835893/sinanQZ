-- 20260919_realname_audit_and_raffle_lock.sql
-- 修复：
-- 1) nft_users 缺 realname_submitted_at / realname_reject_reason（实名审核列表与详情）
-- 2) nft_raffle_activities 缺 win_locked / drawn_at（开奖锁定）

ALTER TABLE `nft_users`
    ADD COLUMN `realname_submitted_at` DATETIME NULL DEFAULT NULL COMMENT '实名提交时间' AFTER `realname_status`,
    ADD COLUMN `realname_reject_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '实名驳回原因' AFTER `realname_submitted_at`;

ALTER TABLE `nft_raffle_activities`
    ADD COLUMN `win_locked` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '开奖锁定：0未锁 1已锁（开奖后禁改名额/配置）' AFTER `status`,
    ADD COLUMN `drawn_at` DATETIME NULL DEFAULT NULL COMMENT '开奖完成时间' AFTER `win_locked`;

UPDATE `nft_users` SET `realname_submitted_at` = `created_at` WHERE `realname_submitted_at` IS NULL AND `is_realname` = 1;
