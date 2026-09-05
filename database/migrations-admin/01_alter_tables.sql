-- ============================================================================
-- 01_alter_tables.sql — 现有表扩展（文档 4.1）+ 存量回填（4.4）+ CHECK 约束升级（4.3.2）
-- 幂等可重跑；原约束名以实际库为准（chk_collectibles_stock）
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 4.1.1 nft_collectibles 藏品主表扩展（盲盒行同样适用，D-3）
-- ---------------------------------------------------------------------------
ALTER TABLE `nft_collectibles`
  ADD COLUMN IF NOT EXISTS `release_quantity` INT UNSIGNED NULL DEFAULT NULL COMMENT '计划发售数量，NULL=不限（以库存池售完为止）' AFTER `edition`,
  ADD COLUMN IF NOT EXISTS `reserved_count`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '配额预留总数（优先购/活动空投/签到/邀请/抽奖等，冻结自库存池）' AFTER `locked_quantity`,
  ADD COLUMN IF NOT EXISTS `airdropped_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已独立空投数量' AFTER `reserved_count`,
  ADD COLUMN IF NOT EXISTS `destroyed_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已销毁数量（不可逆）' AFTER `airdropped_count`,
  ADD COLUMN IF NOT EXISTS `is_transferable`  TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '是否可转赠 1=可 0=不可（藏品级独立开关）' AFTER `tag`,
  ADD COLUMN IF NOT EXISTS `is_resaleable`    TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '是否允许寄售（二级市场总开关，关闭时强制下架全部挂单）' AFTER `is_transferable`,
  ADD COLUMN IF NOT EXISTS `resale_price_mode` TINYINT     NOT NULL DEFAULT 0 COMMENT '寄售价格管控 0=不限价 1=限价' AFTER `is_resaleable`,
  ADD COLUMN IF NOT EXISTS `resale_price_min` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '寄售限价下限（元）' AFTER `resale_price_mode`,
  ADD COLUMN IF NOT EXISTS `resale_price_max` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '寄售限价上限（元）' AFTER `resale_price_min`,
  ADD COLUMN IF NOT EXISTS `per_user_limit`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '藏品级每人限购，0=不限购；非 0 时覆盖系统配置 purchase_limit_per_user' AFTER `resale_price_max`;

-- 状态枚举扩展：draft 草稿(未配发售) / off 已下架（幂等：重复 MODIFY 同一定义无副作用）
ALTER TABLE `nft_collectibles`
  MODIFY COLUMN `status` ENUM('draft','upcoming','onsale','soldout','off')
  NOT NULL DEFAULT 'draft' COMMENT 'draft草稿(未配发售)/upcoming待发售/onsale发售中/soldout已售罄/off已下架';

-- ---------------------------------------------------------------------------
-- 4.1.2 nft_blind_boxes 盲盒专属开关（盲盒库存即其藏品行，D-3）
-- ---------------------------------------------------------------------------
ALTER TABLE `nft_blind_boxes`
  ADD COLUMN IF NOT EXISTS `is_transferable` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '盲盒是否可转赠 1=可 0=不可' AFTER `is_openable`,
  ADD COLUMN IF NOT EXISTS `is_resaleable`   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '盲盒是否允许寄售（预留，P3 起用）' AFTER `is_transferable`;

-- ---------------------------------------------------------------------------
-- 4.1.3 nft_orders / nft_users / nft_resale_listings
-- ---------------------------------------------------------------------------
ALTER TABLE `nft_orders`
  MODIFY COLUMN `source` ENUM('release','market','priority','eligibility')
  NOT NULL DEFAULT 'release' COMMENT 'release公售/market市场/priority优先购/eligibility资格购（盲盒购买复用 release，凭 collectible 存在盲盒行区分）';

ALTER TABLE `nft_users`
  ADD COLUMN IF NOT EXISTS `is_blacklisted`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否黑名单 0=正常 1=黑名单' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `blacklist_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT '拉黑原因' AFTER `is_blacklisted`,
  ADD COLUMN IF NOT EXISTS `blacklist_at`     DATETIME(3) NULL DEFAULT NULL COMMENT '拉黑时间' AFTER `blacklist_reason`,
  ADD COLUMN IF NOT EXISTS `blacklist_by`     BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '拉黑操作管理员ID' AFTER `blacklist_at`;

ALTER TABLE `nft_resale_listings`
  ADD COLUMN IF NOT EXISTS `is_system_delisted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否被系统强制下架 0=否 1=是' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `system_delisted_at` DATETIME(3) NULL DEFAULT NULL COMMENT '系统下架时间' AFTER `is_system_delisted`,
  ADD COLUMN IF NOT EXISTS `delist_reason`      VARCHAR(255) NULL DEFAULT NULL COMMENT '下架原因' AFTER `system_delisted_at`;

ALTER TABLE `nft_resale_listings`
  MODIFY COLUMN `status` ENUM('selling','sold','cancelled','system_off')
  NOT NULL COMMENT 'selling在售/sold已售/cancelled已取消/system_off系统强制下架';

-- ---------------------------------------------------------------------------
-- 独立空投台账支持：独立空投不绑定活动（红线 6），nft_airdrop_records.activity_id 放开为可空
-- ---------------------------------------------------------------------------
ALTER TABLE `nft_airdrop_records`
  MODIFY COLUMN `activity_id` INT UNSIGNED NULL COMMENT '关联活动ID，独立空投为NULL';

-- ---------------------------------------------------------------------------
-- 4.3.2 CHECK 约束升级（先按实际约束名删除旧约束，再建新约束）
--   edition ≥ sold + locked + reserved + airdropped + destroyed 且 circulate ≤ edition
-- ---------------------------------------------------------------------------
-- 注：MariaDB 10.x 删除 CHECK 用 DROP CONSTRAINT（DROP CHECK 为 MySQL 8 语法）
SET @drop_old = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_collectibles'
           AND CONSTRAINT_NAME = 'chk_collectibles_stock'),
  'ALTER TABLE `nft_collectibles` DROP CONSTRAINT `chk_collectibles_stock`',
  'SELECT 1'
);
PREPARE stmt FROM @drop_old; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @drop_new = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_collectibles'
           AND CONSTRAINT_NAME = 'chk_c_stock'),
  'ALTER TABLE `nft_collectibles` DROP CONSTRAINT `chk_c_stock`',
  'SELECT 1'
);
PREPARE stmt FROM @drop_new; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `nft_collectibles` ADD CONSTRAINT `chk_c_stock` CHECK (
  `sold` + `locked_quantity` + `reserved_count` + `airdropped_count` + `destroyed_count` <= `edition`
  AND `circulate` <= `edition`
);

-- ---------------------------------------------------------------------------
-- 4.4 存量数据回填（幂等）
-- ---------------------------------------------------------------------------
-- 历史空投量回填：现有 circulate 与 sold 的差额即历史空投（开发库数据）
UPDATE `nft_collectibles` SET `airdropped_count` = GREATEST(`circulate` - `sold`, 0)
WHERE `circulate` > `sold`;

-- 历史藏品状态归一：已有 onsale_at 且未来 → upcoming；未配发售时间的存量置 draft 需人工核对
UPDATE `nft_collectibles` SET `status` = 'draft' WHERE `onsale_at` IS NULL AND `status` = 'upcoming';
