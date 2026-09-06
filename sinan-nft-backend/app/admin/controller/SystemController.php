<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\SmsService;
use think\facade\Db;

/**
 * 系统配置控制器
 *
 * 四大配置域（与权限表 1300~1303 对应）：
 * 1. 全局参数（system:config）：nft_system_configs 运营参数 KV
 * 2. 支付渠道（system:payment）：nft_payment_channels 第三方钱包/支付通道配置
 * 3. 短信配置（system:sms）：nft_sms_configs 单行配置（密钥 AES 加密落库，永不回显明文）
 * 4. 安全策略（system:security）：登录锁定/风控阈值等安全参数
 *
 * 严谨性设计：
 * - 支付渠道密钥 config JSON 整体 AES 加密，接口仅回显脱敏摘要
 * - 短信/支付配置变更强制写审计日志
 * - 渠道编码白名单：balance/alipay/wechat/huifu/unionpay
 */
class SystemController extends BaseController
{
    // ============================================================
    // 一、全局参数（system:config）
    // ============================================================

    /**
     * GET /admin/system/configs
     */
    public function configList()
    {
        $items = Db::name('system_configs')->order('id', 'asc')->select()->toArray();
        return $this->success($items);
    }

    /**
     * PUT /admin/system/configs/:key { config_value }
     */
    public function configSave()
    {
        $key = trim((string) $this->request->param('key'));
        $value = trim((string) $this->request->param('config_value', $this->request->param('value', '')));

        if ($key === '') {
            return $this->fail(4220, '缺少配置键');
        }
        if (mb_strlen($key) > 50) {
            return $this->fail(4220, '配置键过长');
        }

        // 数值型参数范围校验（防止误配置导致业务异常）
        $numericRanges = [
            'admin_login_fail_limit'   => [1, 20],
            'admin_lock_minutes'       => [1, 1440],
            'resale_price_global_max'  => [1, 10000000],
            'large_recharge_alert'     => [1, 10000000],
            'resale_fee_rate'          => [0, 20],
            'resale_cooldown_seconds'  => [0, 604800],
        ];
        if (isset($numericRanges[$key])) {
            if (!is_numeric($value)) {
                return $this->fail(4220, '参数 ' . $key . ' 需为数值');
            }
            [$min, $max] = $numericRanges[$key];
            if ((float) $value < $min || (float) $value > $max) {
                return $this->fail(4220, '参数 ' . $key . ' 取值范围 ' . $min . '~' . $max);
            }
        }
        if (in_array($key, ['cleanup_sms_required'], true) && !in_array($value, ['0', '1'], true)) {
            return $this->fail(4220, '参数 ' . $key . ' 仅允许 0/1');
        }

        $exists = Db::name('system_configs')->where('config_key', $key)->find();
        if ($exists) {
            Db::name('system_configs')->where('config_key', $key)->update([
                'config_value' => mb_substr($value, 0, 5000),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            // 未知新键：允许创建但标记描述待补充（预留扩展性）
            Db::name('system_configs')->insert([
                'config_key'   => $key,
                'config_value' => mb_substr($value, 0, 5000),
                'description'  => trim((string) $this->request->param('description', '')) ?: null,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $this->audit('system', 'config_save', '更新系统参数「' . $key . '」= ' . mb_substr($value, 0, 100));
        return $this->success(null, '参数已保存并实时生效');
    }

    // ============================================================
    // 二、支付渠道配置（system:payment）—— 第三方钱包配置
    // ============================================================

    /** 渠道编码白名单 */
    private const CHANNEL_CODES = ['balance', 'alipay', 'wechat', 'huifu', 'unionpay'];

    /**
     * GET /admin/system/payment-channels
     * 渠道密钥永不回显：仅返回 configExists 标记与脱敏摘要
     */
    public function paymentList()
    {
        $items = Db::name('payment_channels')->order('sort_order', 'asc')->order('id', 'asc')->select()->toArray();

        $list = [];
        foreach ($items as $item) {
            $configRaw = $item['config'] ?? null;
            $list[] = [
                'id'             => (int) $item['id'],
                'channelCode'    => $item['channel_code'],
                'channelName'    => $item['channel_name'],
                'feeRate'        => (float) $item['fee_rate'],
                'status'         => (int) $item['status'],
                'isRecommended'  => (int) $item['is_recommended'],
                'sortOrder'      => (int) $item['sort_order'],
                'configExists'   => $configRaw !== null && $configRaw !== '',
                'configMasked'   => $this->maskChannelConfig($configRaw),
                'remark'         => $item['remark'],
                'updatedByName'  => $item['updated_by_name'],
                'updatedAt'      => $item['updated_at'],
                'createdAt'      => $item['created_at'],
            ];
        }
        return $this->success($list);
    }

    /**
     * PUT /admin/system/payment-channels/:id
     * { channel_name?, fee_rate?, status?, is_recommended?, sort_order?, config?, remark? }
     * config 为 JSON 对象（app_id/mch_id/private_key/gateway 等），整体 AES 加密落库
     */
    public function paymentSave()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $channel = Db::name('payment_channels')->where('id', $id)->find();
        if (!$channel) {
            return $this->fail(4040, '支付渠道不存在');
        }

        $update = [
            'updated_by'      => $this->adminId(),
            'updated_by_name' => $this->adminName(),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        if ($this->request->param('channel_name') !== null && $this->request->param('channel_name') !== '') {
            $update['channel_name'] = mb_substr(trim((string) $this->request->param('channel_name')), 0, 50);
        }
        if ($this->request->param('fee_rate') !== null && $this->request->param('fee_rate') !== '') {
            $feeRate = (float) $this->request->param('fee_rate');
            if ($feeRate < 0 || $feeRate > 100) {
                return $this->fail(4220, '手续费率需在 0~100% 之间');
            }
            $update['fee_rate'] = $feeRate;
        }
        if ($this->request->param('status') !== null) {
            $update['status'] = (int) $this->request->param('status') === 1 ? 1 : 0;
        }
        if ($this->request->param('is_recommended') !== null) {
            $update['is_recommended'] = (int) $this->request->param('is_recommended') === 1 ? 1 : 0;
        }
        if ($this->request->param('sort_order') !== null) {
            $update['sort_order'] = max(0, (int) $this->request->param('sort_order'));
        }
        if ($this->request->param('remark') !== null) {
            $update['remark'] = mb_substr(trim((string) $this->request->param('remark')), 0, 255);
        }

        // 渠道密钥：传 config 对象则整体加密落库；传空串表示清除
        $configInput = $this->request->param('config');
        if ($configInput !== null) {
            if ($configInput === '' || $configInput === []) {
                $update['config'] = null;
            } else {
                $decoded = is_array($configInput) ? $configInput : json_decode((string) $configInput, true);
                if (!is_array($decoded)) {
                    return $this->fail(4220, 'config 需为合法 JSON 对象');
                }
                $update['config'] = aes_encrypt(json_encode($decoded, JSON_UNESCAPED_UNICODE));
            }
        }

        // 严谨性：启用非余额渠道时必须有密钥配置
        $newStatus = (int) ($update['status'] ?? $channel['status']);
        $hasConfig = isset($update['config']) ? ($update['config'] !== null) : ($channel['config'] !== null && $channel['config'] !== '');
        if ($newStatus === 1 && $channel['channel_code'] !== 'balance' && !$hasConfig) {
            return $this->fail(4220, '启用第三方支付渠道前需先配置渠道密钥（config）');
        }

        Db::name('payment_channels')->where('id', $id)->update($update);

        $this->audit('system', 'payment_save',
            '更新支付渠道「' . ($update['channel_name'] ?? $channel['channel_name']) . '」'
            . (isset($update['config']) ? '（含密钥变更）' : ''),
            ['channel' => $channel['channel_code'], 'status' => $newStatus, 'configChanged' => isset($update['config'])],
            'payment_channel', $id);
        return $this->success(null, '支付渠道配置已保存');
    }

    /**
     * 渠道密钥脱敏摘要（仅展示键名与值长度，不泄露内容）
     */
    private function maskChannelConfig(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }
        $json = aes_decrypt($encrypted);
        if ($json === null) {
            return '密文（无法解密）';
        }
        $arr = json_decode($json, true);
        if (!is_array($arr)) {
            return '已配置';
        }
        $parts = [];
        foreach ($arr as $k => $v) {
            $parts[] = $k . ':***(' . mb_strlen((string) $v) . '字符)';
        }
        return '已配置 [' . implode(', ', array_slice($parts, 0, 8)) . ']';
    }

    // ============================================================
    // 三、短信配置（system:sms）
    // ============================================================

    /**
     * GET /admin/system/sms-config
     * 密钥永不回显明文：仅返回是否已配置 + 掩码摘要
     */
    public function smsConfig()
    {
        $row = Db::name('sms_configs')->where('id', 1)->find();
        if (!$row) {
            return $this->success([
                'provider' => 'mock', 'isEnabled' => 0, 'dailyLimit' => 0,
                'accessKeyMasked' => '', 'accessSecretMasked' => '',
                'signature' => '', 'templateRegister' => '', 'templateLogin' => '', 'templateReset' => '',
                'configured' => false,
            ]);
        }

        $accessKey = $row['access_key'] ? (aes_decrypt((string) $row['access_key']) ?? '') : '';
        $accessSecret = $row['access_secret'] ? (aes_decrypt((string) $row['access_secret']) ?? '') : '';

        return $this->success([
            'provider'         => $row['provider'],
            'isEnabled'         => (int) $row['is_enabled'],
            'dailyLimit'        => (int) $row['daily_limit'],
            'accessKeyMasked'   => $accessKey !== '' ? substr($accessKey, 0, 4) . str_repeat('*', max(0, strlen($accessKey) - 6)) . substr($accessKey, -2) : '',
            'accessSecretMasked'=> $accessSecret !== '' ? str_repeat('*', 8) . '(' . strlen($accessSecret) . '字符)' : '',
            'signature'         => (string) $row['signature'],
            'templateRegister'  => (string) $row['template_register'],
            'templateLogin'     => (string) $row['template_login'],
            'templateReset'     => (string) $row['template_reset'],
            'lastTestAt'        => $row['last_test_at'],
            'lastTestStatus'    => $row['last_test_status'] !== null ? (int) $row['last_test_status'] : null,
            'lastTestMessage'  => (string) $row['last_test_message'],
            'updatedByName'    => (string) $row['updated_by_name'],
            'updatedAt'        => $row['updated_at'],
            'configured'        => SmsService::isConfigured((string) $row['provider'], [
                'access_key' => $accessKey, 'access_secret' => $accessSecret, 'signature' => $row['signature'],
            ]),
        ]);
    }

    /**
     * PUT /admin/system/sms-config
     * { provider, is_enabled, daily_limit, access_key?, access_secret?, signature?, template_*? }
     * 密钥传空串 = 保持不变（前端不回显明文，仅重新输入时提交）
     */
    public function smsSave()
    {
        $input = [
            'provider'         => (string) $this->request->param('provider', 'mock'),
            'is_enabled'       => (int) $this->request->param('is_enabled', 0),
            'daily_limit'      => (int) $this->request->param('daily_limit', 0),
            'signature'        => (string) $this->request->param('signature', ''),
            'template_register' => (string) $this->request->param('template_register', ''),
            'template_login'    => (string) $this->request->param('template_login', ''),
            'template_reset'    => (string) $this->request->param('template_reset', ''),
            'access_key'       => (string) $this->request->param('access_key', ''),
            'access_secret'    => (string) $this->request->param('access_secret', ''),
        ];

        [$ok, $msg] = SmsService::saveConfig($input, $this->adminId(), $this->adminName());
        if (!$ok) {
            return $this->fail(4220, $msg);
        }

        $auditDetail = [
            'provider'      => $input['provider'],
            'enabled'       => $input['is_enabled'],
            'keyChanged'    => $input['access_key'] !== '',
            'secretChanged' => $input['access_secret'] !== '',
        ];
        $this->audit('system', 'sms_save',
            '更新短信配置（渠道 ' . $input['provider'] . '，' . ($input['is_enabled'] === 1 ? '启用' : '停用') . '）',
            $auditDetail);
        return $this->success(null, $msg);
    }

    /**
     * POST /admin/system/sms-config/test { phone }
     */
    public function smsTest()
    {
        $phone = trim((string) $this->request->param('phone', ''));
        if ($phone === '') {
            return $this->fail(4220, '缺少测试手机号 phone');
        }

        [$ok, $msg] = SmsService::sendTest($phone, $this->adminId(), $this->adminName());

        $this->audit('system', 'sms_test', '短信配置测试发送（' . ($ok ? '成功' : '失败：' . $msg) . '）',
            ['phone' => mask_phone($phone)]);
        return $ok ? $this->success(null, $msg) : $this->fail(5000, $msg);
    }

    // ============================================================
    // 四、安全策略（system:security）
    // ============================================================

    /** 安全策略参数白名单（键 => [中文名, 校验类型, min, max]） */
    private const SECURITY_KEYS = [
        'admin_login_fail_limit'  => ['管理后台登录失败锁定阈值（次）', 'int', 1, 20],
        'admin_lock_minutes'      => ['账号锁定时长（分钟）', 'int', 1, 1440],
        'large_recharge_alert'    => ['大额充值风控告警阈值（元）', 'int', 1, 10000000],
        'cleanup_sms_required'    => ['平台清库短信二次确认', 'bool', 0, 1],
    ];

    /**
     * GET /admin/system/security-config
     */
    public function securityConfig()
    {
        $rows = Db::name('system_configs')
            ->whereIn('config_key', array_keys(self::SECURITY_KEYS))
            ->select()->toArray();
        $map = array_column($rows, 'config_value', 'config_key');

        $list = [];
        foreach (self::SECURITY_KEYS as $key => [$name, , $min, $max]) {
            $list[] = [
                'key'         => $key,
                'name'        => $name,
                'value'       => $map[$key] ?? '0',
                'description' => $name,
            ];
        }

        // 附带管理员账号安全概览
        $adminTotal   = Db::name('admin_users')->whereNull('deleted_at')->count();
        $adminLocked  = Db::name('admin_users')->whereNull('deleted_at')
            ->where('locked_until', '>', date('Y-m-d H:i:s'))->count();
        $adminDisabled = Db::name('admin_users')->whereNull('deleted_at')->where('status', 0)->count();

        $login24hFail = Db::name('admin_login_logs')
            ->where('status', 2)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 86400))
            ->count();

        return $this->success([
            'configs' => $list,
            'overview' => [
                'adminTotal'    => $adminTotal,
                'adminLocked'   => $adminLocked,
                'adminDisabled' => $adminDisabled,
                'login24hFail'  => $login24hFail,
            ],
        ]);
    }

    /**
     * PUT /admin/system/security-config/:key { value }
     */
    public function securitySave()
    {
        $key = trim((string) $this->request->param('key'));
        $value = trim((string) $this->request->param('value', ''));

        if (!isset(self::SECURITY_KEYS[$key])) {
            return $this->fail(4220, '不支持的安全参数：' . $key);
        }
        [$name, $type, $min, $max] = self::SECURITY_KEYS[$key];

        if ($type === 'bool') {
            if (!in_array($value, ['0', '1'], true)) {
                return $this->fail(4220, '参数 ' . $key . ' 仅允许 0/1');
            }
        } else {
            if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
                return $this->fail(4220, '参数 ' . $key . ' 需为 ' . $min . '~' . $max . ' 的整数');
            }
        }

        $exists = Db::name('system_configs')->where('config_key', $key)->find();
        if ($exists) {
            Db::name('system_configs')->where('config_key', $key)->update([
                'config_value' => $value,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            Db::name('system_configs')->insert([
                'config_key'   => $key,
                'config_value' => $value,
                'description'  => $name,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $this->audit('system', 'security_save', '更新安全策略「' . $name . '」= ' . $value, ['key' => $key, 'value' => $value]);
        return $this->success(null, '安全策略已更新并实时生效');
    }
}
