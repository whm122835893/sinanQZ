-- ============================================================================
-- 司南数字藏品平台 · 管理后台数据库初始化脚本（admin v1.0.0）
-- 前置依赖：先执行 database/init.sql（C 端 33 张表）
-- 引擎   : InnoDB / 字符集 utf8mb4 / 排序规则 utf8mb4_unicode_ci
-- 内容   :
--   一、管理后台 RBAC 基础表（6 张）：管理员/角色/权限/角色权限/操作日志/登录日志
--   二、业务闭环扩展表（14 张）：资格购/优先购/配额/销毁/退款/空投任务/黑名单/
--       风控告警/安全事件/客服工单/工单回复/清库日志/短信配置/支付渠道
--   三、现有表字段扩展（幂等，重复执行自动跳过已存在的列）
--   四、种子数据：超管账号、5 个预置角色、权限树、角色权限映射、
--       默认短信配置（mock）、默认支付渠道
-- 说明   :
--   1. 本脚本可重复执行：新表 DROP 后重建；ALTER 通过 information_schema
--      条件判断，已存在则跳过（兼容 MySQL 8 与 MariaDB）
--   2. 管理员与 C 端用户完全隔离：独立表、独立 JWT 密钥
--   3. 默认超管账号 admin / admin123（首次登录后请立即修改）
--   4. 敏感配置（短信密钥、支付密钥）通过应用层 AES-256-CBC 加密存储，
--      本脚本仅写入明文占位，由后台页面保存时加密
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `sinan_nft`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE `sinan_nft`;

-- ----------------------------------------------------------------------------
-- 一、管理后台 RBAC 基础表（6 张）
-- ----------------------------------------------------------------------------

-- 1. 管理员角色表 nft_admin_roles（先建角色，管理员表外键依赖）
DROP TABLE IF EXISTS `nft_admin_users`;
DROP TABLE IF EXISTS `nft_admin_role_permissions`;
DROP TABLE IF EXISTS `nft_admin_roles`;
CREATE TABLE `nft_admin_roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name`        VARCHAR(50)  NOT NULL                COMMENT '角色名称',
  `code`        VARCHAR(50)  NOT NULL                COMMENT '角色标识：super_admin/operator/finance/risk/support',
  `description` VARCHAR(255) NULL DEFAULT NULL       COMMENT '角色描述',
  `status`      TINYINT      NOT NULL DEFAULT 1      COMMENT '状态：1启用 0禁用',
  `is_builtin`  TINYINT(1)   NOT NULL DEFAULT 0      COMMENT '是否内置角色：1内置（不可删除）',
  `created_at`  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台角色表';

-- 2. 管理员账号表 nft_admin_users
-- 与 C 端 nft_users 完全隔离；连续登录失败锁定
DROP TABLE IF EXISTS `nft_admin_users`;
CREATE TABLE `nft_admin_users` (
  `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `username`         VARCHAR(50)     NOT NULL                COMMENT '登录账号，唯一',
  `password_hash`    VARCHAR(255)    NOT NULL                COMMENT '密码 bcrypt 哈希',
  `real_name`        VARCHAR(50)    NOT NULL DEFAULT ''     COMMENT '真实姓名',
  `role_id`          INT UNSIGNED    NOT NULL                COMMENT '角色ID，FK→nft_admin_roles.id',
  `phone`            VARCHAR(20)     NULL DEFAULT NULL       COMMENT '绑定手机号（清库短信验证接收号）',
  `email`            VARCHAR(100)    NULL DEFAULT NULL       COMMENT '邮箱',
  `avatar`           VARCHAR(255)    NULL DEFAULT NULL       COMMENT '头像URL',
  `status`           TINYINT         NOT NULL DEFAULT 1      COMMENT '状态：1启用 0禁用',
  `last_login_at`    DATETIME        NULL DEFAULT NULL       COMMENT '最后登录时间',
  `last_login_ip`    VARCHAR(45)     NULL DEFAULT NULL       COMMENT '最后登录IP',
  `login_fail_count` INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '连续登录失败次数',
  `locked_until`     DATETIME        NULL DEFAULT NULL       COMMENT '锁定截止时间，NULL未锁定',
  `last_action_at`   DATETIME        NULL DEFAULT NULL       COMMENT '最后操作时间',
  `deleted_at`       DATETIME        NULL DEFAULT NULL       COMMENT '软删除时间',
  `created_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role` (`role_id`),
  CONSTRAINT `fk_admin_role` FOREIGN KEY (`role_id`) REFERENCES `nft_admin_roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台管理员账号表';

-- 3. 权限表 nft_admin_permissions（菜单树 + 按钮权限）
DROP TABLE IF EXISTS `nft_admin_permissions`;
CREATE TABLE `nft_admin_permissions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name`       VARCHAR(100) NOT NULL                COMMENT '权限/菜单名称',
  `code`       VARCHAR(100) NOT NULL                COMMENT '权限标识：如 user:list / collectible:create',
  `module`     VARCHAR(50)  NOT NULL                COMMENT '所属模块',
  `type`       TINYINT      NOT NULL DEFAULT 1      COMMENT '类型：1菜单 2按钮',
  `parent_id`  INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '父级ID，0为顶级',
  `path`       VARCHAR(100) NULL DEFAULT NULL       COMMENT '前端路由路径（菜单用）',
  `icon`       VARCHAR(50)  NULL DEFAULT NULL       COMMENT '菜单图标（菜单用）',
  `sort_order` INT          NOT NULL DEFAULT 0     COMMENT '排序（升序）',
  `status`     TINYINT      NOT NULL DEFAULT 1      COMMENT '状态：1启用 0禁用',
  `created_at` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_module` (`module`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台权限表（菜单+按钮）';

-- 4. 角色权限关联表 nft_admin_role_permissions
DROP TABLE IF EXISTS `nft_admin_role_permissions`;
CREATE TABLE `nft_admin_role_permissions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `role_id`       INT UNSIGNED NOT NULL                COMMENT '角色ID',
  `permission_id` INT UNSIGNED NOT NULL                COMMENT '权限ID',
  `created_at`    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permission` (`role_id`, `permission_id`),
  KEY `idx_role` (`role_id`),
  KEY `idx_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `nft_admin_roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `nft_admin_permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色权限关联表';

-- 5. 管理后台操作日志表 nft_admin_operation_logs（审计核心）
DROP TABLE IF EXISTS `nft_admin_operation_logs`;
CREATE TABLE `nft_admin_operation_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `admin_id`   INT UNSIGNED    NOT NULL                COMMENT '操作管理员ID',
  `admin_name` VARCHAR(50)     NOT NULL                COMMENT '操作管理员姓名/账号',
  `module`     VARCHAR(50)     NOT NULL                COMMENT '业务模块：user/collectible/order...',
  `action`     VARCHAR(100)    NOT NULL                COMMENT '操作动作：如 freeze/airdrop/destroy',
  `action_desc` VARCHAR(255)   NOT NULL DEFAULT ''     COMMENT '操作描述（人类可读）',
  `target_type` VARCHAR(50)    NULL DEFAULT NULL       COMMENT '操作对象类型：user/collectible/order',
  `target_id`  BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '操作对象ID',
  `detail`     TEXT            NULL                    COMMENT '操作明细JSON（敏感字段脱敏）',
  `method`     VARCHAR(10)     NULL DEFAULT NULL       COMMENT '请求方法：GET/POST/PUT/DELETE',
  `path`       VARCHAR(255)    NULL DEFAULT NULL       COMMENT '请求路径',
  `ip`         VARCHAR(45)     NULL DEFAULT NULL       COMMENT '操作IP',
  `user_agent` VARCHAR(500)    NULL DEFAULT NULL       COMMENT 'User-Agent',
  `created_at` DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_created` (`admin_id`, `created_at`),
  KEY `idx_module` (`module`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台操作审计日志';

-- 6. 管理后台登录日志表 nft_admin_login_logs
DROP TABLE IF EXISTS `nft_admin_login_logs`;
CREATE TABLE `nft_admin_login_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `admin_id`    INT UNSIGNED    NULL DEFAULT NULL      COMMENT '管理员ID（登录失败可为NULL）',
  `username`   VARCHAR(50)     NOT NULL                COMMENT '尝试登录账号',
  `status`     TINYINT         NOT NULL DEFAULT 1      COMMENT '结果：1成功 2失败',
  `reason`     VARCHAR(100)    NULL DEFAULT NULL       COMMENT '失败原因：密码错误/账号锁定/账号禁用',
  `ip`         VARCHAR(45)     NULL DEFAULT NULL       COMMENT '登录IP',
  `user_agent` VARCHAR(500)    NULL DEFAULT NULL       COMMENT 'User-Agent',
  `created_at` DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_created` (`admin_id`, `created_at`),
  KEY `idx_username` (`username`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台登录日志';

-- ----------------------------------------------------------------------------
-- 二、业务闭环扩展表（14 张）
-- ----------------------------------------------------------------------------

-- 7. 资格购配置表 nft_qualification_configs（每藏品一份，uk_collectible）
DROP TABLE IF EXISTS `nft_qualification_configs`;
CREATE TABLE `nft_qualification_configs` (
  `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id`           INT UNSIGNED NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id',
  `is_enabled`              TINYINT(1)   NOT NULL DEFAULT 0     COMMENT '是否开启：1开启 0关闭',
  `required_collectible_ids` JSON         NULL DEFAULT NULL      COMMENT '资格藏品ID数组：持有任一即满足（条件之一）',
  `required_checkin_days`    INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '要求累计签到天数，0=不限',
  `required_invite_count`    INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '要求累计邀请人数，0=不限',
  `condition_type`           TINYINT      NOT NULL DEFAULT 1      COMMENT '组合方式：1满足任一 2满足全部',
  `valid_start_at`           DATETIME     NULL DEFAULT NULL      COMMENT '资格有效期开始',
  `valid_end_at`             DATETIME     NULL DEFAULT NULL      COMMENT '资格有效期结束，NULL=不限',
  `created_at`               DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`               DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collectible` (`collectible_id`),
  CONSTRAINT `chk_qual_checkin` CHECK (`required_checkin_days` >= 0 AND `required_invite_count` >= 0),
  CONSTRAINT `fk_qual_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资格购配置表（购买门槛，不冻结库存）';

-- 8. 资格购白名单表 nft_qualification_whitelists（额外资格手机号）
DROP TABLE IF EXISTS `nft_qualification_whitelists`;
CREATE TABLE `nft_qualification_whitelists` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `config_id`    INT UNSIGNED NOT NULL                COMMENT '资格购配置ID，FK→nft_qualification_configs.id',
  `user_id`      BIGINT UNSIGNED NOT NULL              COMMENT '用户ID，FK→nft_users.id',
  `phone`        VARCHAR(20)  NOT NULL                COMMENT '手机号（快照）',
  `expires_at`   DATETIME     NULL DEFAULT NULL       COMMENT '资格有效期，NULL=永久',
  `created_at`   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_user` (`config_id`, `user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_qw_config` FOREIGN KEY (`config_id`) REFERENCES `nft_qualification_configs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_qw_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资格购白名单（白名单用户无需满足条件）';

-- 9. 优先购活动表 nft_priority_activities（每藏品一份，白名单提前购）
DROP TABLE IF EXISTS `nft_priority_activities`;
CREATE TABLE `nft_priority_activities` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id` INT UNSIGNED NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id',
  `name`           VARCHAR(100) NOT NULL                COMMENT '活动名称',
  `start_time`     DATETIME     NULL DEFAULT NULL       COMMENT '优先购开始时间',
  `end_time`       DATETIME     NULL DEFAULT NULL       COMMENT '优先购结束时间',
  `status`         ENUM('disabled','enabled','ended') NOT NULL DEFAULT 'disabled' COMMENT '状态：disabled停用 enabled启用 ended结束',
  `remark`         VARCHAR(255) NULL DEFAULT NULL      COMMENT '备注',
  `created_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collectible` (`collectible_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_pri_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优先购活动表（时间优先通道，独立于资格购）';

-- 10. 优先购白名单表 nft_priority_whitelists
DROP TABLE IF EXISTS `nft_priority_whitelists`;
CREATE TABLE `nft_priority_whitelists` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id`   INT UNSIGNED NOT NULL                COMMENT '优先购活动ID，FK→nft_priority_activities.id',
  `user_id`       BIGINT UNSIGNED NOT NULL              COMMENT '用户ID，FK→nft_users.id',
  `phone`         VARCHAR(20)  NOT NULL                COMMENT '手机号（快照）',
  `max_quantity`  INT UNSIGNED NOT NULL DEFAULT 1      COMMENT '最大购买量',
  `used_quantity` INT UNSIGNED NOT NULL DEFAULT 0      COMMENT '已用配额',
  `expires_at`    DATETIME     NULL DEFAULT NULL       COMMENT '资格有效期，NULL=跟随活动',
  `status`        TINYINT      NOT NULL DEFAULT 1       COMMENT '状态：1有效 0停用',
  `created_at`    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activity_user` (`activity_id`, `user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `chk_pri_used` CHECK (`used_quantity` <= `max_quantity`),
  CONSTRAINT `fk_pw_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_priority_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pw_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优先购白名单表';

-- 11. 库存配额预留表 nft_inventory_quotas（配额闭环核心）
DROP TABLE IF EXISTS `nft_inventory_quotas`;
CREATE TABLE `nft_inventory_quotas` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id`   INT UNSIGNED NOT NULL                COMMENT '藏品ID（含盲盒对应藏品），FK→nft_collectibles.id',
  `quota_type`       TINYINT      NOT NULL                COMMENT '配额类型：1优先购 2活动空投 3签到 4注册 5邀请 6抽奖 7其他',
  `quota_name`       VARCHAR(100) NOT NULL                COMMENT '配额名称',
  `planned_quantity` INT UNSIGNED NOT NULL DEFAULT 0      COMMENT '计划配额数量',
  `used_quantity`    INT UNSIGNED NOT NULL DEFAULT 0      COMMENT '已使用数量',
  `status`           TINYINT      NOT NULL DEFAULT 1      COMMENT '状态：1生效 0停用（停用未使用部分释放回库存池）',
  `activity_id`      INT UNSIGNED NULL DEFAULT NULL       COMMENT '关联活动ID（泛关联）',
  `activity_type`    VARCHAR(50)  NULL DEFAULT NULL       COMMENT '关联活动类型：priority/checkin/invite/lucky_draw/register',
  `remark`           VARCHAR(255) NULL DEFAULT NULL      COMMENT '备注',
  `created_by`       INT UNSIGNED NOT NULL                COMMENT '创建管理员ID',
  `created_by_name`  VARCHAR(50)  NOT NULL                COMMENT '创建管理员姓名',
  `created_at`       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_type` (`quota_type`),
  KEY `idx_status` (`status`),
  CONSTRAINT `chk_quota_used` CHECK (`used_quantity` <= `planned_quantity`),
  CONSTRAINT `fk_quota_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='库存配额预留表（配置时从库存池冻结）';

-- 12. 销毁记录表 nft_destroy_records
DROP TABLE IF EXISTS `nft_destroy_records`;
CREATE TABLE `nft_destroy_records` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `target_type` TINYINT      NOT NULL                   COMMENT '销毁对象：1藏品 2盲盒',
  `target_id`   INT UNSIGNED NOT NULL                   COMMENT '对象ID（盲盒为其关联藏品ID）',
  `target_name` VARCHAR(100) NOT NULL                   COMMENT '对象名称快照',
  `quantity`    INT UNSIGNED NOT NULL DEFAULT 0        COMMENT '销毁数量',
  `reason`      VARCHAR(255) NULL DEFAULT NULL          COMMENT '销毁原因',
  `admin_id`    INT UNSIGNED NOT NULL                   COMMENT '操作管理员ID',
  `admin_name`  VARCHAR(50)  NOT NULL                   COMMENT '操作管理员姓名',
  `ip`          VARCHAR(45)  NULL DEFAULT NULL          COMMENT '操作IP',
  `created_at`  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='库存销毁记录表（不可逆，需密码+二次确认）';

-- 13. 退款记录表 nft_refunds（退款审批流）
DROP TABLE IF EXISTS `nft_refunds`;
CREATE TABLE `nft_refunds` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `refund_no`      VARCHAR(30)   NOT NULL                COMMENT '退款单号：RF+时间+随机',
  `order_id`       BIGINT UNSIGNED NOT NULL               COMMENT '关联订单ID，FK→nft_orders.id',
  `payment_id`     BIGINT UNSIGNED NOT NULL               COMMENT '关联支付记录ID，FK→nft_payments.id',
  `user_id`        BIGINT UNSIGNED NOT NULL               COMMENT '用户ID，FK→nft_users.id',
  `amount`         DECIMAL(10,2) NOT NULL                COMMENT '退款金额',
  `reason`         VARCHAR(255) NULL DEFAULT NULL        COMMENT '退款原因',
  `status`         TINYINT      NOT NULL DEFAULT 1       COMMENT '状态：1待审批 2已批准 3已拒绝 4已退款',
  `applicant_id`   INT UNSIGNED NOT NULL                 COMMENT '申请人（管理员）ID',
  `applicant_name` VARCHAR(50)  NOT NULL                 COMMENT '申请人姓名',
  `approver_id`    INT UNSIGNED NULL DEFAULT NULL         COMMENT '审批人ID',
  `approver_name`  VARCHAR(50)  NULL DEFAULT NULL        COMMENT '审批人姓名',
  `approved_at`    DATETIME     NULL DEFAULT NULL        COMMENT '审批时间',
  `refunded_at`    DATETIME     NULL DEFAULT NULL        COMMENT '实际退款时间',
  `refund_channel` VARCHAR(50)  NULL DEFAULT NULL         COMMENT '退款渠道：balance/alipay/wechat',
  `comment`        VARCHAR(255) NULL DEFAULT NULL         COMMENT '审批意见',
  `ip`             VARCHAR(45)  NULL DEFAULT NULL         COMMENT '操作IP',
  `created_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refund_no` (`refund_no`),
  KEY `idx_order` (`order_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `chk_refund_amount` CHECK (`amount` > 0),
  CONSTRAINT `fk_refund_order` FOREIGN KEY (`order_id`) REFERENCES `nft_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_refund_payment` FOREIGN KEY (`payment_id`) REFERENCES `nft_payments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_refund_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='退款记录表（大额退款需审批）';

-- 14. 独立空投任务表 nft_airdrop_tasks（不绑定营销活动的发放）
DROP TABLE IF EXISTS `nft_airdrop_tasks`;
CREATE TABLE `nft_airdrop_tasks` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `task_no`       VARCHAR(30)   NOT NULL                 COMMENT '任务编号：AP+时间+随机',
  `target_type`   TINYINT       NOT NULL DEFAULT 1      COMMENT '空投对象：1藏品 2盲盒（盲盒为其关联藏品）',
  `target_id`     INT UNSIGNED  NOT NULL                 COMMENT '目标藏品ID',
  `target_name`   VARCHAR(100)  NOT NULL                 COMMENT '目标名称快照',
  `total_quantity` INT UNSIGNED NOT NULL DEFAULT 0      COMMENT '空投总份数',
  `user_count`    INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '接收用户数',
  `success_count` INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '成功发放数',
  `fail_count`    INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '失败数（未注册等）',
  `fail_list`     JSON          NULL DEFAULT NULL       COMMENT '失败明细：[{phone,reason}]',
  `admin_id`      INT UNSIGNED  NOT NULL                COMMENT '操作管理员ID',
  `admin_name`    VARCHAR(50)   NOT NULL                 COMMENT '操作管理员姓名',
  `ip`            VARCHAR(45)   NULL DEFAULT NULL        COMMENT '操作IP',
  `created_at`    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_no` (`task_no`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='独立空投任务表（从库存池扣减，任意阶段可执行）';

-- 15. 黑名单表 nft_blacklist
DROP TABLE IF EXISTS `nft_blacklist`;
CREATE TABLE `nft_blacklist` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`        BIGINT UNSIGNED NOT NULL               COMMENT '用户ID，FK→nft_users.id',
  `blacklist_type` TINYINT       NOT NULL DEFAULT 1      COMMENT '类型：1用户级 2IP级 3设备级',
  `target_value`   VARCHAR(255)  NOT NULL                 COMMENT '目标值：用户UID/IP/设备号',
  `reason`         VARCHAR(255)  NOT NULL                 COMMENT '拉黑原因',
  `evidence`       TEXT           NULL DEFAULT NULL       COMMENT '证据描述',
  `admin_id`       INT UNSIGNED   NOT NULL                 COMMENT '操作人ID',
  `admin_name`     VARCHAR(50)    NOT NULL                 COMMENT '操作人姓名',
  `status`         TINYINT       NOT NULL DEFAULT 1      COMMENT '状态：1生效中 0已解除',
  `lifted_at`      DATETIME       NULL DEFAULT NULL       COMMENT '解除时间',
  `lifted_by`      INT UNSIGNED   NULL DEFAULT NULL        COMMENT '解除操作人ID',
  `lifted_reason`  VARCHAR(255)   NULL DEFAULT NULL       COMMENT '解除原因',
  `expires_at`     DATETIME       NULL DEFAULT NULL       COMMENT '自动过期时间，NULL=永久',
  `created_at`     DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_type` (`user_id`, `blacklist_type`),
  KEY `idx_status` (`status`),
  KEY `idx_target` (`target_value`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_bl_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='黑名单表（用户/IP/设备级）';

-- 16. 风控告警表 nft_risk_alerts
DROP TABLE IF EXISTS `nft_risk_alerts`;
CREATE TABLE `nft_risk_alerts` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `alert_type`     TINYINT       NOT NULL                 COMMENT '类型：1大额充值 2频繁小额充值 3余额突变 4高频API 5异常时间操作 6异地登录 7批量注册 8异常价格 9其他',
  `alert_level`    TINYINT       NOT NULL DEFAULT 1      COMMENT '等级：1低 2中 3高 4紧急',
  `user_id`       BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '关联用户ID',
  `target_id`      BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '关联业务ID',
  `target_type`    VARCHAR(50)   NULL DEFAULT NULL        COMMENT '关联业务类型',
  `title`          VARCHAR(200)  NOT NULL                 COMMENT '告警标题',
  `description`    TEXT           NULL DEFAULT NULL       COMMENT '告警详情',
  `evidence`       JSON           NULL DEFAULT NULL       COMMENT '证据数据',
  `status`         TINYINT       NOT NULL DEFAULT 1      COMMENT '状态：1未处理 2处理中 3已处理 4已忽略',
  `handler_id`     INT UNSIGNED   NULL DEFAULT NULL        COMMENT '处理人ID',
  `handler_name`   VARCHAR(50)    NULL DEFAULT NULL       COMMENT '处理人姓名',
  `handled_at`     DATETIME       NULL DEFAULT NULL        COMMENT '处理时间',
  `handle_comment` VARCHAR(255)  NULL DEFAULT NULL       COMMENT '处理意见',
  `created_at`     DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`alert_type`),
  KEY `idx_level` (`alert_level`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风控告警表';

-- 17. 安全事件表 nft_security_events
DROP TABLE IF EXISTS `nft_security_events`;
CREATE TABLE `nft_security_events` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `event_type`      TINYINT       NOT NULL                 COMMENT '类型：1越权尝试 2支付回调异常 3Token异常 4暴力破解 5其他',
  `event_level`     TINYINT       NOT NULL DEFAULT 1      COMMENT '等级：1低 2中 3高 4紧急',
  `admin_id`        INT UNSIGNED   NULL DEFAULT NULL        COMMENT '关联管理员ID',
  `user_id`         BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '关联用户ID',
  `ip`              VARCHAR(45)   NULL DEFAULT NULL         COMMENT 'IP地址',
  `user_agent`      VARCHAR(500)  NULL DEFAULT NULL         COMMENT 'User-Agent',
  `request_path`    VARCHAR(255)  NULL DEFAULT NULL         COMMENT '请求路径',
  `request_method`  VARCHAR(10)   NULL DEFAULT NULL         COMMENT '请求方法',
  `request_params`  TEXT           NULL DEFAULT NULL        COMMENT '请求参数（脱敏）',
  `response_status` INT            NULL DEFAULT NULL        COMMENT '响应状态码',
  `description`     TEXT           NULL DEFAULT NULL        COMMENT '事件描述',
  `status`          TINYINT       NOT NULL DEFAULT 1       COMMENT '状态：1未处理 2已确认 3已处理 4误报',
  `handle_comment`  VARCHAR(255)  NULL DEFAULT NULL       COMMENT '处理意见',
  `created_at`      DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`      DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`event_type`),
  KEY `idx_level` (`event_level`),
  KEY `idx_status` (`status`),
  KEY `idx_ip` (`ip`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='安全事件审计表';

-- 18. 客服工单表 nft_support_tickets
DROP TABLE IF EXISTS `nft_support_tickets`;
CREATE TABLE `nft_support_tickets` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `ticket_no`    VARCHAR(30)   NOT NULL                 COMMENT '工单编号：TK+时间+随机',
  `user_id`      BIGINT UNSIGNED NOT NULL               COMMENT '用户ID，FK→nft_users.id',
  `user_phone`   VARCHAR(20)   NULL DEFAULT NULL        COMMENT '用户手机号（快照）',
  `ticket_type`  TINYINT       NOT NULL                 COMMENT '类型：1支付异常 2藏品丢失 3盲盒问题 4转赠纠纷 5账号问题 6其他',
  `priority`     TINYINT       NOT NULL DEFAULT 3       COMMENT '优先级：1紧急 2高 3中 4低',
  `title`        VARCHAR(200)  NOT NULL                 COMMENT '工单标题',
  `description`  TEXT           NULL DEFAULT NULL       COMMENT '工单描述',
  `related_order_id` BIGINT UNSIGNED NULL DEFAULT NULL   COMMENT '关联订单ID（泛关联）',
  `related_collectible_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '关联藏品ID（泛关联）',
  `status`       TINYINT       NOT NULL DEFAULT 1       COMMENT '状态：1待处理 2处理中 3待用户确认 4已解决 5已关闭',
  `assignee_id`  INT UNSIGNED   NULL DEFAULT NULL        COMMENT '分配处理人（管理员）ID',
  `assignee_name` VARCHAR(50)  NULL DEFAULT NULL         COMMENT '处理人姓名',
  `solved_at`    DATETIME       NULL DEFAULT NULL        COMMENT '解决时间',
  `closed_at`    DATETIME       NULL DEFAULT NULL         COMMENT '关闭时间',
  `created_at`   DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`   DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_assignee` (`assignee_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_ticket_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服工单表';

-- 19. 工单回复表 nft_ticket_replies
DROP TABLE IF EXISTS `nft_ticket_replies`;
CREATE TABLE `nft_ticket_replies` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `ticket_id`   BIGINT UNSIGNED NOT NULL                COMMENT '工单ID，FK→nft_support_tickets.id',
  `sender_type` TINYINT       NOT NULL                 COMMENT '发送者：1用户 2客服 3系统',
  `sender_id`   BIGINT UNSIGNED NOT NULL                COMMENT '发送者ID',
  `sender_name` VARCHAR(50)   NOT NULL                 COMMENT '发送者名称',
  `content`     TEXT           NOT NULL                 COMMENT '回复内容',
  `is_internal` TINYINT(1)    NOT NULL DEFAULT 0       COMMENT '是否内部备注：1是（用户不可见）',
  `created_at` DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`),
  KEY `idx_sender` (`sender_id`),
  CONSTRAINT `fk_reply_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `nft_support_tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单回复表';

-- 20. 平台清库日志表 nft_platform_cleanup_logs
DROP TABLE IF EXISTS `nft_platform_cleanup_logs`;
CREATE TABLE `nft_platform_cleanup_logs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `admin_id`        INT UNSIGNED  NOT NULL                 COMMENT '操作人ID',
  `admin_name`      VARCHAR(50)   NOT NULL                  COMMENT '操作人姓名',
  `admin_phone`     VARCHAR(20)   NOT NULL                  COMMENT '操作人绑定手机（短信验证接收号）',
  `ip`              VARCHAR(45)   NOT NULL                  COMMENT '操作IP',
  `reason`          VARCHAR(255)  NOT NULL                  COMMENT '清库原因',
  `backup_path`     VARCHAR(500)  NOT NULL                  COMMENT '备份文件路径',
  `affected_users`  INT UNSIGNED  NOT NULL DEFAULT 0       COMMENT '影响用户数量',
  `affected_orders` INT UNSIGNED  NOT NULL DEFAULT 0       COMMENT '影响订单数量',
  `execution_time`  INT UNSIGNED  NOT NULL DEFAULT 0       COMMENT '执行耗时（秒）',
  `status`          TINYINT      NOT NULL DEFAULT 1       COMMENT '状态：1成功 2失败',
  `error_message`   TEXT          NULL DEFAULT NULL        COMMENT '错误信息',
  `created_at`      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台清库操作日志表（执行前自动备份）';

-- 21. 短信配置表 nft_sms_configs（单行配置，id=1）
DROP TABLE IF EXISTS `nft_sms_configs`;
CREATE TABLE `nft_sms_configs` (
  `id`               TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '固定为1，单行配置',
  `provider`         ENUM('mock','aliyun','tencent') NOT NULL DEFAULT 'mock' COMMENT '短信服务商：mock模拟 aliyun阿里云 tencent腾讯云',
  `is_enabled`       TINYINT(1)   NOT NULL DEFAULT 1    COMMENT '短信总开关：1开 0关（关闭时全部走mock）',
  `access_key`       VARCHAR(255) NULL DEFAULT NULL     COMMENT 'AccessKey ID（应用层AES加密存储）',
  `access_secret`    VARCHAR(255) NULL DEFAULT NULL     COMMENT 'AccessKey Secret（应用层AES加密存储）',
  `signature`        VARCHAR(64)  NULL DEFAULT NULL     COMMENT '短信签名（如：司南数字藏品）',
  `template_register` VARCHAR(64) NULL DEFAULT NULL      COMMENT '注册验证码模板Code',
  `template_login`   VARCHAR(64)   NULL DEFAULT NULL     COMMENT '登录验证码模板Code',
  `template_reset`   VARCHAR(64)   NULL DEFAULT NULL     COMMENT '重置密码验证码模板Code',
  `daily_limit`      INT UNSIGNED  NOT NULL DEFAULT 0    COMMENT '每日发送上限，0=不限',
  `last_test_at`     DATETIME     NULL DEFAULT NULL     COMMENT '最后测试发送时间',
  `last_test_status` TINYINT      NULL DEFAULT NULL     COMMENT '最后测试结果：1成功 2失败',
  `last_test_message` VARCHAR(255) NULL DEFAULT NULL    COMMENT '最后测试结果信息',
  `updated_by`       INT UNSIGNED NULL DEFAULT NULL     COMMENT '最后修改人ID',
  `updated_by_name`  VARCHAR(50)  NULL DEFAULT NULL     COMMENT '最后修改人姓名',
  `created_at`       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='短信服务配置表（单行，密钥加密存储）';

-- 22. 第三方支付渠道配置表 nft_payment_channels
DROP TABLE IF EXISTS `nft_payment_channels`;
CREATE TABLE `nft_payment_channels` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `channel_code`   VARCHAR(30)  NOT NULL                 COMMENT '渠道编码：balance/alipay/wechat/huifu/unionpay',
  `channel_name`   VARCHAR(50)  NOT NULL                 COMMENT '渠道名称：余额/支付宝/微信支付/汇付天下/银联',
  `fee_rate`       DECIMAL(5,2) NOT NULL DEFAULT 0.00    COMMENT '渠道手续费率（%）',
  `status`         TINYINT      NOT NULL DEFAULT 0      COMMENT '状态：1启用 0停用',
  `is_recommended` TINYINT(1)   NOT NULL DEFAULT 0      COMMENT '是否推荐展示：1是',
  `sort_order`     INT          NOT NULL DEFAULT 0      COMMENT '排序（升序）',
  `config`         TEXT          NULL DEFAULT NULL       COMMENT '渠道配置JSON（应用层AES加密存储：app_id/mch_id/私钥/网关等）',
  `remark`         VARCHAR(255) NULL DEFAULT NULL        COMMENT '备注',
  `updated_by`     INT UNSIGNED  NULL DEFAULT NULL       COMMENT '最后修改人ID',
  `updated_by_name` VARCHAR(50) NULL DEFAULT NULL        COMMENT '最后修改人姓名',
  `created_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channel_code` (`channel_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='第三方支付渠道配置表（钱包支付通道）';

-- ----------------------------------------------------------------------------
-- 三、现有表字段扩展（幂等：通过 information_schema 判断，已存在则跳过）
-- ----------------------------------------------------------------------------

-- 3.1 nft_collectibles 库存闭环扩展字段
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='per_user_limit')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `per_user_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''每人限购数量，0=不限购'' AFTER `locked_quantity`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='is_transferable')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `is_transferable` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''是否可转赠：1可 0不可（与寄售开关独立）'' AFTER `per_user_limit`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='is_resaleable')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `is_resaleable` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''是否可寄售：1可 0不可（关闭时在售挂单全部系统下架）'' AFTER `is_transferable`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='resale_price_mode')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `resale_price_mode` TINYINT NOT NULL DEFAULT 0 COMMENT ''寄售价格管控：0不限价 1限价（挂单价须在闭区间内）'' AFTER `is_resaleable`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='resale_price_min')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `resale_price_min` DECIMAL(10,2) NULL DEFAULT NULL COMMENT ''寄售限价下限（元），限价模式必填'' AFTER `resale_price_mode`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='resale_price_max')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `resale_price_max` DECIMAL(10,2) NULL DEFAULT NULL COMMENT ''寄售限价上限（元），限价模式必填'' AFTER `resale_price_min`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='is_qualification_enabled')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `is_qualification_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''是否开启资格购：1开启 0关闭（购买门槛，不冻结库存）'' AFTER `resale_price_max`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='reserved_count')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `reserved_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''已配置配额预留总数（冻结自库存池）'' AFTER `is_qualification_enabled`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='airdropped_count')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `airdropped_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''已独立空投数量'' AFTER `reserved_count`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='destroyed_count')=0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `destroyed_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''已销毁数量'' AFTER `airdropped_count`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3.2 nft_collectibles 状态扩展：增加 off（已下架）
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND COLUMN_NAME='status' AND COLUMN_TYPE LIKE '%''off''')=0,
  'ALTER TABLE `nft_collectibles` MODIFY COLUMN `status` ENUM(''upcoming'',''onsale'',''soldout'',''off'') NOT NULL DEFAULT ''upcoming'' COMMENT ''发售状态：upcoming未发售 onsale发售中 soldout已售罄 off已下架''',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3.3 nft_collectibles 更新防超卖 CHECK（含配额/空投/销毁）
SET @s = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND CONSTRAINT_NAME='chk_collectibles_stock')>0,
  'ALTER TABLE `nft_collectibles` DROP CONSTRAINT `chk_collectibles_stock`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_collectibles' AND CONSTRAINT_NAME='chk_collectibles_stock_v2')=0,
  'ALTER TABLE `nft_collectibles` ADD CONSTRAINT `chk_collectibles_stock_v2` CHECK (`sold` + `locked_quantity` + `reserved_count` + `airdropped_count` + `destroyed_count` <= `edition` AND `circulate` <= `edition`)',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3.4 nft_users 黑名单字段
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_users' AND COLUMN_NAME='is_blacklisted')=0,
  'ALTER TABLE `nft_users` ADD COLUMN `is_blacklisted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''是否黑名单：1是 0否'' AFTER `status`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_users' AND COLUMN_NAME='blacklist_reason')=0,
  'ALTER TABLE `nft_users` ADD COLUMN `blacklist_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT ''拉黑原因'' AFTER `is_blacklisted`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_users' AND COLUMN_NAME='blacklist_at')=0,
  'ALTER TABLE `nft_users` ADD COLUMN `blacklist_at` DATETIME NULL DEFAULT NULL COMMENT ''拉黑时间'' AFTER `blacklist_reason`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3.5 nft_orders 来源扩展（优先购/资格购）与退款状态
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_orders' AND COLUMN_NAME='source' AND COLUMN_TYPE LIKE '%''priority''')=0,
  'ALTER TABLE `nft_orders` MODIFY COLUMN `source` ENUM(''release'',''market'',''priority'',''eligibility'') NOT NULL DEFAULT ''release'' COMMENT ''订单来源：release首发 market市场 priority优先购 eligibility资格购''',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_orders' AND COLUMN_NAME='status' AND COLUMN_TYPE LIKE '%''refunded''')=0,
  'ALTER TABLE `nft_orders` MODIFY COLUMN `status` ENUM(''pending'',''completed'',''cancelled'',''refunding'',''refunded'') NOT NULL DEFAULT ''pending'' COMMENT ''订单状态：pending待支付 completed已完成 cancelled已取消 refunding退款中 refunded已退款''',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3.6 nft_user_collectibles 资产状态扩展：recovered（已回收）
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_user_collectibles' AND COLUMN_NAME='status' AND COLUMN_TYPE LIKE '%''recovered''')=0,
  'ALTER TABLE `nft_user_collectibles` MODIFY COLUMN `status` ENUM(''held'',''consigned'',''frozen'',''transferred'',''consumed'',''recovered'') NOT NULL DEFAULT ''held'' COMMENT ''资产状态：held持有 consigned寄售中 frozen转赠冻结 transferred已转赠 consumed已消耗（开盒/合成） recovered已回收''',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3.7 nft_resale_listings 系统下架字段
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_resale_listings' AND COLUMN_NAME='is_system_delisted')=0,
  'ALTER TABLE `nft_resale_listings` ADD COLUMN `is_system_delisted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''是否被系统强制下架：1是（寄售开关关闭触发）'' AFTER `status`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_resale_listings' AND COLUMN_NAME='system_delisted_at')=0,
  'ALTER TABLE `nft_resale_listings` ADD COLUMN `system_delisted_at` DATETIME(3) NULL DEFAULT NULL COMMENT ''系统下架时间'' AFTER `is_system_delisted`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_resale_listings' AND COLUMN_NAME='delist_reason')=0,
  'ALTER TABLE `nft_resale_listings` ADD COLUMN `delist_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT ''下架原因'' AFTER `system_delisted_at`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3.8 nft_airdrop_records 关联独立空投任务
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_airdrop_records' AND COLUMN_NAME='task_id')=0,
  'ALTER TABLE `nft_airdrop_records` ADD COLUMN `task_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT ''独立空投任务ID，FK→nft_airdrop_tasks.id（泛关联不设外键）'' AFTER `activity_id`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3.9 nft_blind_boxes 开启统计
SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nft_blind_boxes' AND COLUMN_NAME='opened_count')=0,
  'ALTER TABLE `nft_blind_boxes` ADD COLUMN `opened_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''累计开启数（统计用）'' AFTER `is_openable`',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ----------------------------------------------------------------------------
-- 四、种子数据
-- ----------------------------------------------------------------------------

-- 4.1 预置角色（5 个，与提示词权限矩阵一致）
INSERT INTO `nft_admin_roles` (`id`, `name`, `code`, `description`, `status`, `is_builtin`) VALUES
(1, '超级管理员', 'super_admin', '全部权限，含平台清库、完整实名查看、所有高风险操作', 1, 1),
(2, '运营',       'operator',    '藏品/盲盒管理、活动配置、CMS、基础用户管理、订单查看', 1, 1),
(3, '财务',       'finance',    '订单管理、退款审批、钱包流水、财务报表', 1, 1),
(4, '风控',       'risk',        '黑名单、风控告警、实名完整查看、异常交易处理', 1, 1),
(5, '客服',       'support',    '工单处理、基础用户查询（仅脱敏信息）', 1, 1);

-- 4.2 权限树（一级菜单 → 二级页面 → 按钮）
INSERT INTO `nft_admin_permissions` (`id`, `name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`) VALUES
-- 数据大盘
(100, '数据大盘', 'dashboard:view', 'dashboard', 1, 0, '/dashboard', 'Odometer', 1),
-- 用户管理
(200, '用户管理', 'user:list', 'user', 1, 0, '/user', 'User', 2),
(201, '用户详情', 'user:detail', 'user', 1, 200, '/user/:id', '', 1),
(202, '冻结/解冻用户', 'user:freeze', 'user', 2, 200, '', '', 2),
(203, '重置交易密码/强制登出', 'user:manage', 'user', 2, 200, '', '', 3),
(204, '加入/移出黑名单', 'user:blacklist', 'user', 2, 200, '', '', 4),
(205, '强制回收藏品/盲盒', 'user:recover', 'user', 2, 200, '', '', 5),
-- 实名认证
(300, '实名认证', 'realname:list', 'realname', 1, 0, '/realname', 'UserFilled', 3),
(301, '查看完整实名信息', 'realname:full', 'realname', 2, 300, '', '', 1),
-- 藏品管理
(400, '藏品管理', 'collectible:list', 'collectible', 1, 0, '/collectible', 'Collection', 4),
(401, '藏品详情', 'collectible:detail', 'collectible', 1, 400, '/collectible/:id', '', 1),
(402, '创建藏品', 'collectible:create', 'collectible', 2, 400, '', '', 2),
(403, '编辑藏品', 'collectible:edit', 'collectible', 2, 400, '', '', 3),
(404, '发售配置/重新上架', 'collectible:release', 'collectible', 2, 400, '', '', 4),
(405, '配额配置', 'collectible:quota', 'collectible', 2, 400, '', '', 5),
(406, '强制售罄/下架', 'collectible:manage', 'collectible', 2, 400, '', '', 6),
(407, '销毁库存', 'collectible:destroy', 'collectible', 2, 400, '', '', 7),
(408, '删除藏品', 'collectible:delete', 'collectible', 2, 400, '', '', 8),
(409, '独立空投', 'collectible:airdrop', 'collectible', 2, 400, '', '', 9),
(410, '寄售开关/价格管控', 'collectible:market', 'collectible', 2, 400, '', '', 10),
(411, '资格购配置', 'collectible:qualification', 'collectible', 2, 400, '', '', 11),
(412, '库存审计', 'collectible:audit', 'collectible', 2, 400, '', '', 12),
-- 盲盒管理
(500, '盲盒管理', 'blindbox:list', 'blindbox', 1, 0, '/blindbox', 'Box', 5),
(501, '盲盒详情', 'blindbox:detail', 'blindbox', 1, 500, '/blindbox/:id', '', 1),
(502, '创建盲盒', 'blindbox:create', 'blindbox', 2, 500, '', '', 2),
(503, '编辑盲盒', 'blindbox:edit', 'blindbox', 2, 500, '', '', 3),
(504, '子藏品/概率配置', 'blindbox:config', 'blindbox', 2, 500, '', '', 4),
(505, '发售配置', 'blindbox:release', 'blindbox', 2, 500, '', '', 5),
(506, '强制售罄/下架', 'blindbox:manage', 'blindbox', 2, 500, '', '', 6),
(507, '销毁库存', 'blindbox:destroy', 'blindbox', 2, 500, '', '', 7),
(508, '独立空投', 'blindbox:airdrop', 'blindbox', 2, 500, '', '', 8),
(509, '盲盒库存审计', 'blindbox:audit', 'blindbox', 2, 500, '', '', 9),
-- 订单管理
(600, '订单管理', 'order:list', 'order', 1, 0, '/order', 'Document', 6),
(601, '订单详情', 'order:detail', 'order', 1, 600, '/order/:id', '', 1),
(602, '取消/标记支付', 'order:manage', 'order', 2, 600, '', '', 2),
(603, '退款操作', 'order:refund', 'order', 2, 600, '', '', 3),
(604, '异常订单/导出', 'order:audit', 'order', 2, 600, '', '', 4),
-- 退款管理
(700, '退款管理', 'refund:list', 'refund', 1, 0, '/refund', 'Money', 7),
(701, '退款审批', 'refund:approve', 'refund', 2, 700, '', '', 1),
-- 市场寄售
(800, '市场寄售', 'market:list', 'market', 1, 0, '/market', 'Shop', 8),
(801, '挂单冻结/强制下架', 'market:manage', 'market', 2, 800, '', '', 1),
(802, '手续费配置', 'market:config', 'market', 2, 800, '', '', 2),
-- 转赠管理
(900, '转赠管理', 'transfer:list', 'transfer', 1, 0, '/transfer', 'Share', 9),
(901, '撤销/强制取消转赠', 'transfer:manage', 'transfer', 2, 900, '', '', 1),
-- 营销活动
(1000, '营销活动', 'marketing:priority:list', 'marketing', 1, 0, '/marketing/priority', 'Present', 10),
(1001, '优先购活动管理', 'marketing:priority:manage', 'marketing', 2, 1000, '', '', 1),
(1002, '签到活动配置', 'marketing:checkin:config', 'marketing', 1, 1000, '/marketing/checkin', 'Calendar', 2),
(1003, '邀请活动配置', 'marketing:invite:config', 'marketing', 1, 1000, '/marketing/invite', 'Promotion', 3),
(1004, '抽奖活动管理', 'marketing:lucky:list', 'marketing', 1, 1000, '/marketing/lucky-draw', 'Trophy', 4),
(1005, '抽奖奖项配置', 'marketing:lucky:manage', 'marketing', 2, 1004, '', '', 1),
(1006, '合成活动管理', 'marketing:synthesis:list', 'marketing', 1, 1000, '/marketing/synthesis', 'Connection', 5),
(1007, '合成活动编辑', 'marketing:synthesis:manage', 'marketing', 2, 1006, '', '', 1),
(1008, '活动空投发放', 'marketing:airdrop', 'marketing', 1, 1000, '/marketing/airdrop', 'Promotion', 6),
(1009, '注册福利配置', 'marketing:register:config', 'marketing', 1, 1000, '/marketing/register', 'Gift', 7),
-- 钱包财务
(1100, '钱包财务', 'wallet:transaction', 'wallet', 1, 0, '/wallet/transaction', 'Wallet', 11),
(1101, '充值记录', 'wallet:recharge', 'wallet', 1, 1100, '/wallet/recharge', '', 1),
(1102, '手续费统计', 'wallet:fee', 'wallet', 1, 1100, '/wallet/fee', '', 2),
(1103, '资金守恒校验', 'wallet:audit', 'wallet', 2, 1100, '', '', 3),
(1104, '异常资金监控', 'wallet:monitor', 'wallet', 1, 1100, '/wallet/abnormal', '', 4),
-- 内容管理
(1200, '内容管理', 'cms:banner', 'cms', 1, 0, '/cms/banner', 'Picture', 12),
(1201, '公告管理', 'cms:announcement', 'cms', 1, 1200, '/cms/announcement', '', 1),
(1202, '协议管理', 'cms:agreement', 'cms', 1, 1200, '/cms/agreement', '', 2),
(1203, '文物展馆', 'cms:artifact', 'cms', 1, 1200, '/cms/artifact', '', 3),
(1204, '站点装修', 'cms:decoration', 'cms', 1, 1200, '/cms/decoration', '', 4),
-- 系统配置
(1300, '系统配置', 'system:config', 'system', 1, 0, '/system/global', 'Setting', 13),
(1301, '支付渠道配置', 'system:payment', 'system', 1, 1300, '/system/payment', '', 1),
(1302, '短信配置', 'system:sms', 'system', 1, 1300, '/system/sms', '', 2),
(1303, '安全策略配置', 'system:security', 'system', 1, 1300, '/system/security', '', 3),
-- 权限管理
(1400, '权限管理', 'permission:admin', 'permission', 1, 0, '/permission/admin', 'Key', 14),
(1401, '角色管理', 'permission:role', 'permission', 1, 1400, '/permission/role', '', 1),
(1402, '操作/登录日志', 'permission:log', 'permission', 1, 1400, '/permission/operation-log', '', 2),
-- 风控安全
(1500, '风控安全', 'security:blacklist', 'security', 1, 0, '/security/blacklist', 'Warning', 15),
(1501, '风控告警', 'security:alert', 'security', 1, 1500, '/security/risk-alert', '', 1),
(1502, '安全事件', 'security:event', 'security', 1, 1500, '/security/event', '', 2),
-- 客服工单
(1600, '客服工单', 'ticket:list', 'ticket', 1, 0, '/ticket', 'Service', 16),
(1601, '工单处理', 'ticket:manage', 'ticket', 2, 1600, '', '', 1),
-- 数据报表
(1700, '数据报表', 'report:sales', 'report', 1, 0, '/report/sales', 'TrendCharts', 17),
(1701, '用户报表', 'report:user', 'report', 1, 1700, '/report/user', '', 1),
(1702, '藏品报表', 'report:collectible', 'report', 1, 1700, '/report/collectible', '', 2),
(1703, '盲盒报表', 'report:blindbox', 'report', 1, 1700, '/report/blindbox', '', 3),
(1704, '财务对账', 'report:finance', 'report', 1, 1700, '/report/finance', '', 4),
-- 平台运维
(1800, '平台运维', 'platform:log', 'platform', 1, 0, '/platform/logs', 'Delete', 18),
(1801, '一键清库', 'platform:cleanup', 'platform', 2, 1800, '', '', 1);

-- 4.3 超级管理员账号（admin / admin123，首次登录后请修改）
INSERT INTO `nft_admin_users`
  (`id`, `username`, `password_hash`, `real_name`, `role_id`, `phone`, `status`)
VALUES
  (1, 'admin', '$2y$10$MZezM8D3P/6A97GsugEux.HuOiICuhcmmrwHGigMpjehdeCOnQwiG', '超级管理员', 1, '13800000000', 1);

-- 4.4 角色权限映射
-- 超级管理员：全部权限
INSERT INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `nft_admin_permissions`;

-- 运营：藏品/盲盒/营销/CMS/基础用户/订单查看/工单
INSERT INTO `nft_admin_role_permissions` (`role_id`, `permission_id`) VALUES
(2, 100),
(2, 200), (2, 201), (2, 202), (2, 203), (2, 204), (2, 205),
(2, 300),
(2, 400), (2, 401), (2, 402), (2, 403), (2, 404), (2, 405), (2, 406), (2, 407), (2, 408), (2, 409), (2, 410), (2, 411), (2, 412),
(2, 500), (2, 501), (2, 502), (2, 503), (2, 504), (2, 505), (2, 506), (2, 507), (2, 508), (2, 509),
(2, 600), (2, 601),
(2, 800),
(2, 900),
(2, 1000), (2, 1001), (2, 1002), (2, 1003), (2, 1004), (2, 1005), (2, 1006), (2, 1007), (2, 1008), (2, 1009),
(2, 1200), (2, 1201), (2, 1202), (2, 1203), (2, 1204),
(2, 1600), (2, 1601),
(2, 1700), (2, 1701), (2, 1702), (2, 1703);

-- 财务：订单/退款/钱包/财务报表
INSERT INTO `nft_admin_role_permissions` (`role_id`, `permission_id`) VALUES
(3, 100),
(3, 600), (3, 601), (3, 602), (3, 603), (3, 604),
(3, 700), (3, 701),
(3, 800), (3, 802),
(3, 900),
(3, 1100), (3, 1101), (3, 1102), (3, 1103), (3, 1104),
(3, 1700), (3, 1704);

-- 风控：黑名单/告警/安全事件/实名完整查看/回收
INSERT INTO `nft_admin_role_permissions` (`role_id`, `permission_id`) VALUES
(4, 100),
(4, 200), (4, 201), (4, 204), (4, 205),
(4, 300), (4, 301),
(4, 400), (4, 401),
(4, 500), (4, 501),
(4, 600), (4, 601), (4, 604),
(4, 800), (4, 801),
(4, 900), (4, 901),
(4, 1500), (4, 1501), (4, 1502),
(4, 1700), (4, 1701), (4, 1702), (4, 1703), (4, 1704);

-- 客服：工单/基础查询（仅脱敏）
INSERT INTO `nft_admin_role_permissions` (`role_id`, `permission_id`) VALUES
(5, 100),
(5, 200), (5, 201),
(5, 300),
(5, 400), (5, 401),
(5, 500), (5, 501),
(5, 600), (5, 601),
(5, 1600), (5, 1601);

-- 4.5 短信默认配置（mock 模式，通过后台切换为 aliyun/tencent）
INSERT INTO `nft_sms_configs`
  (`id`, `provider`, `is_enabled`, `daily_limit`)
VALUES
  (1, 'mock', 1, 0);

-- 4.6 默认支付渠道（balance 启用，其余为占位配置，密钥通过后台填写）
INSERT INTO `nft_payment_channels`
  (`id`, `channel_code`, `channel_name`, `fee_rate`, `status`, `is_recommended`, `sort_order`, `remark`) VALUES
(1, 'balance', '余额支付', 0.00, 1, 1, 1, '平台钱包余额支付（司南币/现金余额），无需第三方配置'),
(2, 'alipay',  '支付宝',  0.60, 0, 0, 2, '支付宝当面付/APP支付，需配置应用ID与私钥'),
(3, 'wechat',  '微信支付', 0.60, 0, 0, 3, '微信Native/JSAPI支付，需配置商户号与API密钥'),
(4, 'huifu',   '汇付天下', 0.38, 0, 0, 4, '汇付天下聚合支付（钱包brand默认汇付），需配置商户号'),
(5, 'unionpay','银联支付', 0.50, 0, 0, 5, '银联在线支付，需配置商户号与证书');

-- 4.7 系统参数补充（风控/安全相关阈值，后台「系统配置」可改）
INSERT INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES
('admin_login_fail_limit',    '5',    '管理后台连续登录失败锁定阈值（次）'),
('admin_lock_minutes',        '15',   '管理后台账号锁定时长（分钟）'),
('resale_price_global_max',   '100000', '寄售挂单全局最高价（元，不限价模式仍受此约束）'),
('large_recharge_alert',      '10000', '单笔充值风控告警阈值（元）'),
('cleanup_sms_required',     '1',    '平台清库是否需要短信验证码二次确认（1=是）')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 执行完毕。库存恒等式（库存审计依据，任何操作不得打破）：
--   发行总量(edition) = 库存池 + 已配置配额(reserved_count) + 已售出发售(sold)
--                     + 已独立空投(airdropped_count) + 已销毁(destroyed_count)
--   库存池(自由库存) = edition - reserved_count - sold - airdropped_count
--                    - destroyed_count（待支付锁定 locked_quantity 属于未来 sold）
--   流通量(circulate) = 已售出发售 + 已独立空投（已进入用户仓库的数量）
-- 盲盒说明：本项目盲盒与藏品 1:1（nft_blind_boxes.collectible_id 唯一），
--   盲盒的发行量/库存池/配额/空投/销毁计数均落在其关联的 nft_collectibles 行上，
--   开启消耗通过 opened_count 与子藏品 quantity_distributed 统计。
-- ============================================================================
