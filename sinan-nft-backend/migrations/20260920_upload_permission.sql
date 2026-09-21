-- ============================================================================
-- 20260920_upload_permission.sql
-- 图片上传接口收敛权限：新增 system:upload 权限码（1304），
-- 并给已拥有内容编辑权限（藏品/盲盒/CMS/营销管理）的角色自动补授，避免升级后无法上传。
-- 超级管理员（role_id=1 全量映射）与运营角色（admin_init 新库已含）无需处理。
-- ============================================================================

-- 1) 权限表新增（幂等）
INSERT INTO `nft_admin_permissions`
  (`id`, `name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`)
VALUES
  (1304, '图片上传', 'system:upload', 'system', 2, 1300, '', '', 4)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 2) 给拥有内容编辑权限的角色补授（幂等）
INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, 1304
FROM `nft_admin_role_permissions` rp
JOIN `nft_admin_permissions` p ON p.`id` = rp.`permission_id`
WHERE p.`code` IN (
  'collectible:manage', 'blindbox:manage',
  'cms:banner', 'cms:announcement', 'cms:agreement', 'cms:artifact', 'cms:decoration',
  'marketing:priority:manage', 'marketing:lucky:manage', 'marketing:synthesis:manage',
  'marketing:checkin:config', 'marketing:invite:config', 'marketing:register:config'
);
