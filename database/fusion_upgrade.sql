-- ============================================================================
-- 司南数字藏品平台 · 后台融合升级迁移（sinan-admin 主干化）
-- 内容：
--   1. 区块链模块：链网络表（文昌链/联盟链/蚂蚁链）+ 合约登记表
--   2. 审批中心：通用审批工作流表（大额退款等）
--   3. 藏品表：上链状态字段 + chain_type 枚举语义更新（wenchang/consortium/antchain）
--   4. 权限种子：实名审核/社区管理/区块链/审批中心
--   5. 角色权限映射：按角色补授新权限
--   6. 系统参数：大额退款审批阈值
-- 幂等性：可重复执行（CREATE IF NOT EXISTS / INSERT IGNORE / 条件 ALTER）
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. 链网络配置表（三大链：文昌链 / 联盟链 / 蚂蚁链）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_chain_networks` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `chain_code`   VARCHAR(20)  NOT NULL                COMMENT '链标识：wenchang文昌链/consortium联盟链/antchain蚂蚁链',
  `chain_name`   VARCHAR(50)  NOT NULL                COMMENT '链名称（展示用）',
  `env`          VARCHAR(10)  NOT NULL DEFAULT 'main' COMMENT '网络环境：main主网/test测试网',
  `rpc_url`      VARCHAR(255) NULL DEFAULT NULL       COMMENT 'RPC/网关地址',
  `chain_id`     VARCHAR(50)  NULL DEFAULT NULL       COMMENT '链ID/网络标识',
  `api_key`      TEXT         NULL DEFAULT NULL       COMMENT '接入密钥（AES 加密存储）',
  `api_secret`   TEXT         NULL DEFAULT NULL       COMMENT '接入密钥密码（AES 加密存储）',
  `explorer_url` VARCHAR(255) NULL DEFAULT NULL       COMMENT '区块浏览器地址前缀',
  `gas_strategy` VARCHAR(20)  NOT NULL DEFAULT 'medium' COMMENT '上链费率策略：low/medium/high',
  `status`       TINYINT      NOT NULL DEFAULT 0      COMMENT '状态：1启用 0停用',
  `is_default`   TINYINT(1)   NOT NULL DEFAULT 0      COMMENT '是否默认链：1是 0否（全局仅一条）',
  `remark`       VARCHAR(255) NULL DEFAULT NULL       COMMENT '备注',
  `created_at`   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chain_code` (`chain_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='区块链网络配置（藏品上链）';

-- ---------------------------------------------------------------------------
-- 2. 链上合约登记表
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_chain_contracts` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `network_id`      INT UNSIGNED NOT NULL                COMMENT '链网络ID，FK→nft_chain_networks.id',
  `contract_name`   VARCHAR(100) NOT NULL                COMMENT '合约名称（业务命名）',
  `contract_address` VARCHAR(100) NOT NULL                COMMENT '链上合约地址',
  `contract_type`   VARCHAR(20)  NOT NULL DEFAULT 'erc721' COMMENT '合约类型：erc721/erc1155/ddc721/ddc1155',
  `token_standard`  VARCHAR(20)  NULL DEFAULT NULL       COMMENT '代币标准（展示）：ERC-721/ERC-1155/DDC',
  `description`      VARCHAR(255) NULL DEFAULT NULL       COMMENT '用途说明',
  `tx_count`         INT UNSIGNED NOT NULL DEFAULT 0     COMMENT '累计链上交易笔数（铸造时累加）',
  `status`           TINYINT      NOT NULL DEFAULT 1     COMMENT '状态：1启用（监听/可上链） 0停用',
  `created_at`      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_network` (`network_id`),
  KEY `idx_address` (`contract_address`),
  CONSTRAINT `fk_contract_network` FOREIGN KEY (`network_id`) REFERENCES `nft_chain_networks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='链上合约登记（藏品铸造目标合约）';

-- ---------------------------------------------------------------------------
-- 3. 审批中心工作流表
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_approval_requests` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `approval_no`    VARCHAR(30)  NOT NULL                  COMMENT '审批单号：AP+时间+随机',
  `type`           VARCHAR(30)  NOT NULL                  COMMENT '类型：large_refund大额退款/asset_modify资产变更/config_modify配置变更/platform_cleanup平台清库',
  `title`          VARCHAR(100) NOT NULL                  COMMENT '审批标题',
  `detail`         JSON         NULL DEFAULT NULL         COMMENT '业务快照（订单号/金额/理由等，JSON）',
  `amount`         DECIMAL(10,2) NULL DEFAULT NULL        COMMENT '涉及金额（元），可空',
  `target_type`    VARCHAR(30)  NULL DEFAULT NULL         COMMENT '关联目标类型：refund/order/collectible',
  `target_id`      BIGINT UNSIGNED NULL DEFAULT NULL      COMMENT '关联目标ID',
  `applicant_id`   INT UNSIGNED NOT NULL                  COMMENT '申请人（管理员）ID',
  `applicant_name` VARCHAR(50)  NOT NULL                  COMMENT '申请人姓名',
  `status`         TINYINT      NOT NULL DEFAULT 1        COMMENT '状态：1待审批 2已通过 3已驳回',
  `handler_id`     INT UNSIGNED NULL DEFAULT NULL          COMMENT '处理人（管理员）ID',
  `handler_name`   VARCHAR(50)  NULL DEFAULT NULL         COMMENT '处理人姓名',
  `handle_time`    DATETIME     NULL DEFAULT NULL          COMMENT '处理时间',
  `reason`         VARCHAR(255) NULL DEFAULT NULL         COMMENT '审批意见（驳回必填）',
  `created_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_approval_no` (`approval_no`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='审批中心（大额退款等高风险操作工作流）';

-- ---------------------------------------------------------------------------
-- 4. 藏品表：上链状态扩展（幂等：先查信息_schema 判断列是否存在由应用侧执行，
--    MySQL 8.0 不支持 ADD COLUMN IF NOT EXISTS，此处用存储过程实现幂等）
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `proc_fusion_upgrade`;
DELIMITER $$
CREATE PROCEDURE `proc_fusion_upgrade`()
BEGIN
  -- 4.1 collectibles.onchain_status 上链状态
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_collectibles' AND COLUMN_NAME = 'onchain_status') THEN
    ALTER TABLE `nft_collectibles`
      ADD COLUMN `onchain_status` TINYINT NOT NULL DEFAULT 0 COMMENT '上链状态：0未上链 1上链中 2已上链 3上链失败' AFTER `token_standard`,
      ADD COLUMN `minted_at` DATETIME NULL DEFAULT NULL COMMENT '最近一次上链铸造时间' AFTER `onchain_status`;
  END IF;

  -- 4.2 collectibles.chain_type 注释更新为三链枚举
  ALTER TABLE `nft_collectibles`
    MODIFY COLUMN `chain_type` VARCHAR(20) NULL DEFAULT NULL COMMENT '链类型：wenchang文昌链/consortium联盟链/antchain蚂蚁链';
END$$
DELIMITER ;

CALL `proc_fusion_upgrade`();
DROP PROCEDURE IF EXISTS `proc_fusion_upgrade`;

-- ---------------------------------------------------------------------------
-- 5. 权限种子（幂等）
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `nft_admin_permissions` (`id`, `name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`) VALUES
(302,  '实名审核操作',   'realname:audit',    'realname', 2, 300,  '',            '',       2),
(1205, '社区管理',       'cms:community',     'cms',      1, 1200, '/cms/community', '',     5),
(1900, '区块链管理',     'chain:config',     'chain',    1, 0,    '/chain',        'Link',   19),
(1901, '链上交易查询',   'chain:transaction', 'chain',   1, 1900, '/chain/transactions', '',  1),
(1902, '合约登记管理',   'chain:contract',    'chain',    2, 1900, '',            '',       2),
(1903, '上链铸造',       'chain:mint',        'chain',    2, 1900, '',            '',       3),
(2000, '审批中心',       'approval:list',    'approval', 1, 0,    '/approval',     'Stamp',  20),
(2001, '审批处理',       'approval:manage',  'approval', 2, 2000, '',            '',       1);

-- ---------------------------------------------------------------------------
-- 6. 角色权限映射（幂等 INSERT IGNORE）
--    超级管理员：全量（含新增，重刷一遍）
--    运营：+ 实名审核/社区管理
--    财务：+ 审批中心查看（发起大额退款后可跟踪进度）
--    风控：+ 实名审核/审批中心（处理人）
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `nft_admin_permissions`;

INSERT IGNORE INTO `nft_admin_role_permissions` (`role_id`, `permission_id`) VALUES
(2, 302), (2, 1205),
(3, 2000),
(4, 302), (4, 2000), (4, 2001);

-- ---------------------------------------------------------------------------
-- 7. 系统参数：大额退款审批阈值（元，超过即走审批中心）
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES
('large_refund_approval_threshold', '1000', '大额退款审批阈值（元），超过后退款审批需审批中心复核'),
('chain_mint_batch_limit', '500', '单次上链铸造最大持仓条数（防长事务）');

-- ---------------------------------------------------------------------------
-- 8. 链网络种子（三大链默认档案，默认停用，密钥后台填写后启用）
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `nft_chain_networks`
  (`id`, `chain_code`, `chain_name`, `env`, `explorer_url`, `status`, `is_default`, `remark`) VALUES
(1, 'wenchang',   '文昌链（BSN-DDC）', 'main', 'https://www.wenchangchain.com', 0, 1, '国家信息中心 BSN 文昌链 DDC 业务，国内合规数字藏品首选'),
(2, 'consortium', '联盟链（自建）',   'main', '',                              0, 0, '平台自建联盟链（FISCO BCOS 等），需配置节点 RPC'),
(3, 'antchain',   '蚂蚁链（AntChain）', 'main', 'https://antchain.antgroup.com', 0, 0, '蚂蚁链数字藏品服务（蚂蚁链开放联盟线），需配置 AccessKey');
