-- ============================================================================
-- 支付渠道与落库列对齐：nft_payments.payment_method 补上第三方渠道枚举
--
-- 背景：PaymentService::CODES 与 SystemController::CHANNEL_CODES 都认 6 个渠道
--   （balance/alipay/wechat/huifu/unionpay/yeepay），nft_payment_channels 里也确实配了
--   汇付/银联/易宝三条第三方渠道；但 nft_payments.payment_method 只建了
--   enum('balance','alipay','wechat')。后台 POST /admin/orders/{id}/mark-paid 传
--   huifu/unionpay/yeepay 时，插入在 STRICT_TRANS_TABLES 下报
--   「1265 Data truncated for column 'payment_method'」→ 事务回滚，接口返回 5000，
--   等于「第三方渠道线下收款」这一管理动作完全不可用（实测订单仍停在 pending、无支付流水）。
--
-- 安全性：新值一律追加在枚举尾部，已有行的存储索引 1/2/3 不变，MySQL 原地改元数据，
--   不重写表数据；对 balance/alipay/wechat 的历史记录零影响。
-- 幂等：重复执行只会把同一列定义改成同一份定义。
-- 新库无需本脚本：database/full_init.sql、full_schema_all.sql、init.sql 已同步为 6 值。
-- ============================================================================

ALTER TABLE `nft_payments`
  MODIFY COLUMN `payment_method`
  enum('balance','alipay','wechat','huifu','unionpay','yeepay')
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
  COMMENT '支付方式：balance余额（依赖钱包）/alipay支付宝/wechat微信/huifu汇付/unionpay银联/yeepay易宝（与 PaymentService::CODES 同集合）';

-- 校验：期望输出包含 6 个渠道值
SELECT COLUMN_TYPE AS payment_method_enum
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'nft_payments'
   AND COLUMN_NAME = 'payment_method';
