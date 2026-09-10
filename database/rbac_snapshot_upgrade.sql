-- ============================================================================
-- 司南数字藏品平台 · RBAC 权限字典补全 + 数据快照表迁移（v1.0.0）
-- 背景（F7-2 管理端测试发现的两处 schema 漂移）：
--   F7-D1: 抽签发售/求购挂单/置换/分解/回收站/数据快照/藏品置换 7 个功能模块的
--          路由已绑定权限码，但 nft_admin_permissions 字典未登记（91 → 98），
--          导致非超管角色永远 4003、模块沦为超管专属。
--   F7-D2: SnapshotController/SnapshotService 引用 nft_holdings_snapshots 与
--          nft_trade_snapshots 两表，但所有建库/升级脚本均未创建（1146）。
-- 幂等性：INSERT IGNORE（依赖 uk_code / uk_role_permission 唯一键）+
--         CREATE TABLE IF NOT EXISTS，可重复执行。
-- ============================================================================

SET NAMES utf8mb4;
USE `sinan_nft`;

-- ----------------------------------------------------------------------------
-- 一、F7-D1：补登记 7 个缺失权限码（父级沿用现有菜单：800 市场寄售/400 藏品/
--     1000 营销活动/1700 数据报表/1800 平台运维）
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `nft_admin_permissions` (`name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`) VALUES
('求购挂单',   'market:buyrequest:list',    'market',    1, 800,  '/market/buy-request', '', 3),
('置换市场',   'market:swap:list',           'market',    1, 800,  '/market/swap',        '', 4),
('藏品置换',   'collectible:swap',           'collectible', 2, 400, '',                   '', 13),
('抽签发售',   'marketing:raffle:list',     'marketing', 1, 1000, '/marketing/raffle',  'SetUp', 8),
('分解/熔炼',  'marketing:decompose:list',  'marketing', 1, 1000, '/marketing/decompose','', 9),
('数据快照',   'report:snapshot',            'report',    1, 1700, '/report/snapshot',   '', 5),
('回收站',     'platform:trash:list',        'platform', 1, 1800, '/platform/trash',   'DeleteFilled', 2);

-- ----------------------------------------------------------------------------
-- 二、F7-D1：角色授权补全（super 全量保持一致；运营补市场/营销模块；
--     财务/风控补数据快照对账；回收站保持平台运维家族=仅超管）
-- ----------------------------------------------------------------------------
-- 超级管理员：7 项全量（保持角色映射与字典一致）
INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `nft_admin_roles` r, `nft_admin_permissions` p
WHERE r.code = 'super_admin' AND p.code IN (
  'market:buyrequest:list', 'market:swap:list', 'collectible:swap',
  'marketing:raffle:list', 'marketing:decompose:list', 'report:snapshot', 'platform:trash:list');

-- 运营：求购/置换/藏品置换/抽签/分解（市场与营销运营家族）
INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `nft_admin_roles` r, `nft_admin_permissions` p
WHERE r.code = 'operator' AND p.code IN (
  'market:buyrequest:list', 'market:swap:list', 'collectible:swap',
  'marketing:raffle:list', 'marketing:decompose:list');

-- 财务：数据快照（对账家族 report:finance 同级）
INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `nft_admin_roles` r, `nft_admin_permissions` p
WHERE r.code = 'finance' AND p.code IN ('report:snapshot');

-- 风控：数据快照
INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `nft_admin_roles` r, `nft_admin_permissions` p
WHERE r.code = 'risk' AND p.code IN ('report:snapshot');

-- ----------------------------------------------------------------------------
-- 三、F7-D2：数据快照两张表（持仓快照 + 交易快照）
-- ----------------------------------------------------------------------------
-- 1. 持仓快照（用户 × 藏品聚合定格；同日重跑覆盖 → 唯一键支撑删除重插）
CREATE TABLE IF NOT EXISTS `nft_holdings_snapshots` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `snapshot_date`   DATE            NOT NULL                COMMENT '快照基准日期',
  `user_id`         BIGINT UNSIGNED NOT NULL                COMMENT '用户ID',
  `collectible_id`  INT UNSIGNED   NOT NULL                COMMENT '藏品ID',
  `collectible_name` VARCHAR(100)  NOT NULL DEFAULT ''     COMMENT '藏品名称快照',
  `total_count`     INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '持仓总数',
  `held_count`      INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '持有中数量',
  `consigned_count` INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '寄售中数量',
  `frozen_count`    INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '冻结数量',
  `avg_cost`        DECIMAL(10,2)  NULL DEFAULT NULL        COMMENT '平均获取成本',
  `created_at`      DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_user_collectible` (`snapshot_date`, `user_id`, `collectible_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户持仓快照表';

-- 2. 交易快照（按日聚合的用户交易汇总；同日重跑覆盖）
CREATE TABLE IF NOT EXISTS `nft_trade_snapshots` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `snapshot_date`      DATE            NOT NULL                COMMENT '快照基准日期',
  `user_id`            BIGINT UNSIGNED NOT NULL                COMMENT '用户ID',
  `buy_count`          INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '买入笔数',
  `buy_amount`         DECIMAL(12,2)  NOT NULL DEFAULT 0.00   COMMENT '买入总金额',
  `sell_count`         INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '卖出笔数',
  `sell_amount`        DECIMAL(12,2)  NOT NULL DEFAULT 0.00   COMMENT '卖出总金额',
  `transfer_in_count`  INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '受赠笔数',
  `transfer_out_count` INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '赠出笔数',
  `blindbox_open_count` INT UNSIGNED  NOT NULL DEFAULT 0       COMMENT '开盒次数',
  `consume_count`      INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT '合成/分解消耗次数',
  `created_at`         DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_user` (`snapshot_date`, `user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户交易快照表';

-- ----------------------------------------------------------------------------
-- 四、验证（人工执行确认）
-- ----------------------------------------------------------------------------
-- SELECT COUNT(*) FROM nft_admin_permissions WHERE status=1;          -- 预期 98
-- SELECT r.code, COUNT(rp.permission_id) FROM nft_admin_roles r
--   LEFT JOIN nft_admin_role_permissions rp ON rp.role_id=r.id
--   GROUP BY r.code;                                                  -- super 98 / operator 63 / finance 20 / risk 30 / support 12
-- SHOW TABLES LIKE 'nft_%snapshots%';                                 -- 含 holdings/trade 两表
