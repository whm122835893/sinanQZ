<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 短信验证码统一派发（C 端 Auth/User 控制器共用）
 *
 * 频控策略：
 * - 60 秒内同手机号+场景禁止重发
 * - 每手机号每日上限条数（全场景合计），防图形码被破解后短信费被刷爆
 *   限额后台「安全策略→sms_daily_limit」可调（1~100，默认 DEFAULT_DAILY_LIMIT）
 *
 * 落库：bcrypt 哈希存储（防明文泄库）
 * debugCode：仅 mock 渠道 + APP_DEBUG=true 时回传（生产真实渠道不返回）
 */
class SmsDispatchService
{
    /** 每手机号每日发送上限缺省值（后台未配置时生效） */
    public const DEFAULT_DAILY_LIMIT = 10;

    /** 限额配置键（system_configs，后台安全策略可调） */
    public const LIMIT_KEY = 'sms_daily_limit';

    /**
     * 当前每日限额（读 system_configs.sms_daily_limit，10 秒缓存；
     * 未配置/非法值回落 DEFAULT_DAILY_LIMIT，DB 异常按缺省值放行不影响可用性）
     */
    public static function dailyLimit(): int
    {
        $cached = \think\facade\Cache::get('cfg_' . self::LIMIT_KEY);
        if ($cached !== null) {
            return (int) $cached;
        }
        try {
            $val = \think\facade\Db::name('system_configs')
                ->where('config_key', self::LIMIT_KEY)
                ->value('config_value');
            $limit = ($val !== null && (int) $val >= 1) ? (int) $val : self::DEFAULT_DAILY_LIMIT;
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[sms] dailyLimit 查询失败，按缺省值处理: ' . $e->getMessage());
            $limit = self::DEFAULT_DAILY_LIMIT;
        }
        \think\facade\Cache::set('cfg_' . self::LIMIT_KEY, $limit, 10);
        return $limit;
    }

    /**
     * 发送验证码并落库（调用方需自行完成参数校验与图形码前置校验）
     *
     * @param string $phone 手机号
     * @param string $scene 短信场景（register/login/reset_password/cancel）
     * @param string $ip    客户端 IP（落库审计用）
     * @return array{ok: bool, code?: int, message?: string, debugCode?: ?string}
     *         ok=false 时 code/message 供控制器直接 fail(code, message)
     */
    public static function dispatch(string $phone, string $scene, string $ip = ''): array
    {
        // 60 秒内同手机号+场景禁止重发
        $recent = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', $scene)
            ->where('sent_at', '>', date('Y-m-d H:i:s.v', time() - 60))
            ->find();
        if ($recent) {
            return ['ok' => false, 'code' => 1001, 'message' => '验证码发送过于频繁，请稍后再试'];
        }

        // 每手机号每日上限（全场景合计；sent_at 当日 00:00 起算；限额后台可调）
        $limit = self::dailyLimit();
        $todayCount = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('sent_at', '>=', date('Y-m-d 00:00:00'))
            ->count();
        if ($todayCount >= $limit) {
            return ['ok' => false, 'code' => 1001, 'message' => '今日验证码发送次数已达上限（' . $limit . ' 条），请明日再试'];
        }

        // 生成 6 位验证码
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now  = date('Y-m-d H:i:s.v');

        // 短信渠道：读取后台「系统设置→短信配置」（nft_sms_configs），mock 渠道本地模拟
        $smsConfig = SmsService::getConfig();
        $content   = '【' . (string) ($smsConfig['signature'] ?: '司南') . '】您的验证码：' . $code . '，5分钟内有效。';
        [$sent, $smsMsg] = SmsService::send($phone, $content, $smsConfig);
        if (!$sent) {
            return ['ok' => false, 'code' => 5001, 'message' => $smsMsg];
        }

        // 发送成功后再落库（bcrypt 哈希，防明文泄库）
        Db::name('verification_codes')->insert([
            'phone'      => $phone,
            'scene'      => $scene,
            'code'       => hash_password($code),
            'expires_at' => date('Y-m-d H:i:s.v', time() + 300),
            'sent_at'    => $now,
            'ip'         => $ip,
            'created_at' => $now,
        ]);

        // 仅 mock 渠道 + 开发联调（APP_DEBUG=true）返回明文验证码；真实渠道生产不返回
        return [
            'ok'        => true,
            'debugCode' => (SmsService::isMock($smsConfig) && env('APP_DEBUG')) ? $code : null,
        ];
    }
}
