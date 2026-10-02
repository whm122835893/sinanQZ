-- ============================================================================
-- 上传图片清理功能权限种子（幂等，可重复执行）
-- 新增菜单权限 system:image-cleanup（挂在 系统配置 1300 下），
-- 并把该权限授予当前已拥有 system:upload 的角色（super 天然全权，无需绑定）。
-- ============================================================================
USE `sinan_nft`;
SET NAMES utf8mb4;

-- 1) 新增权限（id=2011，若 id 或 code 已占用则跳过）
INSERT INTO `nft_admin_permissions`
    (`id`, `name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`, `status`)
SELECT 2011, '图片清理', 'system:image-cleanup', 'system', 1, 1300, '/system/images', 'Delete', 5, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `nft_admin_permissions` WHERE `id` = 2011)
  AND NOT EXISTS (SELECT 1 FROM `nft_admin_permissions` WHERE `code` = 'system:image-cleanup');

-- 2) 授予已拥有 system:upload 的角色（若因 id=2011 被占用导致上一步未插，则改用自增补插）
SET @new_perm_id = (SELECT `id` FROM `nft_admin_permissions` WHERE `code` = 'system:image-cleanup' LIMIT 1);

INSERT INTO `nft_admin_permissions`
    (`name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`, `status`)
SELECT '图片清理', 'system:image-cleanup', 'system', 1, 1300, '/system/images', 'Delete', 5, 1
FROM DUAL
WHERE @new_perm_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM `nft_admin_permissions` WHERE `code` = 'system:image-cleanup');

SET @new_perm_id = (SELECT `id` FROM `nft_admin_permissions` WHERE `code` = 'system:image-cleanup' LIMIT 1);
SET @upload_perm_id = (SELECT `id` FROM `nft_admin_permissions` WHERE `code` = 'system:upload' LIMIT 1);

-- 3) 角色绑定：拥有 system:upload 的角色自动获得 system:image-cleanup（去重）
INSERT INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT rp.`role_id`, @new_perm_id
FROM `nft_admin_role_permissions` rp
WHERE @new_perm_id IS NOT NULL
  AND @upload_perm_id IS NOT NULL
  AND rp.`permission_id` = @upload_perm_id
  AND NOT EXISTS (
      SELECT 1 FROM `nft_admin_role_permissions` x
      WHERE x.`role_id` = rp.`role_id` AND x.`permission_id` = @new_perm_id
  );
