-- ============================================================================
-- 司南数字藏品平台 · 置换计划升级（幂等可重复执行）
--
-- 统一置换：管理员选择一个或多个源藏品并配置各自置换比例（每持有 1 份 → N 份新藏品），
-- 统一回收所有源藏品有效持仓（held/consigned/frozen），再按「Σ(持有数量 × 比例)」
-- 向持有人空投目标新藏品。全程单事务，记录计划、源配置、用户名单与资产明细。
--
-- 例：a 藏品比例 1:2、b 藏品比例 1:3
--   用户甲持有 1 份 b        → 空投 3 份
--   用户乙持有 a、b 各 1 份  → 空投 2 + 3 = 5 份
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. 置换计划（执行快照）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_swap_plans` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `plan_no`          VARCHAR(30)     NOT NULL                COMMENT '计划编号：SP+时间+随机',
  `new_collectible_id` INT UNSIGNED  NOT NULL                COMMENT '置换目标（新）藏品ID',
  `new_collectible_name` VARCHAR(100) NOT NULL               COMMENT '新藏品名称快照',
  `reason`           VARCHAR(255)    NULL DEFAULT NULL       COMMENT '置换原因',
  `user_count`       INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '受影响用户数（名单人数）',
  `total_recovered`  INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '回收资产总份数',
  `total_airdropped` INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '空投新藏品总份数',
  `admin_id`         INT UNSIGNED    NOT NULL                COMMENT '操作管理员ID',
  `admin_name`       VARCHAR(50)     NOT NULL                COMMENT '操作管理员姓名',
  `ip`               VARCHAR(45)     NULL DEFAULT NULL       COMMENT '操作IP',
  `created_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '执行时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plan_no` (`plan_no`),
  KEY `idx_new_collectible` (`new_collectible_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='置换计划（统一回收+按比例空投）';

-- ----------------------------------------------------------------------------
-- 2. 计划源藏品配置（每个被回收藏品一条，含比例）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_swap_plan_items` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `plan_id`             BIGINT UNSIGNED NOT NULL                COMMENT '计划ID，FK→nft_swap_plans.id',
  `old_collectible_id`  INT UNSIGNED    NOT NULL                COMMENT '源（旧）藏品ID',
  `old_collectible_name` VARCHAR(100)  NOT NULL                COMMENT '源藏品名称快照',
  `ratio`               INT UNSIGNED    NOT NULL DEFAULT 1      COMMENT '置换比例：每持有 1 份源藏品空投 N 份新藏品',
  `recovered_count`     INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '该藏品实际回收份数',
  `created_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_old_collectible` (`old_collectible_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='置换计划源藏品配置';

-- ----------------------------------------------------------------------------
-- 3. 计划用户名单（每个受影响用户一条）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_swap_plan_users` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `plan_id`          BIGINT UNSIGNED NOT NULL                COMMENT '计划ID，FK→nft_swap_plans.id',
  `user_id`          BIGINT UNSIGNED NOT NULL                COMMENT '用户ID，FK→nft_users.id',
  `phone`            VARCHAR(20)     NULL DEFAULT NULL       COMMENT '用户手机号',
  `uid`              VARCHAR(64)     NULL DEFAULT NULL       COMMENT '用户UID',
  `holdings`         JSON            NULL DEFAULT NULL       COMMENT '持有明细快照：[{collectibleId,name,quantity,ratio,newQuantity}]',
  `recovered_total`  INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '该用户被回收总份数',
  `airdrop_quantity` INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '该用户空投新藏品份数',
  `created_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_plan_user` (`plan_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='置换计划用户名单';

-- ----------------------------------------------------------------------------
-- 4. 计划资产明细（每份被回收/新空投资产各一行）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_swap_plan_details` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `plan_id`           BIGINT UNSIGNED NOT NULL                COMMENT '计划ID，FK→nft_swap_plans.id',
  `user_id`           BIGINT UNSIGNED NOT NULL                COMMENT '用户ID，FK→nft_users.id',
  `action`            TINYINT         NOT NULL                COMMENT '动作：1回收源藏品 2空投新藏品',
  `collectible_id`    INT UNSIGNED    NOT NULL                COMMENT '藏品ID（源或目标）',
  `collectible_name`  VARCHAR(100)    NOT NULL                COMMENT '藏品名称快照',
  `serial`            VARCHAR(20)     NULL DEFAULT NULL       COMMENT '资产编号',
  `user_collectible_id` BIGINT UNSIGNED NOT NULL               COMMENT '资产行ID，FK→nft_user_collectibles.id',
  `ratio`             INT UNSIGNED    NULL DEFAULT NULL       COMMENT '比例（仅回收行：该藏品每份对应空投数）',
  `created_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_plan` (`plan_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_plan_action` (`plan_id`, `action`),
  KEY `idx_user_collectible` (`user_collectible_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='置换计划资产明细（回收+空投）';
