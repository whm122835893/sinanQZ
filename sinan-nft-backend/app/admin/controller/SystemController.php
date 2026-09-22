<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\SmsService;
use app\service\CaptchaService;
use think\facade\Cache;
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
 * - 渠道编码白名单：balance/alipay/wechat/huifu/unionpay/yeepay
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
        // 实名审核模式：manual=人工审核 auto=自动通过（提交即认证成功）
        if ($key === 'realname_audit_mode' && !in_array($value, ['manual', 'auto'], true)) {
            return $this->fail(4220, '参数 realname_audit_mode 仅允许 manual / auto');
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
    private const CHANNEL_CODES = ['balance', 'alipay', 'wechat', 'huifu', 'unionpay', 'yeepay'];

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
        'captcha.enable'          => ['图形验证码总开关（1=开启 0=关闭，关闭后所有场景均关闭）', 'bool', 0, 1],
        'captcha.scenes'          => ['图形验证码场景开关（JSON：场景key→0/1）', 'json', 0, 1],
        'captcha.provider'        => ['验证码服务商（local=本地图形码 aliyun=阿里云验证码2.0）', 'provider', 0, 1],
        'captcha.mode'            => ['验证模式（slider=滑块行为验证 graphic=图形验证码）', 'mode', 0, 1],
        'captcha.aliyun.scene_id' => ['阿里云验证码场景ID（SceneId，控制台场景列表获取）', 'text', 0, 1],
        'captcha.aliyun.prefix'   => ['阿里云验证码身份标（prefix，控制台概览页获取，前端初始化用）', 'text', 0, 1],
        'captcha.aliyun.access_key_id'     => ['阿里云 AccessKey ID（AES 加密落库，永不回显明文）', 'secret', 0, 1],
        'captcha.aliyun.access_key_secret' => ['阿里云 AccessKey Secret（AES 加密落库，永不回显明文）', 'secret', 0, 1],
        'sms_daily_limit'         => ['每手机号短信验证码每日上限（条）', 'int', 1, 100],
        'admin_login_fail_limit'  => ['管理后台登录失败锁定阈值（次）', 'int', 1, 20],
        'admin_lock_minutes'      => ['账号锁定时长（分钟）', 'int', 1, 1440],
        'large_recharge_alert'    => ['大额充值风控告警阈值（元）', 'int', 1, 10000000],
        'cleanup_sms_required'    => ['平台清库短信二次确认', 'bool', 0, 1],
    ];

    /** 验证码服务商/阿里云配置键（securityConfig 单独区块渲染，不进普通参数列表） */
    private const CAPTCHA_PROVIDER_KEYS = [
        'captcha.provider',
        'captcha.mode',
        'captcha.aliyun.scene_id',
        'captcha.aliyun.prefix',
        'captcha.aliyun.access_key_id',
        'captcha.aliyun.access_key_secret',
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
            // captcha.scenes 由下方 captchaScenes 场景表单独渲染；
            // 服务商/阿里云配置由下方 captchaProvider 区块单独渲染，均不进普通参数列表
            if ($key === 'captcha.scenes' || in_array($key, self::CAPTCHA_PROVIDER_KEYS, true)) {
                continue;
            }
            // 未配置时的默认值：captcha.enable 默认开启（与 CaptchaService::isEnabled 一致）；
            // sms_daily_limit 默认 10（与 SmsDispatchService::DEFAULT_DAILY_LIMIT 一致）；其余 '0'
            $default = match ($key) {
                'captcha.enable'  => '1',
                'sms_daily_limit' => (string) \app\service\SmsDispatchService::DEFAULT_DAILY_LIMIT,
                default           => '0',
            };
            $list[] = [
                'key'         => $key,
                'name'        => $name,
                'value'       => $map[$key] ?? $default,
                'description' => $name,
            ];
        }

        // 附带图形码场景表（供前端渲染场景级开关）
        $scenesRaw = $map['captcha.scenes'] ?? '';
        $scenesMap = $scenesRaw !== '' ? (json_decode($scenesRaw, true) ?: []) : [];
        $captchaScenes = [];
        foreach (CaptchaService::SCENES as $scene => $sceneName) {
            $captchaScenes[] = [
                'key'     => $scene,
                'name'    => $sceneName,
                // 未配置默认 '1'（开启）
                'value'   => (string) ($scenesMap[$scene] ?? '1'),
            ];
        }

        // 附带验证码服务商区块（provider + mode + 阿里云参数，密钥仅回显掩码）
        $aliyunCfg = CaptchaService::aliyunConfig();
        $provider = ($map['captcha.provider'] ?? '') === CaptchaService::PROVIDER_ALIYUN
            ? CaptchaService::PROVIDER_ALIYUN : CaptchaService::PROVIDER_LOCAL;
        $ak = $aliyunCfg['access_key_id'];
        $sk = $aliyunCfg['access_key_secret'];
        $modeRaw = $map['captcha.mode'] ?? CaptchaService::MODE_GRAPHIC;
        $mode = ($modeRaw === CaptchaService::MODE_SLIDER || $modeRaw === CaptchaService::MODE_GRAPHIC)
            ? $modeRaw : CaptchaService::MODE_GRAPHIC;
        // local 只支持 graphic,强制归一化
        if ($provider === CaptchaService::PROVIDER_LOCAL) {
            $mode = CaptchaService::MODE_GRAPHIC;
        }
        $captchaProvider = [
            'provider' => $provider,
            'mode'     => $mode,
            'aliyun'   => [
                'sceneId'     => $aliyunCfg['scene_id'],
                'prefix'      => $aliyunCfg['prefix'],
                'akMasked'    => $ak !== '' ? substr($ak, 0, 4) . str_repeat('*', max(0, strlen($ak) - 6)) . substr($ak, -2) : '',
                'skMasked'    => $sk !== '' ? str_repeat('*', 8) . '(' . strlen($sk) . '字符)' : '',
                'akConfigured' => $ak !== '',
                'skConfigured' => $sk !== '',
            ],
        ];

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
            'captchaScenes' => $captchaScenes,
            'captchaProvider' => $captchaProvider,
            'captchaEnabled' => CaptchaService::isEnabled(),
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
     *
     * 类型说明：
     *   bool    0/1
     *   json    场景开关 JSON（captcha.scenes）
     *   provider local/aliyun；切 aliyun 前强制校验阿里云参数齐全
     *   text    普通文本（阿里云 scene_id/prefix，长度 ≤100）
     *   secret  密钥（AES 加密落库；传空串 = 保持不变）
     *   int     数值范围校验
     */
    public function securitySave()
    {
        $key = trim((string) $this->request->param('key'));
        $value = trim((string) $this->request->param('value', ''));

        if (!isset(self::SECURITY_KEYS[$key])) {
            return $this->fail(4220, '不支持的安全参数：' . $key);
        }
        [$name, $type, $min, $max] = self::SECURITY_KEYS[$key];

        $auditValue = $value; // 审计日志展示值（secret 类型脱敏）

        if ($type === 'bool') {
            if (!in_array($value, ['0', '1'], true)) {
                return $this->fail(4220, '参数 ' . $key . ' 仅允许 0/1');
            }
        } elseif ($type === 'json') {
            // captcha.scenes：JSON 对象，key 必须在场景表内，值仅允许 '0'/'1'
            $map = json_decode($value, true);
            if (!is_array($map)) {
                return $this->fail(4220, '参数 ' . $key . ' 需为合法 JSON 对象');
            }
            foreach ($map as $scene => $v) {
                if (!isset(CaptchaService::SCENES[$scene])) {
                    return $this->fail(4220, '未知图形码场景：' . $scene);
                }
                if (!in_array((string) $v, ['0', '1'], true)) {
                    return $this->fail(4220, '场景 ' . $scene . ' 仅允许 0/1');
                }
            }
            // 归一化：补齐缺失场景（默认 '1'），按键排序保证可读
            $normalized = [];
            foreach (CaptchaService::SCENES as $scene => $sceneName) {
                $normalized[$scene] = (string) ($map[$scene] ?? '1');
            }
            $value = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        } elseif ($type === 'provider') {
            if (!in_array($value, [CaptchaService::PROVIDER_LOCAL, CaptchaService::PROVIDER_ALIYUN], true)) {
                return $this->fail(4220, '参数 ' . $key . ' 仅允许 local / aliyun');
            }
            // 切换到 aliyun 前强制校验参数齐全（防止切完线上验证全挂）
            if ($value === CaptchaService::PROVIDER_ALIYUN) {
                $cfg = CaptchaService::aliyunConfig();
                $missing = [];
                if ($cfg['scene_id'] === '') $missing[] = '场景ID';
                if ($cfg['prefix'] === '') $missing[] = '身份标(prefix)';
                if ($cfg['access_key_id'] === '') $missing[] = 'AccessKey ID';
                if ($cfg['access_key_secret'] === '') $missing[] = 'AccessKey Secret';
                if ($missing) {
                    return $this->fail(4220, '切换阿里云验证码前请先配置：' . implode('、', $missing));
                }
            }
        } elseif ($type === 'mode') {
            if (!in_array($value, [CaptchaService::MODE_SLIDER, CaptchaService::MODE_GRAPHIC], true)) {
                return $this->fail(4220, '参数 ' . $key . ' 仅允许 slider / graphic');
            }
            // local 只支持 graphic,强制归一化
            if (CaptchaService::provider() === CaptchaService::PROVIDER_LOCAL && $value !== CaptchaService::MODE_GRAPHIC) {
                return $this->fail(4220, '当前服务商为 local，仅支持 graphic 模式');
            }
        } elseif ($type === 'text') {
            if ($value === '' || mb_strlen($value) > 100) {
                return $this->fail(4220, '参数 ' . $key . ' 不能为空且长度不超过 100');
            }
            if (!preg_match('/^[A-Za-z0-9_\-]+$/', $value)) {
                return $this->fail(4220, '参数 ' . $key . ' 仅允许字母/数字/中划线/下划线');
            }
        } elseif ($type === 'secret') {
            // 空串 = 保持不变（前端不回显明文，仅重新输入时提交）
            if ($value === '') {
                return $this->success(null, '参数未变更');
            }
            if (strlen($value) < 8 || strlen($value) > 100) {
                return $this->fail(4220, '参数 ' . $key . ' 长度需在 8~100 之间');
            }
            $value = aes_encrypt($value);
            $auditValue = '******（已更新密钥）';
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

        // 清理 CaptchaService 的配置缓存（cfg_*，10s TTL），保证保存后立即生效
        Cache::delete('cfg_' . $key);

        $this->audit('system', 'security_save', '更新安全策略「' . $name . '」= ' . $auditValue, ['key' => $key, 'value' => $auditValue]);
        return $this->success(null, '安全策略已更新并实时生效');
    }
}
