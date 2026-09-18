-- =============================================================
-- 司南数字藏品平台 · 全量建库脚本（合并版）
-- 合并来源: 19 个 SQL 文件
-- 存活表数: 79 张
-- ALTER 迁移: 76 条，涉及 18 张表
-- DROP 废弃: 3 条
-- 幂等安全，可重复执行（所有 CREATE 用 IF NOT EXISTS）
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- CREATE TABLE ----------
-- === 来自 init.sql ===
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
  `password`             VARCHAR(255)    NULL DEFAULT NULL       COMMENT '登录密码，bcrypt/scrypt 哈希（注册时设置，可密码登录）',
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
CREATE TABLE `nft_verification_codes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `phone`      VARCHAR(11)     NOT NULL                COMMENT '接收手机号',
  `scene`      VARCHAR(32)     NOT NULL                COMMENT '使用场景：C端 register/login/reset_password；管理端敏感操作（platform_cleanup 等）',
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
  `deleted_at`        DATETIME        NULL DEFAULT NULL       COMMENT '删除时间（软删除回收站，null=未删除）',
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
CREATE TABLE `nft_airdrop_records` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_id`         INT UNSIGNED    NULL DEFAULT NULL       COMMENT '空投活动ID，FK→nft_airdrop_activities.id（独立空投任务发放时为NULL）',
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
  `status`     TINYINT(1)    NOT NULL DEFAULT 1      COMMENT '展示状态：1展示中 0已隐藏',
  `deleted_at` DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at` DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at` DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_dynasty` (`dynasty`),
  KEY `idx_deleted` (`deleted_at`),
  CONSTRAINT `chk_artifacts_height` CHECK (`img_height` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文物表：展览区主数据';
-- === 来自 init.sql ===
CREATE TABLE `nft_announcements` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `title`       VARCHAR(200)  NOT NULL                COMMENT '标题',
  `summary`     VARCHAR(500)  NULL DEFAULT NULL       COMMENT '摘要（首页轮播同源）',
  `content`     TEXT          NULL                    COMMENT '正文/富文本HTML（含图片）',
  `cover_image` VARCHAR(255)  NULL DEFAULT NULL       COMMENT '封面图URL',
  `type`        ENUM('notice','news') NOT NULL        COMMENT '类型（决策5）：notice公告/news新闻',
  `subtype`     VARCHAR(20)   NULL DEFAULT NULL       COMMENT '子分类：activity活动/compose合成/operation运营',
  `tag_color`   VARCHAR(20)   NULL DEFAULT NULL       COMMENT '标签色（前端展示）',
  `status`      ENUM('draft','published') NOT NULL DEFAULT 'published' COMMENT '状态：draft草稿/published已发布（含定时待生效）',
  `publish_time` DATETIME(3)  NULL DEFAULT NULL       COMMENT '定时发布时间（NULL=创建即生效）',
  `is_top`      TINYINT(1)    NOT NULL DEFAULT 0      COMMENT '是否置顶：1是 0否',
  `deleted_at`  DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '发布时间',
  `updated_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type_created` (`type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告/新闻合并表';
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
CREATE TABLE `nft_community_groups` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `icon`        VARCHAR(255)  NOT NULL                COMMENT '群图标URL',
  `name`        VARCHAR(50)   NOT NULL                COMMENT '群名称',
  `description` VARCHAR(255)  NULL DEFAULT NULL       COMMENT '群简介',
  `qr_code`     VARCHAR(255)  NULL DEFAULT NULL       COMMENT '二维码图URL',
  `qq_group`    VARCHAR(20)   NULL DEFAULT NULL       COMMENT 'QQ群号（配置后「加入社群」跳转QQ入群）',
  `sort_order`  INT           NOT NULL DEFAULT 0      COMMENT '排序（升序）',
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1      COMMENT '是否启用：1是 0否',
  `deleted_at`  DATETIME      NULL DEFAULT NULL       COMMENT '软删除时间，NULL未删除',
  `created_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区群表：交流入口';
-- === 来自 init.sql ===
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
-- === 来自 init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
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
-- === 来自 admin_init.sql ===
CREATE TABLE `nft_payment_channels` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `channel_code`   VARCHAR(30)  NOT NULL                 COMMENT '渠道编码：balance/alipay/wechat/huifu/unionpay/yeepay',
  `channel_name`   VARCHAR(50)  NOT NULL                 COMMENT '渠道名称：余额/支付宝/微信支付/汇付天下/银联/易宝支付',
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
-- === 来自 fusion_upgrade.sql ===
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
-- === 来自 fusion_upgrade.sql ===
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
-- === 来自 fusion_upgrade.sql ===
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
-- === 来自 full_feature_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_raffle_activities` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '藏品 ID',
  `name`          VARCHAR(120) NOT NULL COMMENT '活动名称',
  `description`   TEXT NULL COMMENT '活动规则说明',
  `ticket_price`  DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '抽签报名费（0=免费）',
  `winner_count`  INT UNSIGNED NOT NULL COMMENT '中签名额数',
  `sale_quantity`  INT UNSIGNED NOT NULL COMMENT '中签用户每人可购买数量',
  `sale_price`    DECIMAL(10,2) NOT NULL COMMENT '中签后购买价',
  `registration_start` DATETIME NOT NULL COMMENT '报名开始时间',
  `registration_end`   DATETIME NOT NULL COMMENT '报名截止时间',
  `draw_time`     DATETIME NOT NULL COMMENT '抽签时间（自动执行）',
  `purchase_start` DATETIME NULL COMMENT '中签购买有效期开始',
  `purchase_end`   DATETIME NULL COMMENT '中签购买有效期结束',
  `status`        TINYINT NOT NULL DEFAULT 0 COMMENT '0草稿 1报名中 2抽签中 3已结束 4已取消',
  `draw_result`   JSON NULL COMMENT '抽签结果快照 [{user_id, user_name, phone}]',
  `extra`         JSON NULL COMMENT '扩展字段',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL DEFAULT NULL,
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_status` (`status`),
  KEY `idx_draw_time` (`draw_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽签发售活动';
-- === 来自 full_feature_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_raffle_registrations` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `activity_id`   BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `ticket_count`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '报名票数',
  `pay_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '报名费合计',
  `pay_status`    TINYINT NOT NULL DEFAULT 0 COMMENT '0未支付 1已支付 2已退款',
  `draw_status`   TINYINT NOT NULL DEFAULT 0 COMMENT '0未抽签 1中签 2未中',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_activity_user` (`activity_id`, `user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_draw_status` (`draw_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽签发售报名记录';
-- === 来自 full_feature_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_buy_requests` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '目标藏品 ID',
  `user_id`       BIGINT UNSIGNED NOT NULL COMMENT '求购发起者',
  `price`         DECIMAL(10,2) NOT NULL COMMENT '求购单价',
  `quantity`      INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '求购数量',
  `status`        TINYINT NOT NULL DEFAULT 1 COMMENT '1求购中 2已接单 3已取消 4已成交 5已过期',
  `accepted_by`   BIGINT UNSIGNED NULL COMMENT '接单用户（卖家）',
  `accepted_at`   DATETIME NULL,
  `order_no`      VARCHAR(64) NULL COMMENT '成交后关联订单号',
  `remark`        VARCHAR(500) NULL,
  `expires_at`    DATETIME NULL COMMENT '求购有效期',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL DEFAULT NULL,
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='求购挂单';
-- === 来自 full_feature_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_decompose_rules` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(120) NOT NULL COMMENT '分解规则名称',
  `source_collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '分解的源藏品',
  `enabled`       TINYINT NOT NULL DEFAULT 1,
  `per_user_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '每人限分解次数（0=不限）',
  `daily_limit`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平台每日上限（0=不限）',
  `start_time`    DATETIME NULL,
  `end_time`      DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL DEFAULT NULL,
  KEY `idx_source` (`source_collectible_id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分解规则';
-- === 来自 full_feature_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_decompose_items` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rule_id`       BIGINT UNSIGNED NOT NULL,
  `result_collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '分解后产出的藏品',
  `quantity_per`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '每分解 1 件源藏品产出的数量',
  KEY `idx_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分解产出明细';
-- === 来自 full_feature_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_decompose_records` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rule_id`       BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `source_collectible_id` BIGINT UNSIGNED NOT NULL,
  `source_serial` VARCHAR(64) NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user` (`user_id`),
  KEY `idx_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分解执行记录';
-- === 来自 swap_plan_upgrade.sql ===
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
-- === 来自 swap_plan_upgrade.sql ===
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
-- === 来自 swap_plan_upgrade.sql ===
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
-- === 来自 swap_plan_upgrade.sql ===
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
-- === 来自 rbac_snapshot_upgrade.sql ===
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
-- === 来自 rbac_snapshot_upgrade.sql ===
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
-- === 来自 marketing_activity_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_lucky_draw_activities` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL COMMENT '活动名称',
  `status`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '状态：0停用 1启用',
  `start_time` DATETIME(3)  NULL DEFAULT NULL COMMENT '开始时间',
  `end_time`   DATETIME(3)  NULL DEFAULT NULL COMMENT '结束时间',
  `deleted_at` DATETIME     NULL DEFAULT NULL,
  `created_at` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽奖活动（奖项挂在 activity_id 下）';
-- === 来自 activity_reward_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_activity_reward_records` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_type`  ENUM('synthesis','lucky_draw','checkin','invite','register') NOT NULL COMMENT '活动类型',
  `activity_id`    INT UNSIGNED    NOT NULL COMMENT '活动ID（对应各活动表主键）',
  `activity_title` VARCHAR(100)    NOT NULL DEFAULT '' COMMENT '活动名称快照',
  `user_id`        BIGINT UNSIGNED NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `phone`          VARCHAR(11)     NOT NULL DEFAULT '' COMMENT '用户手机号快照',
  `reward_type`    ENUM('collectible','points','draw_chance','priority_qualification','eligibility_qualification','blindbox','none') NOT NULL COMMENT '奖励类型',
  `reward_config`  JSON            NULL DEFAULT NULL COMMENT '奖励配置快照（collectibleId/quantity/amount/expiresAt 等）',
  `reward_label`   VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '奖励描述（导出名单展示）',
  `status`         ENUM('pending','issued','failed','cancelled') NOT NULL DEFAULT 'pending' COMMENT '状态：pending待发放/issued已发放/failed发放失败/cancelled已取消',
  `issue_result`   VARCHAR(255)    NULL DEFAULT NULL COMMENT '统一发放结果（成功/失败原因）',
  `issued_at`      DATETIME(3)     NULL DEFAULT NULL COMMENT '实际发放时间',
  `dedupe_key`     VARCHAR(128)    NULL DEFAULT NULL COMMENT '去重键（活动+用户+奖励位，防重复入账；uk）',
  `created_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dedupe_key` (`dedupe_key`),
  KEY `idx_activity` (`activity_type`, `activity_id`, `status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='活动奖励名单：manual 模式记录→导出→统一发放';
-- === 来自 activity_reward_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_register_activities` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name`        VARCHAR(100)  NOT NULL COMMENT '活动名称',
  `status`      ENUM('disabled','enabled') NOT NULL DEFAULT 'disabled' COMMENT '总开关：disabled关闭/enabled开启',
  `start_time`  DATETIME(3)   NULL DEFAULT NULL COMMENT '开始时间',
  `end_time`    DATETIME(3)   NULL DEFAULT NULL COMMENT '结束时间',
  `tiers`       JSON          NULL DEFAULT NULL COMMENT '实名前N名档位奖励 JSON：[{rankLimit, rewards:[{type,...}]}]',
  `grant_mode`  ENUM('realtime','manual') NOT NULL DEFAULT 'realtime' COMMENT '奖励发放方式：realtime实时到账/manual记录名单统一发放',
  `used_count`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '已发放人数',
  `description` TEXT          NULL COMMENT '活动说明文案',
  `deleted_at`  DATETIME      NULL DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='注册活动表：实名前N名档位奖励';
-- === 来自 activity_reward_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_priority_sales` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id` INT UNSIGNED NOT NULL COMMENT '目标藏品ID，FK→nft_collectibles.id',
  `name`          VARCHAR(100)  NOT NULL COMMENT '活动名称',
  `status`        TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '状态：0停用 1启用',
  `start_time`    DATETIME      NOT NULL COMMENT '开始时间',
  `end_time`      DATETIME      NOT NULL COMMENT '结束时间',
  `created_at`    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_collectible_status` (`collectible_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优先购活动表（奖励发放自动落位）';
-- === 来自 activity_reward_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_priority_sale_whitelists` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `priority_sale_id` INT UNSIGNED  NOT NULL COMMENT '优先购活动ID，FK→nft_priority_sales.id',
  `user_id`         BIGINT UNSIGNED NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `phone`           VARCHAR(11)    NOT NULL COMMENT '手机号',
  `max_quantity`    INT UNSIGNED   NOT NULL DEFAULT 1 COMMENT '可购上限',
  `used_quantity`   INT UNSIGNED   NOT NULL DEFAULT 0 COMMENT '已购数量',
  `expires_at`      DATETIME       NOT NULL COMMENT '资格过期时间',
  `status`          TINYINT(1)     NOT NULL DEFAULT 1 COMMENT '状态：0失效 1有效',
  `created_at`      DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`      DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sale_user` (`priority_sale_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优先购白名单表';
-- === 来自 activity_reward_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_lucky_draw_chances` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`        BIGINT UNSIGNED NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `activity_id`    INT UNSIGNED    NOT NULL COMMENT '抽奖活动ID',
  `source`         VARCHAR(20)     NOT NULL DEFAULT 'free' COMMENT '来源：checkin/invite/register/airdrop/free',
  `total_quantity` INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT '发放次数',
  `used_quantity`  INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '已使用次数',
  `related_id`     BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '关联业务ID',
  `created_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_activity` (`user_id`, `activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽奖次数台账表';
-- === 来自 raffle_draw_code_system_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_user_draw_codes` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT '所属用户',
  `code`        VARCHAR(20) NOT NULL COMMENT '抽签码展示串（S+日期6+随机6）',
  `source`      TINYINT NOT NULL DEFAULT 1 COMMENT '来源：1报名 2邀请 3购买 4后台手动',
  `activity_id` BIGINT UNSIGNED NULL COMMENT '来源活动 id（报名发放时非空；NULL 为通用码）',
  `status`      TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1未使用 2已报名 3已失效 4已中签',
  `remark`      VARCHAR(255) NULL COMMENT '备注（作废原因等）',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_user` (`user_id`),
  KEY `idx_activity_user` (`activity_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户抽签码（报名/邀请/购买发放）';
-- === 来自 raffle_admin_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_raffle_operation_logs` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `admin_id`    BIGINT UNSIGNED NULL COMMENT '操作管理员 ID',
  `admin_name`  VARCHAR(64) NULL COMMENT '操作管理员名称',
  `activity_id` BIGINT UNSIGNED NULL COMMENT '关联抽签活动 ID',
  `action`      VARCHAR(50) NOT NULL COMMENT '动作标识 set_force_win/cancel_force_win/change_quota/change_buy_limit/change_total_supply/draw/code_import/... ',
  `action_desc` VARCHAR(255) NOT NULL COMMENT '动作中文描述',
  `detail`      JSON NULL COMMENT '变更明细（前后值/名单）',
  `ip`          VARCHAR(45) NULL COMMENT '操作 IP',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_activity` (`activity_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽签独立操作日志（不可删除）';
-- === 来自 fusion_final_upgrade.sql ===
CREATE TABLE IF NOT EXISTS `nft_inbox` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`          BIGINT UNSIGNED NOT NULL                COMMENT '收件用户ID，FK→nft_users.id',
  `type`             ENUM('airdrop','transfer') NOT NULL      COMMENT '通知类型：airdrop空投/transfer转赠',
  `ref_id`           BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '关联业务ID（airdrop_task.id 或 transfers.id）',
  `title`            VARCHAR(100)    NOT NULL                COMMENT '通知标题，如"恭喜你收到空投藏品"',
  `collectible_id`   INT UNSIGNED    NOT NULL                COMMENT '藏品ID，FK→nft_collectibles.id',
  `name`             VARCHAR(100)    NOT NULL                COMMENT '藏品名称快照',
  `image`            VARCHAR(500)    NULL DEFAULT NULL       COMMENT '藏品图片URL快照',
  `extra`            JSON            NULL DEFAULT NULL       COMMENT '扩展字段：空投含{quantity,reason,adminName}；转赠含{fromNickname}',
  `status`           TINYINT UNSIGNED NOT NULL DEFAULT 0      COMMENT '状态：0未读/1已读/2已确认',
  `read_at`          DATETIME(3)     NULL DEFAULT NULL       COMMENT '已读时间',
  `confirmed_at`     DATETIME(3)     NULL DEFAULT NULL       COMMENT '确认时间',
  `created_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_type_ref` (`type`, `ref_id`),
  KEY `idx_collectible` (`collectible_id`),
  CONSTRAINT `fk_inbox_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_inbox_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收件箱：空投/转赠到达通知，支持未读弹窗与已读标记';

-- ---------- ALTER TABLE（升级迁移）----------
-- 注意: 部分 ALTER 来自原脚本的动态 SQL 幂等包装，
--       合并后裸执行——如目标列已存在会报 Duplicate column。
--       如需严格幂等，请保留原拆分脚本的动态 SQL。

-- ====== ALTER nft_airdrop_records ======
-- 来自 admin_init.sql
ALTER TABLE `nft_airdrop_records` ADD COLUMN `task_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_airdrop_records` MODIFY COLUMN `activity_id` INT UNSIGNED NULL DEFAULT NULL COMMENT;

-- ====== ALTER nft_blind_boxes ======
-- 来自 admin_init.sql
ALTER TABLE `nft_blind_boxes` ADD COLUMN `opened_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT;
-- 来自 full_feature_upgrade.sql
ALTER TABLE `nft_blind_boxes` ADD COLUMN `schedule_time` DATETIME NULL COMMENT \;
-- 来自 full_feature_upgrade.sql
ALTER TABLE `nft_blind_boxes` ADD COLUMN `is_scheduled` TINYINT NOT NULL DEFAULT 0 COMMENT \;

-- ====== ALTER nft_categories ======
-- 来自 category_scene_upgrade.sql
ALTER TABLE `nft_categories` ADD COLUMN `scene` VARCHAR(20) NOT NULL DEFAULT;

-- ====== ALTER nft_check_in_records ======
-- 来自 activity_reward_upgrade.sql
ALTER TABLE `nft_check_in_records` MODIFY COLUMN `reward_type` ENUM(\;

-- ====== ALTER nft_collectibles ======
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `per_user_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `is_transferable` TINYINT(1) NOT NULL DEFAULT 1 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `is_resaleable` TINYINT(1) NOT NULL DEFAULT 1 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `resale_price_mode` TINYINT NOT NULL DEFAULT 0 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `resale_price_min` DECIMAL(10,2) NULL DEFAULT NULL COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `resale_price_max` DECIMAL(10,2) NULL DEFAULT NULL COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `is_qualification_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `reserved_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `airdropped_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `destroyed_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` MODIFY COLUMN `status` ENUM(;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` DROP CONSTRAINT `chk_collectibles_stock`;
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD CONSTRAINT `chk_collectibles_stock_v2` CHECK (`sold` + `locked_quantity` + `reserved_count` + `airdropped_count` + `destroyed_count` <= `edition` AND `circulate` <= `edition`);
-- 来自 admin_init.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `is_buy_request_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT;
-- 来自 full_feature_upgrade.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `schedule_time` DATETIME NULL COMMENT \;
-- 来自 full_feature_upgrade.sql
ALTER TABLE `nft_collectibles` ADD COLUMN `is_scheduled` TINYINT NOT NULL DEFAULT 0 COMMENT \;

-- ====== ALTER nft_community_groups ======
-- 来自 admin_init.sql
ALTER TABLE `nft_community_groups` ADD COLUMN `members` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_community_groups` ADD COLUMN `qq_group` VARCHAR(20) NULL DEFAULT NULL COMMENT;

-- ====== ALTER nft_invite_activities ======
-- 来自 activity_reward_upgrade.sql
ALTER TABLE `nft_invite_activities` ADD COLUMN `tiers` JSON NULL DEFAULT NULL COMMENT \;

-- ====== ALTER nft_lucky_draw_activities ======
-- 来自 activity_reward_upgrade.sql
ALTER TABLE `nft_lucky_draw_activities` ADD COLUMN `eligibility_type` VARCHAR(20) NOT NULL DEFAULT \;

-- ====== ALTER nft_lucky_draw_prizes ======
-- 来自 activity_reward_upgrade.sql
ALTER TABLE `nft_lucky_draw_prizes` ADD COLUMN `prize_name` VARCHAR(100) NULL DEFAULT NULL COMMENT \;
-- 来自 activity_reward_upgrade.sql
ALTER TABLE `nft_lucky_draw_prizes` MODIFY COLUMN `prize_type` ENUM(\;

-- ====== ALTER nft_orders ======
-- 来自 init.sql
ALTER TABLE `nft_orders`
  ADD CONSTRAINT `fk_orders_resale_listing`
  FOREIGN KEY (`resale_listing_id`) REFERENCES `nft_resale_listings` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;
-- 来自 admin_init.sql
ALTER TABLE `nft_orders` MODIFY COLUMN `source` ENUM(;
-- 来自 admin_init.sql
ALTER TABLE `nft_orders` MODIFY COLUMN `status` ENUM(;
-- 来自 batch_buy_upgrade.sql
ALTER TABLE `nft_orders`
  ADD COLUMN `batch_listing_ids` VARCHAR(512) NULL DEFAULT NULL
    COMMENT '批量市场单关联的挂单ID列表（逗号分隔），非空表示批量购买订单'
    AFTER `resale_listing_id`;

-- ====== ALTER nft_raffle_activities ======
-- 来自 raffle_draw_code_system_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `draw_code_enabled` TINYINT NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_draw_code_system_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `draw_code_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `total_supply` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `draw_win_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `buy_code_limit` INT UNSIGNED NOT NULL DEFAULT 5 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `invite_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `invite_code_limit` INT UNSIGNED NOT NULL DEFAULT 5 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `invite_user_needed` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `max_wins_per_user` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` DROP COLUMN `user_max_code`;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `win_locked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` DROP COLUMN `whitelist_only`;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` DROP COLUMN `limit_per_user`;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_activities` ADD COLUMN `drawn_at` DATETIME NULL COMMENT;

-- ====== ALTER nft_raffle_registrations ======
-- 来自 raffle_draw_code_system_upgrade.sql
ALTER TABLE `nft_raffle_registrations` DROP COLUMN `draw_code`;
-- 来自 raffle_purchase_upgrade.sql
ALTER TABLE `nft_raffle_registrations` ADD COLUMN `purchased_quantity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_registrations` ADD COLUMN `is_force_win` TINYINT(1) NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_registrations` ADD COLUMN `force_set_by` BIGINT UNSIGNED NULL COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_registrations` ADD COLUMN `force_set_at` DATETIME NULL COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_paid` TINYINT(1) NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_paid_at` DATETIME NULL COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_verified_at` DATETIME NULL COMMENT;

-- ====== ALTER nft_resale_listings ======
-- 来自 admin_init.sql
ALTER TABLE `nft_resale_listings` ADD COLUMN `is_system_delisted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_resale_listings` ADD COLUMN `system_delisted_at` DATETIME(3) NULL DEFAULT NULL COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_resale_listings` ADD COLUMN `delist_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT;

-- ====== ALTER nft_synthesis_activities ======
-- 来自 marketing_activity_upgrade.sql
ALTER TABLE `nft_synthesis_activities` ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \;
-- 来自 activity_reward_upgrade.sql
ALTER TABLE `nft_synthesis_activities` ADD COLUMN `result_quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT \;
-- 来自 activity_reward_upgrade.sql
ALTER TABLE `nft_synthesis_activities` ADD COLUMN `eligibility_type` VARCHAR(20) NOT NULL DEFAULT \;

-- ====== ALTER nft_user_collectibles ======
-- 来自 init.sql
ALTER TABLE `nft_user_collectibles`
  ADD CONSTRAINT `fk_uc_airdrop_record`
  FOREIGN KEY (`airdrop_record_id`) REFERENCES `nft_airdrop_records` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;
-- 来自 admin_init.sql
ALTER TABLE `nft_user_collectibles` MODIFY COLUMN `status` ENUM(;

-- ====== ALTER nft_user_draw_codes ======
-- 来自 raffle_draw_code_system_upgrade.sql
ALTER TABLE `nft_user_draw_codes` ADD COLUMN `status` TINYINT NOT NULL DEFAULT 1 COMMENT;
-- 来自 raffle_draw_code_system_upgrade.sql
ALTER TABLE `nft_user_draw_codes` ADD COLUMN `remark` VARCHAR(255) NULL COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_user_draw_codes` MODIFY COLUMN `status` TINYINT NOT NULL DEFAULT 1 COMMENT;
-- 来自 raffle_admin_upgrade.sql
ALTER TABLE `nft_user_draw_codes` ADD KEY `idx_activity_status` (`activity_id`, `status`);

-- ====== ALTER nft_users ======
-- 来自 admin_init.sql
ALTER TABLE `nft_users` ADD COLUMN `password` VARCHAR(255) NULL DEFAULT NULL COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_users` ADD COLUMN `is_blacklisted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_users` ADD COLUMN `blacklist_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_users` ADD COLUMN `blacklist_at` DATETIME NULL DEFAULT NULL COMMENT;
-- 来自 admin_init.sql
ALTER TABLE `nft_users` ADD COLUMN `logout_before` DATETIME NULL DEFAULT NULL COMMENT;
-- 来自 activity_reward_upgrade.sql
ALTER TABLE `nft_users` ADD COLUMN `realname_verified_at` DATETIME(3) NULL DEFAULT NULL COMMENT \;

-- ====== ALTER nft_verification_codes ======
-- 来自 admin_sms_scene_upgrade.sql
ALTER TABLE `nft_verification_codes` MODIFY `scene` VARCHAR(32) NOT NULL COMMENT;

-- ---------- DROP 废弃表 ----------
-- 来自 raffle_admin_upgrade.sql
DROP TABLE IF EXISTS `nft_raffle_whitelists`;
-- 来自 swap_c2c_removal.sql
DROP TABLE IF EXISTS `nft_swap_records`;
-- 来自 swap_c2c_removal.sql
DROP TABLE IF EXISTS `nft_swap_offers`;

SET FOREIGN_KEY_CHECKS = 1;
-- =============================================================