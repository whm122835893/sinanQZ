-- ============================================================================
-- 04_create_governance.sql — 审计与治理（文档 4.2.2 剩余表）+ 客服与运维（4.2.5）
-- 幂等可重跑（CREATE TABLE IF NOT EXISTS）
-- 注：nft_operation_logs / nft_admin_login_logs 已随 02 脚本建表
-- P1 使用：nft_destroy_records（销毁记录）、nft_refunds（退款审批）；
-- 其余表随 P2/P3 模块启用，先行建表不改任何现有功能。
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 4.2.2 审计与治理（nft_operation_logs 见 02 脚本）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_destroy_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_type` TINYINT NOT NULL COMMENT '1藏品 2盲盒（盲盒=target_id 为 nft_blind_boxes.id）',
  `target_id` INT UNSIGNED NOT NULL,
  `collectible_id` INT UNSIGNED NOT NULL COMMENT '统一冗余藏品ID（盲盒场景为盲盒藏品ID）',
  `target_name` VARCHAR(100) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NULL,
  `admin_id` INT UNSIGNED NOT NULL,
  `admin_name` VARCHAR(50) NOT NULL,
  `ip` VARCHAR(50) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), KEY `idx_collectible` (`collectible_id`), KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='销毁记录（不可逆）';

CREATE TABLE IF NOT EXISTS `nft_refunds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refund_no` VARCHAR(30) NOT NULL COMMENT '退款单号 TK+时间戳',
  `order_id` BIGINT UNSIGNED NOT NULL,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL COMMENT '退款金额（≤ 实付）',
  `reason` VARCHAR(255) NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1待审批 2已批准 3已拒绝 4已退款',
  `applicant_id` INT UNSIGNED NOT NULL COMMENT '申请人（管理员）',
  `approver_id` INT UNSIGNED NULL,
  `approved_at` DATETIME(3) NULL,
  `refunded_at` DATETIME(3) NULL,
  `refund_channel` VARCHAR(50) NULL COMMENT 'balance/alipay/wechat',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), UNIQUE KEY `uk_refund_no` (`refund_no`),
  KEY `idx_order` (`order_id`), KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='退款记录（需审批工作流）';

CREATE TABLE IF NOT EXISTS `nft_approvals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `approval_type` TINYINT NOT NULL COMMENT '1大额退款(≥5000元) 2强制修改用户资产 3修改支付配置 4平台清库 5其他敏感操作',
  `target_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `request_data` JSON NULL COMMENT '申请数据快照',
  `applicant_id` INT UNSIGNED NOT NULL,
  `applicant_name` VARCHAR(50) NOT NULL,
  `approver_id` INT UNSIGNED NULL,
  `approver_name` VARCHAR(50) NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1待审批 2已批准 3已拒绝',
  `comment` VARCHAR(255) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `approved_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`), KEY `idx_type` (`approval_type`), KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='敏感操作审批工作流';

CREATE TABLE IF NOT EXISTS `nft_blacklist` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `blacklist_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1用户级 2IP级 3设备级',
  `target_value` VARCHAR(255) NOT NULL COMMENT '用户ID/IP/设备号',
  `reason` VARCHAR(255) NOT NULL,
  `evidence` TEXT NULL,
  `admin_id` INT UNSIGNED NOT NULL,
  `admin_name` VARCHAR(50) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1生效 0已解除',
  `lifted_at` DATETIME(3) NULL,
  `lifted_by` INT UNSIGNED NULL,
  `expires_at` DATETIME(3) NULL COMMENT '自动过期，NULL=永久',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), UNIQUE KEY `uk_user_type` (`user_id`,`blacklist_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='黑名单';

CREATE TABLE IF NOT EXISTS `nft_risk_alerts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_type` TINYINT NOT NULL COMMENT '1大额充值 2频繁小额充值 3余额突变 4高频API 5异常时间操作 6异地登录 7批量注册 8异常价格 9其他',
  `alert_level` TINYINT NOT NULL DEFAULT 1 COMMENT '1低 2中 3高 4紧急',
  `user_id` BIGINT UNSIGNED NULL,
  `target_id` BIGINT UNSIGNED NULL, `target_type` VARCHAR(50) NULL,
  `title` VARCHAR(200) NOT NULL, `description` TEXT NULL,
  `evidence` JSON NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1未处理 2处理中 3已处理 4已忽略',
  `handler_id` INT UNSIGNED NULL, `handler_name` VARCHAR(50) NULL,
  `handled_at` DATETIME(3) NULL, `handle_comment` VARCHAR(255) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), KEY `idx_type` (`alert_type`), KEY `idx_status` (`status`), KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风控告警';

CREATE TABLE IF NOT EXISTS `nft_security_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` TINYINT NOT NULL COMMENT '1IDOR尝试 2越权访问 3支付回调篡改 4Token异常 5暴力破解 6SQL注入 7XSS 8其他',
  `event_level` TINYINT NOT NULL DEFAULT 1,
  `user_id` BIGINT UNSIGNED NULL, `admin_id` INT UNSIGNED NULL,
  `ip` VARCHAR(50) NULL, `user_agent` VARCHAR(500) NULL,
  `request_path` VARCHAR(255) NULL, `request_method` VARCHAR(10) NULL,
  `request_params` TEXT NULL,
  `description` TEXT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1未处理 2已确认 3已处理 4误报',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), KEY `idx_type` (`event_type`), KEY `idx_ip` (`ip`), KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='安全事件审计';

-- ---------------------------------------------------------------------------
-- 4.2.5 客服与运维（5 张，随 P3/P4 模块启用）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_support_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no` VARCHAR(20) NOT NULL COMMENT 'TK+日期+序号',
  `user_id` BIGINT UNSIGNED NOT NULL,
  `user_phone` VARCHAR(11) NULL,
  `ticket_type` TINYINT NOT NULL COMMENT '1支付异常 2藏品丢失 3盲盒问题 4转赠纠纷 5账号问题 6其他',
  `priority` TINYINT NOT NULL DEFAULT 3 COMMENT '1紧急 2高 3中 4低',
  `title` VARCHAR(200) NOT NULL, `description` TEXT NULL,
  `related_order_id` BIGINT UNSIGNED NULL, `related_collectible_id` INT UNSIGNED NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1待处理 2处理中 3待用户确认 4已解决 5已关闭',
  `assignee_id` INT UNSIGNED NULL COMMENT '处理管理员', `assignee_name` VARCHAR(50) NULL,
  `solved_at` DATETIME(3) NULL, `closed_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_status` (`status`), KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服工单（P4，C端暂无提单入口，先支持后台代录）';

CREATE TABLE IF NOT EXISTS `nft_ticket_replies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `sender_type` TINYINT NOT NULL COMMENT '1用户 2客服 3系统',
  `sender_id` BIGINT UNSIGNED NOT NULL,
  `sender_name` VARCHAR(50) NOT NULL,
  `content` TEXT NOT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1内部备注（用户不可见）',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), KEY `idx_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单回复';

CREATE TABLE IF NOT EXISTS `nft_user_feedbacks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `content` TEXT NOT NULL,
  `contact` VARCHAR(50) NULL COMMENT '联系方式',
  `images` JSON NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1未读 2已读 3已处理',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户反馈（查缺补漏新增：提示词含反馈管理但 C 端无入口，P4 提单入口一并补）';

CREATE TABLE IF NOT EXISTS `nft_platform_cleanup_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL, `admin_name` VARCHAR(50) NOT NULL, `admin_phone` VARCHAR(20) NOT NULL,
  `ip` VARCHAR(50) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `backup_path` VARCHAR(500) NOT NULL COMMENT 'mysqldump 备份文件路径',
  `affected_users` INT UNSIGNED NOT NULL DEFAULT 0,
  `affected_orders` INT UNSIGNED NOT NULL DEFAULT 0,
  `execution_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '耗时秒',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1成功 2失败',
  `error_message` TEXT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='平台清库日志';

CREATE TABLE IF NOT EXISTS `nft_agreements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('user','privacy','risk','recharge','trade') NOT NULL COMMENT '用户协议/隐私政策/风险提示/充值协议/交易协议',
  `title` VARCHAR(100) NOT NULL,
  `content` LONGTEXT NOT NULL COMMENT '富文本 HTML',
  `version` VARCHAR(20) NOT NULL COMMENT '版本号 v1.0',
  `is_current` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '同 type 仅一条 current',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), KEY `idx_type` (`type`,`is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='协议管理（查缺补漏新增：C 端登录/注册页协议弹窗目前为占位文案）';
