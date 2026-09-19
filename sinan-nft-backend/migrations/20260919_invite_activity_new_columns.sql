-- 邀请活动新版字段（与 MarketingController::inviteSave / ActivityRewardService::settleInviteReward 对齐）
-- 现状：代码读写 tiers / invitee_reward_config / invitee_conditions / grant_mode 四列，表结构缺失导致保存与结算报 Unknown column

ALTER TABLE `nft_invite_activities`
    ADD COLUMN `tiers` TEXT NULL DEFAULT NULL COMMENT '邀请档位奖励JSON：[{inviteCount,rewards:[...]}]' AFTER `airdrop_mode`,
    ADD COLUMN `invitee_reward_config` TEXT NULL DEFAULT NULL COMMENT '被邀请人奖励JSON（六类奖励单选）' AFTER `tiers`,
    ADD COLUMN `invitee_conditions` VARCHAR(255) NULL DEFAULT NULL COMMENT '被邀请人完成条件JSON：realname/wallet/checkin/consume' AFTER `invitee_reward_config`,
    ADD COLUMN `grant_mode` VARCHAR(20) NOT NULL DEFAULT 'realtime' COMMENT '发放方式：realtime 实时 / manual 名单统一发放' AFTER `invitee_conditions`;
