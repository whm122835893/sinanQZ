-- ============================================================================
-- 钱包流水台账分类：nft_wallet_transactions.trans_type 补上 refund（订单退款入账）
--
-- 背景：枚举只有 recharge/buy/withdraw/reward 四类，而 RefundController::execute 的
--   退款入账是法币资金，只能被迫写成 trans_type='reward'（与「司南币发放/寄售成交结算」
--   同名）。后果是三条链路口径失真：
--     1) 后台钱包统计/报表把退款计进「奖励发放」，rewardIn 与 refundAmount 重复口径；
--     2) 钱包流水按类型筛选（recharge/buy/withdraw/reward）无法单独查退款；
--     3) qa/sit_z_identity.php 只能用「reward 且带 biz_no」这种权宜条件把法币入账从
--        积分发放里刨出来（脚本头注已注明彻底解法是给枚举加 refund）。
--
-- 安全性：新值追加在枚举尾部，已有行的存储索引 1~4 不变，MySQL 原地改元数据，不重写表数据。
--   回填只命中 title='订单退款入账' 的 reward 行（该产品写入点唯一），积分发放、
--   寄售成交结算（title='寄售成交结算'）均不受影响。
-- 幂等：ALTER 重复执行改成同一份定义；UPDATE 第二次执行匹配 0 行。
-- 新库无需本脚本：database/full_init.sql、init.sql 已同步为 5 值。
--
-- 执行方式：回填条件含中文字面量，必须指定客户端字符集，否则报 1267 Illegal mix of collations：
--   mysql --default-character-set=utf8mb4 -h<host> -P<port> -u<user> -p <db> < database/wallet_refund_trans_type_upgrade.sql
--
-- 配套代码改动（必须与本脚本同批上线）：
--   app/admin/controller/RefundController.php::execute  写入 trans_type='refund'
--   app/admin/controller/WalletController.php           stats 增 todayRefund、流水类型白名单加 refund、
--                                                       auditList 资金恒等式右端加 refund 入账项
-- ============================================================================

ALTER TABLE `nft_wallet_transactions`
  MODIFY COLUMN `trans_type`
  enum('recharge','buy','withdraw','reward','refund')
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
  COMMENT '交易类型：recharge充值/buy消费/withdraw提现/reward奖励(发放·结算)/refund订单退款入账';

-- 历史行回填：把退款入账从 reward 中剥离
UPDATE `nft_wallet_transactions`
   SET `trans_type` = 'refund'
 WHERE `trans_type` = 'reward'
   AND `title` = '订单退款入账';

-- 校验 1：期望输出包含 5 个类型值
SELECT COLUMN_TYPE AS trans_type_enum
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'nft_wallet_transactions'
   AND COLUMN_NAME = 'trans_type';

-- 校验 2：期望 0 —— reward 里不应再残留退款入账
SELECT COUNT(*) AS refund_still_in_reward
  FROM `nft_wallet_transactions`
 WHERE `trans_type` = 'reward'
   AND `title` = '订单退款入账';
