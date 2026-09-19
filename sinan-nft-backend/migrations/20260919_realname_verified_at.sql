-- 实名审核通过时间列（RealnameController::doAudit 写入、ActivityRewardService 注册活动实名排位读取）
-- 现状：代码读写 realname_verified_at，nft_users 表缺列 → 实名审核通过接口 500（数据表字段不存在）

ALTER TABLE `nft_users`
    ADD COLUMN `realname_verified_at` DATETIME NULL DEFAULT NULL COMMENT '实名审核通过时间（注册活动排位依据）' AFTER `realname_submitted_at`;
