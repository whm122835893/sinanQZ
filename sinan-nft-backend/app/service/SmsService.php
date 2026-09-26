<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 短信发送服务（C 端验证码等业务的通用发送入口）
 *
 * 统一读取管理后台「系统设置 → 短信配置」中的 nft_sms_configs 单行配置，
 * 决定验证码短信的派发方式，替代早期散落在控制器中的 SMS_MOCK 环境变量。
 *
 * - provider = mock：本地模拟发送（仅记录日志，验证码明文由控制器在调试态回传）
 * - provider = aliyun / tencent：真实渠道占位，SDK 接入后在 send() 内扩展即可生效
 */
class SmsService
{
    public const PROVIDERS = ['mock', 'aliyun', 'tencent'];

    /**
     * 读取短信渠道配置（access_key / access_secret 解密后供服务端内部使用）
     */
    public static function getConfig(): array
    {
        $row = Db::name('sms_configs')->where('id', 1)->find();
        if (!$row) {
            return ['provider' => 'mock', 'is_enabled' => 0];
        }
        if (!empty($row['access_key'])) {
            $row['access_key'] = aes_decrypt((string) $row['access_key']) ?? '';
        }
        if (!empty($row['access_secret'])) {
            $row['access_secret'] = aes_decrypt((string) $row['access_secret']) ?? '';
        }
        return $row;
    }

    /**
     * 渠道是否为 mock（供调用方决定是否回传 debugCode 调试验证码）
     */
    public static function isMock(array $config): bool
    {
        return (string) ($config['provider'] ?? 'mock') === 'mock';
    }

    /**
     * 渠道配置是否完整（mock 恒可用；真实渠道需密钥 + 签名齐备）
     */
    public static function isConfigured(array $config): bool
    {
        if (self::isMock($config)) {
            return true;
        }
        return !empty($config['access_key'])
            && !empty($config['access_secret'])
            && !empty($config['signature']);
    }

    /**
     * 发送短信
     *
     * @return array [bool 是否成功, string 结果说明]
     */
    public static function send(string $phone, string $content, ?array $config = null): array
    {
        $config = $config ?? self::getConfig();

        if ((int) ($config['is_enabled'] ?? 0) !== 1) {
            return [false, '短信服务已停用，请联系平台'];
        }

        $provider = (string) ($config['provider'] ?? 'mock');
        if ($provider === 'mock') {
            // M6 修复：日志不输出短信内容（含明文验证码），仅记录手机号
            Log::info('[SMS][mock] to=' . $phone);
            return [true, 'ok'];
        }

        if (!in_array($provider, self::PROVIDERS, true)) {
            return [false, '未知短信渠道'];
        }
        if (!self::isConfigured($config)) {
            return [false, '短信渠道配置不完整，请先在后台完善 AccessKey / AccessSecret / 短信签名'];
        }

        // 真实渠道派发占位：阿里云 Dysmsapi / 腾讯云 SMS 的 SDK 接入后在此替换实现
        Log::info('[SMS][' . $provider . '] to=' . $phone);
        return [false, '当前短信渠道已配置，但服务商 SDK 尚未接入，请先切换为 Mock 渠道'];
    }
}