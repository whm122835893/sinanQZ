-- ============================================================================
-- 管理后台迁移 05：种子数据（角色 + 管理员账号）
-- 依据：《管理后台开发文档》4.2.1 / 7.1
-- 幂等：可重复执行（INSERT ... ON DUPLICATE KEY UPDATE / UPDATE）
--
-- 初始账号（仅开发环境，生产必须改密）：
--   超级管理员 admin   / Admin123456
--   运营       yunying  / Operator123456
-- 权限 P0 由 PermissionMap 静态维护，本文件只种角色与管理员；
-- P3 升级库表驱动时再补 nft_admin_permissions / nft_admin_role_permissions 种子。
-- ============================================================================

-- 5 个内置角色（id 与 role 枚举一致，固定 1~5）
INSERT INTO `nft_admin_roles` (`id`,`name`,`code`,`description`,`status`) VALUES
  (1,'超级管理员','super_admin','拥有全部权限，含平台清库、完整实名查看等最高风险操作',1),
  (2,'运营','operator','藏品/盲盒/活动/CMS/基础用户管理/订单查看',1),
  (3,'财务','finance','订单管理/钱包流水/充值记录/财务报表/手续费统计/收支导出',1),
  (4,'风控','risk','黑名单/风控告警/安全事件/异常交易审批/实名完整查看',1),
  (5,'客服','customer_service','工单处理/基础用户查询（脱敏）/用户资产查看',1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `code`=VALUES(`code`), `description`=VALUES(`description`), `status`=VALUES(`status`);

-- 超级管理员（admin / Admin123456）
INSERT INTO `nft_admin_users` (`username`,`password`,`real_name`,`role`,`status`,`must_change_pwd`) VALUES
  ('admin','$2y$10$TIYRnPmeju1M/p5Ab3BK8u.iw6XP7WDE2PLcC3bvS8nloKJjHntba','平台超管',1,1,0)
ON DUPLICATE KEY UPDATE `role`=1, `status`=1;

-- 运营（yunying / Operator123456）——用于验证菜单按权限渲染
INSERT INTO `nft_admin_users` (`username`,`password`,`real_name`,`role`,`status`,`must_change_pwd`) VALUES
  ('yunying','$2y$10$lreNCqk2R9W3erDyNd/2eOMCyfGumrywt1rokfyUadY3pS.EIJHyS','运营账号',2,1,0)
ON DUPLICATE KEY UPDATE `role`=2, `status`=1;
