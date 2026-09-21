-- 签到记录表补充 activity_id 列（多活动化遗留缺失，CheckIn::perform 写入时报
-- "fields not exists:[activity_id]" 导致C端签到全量失败）
ALTER TABLE `nft_check_in_records`
    ADD COLUMN `activity_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '签到活动ID' AFTER `user_id`,
    ADD KEY `idx_activity` (`activity_id`);
