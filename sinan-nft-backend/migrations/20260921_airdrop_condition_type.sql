-- ============================================================
-- 空投管理：支持「条件筛选」资格类型
-- 1. nft_airdrop_activities.type 枚举增加 'condition'
-- 2. nft_airdrop_eligibilities.task_type 枚举增加 'condition' / 'direct'
--    （名单由管理端按条件/全量生成，非用户行为事件触发）
-- 幂等：可重复执行（MODIFY 重复应用无副作用）
-- ============================================================

ALTER TABLE `nft_airdrop_activities`
  MODIFY COLUMN `type` ENUM('direct','hold','checkin','register','login','invite','condition') NOT NULL COMMENT '资格来源：direct=全部用户 hold=持有快照藏品 checkin=连续签到 register=注册 login=登录 invite=邀请 condition=条件筛选';

ALTER TABLE `nft_airdrop_eligibilities`
  MODIFY COLUMN `task_type` ENUM('hold','checkin','register','login','invite','condition','direct') NOT NULL COMMENT '资格产生方式：hold=持有 checkin=签到 register=注册 login=登录 invite=邀请 condition=条件筛选 direct=全量名单';
