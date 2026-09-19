-- 合成活动补齐参与资格字段（对齐签到/抽奖活动）
-- 后端 synthesisSave 已写 status/eligibility_type/eligibility_config/grant_mode，但旧库缺列导致保存必报错
ALTER TABLE `nft_synthesis_activities`
    ADD COLUMN `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1启用 0停用' AFTER `type`;

ALTER TABLE `nft_synthesis_activities`
    ADD COLUMN `eligibility_type` VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT '参与资格类型' AFTER `rules`;

ALTER TABLE `nft_synthesis_activities`
    ADD COLUMN `eligibility_config` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '参与资格配置JSON' AFTER `eligibility_type`;

ALTER TABLE `nft_synthesis_activities`
    ADD COLUMN `grant_mode` VARCHAR(20) NOT NULL DEFAULT 'realtime' COMMENT '发放方式：realtime 实时 / manual 名单统一发放' AFTER `eligibility_config`;

-- 存量活动默认全部可参与
UPDATE `nft_synthesis_activities` SET `status` = 1, `eligibility_type` = 'all', `eligibility_config` = '', `grant_mode` = 'realtime' WHERE `eligibility_type` = '' OR `eligibility_type` IS NULL;
