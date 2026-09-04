-- ============================================================================
-- 司南数字藏品平台 · MySQL 初始化脚本（自检修正版 v2.2.3）
-- 版本   : v2.2.3（对应数据库实体模型设计文档 database-design.html v2.2）
-- 引擎   : InnoDB / 字符集 utf8mb4 / 排序规则 utf8mb4_unicode_ci
-- 表结构 : 33 张（统一前缀 nft_），56 条外键，16 个 CHECK 约束，2 个条件唯一生成列
-- 说明   :
--   1. 严格按外键依赖顺序建表；仅两处循环依赖外键在末尾 ALTER 补挂：
--      nft_orders.resale_listing_id → nft_resale_listings
--      nft_user_collectibles.airdrop_record_id → nft_airdrop_records
--   2. 所有删除均为软删除（deleted_at）；FK 的 ON DELETE 为物理删除防御：
--      - RESTRICT : 流水/审计/主数据，禁止级联误删
--      - CASCADE  : 生命周期完全依附父表的配置子表/关系表
--      - SET NULL : 可空溯源字段
--   3. Mock 字符串已按设计文档合理转为 ENUM（status/source/prize_type 等）
--   4. 数据库层完整落库（v2.2.1 自检修正）：
--      - verification_codes.code 扩为 VARCHAR(128) 以支持哈希存储
--      - resale_listings 生成列 selling_ucid 条件唯一：同一资产在售挂单唯一
--      - transfers 生成列 pending_ucid 条件唯一：同一资产仅一笔待确认转赠
--      - 16 个命名 CHECK：金额/数量非负、概率值域、防超卖(sold+locked≤edition)
--   5. 以下约束按设计文档由业务层保证，不落库：
--      - 钱包恒等式 balance=available+frozen（事务中间态会瞬时违反）
--      - 盲盒概率跨行合计=1；合成产物不得出现在自身材料中（跨表）
--   6. 脚本可重复执行（开头 DROP + FK_CHECKS=0，结尾恢复）
--   7. v2.2.2 修正（沙箱实测 ERROR 1901）：被存储生成列引用的列
--      （resale_listings / transfers 的 user_collectible_id）上的外键
--      ON UPDATE 由 CASCADE 改为 RESTRICT —— MySQL/MariaDB 均禁止存储
--      生成列引用带级联更新外键的列（级联更新无法重算生成列导致唯一
--      索引失守）。被引用对象为自增主键、值永不变化，RESTRICT 无副作用
--   8. v2.2.3 修正：nft_lucky_draw_prizes 新增 probability DECIMAL(5,4)
--      中奖概率列（此前抽奖概率硬编码在后端，无法运营配置；同一活动
--      合计=1 由业务层归一化兜底，数据库仅保证值域 0~1）
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `sinan_nft`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE `sinan_nft`;

-- 逆序清理（配合 FOREIGN_KEY_CHECKS=0，可重复执行）
DROP TABLE IF EXISTS `nft_site_settings`;
DROP TABLE IF EXISTS `nft_system_configs`;
DROP TABLE IF EXISTS `nft_community_groups`;
DROP TABLE IF EXISTS `nft_banners`;
DROP TABLE IF EXISTS `nft_announcements`;
DROP TABLE IF EXISTS `nft_artifacts`;
DROP TABLE IF EXISTS `nft_invite_records`;
DROP TABLE IF EXISTS `nft_airdrop_eligibilities`;
DROP TABLE IF EXISTS `nft_airdrop_records`;
DROP TABLE IF EXISTS `nft_airdrop_snapshots`;
DROP TABLE IF EXISTS `nft_airdrop_activities`;
DROP TABLE IF EXISTS `nft_invite_activities`;
DROP TABLE IF EXISTS `nft_check_in_records`;
DROP TABLE IF EXISTS `nft_lucky_draw_records`;
DROP TABLE IF EXISTS `nft_lucky_draw_prizes`;
DROP TABLE IF EXISTS `nft_synthesis_record_items`;
DROP TABLE IF EXISTS `nft_synthesis_records`;
DROP TABLE IF EXISTS `nft_synthesis_materials`;
DROP TABLE IF EXISTS `nft_synthesis_activities`;
DROP TABLE IF EXISTS `nft_transfers`;
DROP TABLE IF EXISTS `nft_resale_listings`;
DROP TABLE IF EXISTS `nft_payments`;
DROP TABLE IF EXISTS `nft_user_collectibles`;
DROP TABLE IF EXISTS `nft_orders`;
DROP TABLE IF EXISTS `nft_user_favorites`;
DROP TABLE IF EXISTS `nft_blind_box_items`;
DROP TABLE IF EXISTS `nft_blind_boxes`;
DROP TABLE IF EXISTS `nft_collectibles`;
DROP TABLE IF EXISTS `nft_categories`;
DROP TABLE IF EXISTS `nft_verification_codes`;
DROP TABLE IF EXISTS `nft_wallet_transactions`;
DROP TABLE IF EXISTS `nft_wallets`;
DROP TABLE IF EXISTS `nft_users`;

-- ============================================================================
-- 一、用户域·基础（4 张）
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. 用户表 nft_users
-- 平台账户主表：登录手机号、实名信息（AES-256/SM4 加密）、交易密码（哈希）、
-- 邀请码与软删除
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_users` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `phone`                VARCHAR(11)     NOT NULL                COMMENT '手机号，登录账号',
  `username`             VARCHAR(50)     NOT NULL                COMMENT '用户名/昵称',
  `avatar`               VARCHAR(255)    NOT NULL DEFAULT ''     COMMENT '头像URL（空串由应用层兜底默认头像，避免NULL判断）',
  `uid`                  VARCHAR(10)     NOT NULL                COMMENT '站内展示UID',
  `invite_code`          VARCHAR(16)     NOT NULL                COMMENT '我的邀请码，注册链接 ?code= 绑定',
  `is_realname`          TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '实名标志：1已实名 0未实名（购买/寄售/转赠前置校验）',
  `real_name`            VARCHAR(255)    NULL DEFAULT NULL       COMMENT '真实姓名，AES-256/SM4 加密存储',
  `id_card`              VARCHAR(255)    NULL DEFAULT NULL       COMMENT '身份证号，加密存储',
  `transaction_password` VARCHAR(255)    NULL DEFAULT NULL       COMMENT '交易密码，bcrypt/scrypt 哈希（禁止明文）',
  `status`               TINYINT         NOT NULL DEFAULT 1      COMMENT '账户状态：1正常 0禁用',
  `last_login_at`        DATETIME(3)     NULL DEFAULT NULL       COMMENT '最后登录时间（登录空投依赖）',
  `login_count`          INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT '累计登录次数',
  `deleted_at`           DATETIME        NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at`           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone` (`phone`),
  UNIQUE KEY `uk_uid` (`uid`),
  UNIQUE KEY `uk_invite_code` (`invite_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表：平台账户主数据';

-- ----------------------------------------------------------------------------
-- 2. 钱包表 nft_wallets
-- 与用户 1:1。payments.payment_method='balance' 的扣款依据；司南币以 points 记账。
-- 恒等式 balance=available+frozen 由事务保证（不设CHECK，避免中间态瞬时违反）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_wallets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`    BIGINT UNSIGNED NOT NULL                COMMENT '用户ID，FK→nft_users.id，唯一',
  `balance`    DECIMAL(12,2)   NOT NULL DEFAULT 0.00   COMMENT '总资产（元）',
  `available`  DECIMAL(12,2)   NOT NULL DEFAULT 0.00   COMMENT '可用余额（元）',
  `frozen`     DECIMAL(12,2)   NOT NULL DEFAULT 0.00   COMMENT '冻结金额（待支付占用，元）',
  `points`     DECIMAL(12,2)   NOT NULL DEFAULT 0.00   COMMENT '司南币余额',
  `brand`      VARCHAR(16)     NOT NULL DEFAULT '汇付' COMMENT '第三方支付品牌',
  `created_at` DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  CONSTRAINT `chk_wallets_nonneg` CHECK (`balance` >= 0 AND `available` >= 0 AND `frozen` >= 0 AND `points` >= 0),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='钱包表：与用户1:1，余额/冻结/司南币';

-- ----------------------------------------------------------------------------
-- 3. 钱包流水表 nft_wallet_transactions（纯日志表，无 updated_at）
-- 充值/消费/提现/奖励四类，记录余额快照便于对账
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_wallet_transactions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`       BIGINT UNSIGNED NOT NULL                COMMENT '用户ID，FK→nft_users.id',
  `trans_type`    ENUM('recharge','buy','withdraw','reward') NOT NULL COMMENT '交易类型：recharge充值/buy消费/withdraw提现/reward奖励',
  `title`         VARCHAR(64)     NOT NULL                COMMENT '明细标题',
  `direction`     TINYINT         NOT NULL                COMMENT '资金方向：1收入 2支出',
  `amount`        DECIMAL(12,2)   NOT NULL                COMMENT '金额（绝对值）',
  `balance_after` DECIMAL(12,2)   NULL DEFAULT NULL       COMMENT '交易后余额快照（对账依据）',
  `biz_no`        VARCHAR(64)      NULL DEFAULT NULL       COMMENT '关联业务单号（订单号/空投记录ID等）',
  `created_at`    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_biz_no` (`biz_no`),
  CONSTRAINT `chk_wt_amount` CHECK (`amount` >= 0),
  CONSTRAINT `fk_wallet_trans_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='钱包流水表：纯日志，记录余额快照';

-- ----------------------------------------------------------------------------
-- 4. 短信验证码表 nft_verification_codes（纯日志表，无 updated_at）
-- 登录/注册/找回密码验证码：5 分钟有效、60 秒重发频控、一次性核销。
-- v2.2.1 修正：code 由 VARCHAR(6) 扩为 VARCHAR(128)，支持 bcrypt/scrypt 哈希存储
-- （6 位仅能存明文，与安全存储要求冲突）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_verification_codes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `phone`      VARCHAR(11)     NOT NULL                COMMENT '接收手机号',
  `scene`      ENUM('register','login','reset_password') NOT NULL COMMENT '使用场景：register注册/login登录/reset_password重置密码',
  `code`       VARCHAR(128)    NOT NULL                 COMMENT '验证码（哈希存储，bcrypt/scrypt 输出≥60字符）',
  `expires_at` DATETIME(3)     NOT NULL                 COMMENT '过期时间（发送时刻+5分钟）',
  `used_at`    DATETIME(3)     NULL DEFAULT NULL        COMMENT '核销时间（一次性，用过即失效）',
  `ip`         VARCHAR(45)     NULL DEFAULT NULL        COMMENT '请求IP（风控/频控，兼容IPv6）',
  `sent_at`    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '发送时间（60s内禁止重发）',
  `created_at` DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_phone_scene` (`phone`, `scene`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='短信验证码表：一次性核销，哈希存储';

-- ============================================================================
-- 二、藏品域（4 张）
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 5. 藏品分类表 nft_categories
-- 分类 code 英文标识（painting/blindbox…），前端 tab 筛选依据
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name`       VARCHAR(20) NOT NULL                COMMENT '分类名（如：水墨/国潮/盲盒/实物/联名）',
  `code`       VARCHAR(20) NOT NULL                COMMENT '分类编码（英文标识），唯一',
  `sort_order` INT         NOT NULL DEFAULT 0      COMMENT '排序（升序）',
  `icon`       VARCHAR(50) NULL DEFAULT NULL       COMMENT '分类图标',
  `deleted_at` DATETIME    NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='藏品分类表：市场tab筛选';

-- ----------------------------------------------------------------------------
-- 6. 藏品主表 nft_collectibles
-- 发售、盲盒、市场、合成产物统一主表；发售状态落库（决策1）；
-- 高并发库存用 locked_quantity 数据库原子操作（规范8）。
-- v2.2.1 新增 CHECK：防超卖 sold+locked_quantity≤edition、流通不超发行
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_collectibles` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `category_id`     INT UNSIGNED  NOT NULL                COMMENT '分类ID，FK→nft_categories.id',
  `name`            VARCHAR(100)  NOT NULL                COMMENT '藏品名称',
  `subtitle`        VARCHAR(100)  NULL DEFAULT NULL       COMMENT '副标题/系列名',
  `image`           VARCHAR(255)  NOT NULL                COMMENT '封面图URL',
  `gradient`        VARCHAR(100)  NULL DEFAULT NULL       COMMENT '卡片渐变兜底色（CSS渐变描述）',
  `icon`            VARCHAR(50)   NULL DEFAULT NULL       COMMENT '列表小图标',
  `price`           DECIMAL(10,2) NOT NULL                COMMENT '发售价（元）',
  `edition`         INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '发行总量（份）',
  `circulate`       INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '流通量（支付成功累加）',
  `sold`            INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '累计已售数量',
  `locked_quantity` INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '待支付锁定数量（下单+qty/支付成功-qty并转sold/取消-qty，数据库原子操作）',
  `vol`             INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '今日成交量（每日零点业务层重置）',
  `status`          ENUM('upcoming','onsale','soldout') NOT NULL DEFAULT 'upcoming' COMMENT '发售状态（决策1：与sold_out合并）：upcoming即将发售/onsale发售中/soldout已售罄',
  `issuer`          VARCHAR(50)   NULL DEFAULT NULL       COMMENT '发行方（运营后台创建藏品时必填）',
  `creator`         VARCHAR(50)   NULL DEFAULT NULL       COMMENT '创作方（运营后台创建藏品时必填）',
  `brand`           VARCHAR(50)   NULL DEFAULT NULL       COMMENT '品牌（运营后台创建藏品时必填）',
  `album`           VARCHAR(50)   NULL DEFAULT NULL       COMMENT '所属系列/专辑',
  `contract`        VARCHAR(100)  NULL DEFAULT NULL       COMMENT '链上合约地址',
  `chain_type`      VARCHAR(20)   NULL DEFAULT NULL       COMMENT '链类型：ethereum/polygon/联盟链',
  `token_standard`  VARCHAR(20)   NULL DEFAULT NULL       COMMENT '代币标准：ERC-721/ERC-1155',
  `cert_id`         VARCHAR(50)   NULL DEFAULT NULL       COMMENT '认证证书编号',
  `serial_no`       VARCHAR(50)   NULL DEFAULT NULL       COMMENT '编号规则模板（如 SN-{id}-{seq}）',
  `release_date`    DATETIME(3)   NULL DEFAULT NULL       COMMENT '发售日期（展示用）',
  `onsale_at`       DATETIME(3)   NULL DEFAULT NULL       COMMENT '开售时间',
  `off_sale_at`     DATETIME(3)   NULL DEFAULT NULL       COMMENT '发售结束时间',
  `tag`             VARCHAR(50)   NULL DEFAULT NULL       COMMENT '发售方式标签：首发/优先购/资格购/盲盒',
  `is_release`      TINYINT(1)    NOT NULL DEFAULT 0      COMMENT '是否首页发售位：1是 0否',
  `featured`        TINYINT(1)    NOT NULL DEFAULT 0      COMMENT '是否推荐位：1是 0否',
  `market_tag`      VARCHAR(50)   NULL DEFAULT NULL       COMMENT '市场标签（如：寄售）',
  `description`     TEXT          NULL                    COMMENT '藏品故事/购买须知（合并存储）',
  `deleted_at`      DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at`      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_release` (`status`, `is_release`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `chk_collectibles_price` CHECK (`price` >= 0),
  CONSTRAINT `chk_collectibles_stock` CHECK (`sold` + `locked_quantity` <= `edition` AND `circulate` <= `edition`),
  CONSTRAINT `fk_collectibles_category` FOREIGN KEY (`category_id`) REFERENCES `nft_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='藏品主表：发售/盲盒/市场/合成产物统一';

-- ----------------------------------------------------------------------------
-- 7. 盲盒表 nft_blind_boxes
-- 盲盒配置（藏品 1:1 扩展表）。开启即消耗原资产（决策16）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_blind_boxes` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id` INT UNSIGNED NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id，唯一（1:1）',
  `description`    VARCHAR(500) NULL DEFAULT NULL       COMMENT '盲盒说明文案',
  `is_openable`    TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '是否可开启：1可开启 0已关闭（下架开启入口）',
  `created_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collectible` (`collectible_id`),
  CONSTRAINT `fk_blind_boxes_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='盲盒表：与藏品1:1扩展';

-- ----------------------------------------------------------------------------
-- 8. 盲盒奖品池配置表 nft_blind_box_items
-- 盲盒奖池：概率 DECIMAL(5,4)（同一盲盒合计=1，跨行校验由业务层保证），
-- 限量与已发放计数控制稀有度。逻辑删除禁止物理删除（规范6）。
-- v2.2.1 新增 CHECK：概率值域 0~1
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_blind_box_items` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `blind_box_id`         INT UNSIGNED  NOT NULL                COMMENT '盲盒ID，FK→nft_blind_boxes.id',
  `prize_collectible_id` INT UNSIGNED  NOT NULL                COMMENT '奖品藏品ID，FK→nft_collectibles.id',
  `probability`          DECIMAL(5,4)  NOT NULL DEFAULT 0.0000  COMMENT '出货概率（同一盲盒合计=1）',
  `quantity_limit`       INT UNSIGNED  NULL DEFAULT NULL       COMMENT '限量份数（NULL不限量）',
  `quantity_distributed` INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '已发放数量',
  `deleted_at`           DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间（禁止物理删除，保证历史开盒可审计）',
  `created_at`           DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`           DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_blind_box` (`blind_box_id`),
  CONSTRAINT `chk_bbi_probability` CHECK (`probability` >= 0 AND `probability` <= 1),
  CONSTRAINT `fk_bb_items_blind_box` FOREIGN KEY (`blind_box_id`) REFERENCES `nft_blind_boxes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bb_items_prize` FOREIGN KEY (`prize_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='盲盒奖品池配置表：概率与限量';

-- ============================================================================
-- 三、用户域·关系表（1 张，依赖藏品表，故排于此）
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 9. 用户关注表 nft_user_favorites（纯关系表，无 updated_at）
-- 用户对藏品的关注（收藏）关系，用于市场「关注」视图
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_user_favorites` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`        BIGINT UNSIGNED NOT NULL                COMMENT '用户ID，FK→nft_users.id',
  `collectible_id` INT UNSIGNED    NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id',
  `created_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '关注时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_collectible` (`user_id`, `collectible_id`),
  CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_favorites_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户关注表：市场「关注」视图';

-- ============================================================================
-- 四、资产与交易域（5 张）
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 10. 订单表 nft_orders
-- 发售购买与市场挂单购买统一订单（决策9：不加 order_items）。
-- 待支付 5 分钟超时取消（nft_system_configs.order_pay_timeout_seconds）。
-- 注意：resale_listing_id 外键因循环依赖在脚本末尾 ALTER 补挂。
-- v2.2.1 新增 CHECK：数量≥1、价格非负
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_orders` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `order_no`          VARCHAR(20)     NOT NULL                COMMENT '订单号，唯一（如 JC+日期+随机）',
  `user_id`           BIGINT UNSIGNED NOT NULL                COMMENT '买家用户ID，FK→nft_users.id',
  `collectible_id`    INT UNSIGNED    NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id',
  `resale_listing_id` BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '市场挂单ID，FK→nft_resale_listings.id（市场单关联；循环外键，脚本末尾ALTER补挂）',
  `unit_price`        DECIMAL(10,2)   NOT NULL                COMMENT '单价（元）',
  `quantity`          INT UNSIGNED    NOT NULL DEFAULT 1      COMMENT '数量',
  `total_price`       DECIMAL(10,2)   NOT NULL                COMMENT '总金额（元）',
  `status`            ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending' COMMENT '订单状态：pending待支付/completed已完成/cancelled已取消（超时/手动）',
  `source`            ENUM('release','market') NOT NULL       COMMENT '订单来源：release发售购买/market市场挂单购买',
  `created_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '下单时间',
  `paid_at`           DATETIME(3)     NULL DEFAULT NULL       COMMENT '支付时间',
  `completed_at`      DATETIME(3)     NULL DEFAULT NULL       COMMENT '完成时间',
  `cancelled_at`      DATETIME(3)     NULL DEFAULT NULL       COMMENT '取消时间',
  `cancel_reason`     VARCHAR(100)    NULL DEFAULT NULL       COMMENT '取消原因（超时/手动）',
  `expires_at`        DATETIME(3)     NOT NULL                COMMENT '待支付截止时间（下单+超时秒数，超时自动取消并释放库存）',
  `updated_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_resale_listing` (`resale_listing_id`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `chk_orders_amount` CHECK (`quantity` >= 1 AND `unit_price` >= 0 AND `total_price` >= 0),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单表：发售与市场购买统一';

-- ----------------------------------------------------------------------------
-- 11. 用户藏品表 nft_user_collectibles
-- 每份藏品一行（决策3，数字藏品非同质化）。编号在藏品维度唯一；
-- 来源枚举（决策2）；is_consigned 反规范化（决策7）；
-- 盲盒开启/合成消耗置 consumed（决策16）。
-- 注意：airdrop_record_id 外键因循环依赖在脚本末尾 ALTER 补挂
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_user_collectibles` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`           BIGINT UNSIGNED NOT NULL                COMMENT '持有用户ID，FK→nft_users.id',
  `collectible_id`    INT UNSIGNED    NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id',
  `order_id`          BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '来源订单ID，FK→nft_orders.id（purchase来源）',
  `blind_box_item_id` INT UNSIGNED    NULL DEFAULT NULL       COMMENT '盲盒奖品配置ID，FK→nft_blind_box_items.id（blindbox来源）',
  `airdrop_record_id` BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '空投记录ID，FK→nft_airdrop_records.id（airdrop来源溯源，决策13；循环外键，脚本末尾ALTER补挂）',
  `serial`            VARCHAR(20)     NOT NULL                COMMENT '藏品编号（如 SN-1-0001，藏品维度唯一）',
  `source`            ENUM('purchase','blindbox','transfer','airdrop','synthesis','lucky_draw') NOT NULL COMMENT '来源（决策2，替代is_lucky）：purchase购买/blindbox开盲盒/transfer受赠/airdrop空投/synthesis合成/lucky_draw抽奖',
  `acquired_price`    DECIMAL(10,2)   NOT NULL DEFAULT 0.00   COMMENT '入手价（决策8：非购买来源存0）',
  `acquired_at`       DATETIME(3)     NOT NULL                COMMENT '入库时间',
  `is_consigned`      TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '寄售反规范化标记（决策7）：1寄售中 0否',
  `status`            ENUM('held','consigned','frozen','transferred','consumed') NOT NULL DEFAULT 'held' COMMENT '资产状态：held持有/consigned寄售中/frozen冻结（转赠中）/transferred已转出/consumed已消耗（开盒/合成）',
  `tx_hash`           VARCHAR(100)    NULL DEFAULT NULL       COMMENT '链上交易哈希',
  `block_number`      BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '区块高度',
  `token_id`          VARCHAR(100)    NULL DEFAULT NULL       COMMENT '链上token ID',
  `mint_status`       ENUM('pending','minting','minted','failed') NULL DEFAULT 'pending' COMMENT '铸造状态：pending待铸造/minting铸造中/minted已上链/failed失败',
  `created_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collectible_serial` (`collectible_id`, `serial`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_user_collectible` (`user_id`, `collectible_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_airdrop_record` (`airdrop_record_id`),
  CONSTRAINT `chk_uc_price` CHECK (`acquired_price` >= 0),
  CONSTRAINT `fk_uc_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_uc_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_uc_order` FOREIGN KEY (`order_id`) REFERENCES `nft_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_uc_blind_box_item` FOREIGN KEY (`blind_box_item_id`) REFERENCES `nft_blind_box_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户藏品表：每份一行';

-- ----------------------------------------------------------------------------
-- 12. 支付记录表 nft_payments
-- 与订单 1:1。balance 支付依赖钱包表扣款（决策14）；需校验交易密码后创建
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_payments` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `order_id`       BIGINT UNSIGNED NOT NULL                COMMENT '订单ID，FK→nft_orders.id，唯一（1:1）',
  `user_id`        BIGINT UNSIGNED NOT NULL                COMMENT '支付用户ID，FK→nft_users.id',
  `amount`         DECIMAL(10,2)   NOT NULL                COMMENT '实付金额（元）',
  `payment_method` ENUM('balance','alipay','wechat') NOT NULL COMMENT '支付方式：balance余额（依赖钱包）/alipay支付宝/wechat微信',
  `transaction_no` VARCHAR(64)     NULL DEFAULT NULL       COMMENT '第三方支付流水号',
  `status`         ENUM('pending','success','failed','refunded') NOT NULL DEFAULT 'pending' COMMENT '支付状态：pending待支付/success成功/failed失败/refunded已退款',
  `paid_at`        DATETIME(3)     NULL DEFAULT NULL       COMMENT '支付成功时间',
  `created_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`order_id`),
  KEY `idx_user_status` (`user_id`, `status`),
  CONSTRAINT `chk_payments_amount` CHECK (`amount` >= 0),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `nft_orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付记录表：与订单1:1';

-- ----------------------------------------------------------------------------
-- 13. 寄售挂单表 nft_resale_listings
-- 市场寄售挂单。挂单不复制藏品快照，通过 collectible_id JOIN 取实时数据（决策4）；
-- 寄售锁定资产不扣藏品库存；取消后冷却（决策15）；
-- 地板价 = MIN(price) WHERE status='selling'。
-- v2.2.1 修正：生成列 selling_ucid + 唯一索引，将设计文档「在售期间唯一」
-- （同一资产同时只能有一条在售挂单）落库；sold/cancelled 状态生成 NULL，
-- 唯一索引允许多个 NULL，不阻断历史挂单记录
-- v2.2.2 修正：fk_resale_user_collectible ON UPDATE 改 RESTRICT（见文件头说明7）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_resale_listings` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `seller_id`           BIGINT UNSIGNED NOT NULL                COMMENT '卖家用户ID，FK→nft_users.id',
  `collectible_id`      INT UNSIGNED    NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id（不存快照，决策4）',
  `user_collectible_id` BIGINT UNSIGNED NOT NULL                COMMENT '挂单资产ID，FK→nft_user_collectibles.id（在售期间唯一，见 selling_ucid）',
  `price`               DECIMAL(10,2)   NOT NULL                COMMENT '寄售价（元）',
  `fee_rate`            DECIMAL(5,2)    NULL DEFAULT NULL       COMMENT '手续费率（%，默认取系统配置 resale_fee_rate）',
  `fee_amount`          DECIMAL(10,2)   NOT NULL DEFAULT 0.00   COMMENT '手续费（元，=price×fee_rate/100）',
  `actual_amount`       DECIMAL(10,2)   NOT NULL DEFAULT 0.00   COMMENT '预计到账（元，=price−fee_amount）',
  `status`              ENUM('selling','sold','cancelled') NOT NULL DEFAULT 'selling' COMMENT '挂单状态：selling在售/sold已售/cancelled已取消',
  `listed_at`           DATETIME(3)     NOT NULL                COMMENT '挂单时间',
  `cooldown_until`      DATETIME(3)     NULL DEFAULT NULL       COMMENT '取消后冷却截止（=取消时刻+resale_cooldown_seconds秒，冷却期内禁止重新挂单）',
  `selling_ucid`        BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status` = 'selling' THEN `user_collectible_id` ELSE NULL END) STORED COMMENT '在售唯一辅助列（生成列）：selling 时取资产ID，否则 NULL',
  `created_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_selling_ucid` (`selling_ucid`),
  KEY `idx_collectible_status` (`collectible_id`, `status`),
  KEY `idx_seller_status` (`seller_id`, `status`),
  CONSTRAINT `chk_resale_amount` CHECK (`price` >= 0 AND `fee_amount` >= 0 AND `actual_amount` >= 0),
  CONSTRAINT `fk_resale_seller` FOREIGN KEY (`seller_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_resale_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_resale_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='寄售挂单表：市场寄售，同一资产在售唯一';

-- ----------------------------------------------------------------------------
-- 14. 转赠记录表 nft_transfers
-- 藏品转赠，含 to_user_id 支持双向查询（决策11）。
-- 状态机：发起→资产 frozen → accepted/rejected/cancelled。
-- v2.2.1 修正：生成列 pending_ucid + 唯一索引，将状态机约束
-- 「同一资产仅一笔待确认转赠」落库；accepted/rejected/cancelled 生成 NULL 不冲突
-- v2.2.2 修正：fk_transfer_user_collectible ON UPDATE 改 RESTRICT（见文件头说明7）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_transfers` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `from_user_id`        BIGINT UNSIGNED NOT NULL                COMMENT '转出方用户ID，FK→nft_users.id',
  `to_user_id`          BIGINT UNSIGNED NOT NULL                COMMENT '受赠方用户ID，FK→nft_users.id（决策11）',
  `to_phone`            VARCHAR(11)     NOT NULL                COMMENT '受赠方手机号',
  `to_nickname`         VARCHAR(50)     NULL DEFAULT NULL       COMMENT '受赠人昵称快照',
  `collectible_id`      INT UNSIGNED    NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id',
  `user_collectible_id` BIGINT UNSIGNED NOT NULL                COMMENT '转赠资产ID，FK→nft_user_collectibles.id',
  `status`              ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending' COMMENT '转赠状态：pending待确认（资产frozen）/accepted已接受/rejected已拒绝/cancelled已取消',
  `pending_ucid`        BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `status` = 'pending' THEN `user_collectible_id` ELSE NULL END) STORED COMMENT '待确认唯一辅助列（生成列）：pending 时取资产ID，否则 NULL',
  `created_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '发起时间',
  `confirmed_at`        DATETIME(3)     NULL DEFAULT NULL       COMMENT '对方确认时间',
  `updated_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pending_ucid` (`pending_ucid`),
  KEY `idx_from_user` (`from_user_id`),
  KEY `idx_to_user` (`to_user_id`),
  KEY `idx_status_created` (`status`, `created_at`),
  CONSTRAINT `fk_transfer_from_user` FOREIGN KEY (`from_user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transfer_to_user` FOREIGN KEY (`to_user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transfer_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transfer_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='转赠记录表：状态机冻结-确认，同一资产仅一笔待确认';

-- ============================================================================
-- 五、运营活动域（8 张）
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 15. 合成活动表 nft_synthesis_activities
-- 合成活动：限时/永久两类，产出 result_collectible_id；
-- 防死循环约束：result_collectible_id 不得出现在同活动材料中（业务层校验）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_synthesis_activities` (
  `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `type`                  ENUM('limit','permanent') NOT NULL    COMMENT '活动类型：limit限时/permanent永久',
  `title`                 VARCHAR(100) NOT NULL                COMMENT '活动名称',
  `start_time`            DATETIME(3)   NULL DEFAULT NULL       COMMENT '开始时间（限时活动必填）',
  `end_time`              DATETIME(3)   NULL DEFAULT NULL       COMMENT '结束时间（限时活动必填）',
  `rules`                 TEXT          NOT NULL                COMMENT '活动规则文案',
  `result_collectible_id` INT UNSIGNED  NOT NULL                COMMENT '产物藏品ID，FK→nft_collectibles.id',
  `per_user_limit`        INT           NOT NULL DEFAULT 0      COMMENT '每人限合成次数（0不限）',
  `total_limit`           INT UNSIGNED  NULL DEFAULT NULL       COMMENT '总份数限制（NULL不限）',
  `used_count`            INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '已合成次数',
  `image`                 VARCHAR(255) NULL DEFAULT NULL       COMMENT '活动封面图URL',
  `created_at`            DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`            DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_time` (`start_time`, `end_time`),
  CONSTRAINT `fk_syn_act_result` FOREIGN KEY (`result_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合成活动表：限时/永久';

-- ----------------------------------------------------------------------------
-- 16. 合成材料子表 nft_synthesis_materials（配置子表，无 updated_at）
-- 合成所需材料（活动与藏品 M:N 中间表），count 为需求量
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_synthesis_materials` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id`    INT UNSIGNED    NOT NULL                COMMENT '合成活动ID，FK→nft_synthesis_activities.id',
  `collectible_id` INT UNSIGNED    NOT NULL                COMMENT '材料藏品ID，FK→nft_collectibles.id',
  `count`          INT UNSIGNED    NOT NULL DEFAULT 1      COMMENT '需要数量',
  `created_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_activity_id` (`activity_id`),
  CONSTRAINT `chk_syn_mat_count` CHECK (`count` >= 1),
  CONSTRAINT `fk_syn_mat_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_synthesis_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_syn_mat_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合成材料子表：材料M:N';

-- ----------------------------------------------------------------------------
-- 17. 合成记录表 nft_synthesis_records
-- 一次合成一条记录，产物挂在用户藏品表（source=synthesis）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_synthesis_records` (
  `id`                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`                    BIGINT UNSIGNED NOT NULL                COMMENT '合成用户ID，FK→nft_users.id',
  `activity_id`                INT UNSIGNED    NOT NULL                COMMENT '合成活动ID，FK→nft_synthesis_activities.id',
  `result_user_collectible_id` BIGINT UNSIGNED NOT NULL                COMMENT '产物资产ID，FK→nft_user_collectibles.id（source=synthesis）',
  `created_at`                 DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '合成时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_activity` (`user_id`, `activity_id`),
  CONSTRAINT `fk_syn_rec_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_syn_rec_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_synthesis_activities` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_syn_rec_result` FOREIGN KEY (`result_user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合成记录表：一次合成一条';

-- ----------------------------------------------------------------------------
-- 18. 合成消耗明细表 nft_synthesis_record_items（纯明细表，无 updated_at）
-- 合成消耗的具体资产实例明细（每行一份，与决策3一致），保证消耗可审计、可回溯
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_synthesis_record_items` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `synthesis_record_id` BIGINT UNSIGNED NOT NULL                COMMENT '合成记录ID，FK→nft_synthesis_records.id',
  `user_collectible_id` BIGINT UNSIGNED NOT NULL                COMMENT '被消耗资产ID，FK→nft_user_collectibles.id（该资产置 consumed）',
  `created_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_record` (`synthesis_record_id`),
  KEY `idx_user_collectible` (`user_collectible_id`),
  CONSTRAINT `fk_sri_record` FOREIGN KEY (`synthesis_record_id`) REFERENCES `nft_synthesis_records` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sri_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合成消耗明细表：消耗可审计';

-- ----------------------------------------------------------------------------
-- 19. 抽奖奖品池表 nft_lucky_draw_prizes
-- 抽奖奖品池（决策10 独立表）。prize_type/coin_amount 支持「司南币」「谢谢参与」，
-- collectible_id 放宽为可空（非藏品奖）。逻辑删除禁止物理删除（规范6）。
-- v2.2.1 新增 CHECK：won≤total、数量与金额非负
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_lucky_draw_prizes` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id`    INT UNSIGNED  NOT NULL                COMMENT '抽奖活动/期数ID（运营配置标识，暂无独立活动表，仅建索引）',
  `tier_name`      VARCHAR(20)   NOT NULL                COMMENT '奖档名（如：普通/稀有/史诗/传说）',
  `prize_type`     ENUM('collectible','points','none') NOT NULL DEFAULT 'collectible' COMMENT '奖品类型：collectible藏品/points司南币/none谢谢参与',
  `collectible_id` INT UNSIGNED  NULL DEFAULT NULL       COMMENT '藏品奖ID，FK→nft_collectibles.id（prize_type=collectible 时必填）',
  `coin_amount`    DECIMAL(12,2) NULL DEFAULT NULL       COMMENT '司南币奖金额（prize_type=points 时必填）',
  `total`          INT UNSIGNED  NOT NULL                COMMENT '奖品总量',
  `won`            INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '已被抽中数量',
  `sort_order`     INT           NOT NULL DEFAULT 0      COMMENT '转盘展示顺序',
  `probability`    DECIMAL(5,4)  NOT NULL DEFAULT 0.0000 COMMENT '中奖概率（同一活动合计=1，业务层兜底归一化；v2.2.3 新增）',
  `deleted_at`     DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间（禁止物理删除，保证历史抽奖可审计）',
  `created_at`     DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_activity` (`activity_id`),
  CONSTRAINT `chk_prizes_domain` CHECK (`total` >= 0 AND `won` >= 0 AND `won` <= `total` AND (`coin_amount` IS NULL OR `coin_amount` >= 0) AND `probability` >= 0 AND `probability` <= 1),
  CONSTRAINT `fk_prizes_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖奖品池表：概率与限量';

-- ----------------------------------------------------------------------------
-- 20. 抽奖记录表 nft_lucky_draw_records（纯流水表，无 updated_at）
-- 抽奖流水。user_collectible_id 可空（谢谢参与/司南币奖励无资产）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_lucky_draw_records` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`             BIGINT UNSIGNED NOT NULL                COMMENT '抽奖用户ID，FK→nft_users.id',
  `prize_id`            INT UNSIGNED    NOT NULL                COMMENT '奖品ID，FK→nft_lucky_draw_prizes.id',
  `user_collectible_id` BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '获得资产ID，FK→nft_user_collectibles.id（藏品奖回填，points/none 为空）',
  `created_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '抽奖时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_ld_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ld_prize` FOREIGN KEY (`prize_id`) REFERENCES `nft_lucky_draw_prizes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ld_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖记录表：抽奖流水';

-- ----------------------------------------------------------------------------
-- 21. 签到记录表 nft_check_in_records（纯流水表，无 updated_at）
-- 每天一次（uk_user_date）。连续签到天数随行记录；
-- 奖励配置存 nft_system_configs.checkin_rewards
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_check_in_records` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`            BIGINT UNSIGNED NOT NULL                COMMENT '签到用户ID，FK→nft_users.id',
  `check_in_date`      DATE            NOT NULL                COMMENT '签到日期（每人每天一次）',
  `consecutive_days`   INT UNSIGNED    NOT NULL DEFAULT 1      COMMENT '签到时连续签到天数',
  `reward_type`        ENUM('none','collectible','points','draw_chance') NOT NULL DEFAULT 'none' COMMENT '奖励类型：none无/collectible藏品/points司南币/draw_chance抽奖机会',
  `reward_amount`      INT             NOT NULL DEFAULT 0      COMMENT '奖励数量',
  `reward_related_id`  BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '关联业务ID（藏品ID等，泛关联不设外键；BIGINT 以容纳各表id）',
  `reward_description` VARCHAR(255)    NULL DEFAULT NULL       COMMENT '奖励描述',
  `created_at`         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '签到时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date` (`user_id`, `check_in_date`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  CONSTRAINT `chk_checkin_domain` CHECK (`consecutive_days` >= 1 AND `reward_amount` >= 0),
  CONSTRAINT `fk_checkin_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='签到记录表：每天一次';

-- ----------------------------------------------------------------------------
-- 22. 邀请活动配置表 nft_invite_activities
-- 邀请奖励活动配置：开关/时间窗/双方奖励藏品与数量，
-- 奖励经 nft_airdrop_records 落账（source=airdrop）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_invite_activities` (
  `id`                     INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name`                   VARCHAR(100)  NOT NULL                COMMENT '活动名称',
  `status`                 ENUM('disabled','enabled') NOT NULL DEFAULT 'disabled' COMMENT '总开关：disabled关闭/enabled开启（关闭时禁止产生新邀请关系与奖励）',
  `start_time`             DATETIME(3)   NULL DEFAULT NULL       COMMENT '开始时间',
  `end_time`               DATETIME(3)   NULL DEFAULT NULL       COMMENT '结束时间',
  `inviter_collectible_id` INT UNSIGNED  NULL DEFAULT NULL       COMMENT '邀请人奖励藏品ID，FK→nft_collectibles.id',
  `inviter_quantity`       INT UNSIGNED  NOT NULL DEFAULT 1      COMMENT '邀请人奖励数量',
  `invitee_collectible_id` INT UNSIGNED  NULL DEFAULT NULL       COMMENT '被邀请人奖励藏品ID，FK→nft_collectibles.id',
  `invitee_quantity`       INT UNSIGNED  NOT NULL DEFAULT 1      COMMENT '被邀请人奖励数量',
  `airdrop_mode`           ENUM('realtime','batch') NOT NULL DEFAULT 'realtime' COMMENT '奖励发放模式：realtime实时/batch批量',
  `total_limit`            INT UNSIGNED  NULL DEFAULT NULL       COMMENT '总邀请奖励上限（NULL不限）',
  `used_count`             INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '已发放奖励数量',
  `description`            TEXT          NULL                    COMMENT '活动说明文案',
  `deleted_at`             DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at`             DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`             DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `chk_inv_act_quantity` CHECK (`inviter_quantity` >= 1 AND `invitee_quantity` >= 1),
  CONSTRAINT `fk_inv_act_inviter_collectible` FOREIGN KEY (`inviter_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_act_invitee_collectible` FOREIGN KEY (`invitee_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邀请活动配置表：双方奖励';

-- ============================================================================
-- 六、空投域（4 张）
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 23. 空投活动表 nft_airdrop_activities
-- 六种空投类型，双发放模式（realtime 直发 / batch 记资格后台统一发）。
-- 状态机：draft→active→paused/ended，仅 active 产生新资格/发放。
-- 逻辑删除禁止物理删除（规范6）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_airdrop_activities` (
  `id`                      INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name`                    VARCHAR(100)  NOT NULL                COMMENT '活动名称',
  `type`                    ENUM('direct','hold','checkin','register','login','invite') NOT NULL COMMENT '空投类型：direct直投/hold持有快照/checkin连续签到/register注册/login登录/invite邀请',
  `status`                  ENUM('draft','active','paused','ended') NOT NULL DEFAULT 'draft' COMMENT '活动状态：draft草稿/active进行中/paused暂停/ended结束',
  `airdrop_mode`            ENUM('realtime','batch') NOT NULL DEFAULT 'realtime' COMMENT '发放模式：realtime实时发放/batch记资格后台统一发',
  `collectible_id`          INT UNSIGNED  NOT NULL                COMMENT '空投目标藏品ID，FK→nft_collectibles.id',
  `quantity_per_user`       INT UNSIGNED  NOT NULL DEFAULT 1       COMMENT '每人发放数量',
  `total_limit`             INT UNSIGNED  NULL DEFAULT NULL       COMMENT '总限量（NULL不限）',
  `issued_count`            INT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '已发放数量',
  `start_time`              DATETIME(3)   NULL DEFAULT NULL       COMMENT '开始时间（注册/登录空投须落在窗口内）',
  `end_time`                DATETIME(3)   NULL DEFAULT NULL       COMMENT '结束时间',
  `snapshot_at`             DATETIME(3)   NULL DEFAULT NULL       COMMENT '持有快照时间（仅 type=hold）',
  `snapshot_collectible_id` INT UNSIGNED  NULL DEFAULT NULL       COMMENT '持有快照目标藏品ID，FK→nft_collectibles.id（仅 type=hold）',
  `checkin_days`            INT UNSIGNED  NULL DEFAULT NULL       COMMENT '要求连续签到天数（仅 type=checkin，如 1/3/7）',
  `condition_config`        JSON          NULL DEFAULT NULL       COMMENT '扩展条件配置（JSON）',
  `description`             TEXT          NULL                    COMMENT '活动说明文案',
  `deleted_at`              DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间（禁止物理删除，保证发放历史可审计）',
  `created_at`              DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`              DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`, `status`),
  KEY `idx_start_end` (`start_time`, `end_time`),
  CONSTRAINT `chk_air_act_quantity` CHECK (`quantity_per_user` >= 1 AND `issued_count` >= 0),
  CONSTRAINT `fk_air_act_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_air_act_snapshot_collectible` FOREIGN KEY (`snapshot_collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='空投活动表：六类型双模式';

-- ----------------------------------------------------------------------------
-- 24. 持有藏品快照表 nft_airdrop_snapshots（纯明细表，无 updated_at）
-- hold 空投先快照后发放：扫描持有目标藏品的所有用户资产写入本表，
-- 仅 batch 模式必须先落快照再发放
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_airdrop_snapshots` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id`         INT UNSIGNED    NOT NULL                COMMENT '空投活动ID，FK→nft_airdrop_activities.id',
  `user_id`             BIGINT UNSIGNED NOT NULL                COMMENT '持有人ID，FK→nft_users.id',
  `collectible_id`      INT UNSIGNED    NOT NULL                COMMENT '快照时持有的藏品ID',
  `user_collectible_id` BIGINT UNSIGNED NOT NULL                COMMENT '具体资产实例ID，FK→nft_user_collectibles.id',
  `snapshot_at`         DATETIME(3)     NOT NULL                COMMENT '快照时间',
  `created_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activity_user_collectible` (`activity_id`, `user_id`, `user_collectible_id`),
  KEY `idx_activity` (`activity_id`),
  CONSTRAINT `fk_as_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_airdrop_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_as_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_as_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='持有藏品快照表：hold空投';

-- ----------------------------------------------------------------------------
-- 25. 空投记录表 nft_airdrop_records
-- 空投发放流水：发放后回填 user_collectible_id；
-- nft_user_collectibles.airdrop_record_id 指向本表溯源（决策13）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_airdrop_records` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id`         INT UNSIGNED    NOT NULL                COMMENT '空投活动ID，FK→nft_airdrop_activities.id',
  `user_id`             BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '接收用户ID，FK→nft_users.id（直投可先手机号后注册）',
  `phone`               VARCHAR(11)     NOT NULL                COMMENT '接收手机号',
  `collectible_id`      INT UNSIGNED    NOT NULL                COMMENT '空投藏品ID，FK→nft_collectibles.id',
  `user_collectible_id` BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '发放后回填的资产ID，FK→nft_user_collectibles.id（source=airdrop）',
  `quantity`            INT UNSIGNED    NOT NULL DEFAULT 1      COMMENT '发放数量',
  `status`              ENUM('pending','issued','failed') NOT NULL DEFAULT 'pending' COMMENT '发放状态：pending待发放/issued已发放/failed失败',
  `issued_at`           DATETIME(3)     NULL DEFAULT NULL       COMMENT '实际发放时间',
  `created_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_activity_phone` (`activity_id`, `phone`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `chk_ar_quantity` CHECK (`quantity` >= 1),
  CONSTRAINT `fk_ar_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_airdrop_activities` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ar_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ar_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ar_user_collectible` FOREIGN KEY (`user_collectible_id`) REFERENCES `nft_user_collectibles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='空投记录表：发放流水';

-- ----------------------------------------------------------------------------
-- 26. 空投资格记录表 nft_airdrop_eligibilities
-- 批量空投模式：完成任务只记资格（uk_activity_phone 去重），
-- 后台统一执行后生成空投记录与资产
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_airdrop_eligibilities` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id`       INT UNSIGNED    NOT NULL                COMMENT '空投活动ID，FK→nft_airdrop_activities.id',
  `user_id`           BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '完成任务用户ID，FK→nft_users.id',
  `phone`             VARCHAR(11)     NOT NULL                COMMENT '完成任务时手机号',
  `task_type`         ENUM('hold','checkin','register','login','invite') NOT NULL COMMENT '完成的任务类型：hold持有/checkin签到/register注册/login登录/invite邀请',
  `task_completed_at` DATETIME(3)     NOT NULL                COMMENT '任务完成时间',
  `status`            ENUM('eligible','issued') NOT NULL DEFAULT 'eligible' COMMENT '资格状态：eligible待发放/issued已发放',
  `airdrop_record_id` BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '关联空投记录ID，FK→nft_airdrop_records.id',
  `created_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activity_phone` (`activity_id`, `phone`),
  KEY `idx_activity_status` (`activity_id`, `status`),
  CONSTRAINT `fk_ae_activity` FOREIGN KEY (`activity_id`) REFERENCES `nft_airdrop_activities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ae_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ae_airdrop_record` FOREIGN KEY (`airdrop_record_id`) REFERENCES `nft_airdrop_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='空投资格记录表：batch模式';

-- ============================================================================
-- 七、用户域·邀请关系表（1 张，依赖空投记录表，故排于此）
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 27. 邀请关系记录表 nft_invite_records
-- 用户A邀请用户B的绑定关系；注册携带 ?code= 时写入；奖励经空投落账。
-- 一人仅能被邀请一次（uk_invitee）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_invite_records` (
  `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `inviter_id`                BIGINT UNSIGNED NOT NULL                COMMENT '邀请人用户ID，FK→nft_users.id',
  `invitee_id`                BIGINT UNSIGNED NOT NULL                COMMENT '被邀请人用户ID，FK→nft_users.id，唯一（一人仅能被邀请一次）',
  `invite_code`               VARCHAR(16)     NOT NULL                COMMENT '注册时使用的邀请码',
  `status`                    ENUM('pending','registered') NOT NULL DEFAULT 'pending' COMMENT '绑定状态：pending已邀请未注册/registered已注册',
  `inviter_airdrop_record_id` BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '邀请人奖励空投记录ID，FK→nft_airdrop_records.id',
  `invitee_airdrop_record_id` BIGINT UNSIGNED NULL DEFAULT NULL       COMMENT '被邀请人奖励空投记录ID，FK→nft_airdrop_records.id',
  `created_at`                DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`                DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invitee` (`invitee_id`),
  KEY `idx_inviter` (`inviter_id`),
  CONSTRAINT `fk_invite_inviter` FOREIGN KEY (`inviter_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_invitee` FOREIGN KEY (`invitee_id`) REFERENCES `nft_users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_inviter_airdrop` FOREIGN KEY (`inviter_airdrop_record_id`) REFERENCES `nft_airdrop_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_invite_invitee_airdrop` FOREIGN KEY (`invitee_airdrop_record_id`) REFERENCES `nft_airdrop_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邀请关系记录表：一人仅能被邀请一次';

-- ============================================================================
-- 八、内容域（4 张）
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 28. 文物表 nft_artifacts
-- 文物展览区（列表 + 详情）。分类筛选经 tags JSON；
-- specs 存规格档案键值对
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_artifacts` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name`       VARCHAR(100)  NOT NULL                COMMENT '文物名称',
  `dynasty`    VARCHAR(50)   NOT NULL                COMMENT '朝代（如：西周）',
  `image`      VARCHAR(255)  NOT NULL                COMMENT '文物图URL',
  `img_height` INT           NOT NULL DEFAULT 150    COMMENT '瀑布流图片高度（px）',
  `material`   VARCHAR(50)   NOT NULL                COMMENT '材质（如：青铜）',
  `period`     VARCHAR(100)  NOT NULL                COMMENT '年代（如：约公元前1046年－前771年）',
  `size`       VARCHAR(100)  NULL DEFAULT NULL       COMMENT '尺寸概述',
  `origin`     VARCHAR(100)  NULL DEFAULT NULL       COMMENT '出土/来源地',
  `museum`     VARCHAR(100)  NULL DEFAULT NULL       COMMENT '馆藏地点',
  `level`      VARCHAR(20)   NULL DEFAULT NULL       COMMENT '文物等级（如：国家一级文物）',
  `specs`      JSON          NULL DEFAULT NULL       COMMENT '规格档案键值对（如：{"器型":"鼎","通高":"53cm"}）',
  `story`      TEXT          NOT NULL                COMMENT '详细介绍（分段文本）',
  `tags`       JSON          NULL DEFAULT NULL       COMMENT '标签数组（如：["青铜","礼器"]，分类筛选依据）',
  `deleted_at` DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at` DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_dynasty` (`dynasty`),
  KEY `idx_deleted` (`deleted_at`),
  CONSTRAINT `chk_artifacts_height` CHECK (`img_height` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文物表：展览区主数据';

-- ----------------------------------------------------------------------------
-- 29. 公告/新闻合并表 nft_announcements
-- 公告与新闻合并（决策5），type 区分；subtype 承接原分类：
-- activity/compose/operation；富文本内容存 content（含图片以 HTML 引用）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_announcements` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `title`       VARCHAR(200)  NOT NULL                COMMENT '标题',
  `summary`     VARCHAR(500)  NULL DEFAULT NULL       COMMENT '摘要（首页轮播同源）',
  `content`     TEXT          NULL                    COMMENT '正文/富文本HTML（含图片）',
  `cover_image` VARCHAR(255)  NULL DEFAULT NULL       COMMENT '封面图URL',
  `type`        ENUM('notice','news') NOT NULL        COMMENT '类型（决策5）：notice公告/news新闻',
  `subtype`     VARCHAR(20)   NULL DEFAULT NULL       COMMENT '子分类：activity活动/compose合成/operation运营',
  `tag_color`   VARCHAR(20)   NULL DEFAULT NULL       COMMENT '标签色（前端展示）',
  `is_top`      TINYINT(1)    NOT NULL DEFAULT 0      COMMENT '是否置顶：1是 0否',
  `deleted_at`  DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '发布时间',
  `updated_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type_created` (`type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告/新闻合并表';

-- ----------------------------------------------------------------------------
-- 30. 轮播图表 nft_banners
-- 首页背景轮播，支持排序与上下架
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_banners` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `image`       VARCHAR(255)  NOT NULL                COMMENT '轮播图URL',
  `description` VARCHAR(100)  NULL DEFAULT NULL       COMMENT '描述/alt',
  `sort_order`  INT           NOT NULL DEFAULT 0      COMMENT '排序（升序）',
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1      COMMENT '是否启用：1是 0否',
  `deleted_at`  DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort_active` (`sort_order`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='轮播图表：首页背景';

-- ----------------------------------------------------------------------------
-- 31. 社区群表 nft_community_groups
-- 社区交流群入口配置（图标/名称/简介/二维码）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_community_groups` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `icon`        VARCHAR(255)  NOT NULL                COMMENT '群图标URL',
  `name`        VARCHAR(50)   NOT NULL                COMMENT '群名称',
  `description` VARCHAR(255)  NULL DEFAULT NULL       COMMENT '群简介',
  `qr_code`     VARCHAR(255)  NULL DEFAULT NULL       COMMENT '二维码图URL',
  `sort_order`  INT           NOT NULL DEFAULT 0      COMMENT '排序（升序）',
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1      COMMENT '是否启用：1是 0否',
  `deleted_at`  DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区群表：交流入口';

-- ============================================================================
-- 九、系统配置域（2 张）
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 32. 系统参数表 nft_system_configs
-- 运营参数 KV（决策6：禁止硬编码）。预设键见脚本末尾种子数据
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_system_configs` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `config_key`   VARCHAR(50)   NOT NULL                COMMENT '参数键，唯一',
  `config_value` TEXT          NOT NULL                COMMENT '参数值',
  `description`  VARCHAR(200)  NULL DEFAULT NULL       COMMENT '参数说明',
  `created_at`   DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`   DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统参数表：运营参数KV';

-- ----------------------------------------------------------------------------
-- 33. 网站全局配置表 nft_site_settings
-- 站点级配置，分组 basic/theme/button/seo（决策：主题按钮色可配）
-- ----------------------------------------------------------------------------
CREATE TABLE `nft_site_settings` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `setting_key`   VARCHAR(50)   NOT NULL                COMMENT '配置键，唯一',
  `setting_value` TEXT          NOT NULL                COMMENT '配置值',
  `setting_group` ENUM('basic','theme','button','seo') NOT NULL DEFAULT 'basic' COMMENT '配置分组：basic基础/theme主题/button按钮色/seo搜索优化',
  `description`   VARCHAR(200)  NULL DEFAULT NULL       COMMENT '配置说明',
  `created_at`    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网站全局配置表：分组KV';

-- ============================================================================
-- 十、循环依赖外键补挂（ALTER TABLE）
-- 订单 ←→ 挂单 ←→ 用户资产 ←→ 空投记录 四表成环，两条环边延后补挂
-- ============================================================================

-- 订单关联市场挂单（订单表先于挂单表创建，此处补挂）
ALTER TABLE `nft_orders`
  ADD CONSTRAINT `fk_orders_resale_listing`
  FOREIGN KEY (`resale_listing_id`) REFERENCES `nft_resale_listings` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- 用户资产关联空投记录溯源（决策13，资产表先于空投记录表创建，此处补挂）
ALTER TABLE `nft_user_collectibles`
  ADD CONSTRAINT `fk_uc_airdrop_record`
  FOREIGN KEY (`airdrop_record_id`) REFERENCES `nft_airdrop_records` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- ============================================================================
-- 十一、种子数据：运营参数初始配置（决策6 预设键）
-- 说明：仅写入系统参数与站点基础配置；业务数据（藏品/用户/订单等）由业务系统产生，
-- 不写入任何 Mock 数据
-- ============================================================================

INSERT INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES
  ('purchase_limit_per_user',   '5',    '每藏品每用户限购数量'),
  ('order_pay_timeout_seconds', '300',  '订单待支付超时秒数（超时自动取消并释放库存）'),
  ('resale_cooldown_seconds',   '180',  '取消寄售后重新挂单冷却秒数'),
  ('resale_fee_rate',           '1.00', '寄售手续费率（百分比，如 1.00 表示 1%）'),
  ('checkin_rewards',           '{"1":5,"2":5,"3":10,"4":10,"5":15,"6":15,"7":30}', '连续签到奖励配置（JSON：天数→司南币数量）')
ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`), `updated_at` = CURRENT_TIMESTAMP(3);

INSERT INTO `nft_site_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
  ('site_name', '司南数字藏品', 'basic', '站点名称')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = CURRENT_TIMESTAMP(3);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 初始化完成（v2.2.2 自检修正版）：
--   33 张表 / 56 条外键（含 2 条 ALTER 补挂）/ 16 个 CHECK 约束
--   / 2 个条件唯一生成列（挂单在售唯一、转赠送待确认唯一）
-- ============================================================================
