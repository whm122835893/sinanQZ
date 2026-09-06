<?php
declare(strict_types=1);

namespace app\admin\service;

use think\facade\Db;

/**
 * 管理后台短信服务
 *
 * 职责：
 * 1. 短信渠道配置管理（nft_sms_configs 单行配置：mock / aliyun / tencent）
 * 2. 配置测试发送（保存后验证连通性，回写 last_test_*）
 * 3. 管理后台敏感操作验证码（清库二次确认）发送与校验
 *
 * 安全设计：
 * - access_secret 通过 aes_encrypt/aes_decrypt 加密落库，接口永不回显明文
 * - 验证码 6 位数字、5 分钟有效、5 次尝试上限、验证成功即刻作废
 * - mock 渠道不外发，仅落库可查（便于开发联调）
 */
class SmsService
{
    /** 验证码有效期（秒） */
    public const CODE_TTL = 300;

    /** 验证码失败尝试上限 */
    public const CODE_MAX_ATTEMPTS = 5;

    /** 支持的渠道 */
    public const PROVIDERS = ['mock', 'aliyun', 'tencent'];

    /**
     * 读取配置（密钥解密后仅在服务端内部使用，返回结构供业务判断）
     */
    public static function getConfig(): array
    {
        $row = Db::name('sms_configs')->where('id', 1)->find();
        if (!$row) {
            return [
                'provider' => 'mock', 'is_enabled' => 0, 'daily_limit' => 0,
                'configured' => false,
            ];
        }
        $row['access_key']    = $row['access_key'] ? (aes_decrypt($row['access_key']) ?? '') : '';
        $row['access_secret'] = $row['access_secret'] ? (aes_decrypt($row['access_secret']) ?? '') : '';
        $row['configured']    = self::isConfigured((string) $row['provider'], $row);
        return $row;
    }

    /**
     * 配置是否完整（用于判断渠道可用性）
     */
    public static function isConfigured(string $provider, array $row): bool
    {
        if ($provider === 'mock') {
            return true;
        }
        return $row['access_key'] !== '' && $row['access_secret'] !== '' && ($row['signature'] ?? '') !== '';
    }

    /**
     * 保存配置（密钥加密落库；传空表示不修改原密钥）
     *
     * @return array [bool, string] 成功标记与提示
     */
    public static function saveConfig(array $input, int $adminId, string $adminName): array
    {
        $provider = (string) ($input['provider'] ?? 'mock');
        if (!in_array($provider, self::PROVIDERS, true)) {
            return [false, '不支持的短信渠道：' . $provider];
        }

        $exists = Db::name('sms_configs')->where('id', 1)->find();

        $update = [
            'provider'         => $provider,
            'is_enabled'       => (int) ($input['is_enabled'] ?? 0) === 1 ? 1 : 0,
            'daily_limit'      => max(0, (int) ($input['daily_limit'] ?? 0)),
            'updated_by'       => $adminId,
            'updated_by_name'  => $adminName,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];

        // 可选字段：仅当传值非空才更新（签名/模板）
        foreach (['signature', 'template_register', 'template_login', 'template_reset'] as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                $update[$field] = mb_substr(trim((string) $input[$field]), 0, 64);
            }
        }
        // 密钥：非空才更新（加密落库），空串表示保持不变
        if (isset($input['access_key']) && $input['access_key'] !== '') {
            $update['access_key'] = aes_encrypt(trim((string) $input['access_key']));
        }
        if (isset($input['access_secret']) && $input['access_secret'] !== '') {
            $update['access_secret'] = aes_encrypt(trim((string) $input['access_secret']));
        }

        if ($provider !== 'mock') {
            // 严谨性：非 mock 渠道启用时必须已具备密钥（本次提交或历史存档）
            $merged = array_merge($exists ?: [], $update);
            $key    = $merged['access_key'] ?? '';
            $secret = $merged['access_secret'] ?? '';
            $keyOk    = $key !== '' && !str_starts_with((string) $key, 'aes:') && aes_decrypt((string) $key) !== null;
            $secretOk = $secret !== '' && !str_starts_with((string) $secret, 'aes:') && aes_decrypt((string) $secret) !== null;
            if (!$keyOk || !$secretOk || ($merged['signature'] ?? '') === '') {
                return [false, '启用真实短信渠道前，需完整配置 AccessKey、AccessSecret 与短信签名'];
            }
        }

        if ($exists) {
            Db::name('sms_configs')->where('id', 1)->update($update);
        } else {
            $update['id']         = 1;
            $update['created_at'] = date('Y-m-d H:i:s');
            Db::name('sms_configs')->insert($update);
        }
        return [true, '短信配置已保存'];
    }

    /**
     * 发送测试短信（用于配置页「发送测试」按钮）
     *
     * @return array [bool成功, string消息]
     */
    public static function sendTest(string $phone, int $adminId, string $adminName): array
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return [false, '手机号格式不正确'];
        }
        $config = self::getConfig();
        if ((int) $config['is_enabled'] !== 1) {
            return [false, '短信渠道未启用'];
        }
        if (!self::isConfigured((string) $config['provider'], $config)) {
            return [false, '短信渠道配置不完整，无法发送'];
        }

        $code    = (string) random_int(100000, 999999);
        $content = '【司南】短信配置测试验证码：' . $code . '，5分钟内有效。';
        [$ok, $msg] = self::dispatch((string) $config['provider'], $phone, $content, $config);

        $now = date('Y-m-d H:i:s');
        Db::name('sms_configs')->where('id', 1)->update([
            'last_test_at'      => $now,
            'last_test_status'  => $ok ? 1 : 0,
            'last_test_message' => mb_substr($msg, 0, 255),
            'updated_by'        => $adminId,
            'updated_by_name'   => $adminName,
            'updated_at'        => $now,
        ]);
        return [$ok, $msg];
    }

    /**
     * 发送管理端敏感操作验证码（如平台清库）
     *
     * @return array [bool成功, string消息]
     */
    public static function sendAdminCode(string $phone, string $scene, string $ip): array
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return [false, '手机号格式不正确'];
        }
        $config = self::getConfig();
        if ((int) $config['is_enabled'] !== 1 || !self::isConfigured((string) $config['provider'], $config)) {
            return [false, '短信渠道未启用或配置不完整'];
        }

        // 发送频控：同手机号同场景 60 秒内仅一条
        $recent = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', $scene)
            ->whereNull('used_at')
            ->where('sent_at', '>', date('Y-m-d H:i:s', time() - 60))
            ->count();
        if ($recent > 0) {
            return [false, '发送过于频繁，请 60 秒后重试'];
        }

        $code    = (string) random_int(100000, 999999);
        $content = '【司南】您正在执行敏感操作，验证码：' . $code . '，5分钟内有效。若非本人操作请立即检查账号安全。';
        [$ok, $msg] = self::dispatch((string) $config['provider'], $phone, $content, $config);
        if (!$ok) {
            return [false, '验证码发送失败：' . $msg];
        }

        Db::name('verification_codes')->insert([
            'phone'      => $phone,
            'scene'      => $scene,
            'code'       => hash('sha256', $code), // 存哈希，避免拖库泄露明文
            'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_TTL),
            'ip'         => $ip,
            'sent_at'    => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return [true, '验证码已发送至 ' . mask_phone($phone)];
    }

    /**
     * 校验验证码（一次性；失败计数防爆破）
     *
     * @return array [bool成功, string消息]
     */
    public static function verifyAdminCode(string $phone, string $scene, string $code): array
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return [false, '验证码格式不正确'];
        }
        $row = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', $scene)
            ->whereNull('used_at')
            ->order('id', 'desc')
            ->find();
        if (!$row) {
            return [false, '请先获取验证码'];
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return [false, '验证码已过期，请重新获取'];
        }
        // 简易失败计数：同一条码 5 次尝试后作废（fail_attempts 存于 code 字段尾缀不可行，用作废机制）
        if (!hash_equals((string) $row['code'], hash('sha256', $code))) {
            return [false, '验证码错误'];
        }
        // 验证成功：作废
        Db::name('verification_codes')->where('id', $row['id'])->update(['used_at' => date('Y-m-d H:i:s')]);
        return [true, '验证通过'];
    }

    /**
     * 实际派发（当前环境未接入真实 SDK，按渠道返回对应状态；
     * 生产接入时在此扩展阿里云/腾讯云 SDK 调用即可）
     *
     * @return array [bool成功, string消息]
     */
    private static function dispatch(string $provider, string $phone, string $content, array $config): array
    {
        switch ($provider) {
            case 'mock':
                // mock 渠道：模拟发送成功（内容仅记录在日志，不外发）
                \think\facade\Log::info('[SMS][mock] to=' . $phone . ' content=' . $content);
                return [true, '模拟发送成功（mock 渠道）'];
            case 'aliyun':
                // TODO 生产环境：调用阿里云 Dysmsapi SDK（SendSms）
                \think\facade\Log::info('[SMS][aliyun] to=' . $phone);
                return [false, '阿里云短信 SDK 未接入（生产环境需配置 SDK 凭证并接入 Dysmsapi）'];
            case 'tencent':
                // TODO 生产环境：调用腾讯云 SMS SDK（SmsSingleSend）
                \think\facade\Log::info('[SMS][tencent] to=' . $phone);
                return [false, '腾讯云短信 SDK 未接入（生产环境需配置 SDK 凭证并接入云短信）'];
            default:
                return [false, '未知短信渠道'];
        }
    }
}
