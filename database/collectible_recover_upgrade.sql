-- ============================================================================
-- 司南数字藏品平台 · 藏品回收权限补登记 + 角色授权（v1.1.0）
-- 背景：藏品详情新增「全体回收」「批量回收」两个功能，
--       路由绑定 collectible:batch-recover / collectible:phone-recover 权限码，
--       需在 nft_admin_permissions 登记，并给 super_admin / operator 角色授权。
-- 幂等性：INSERT IGNORE（依赖 uk_code / uk_role_permission 唯一键），可重复执行。
-- ============================================================================

SET NAMES utf8mb4;
USE `sinan_nft`;

-- ----------------------------------------------------------------------------
-- 一、登记两个权限码（父级 400 藏品管理，sort 承接置换 13 之后）
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `nft_admin_permissions` (`name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`) VALUES
('全体回收',     'collectible:batch-recover', 'collectible', 2, 400, '', '', 14),
('批量回收', 'collectible:phone-recover',  'collectible', 2, 400, '', '', 15);

-- ----------------------------------------------------------------------------
-- 二、角色授权（超级管理员 is_super 全量，此项保持角色映射一致；运营可操作）
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `nft_admin_roles` r, `nft_admin_permissions` p
WHERE r.code = 'super_admin' AND p.code IN ('collectible:batch-recover', 'collectible:phone-recover');

INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `nft_admin_roles` r, `nft_admin_permissions` p
WHERE r.code = 'operator' AND p.code IN ('collectible:batch-recover', 'collectible:phone-recover');