-- ============================================================================
-- 支付渠道补充：新增「易宝支付」（yeepay）渠道
--   在 nft_payment_channels 中补充易宝支付占位渠道（密钥通过后台填写），
--   与其余第三方渠道（alipay/wechat/huifu/unionpay）并列，供后台「系统设置→支付渠道」配置。
-- ============================================================================

INSERT INTO `nft_payment_channels`
  (`id`, `channel_code`, `channel_name`, `fee_rate`, `status`, `is_recommended`, `sort_order`, `remark`)
VALUES
  (6, 'yeepay', '易宝支付', 0.50, 0, 0, 6, '易宝支付聚合支付，需配置商户号与密钥')
ON DUPLICATE KEY UPDATE
  `channel_name` = VALUES(`channel_name`),
  `remark`       = VALUES(`remark`);