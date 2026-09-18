<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 支付渠道服务（C 端下单/支付的统一支付方式入口）
 *
 * 统一读取管理后台「系统设置 → 支付渠道」（nft_payment_channels）中各渠道的启停与密钥配置，
 * 决定 C 端展示哪些支付方式、以及支付时是否放行，替代早期硬编码的
 * ['balance','alipay','wechat'] 与 PAY_MOCK 环境变量。
 *
 * - balance：平台余额，由 Orders 直接扣钱包，走渠道 status 控制是否可用
 * - alipay/wechat/huifu/unionpay/yeepay：第三方渠道，后台启用 + 密钥齐备后 C 端展示；
 *   真实收银台 SDK 接入后在 payThirdParty() 内扩展即可生效
 */
class PaymentService
{
    /** 全部渠道编码（含余额），与管理后台 SystemController::CHANNEL_CODES 保持一致 */
    public const CODES = ['balance', 'alipay', 'wechat', 'huifu', 'unionpay', 'yeepay'];

    /**
     * C 端可用支付方式（已启用渠道，按后台 sort_order 升序）
     *
     * @return array<int,array{method:string,name:string}>
     */
    public static function availableMethods(): array
    {
        $rows = Db::name('payment_channels')
            ->where('status', 1)
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $methods = [];
        foreach ($rows as $row) {
            $methods[] = [
                'method' => $row['channel_code'],
                'name'   => $row['channel_name'],
            ];
        }
        return $methods;
    }

    /**
     * 读取单渠道（config 密文解密后供服务端内部判断是否已配置）
     */
    public static function getChannel(string $code): ?array
    {
        $row = Db::name('payment_channels')->where('channel_code', $code)->find();
        if (!$row) {
            return null;
        }
        if (!empty($row['config'])) {
            $row['config_data'] = aes_decrypt((string) $row['config']);
        }
        return $row;
    }

    /**
     * 第三方支付（C 端支付时的渠道校验与分派）
     *
     * - 渠道不存在/未启用/未配置密钥：返回失败
     * - 开发联调（APP_DEBUG=true）：mock 成功（仅记日志，等同早期 PAY_MOCK=true）
     * - 生产（APP_DEBUG=false）：真实收银台 SDK 尚未接入，明确拒绝
     *
     * @return array{0:bool,1:string} [是否成功, 结果说明]
     */
    public static function payThirdParty(string $method, string $orderNo): array
    {
        $channel = self::getChannel($method);
        if ($channel === null) {
            return [false, '支付方式不支持'];
        }
        if ((int) $channel['status'] !== 1) {
            return [false, '该支付方式已停用，请更换支付方式'];
        }
        if (empty($channel['config'])) {
            return [false, '支付渠道配置不完整，请联系平台'];
        }

        // 真实收银台 SDK 尚未接入：开发联调按 mock 成功，生产明确拒绝
        if (!(bool) env('APP_DEBUG', false)) {
            Log::info('[PAY][' . $method . '] gateway-not-integrated order=' . $orderNo);
            return [false, '该支付网关尚未接入，请改用余额支付'];
        }

        Log::info('[PAY][' . $method . '][mock] order=' . $orderNo . ' (开发联调模拟支付成功)');
        return [true, 'ok'];
    }
}