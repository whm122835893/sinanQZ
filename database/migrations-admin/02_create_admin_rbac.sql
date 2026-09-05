-- ============================================================================
-- 管理后台迁移 02：RBAC 5 表 + 操作审计日志表
-- 依据：《管理后台开发文档》4.2.1 / 4.2.2
-- 幂等：可重复执行（IF NOT EXISTS）
-- ============================================================================

-- 1. 管理员账号表
CREATE TABLE IF NOT EXISTS `nft_admin_users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username`       VARCHAR(50) NOT NULL COMMENT '登录名，唯一',
  `password`       VARCHAR(255) NOT NULL COMMENT 'bcrypt 哈希（复用 hash_password）',
  `real_name`      VARCHAR(50) NULL COMMENT '真实姓名',
  `role`           TINYINT NOT NULL DEFAULT 2 COMMENT '1超管/2运营/3财务/4风控/5客服（关联 nft_admin_roles.code 兜底）',
  `phone`          VARCHAR(20) NULL COMMENT '绑定手机（2FA/清库短信用）',
  `email`          VARCHAR(100) NULL,
  `avatar`         VARCHAR(500) NULL,
  `twofa_secret`   VARCHAR(255) NULL COMMENT 'TOTP 密钥（P4）',
  `ip_whitelist`   VARCHAR(500) NULL COMMENT '登录 IP 白名单，逗号分隔，空=不限',
  `status`         TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `last_login_at`  DATETIME(3) NULL,
  `last_action_at` DATETIME(3) NULL,
  `login_fail_count` INT NOT NULL DEFAULT 0 COMMENT '连续失败次数',
  `locked_until`   DATETIME(3) NULL COMMENT '锁定截止（连续失败5次锁30分钟）',
  `must_change_pwd` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首次登录强制改密',
  `created_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员账号';

-- 2. 管理员角色表
CREATE TABLE IF NOT EXISTS `nft_admin_roles` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL COMMENT '角色名：超级管理员/运营/财务/风控/客服',
  `code` VARCHAR(50) NOT NULL COMMENT 'super_admin/operator/finance/risk/customer_service',
  `description` VARCHAR(255) NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员角色';

-- 3. 权限表（P0 由 PermissionMap 静态维护，种表为 P3 库表驱动做准备）
CREATE TABLE IF NOT EXISTS `nft_admin_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL COMMENT '权限名称',
  `code` VARCHAR(100) NOT NULL COMMENT '权限码，如 collectible:release',
  `module` VARCHAR(50) NOT NULL COMMENT 'dashboard/user/realname/collectible/blindbox/order/refund/market/transfer/marketing/wallet/cms/system/permission/security/ticket/report/platform',
  `type` TINYINT NOT NULL DEFAULT 1 COMMENT '1菜单 2按钮 3接口',
  `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限表';

-- 4. 角色权限关联表（P3 起用）
CREATE TABLE IF NOT EXISTS `nft_admin_role_permissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` TINYINT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_perm` (`role_id`,`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色权限关联';

-- 5. 管理员登录日志表
CREATE TABLE IF NOT EXISTS `nft_admin_login_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NULL COMMENT '成功时记录；失败尝试填 username 快照',
  `username` VARCHAR(50) NOT NULL,
  `ip` VARCHAR(50) NULL,
  `user_agent` VARCHAR(500) NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1成功 0失败',
  `fail_reason` VARCHAR(100) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员登录日志';

-- 6. 管理员操作审计日志表（来自 4.2.2 治理组，P0 即需要）
CREATE TABLE IF NOT EXISTS `nft_operation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `admin_name` VARCHAR(50) NOT NULL COMMENT '操作人姓名快照',
  `module` VARCHAR(50) NOT NULL COMMENT 'collectible/order/... 同权限 module',
  `action` VARCHAR(100) NOT NULL COMMENT '动作码，如 collectible.release / order.force_cancel',
  `target_type` VARCHAR(50) NULL COMMENT '目标对象类型：collectible/order/user/...',
  `target_id` VARCHAR(64) NULL COMMENT '目标对象 ID',
  `target_desc` VARCHAR(200) NULL COMMENT '目标描述（藏品名等）',
  `before_value` JSON NULL COMMENT '变更前快照',
  `after_value` JSON NULL COMMENT '变更后快照',
  `reason` VARCHAR(255) NULL COMMENT '操作原因（高风险操作必填）',
  `ip` VARCHAR(50) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_module` (`module`),
  KEY `idx_created` (`created_at`),
  KEY `idx_target` (`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员操作审计日志（全量高风险与配置变更）';
