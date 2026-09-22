<?php
declare(strict_types=1);

namespace app\service;

/**
 * 图形验证码服务
 *
 * 服务商（captcha.provider，后台「安全策略」可切换）：
 *   local  = 自研图形验证码（GD 渲染，本类 build/verify，默认）
 *   aliyun = 阿里云验证码 2.0（前端滑块拿 captcha_verify_param，
 *            后端调 VerifyIntelligentCaptcha 二次校验，ACS3-HMAC-SHA256 签名）
 *
 * 总开关 captcha.enable 与场景开关 captcha.scenes 对两种服务商均生效
 * （provider 只决定验证方式，不决定是否需要验证）。
 *
 * 存储：验证码明文 sha256 后存 file cache（不暴露原值给前端），
 *       前端只拿 captcha_id，提交时比对哈希。
 * TTL ：默认 5 分钟。验证成功后一次性删除（防重放）。
 */
final class CaptchaService
{
    /** 服务商：自研图形码 */
    public const PROVIDER_LOCAL = 'local';

    /** 服务商：阿里云验证码 2.0 */
    public const PROVIDER_ALIYUN = 'aliyun';

    /** 验证模式：滑块行为验证（aliyun 特有） */
    public const MODE_SLIDER = 'slider';

    /** 验证模式：图形字符码（local / aliyun 均支持） */
    public const MODE_GRAPHIC = 'graphic';

    /** @var int 字符位数 */
    private int $length = 4;

    /** @var int 图片宽度 */
    private int $width = 160;

    /** @var int 图片高度 */
    private int $height = 60;

    /** @var int 干扰线数量 */
    private int $lines = 6;

    /** @var int 干扰点数量 */
    private int $dots = 80;

    /** @var int 过期秒数 */
    private int $ttl = 300;

    /** @var string 允许的字符（去掉了 O/0/I/1 等易混） */
    private const CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * 图形验证码场景表（key => 中文名）
     * 与前端约定一致；后台「安全策略」按此表展示场景级开关。
     */
    public const SCENES = [
        'auth_login_password' => '密码登录',
        'auth_login_sms'      => '登录短信验证码发送',
        'auth_register'       => '注册短信验证码发送',
        'auth_forgot'         => '忘记密码短信验证码发送',
        'user_change_pwd'     => '已登录修改登录密码',
        'user_op_pwd'         => '设置/修改支付密码',
        'user_cancel'         => '注销账户',
        'admin_login'         => '管理后台登录',
    ];

    /**
     * 判断图形验证码是否启用（支持场景级开关）。
     *
     * 优先级：总开关 captcha.enable 关闭 → 一律 false；
     *        指定场景 → 读 captcha.scenes JSON 中该场景值（未配置默认 true，fail-closed）。
     *
     * @param string|null $scene 场景 key（传 null 时只看总开关）
     */
    public static function isEnabled(?string $scene = null): bool
    {
        // 1) 总开关（每次直查 DB，让后台切换即时生效）
        try {
            $val = \think\facade\Db::name('system_configs')
                ->where('config_key', 'captcha.enable')
                ->value('config_value');
            $enabled = $val !== null ? (bool) $val : true; // 没配置默认开启
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[captcha] isEnabled 查询失败，按开启处理: ' . $e->getMessage());
            $enabled = true;
        }
        if (!$enabled || $scene === null) {
            return $enabled;
        }

        // 2) 场景开关
        return self::isSceneEnabled($scene);
    }

    /**
     * 读取场景级开关。未配置 / 解析失败 → 默认开启（fail-closed）。
     */
    public static function isSceneEnabled(string $scene): bool
    {
        try {
            $raw = \think\facade\Db::name('system_configs')
                ->where('config_key', 'captcha.scenes')
                ->value('config_value');
            $scenesRaw = (string) ($raw ?? '');
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[captcha] scenes 查询失败，按开启处理: ' . $e->getMessage());
            $scenesRaw = '';
        }
        if ($scenesRaw === '') {
            return true; // 未配置 → 默认开启
        }
        $map = json_decode($scenesRaw, true);
        if (!is_array($map) || !array_key_exists($scene, $map)) {
            return true; // 场景未单独配置 → 默认开启
        }
        return (bool) $map[$scene];
    }

    /**
     * 批量读取所有场景开关（后台展示 / 前端探测用）。
     * 返回 [scene_key => bool]，未配置的场景为 true。
     */
    public static function sceneMap(): array
    {
        $result = [];
        foreach (self::SCENES as $scene => $name) {
            $result[$scene] = self::isEnabled($scene);
        }
        return $result;
    }

    // ============================================================
    // 服务商（captcha.provider）：local / aliyun
    // ============================================================

    /**
     * 当前验证码服务商（每次直查 DB，后台切换即时生效）。
     * 未配置 / 非法值 → local（fail-safe：保证验证能力始终可用）。
     */
    public static function provider(): string
    {
        $key = 'captcha.provider';
        try {
            $val = \think\facade\Db::name('system_configs')
                ->where('config_key', $key)
                ->value('config_value');
        } catch (\Throwable $e) {
            $val = null;
        }
        return $val === self::PROVIDER_ALIYUN ? self::PROVIDER_ALIYUN : self::PROVIDER_LOCAL;
    }

    /**
     * 当前验证模式（每次直查 DB）。
     *   provider=local 时忽略该字段，实际固定为 graphic；
     *   provider=aliyun 时可切 slider（滑块行为验证） / graphic（阿里云图形验证码）。
     * 未配置 / 非法值 → graphic。
     */
    public static function mode(): string
    {
        try {
            $val = \think\facade\Db::name('system_configs')
                ->where('config_key', 'captcha.mode')
                ->value('config_value');
        } catch (\Throwable $e) {
            $val = null;
        }
        if (self::provider() === self::PROVIDER_LOCAL) {
            return self::MODE_GRAPHIC; // local 只支持 graphic
        }
        return $val === self::MODE_SLIDER ? self::MODE_SLIDER : self::MODE_GRAPHIC;
    }

    /**
     * 读取阿里云验证码配置（每次直查库，securitySave 保存后即时生效）。
     *
     * @return array{scene_id: string, prefix: string, access_key_id: string, access_key_secret: string}
     *         ak/sk 为 AES 解密后的明文；未配置项为空串
     */
    public static function aliyunConfig(): array
    {
        $keys = [
            'captcha.aliyun.scene_id',
            'captcha.aliyun.prefix',
            'captcha.aliyun.access_key_id',
            'captcha.aliyun.access_key_secret',
        ];
        try {
            $rows = \think\facade\Db::name('system_configs')
                ->whereIn('config_key', $keys)
                ->column('config_value', 'config_key');
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[captcha] aliyunConfig 查询失败: ' . $e->getMessage());
            $rows = [];
        }

        $decrypt = function (?string $encrypted): string {
            if ($encrypted === null || $encrypted === '') {
                return '';
            }
            return (string) (\aes_decrypt($encrypted) ?? '');
        };

        return [
            'scene_id'          => trim((string) ($rows['captcha.aliyun.scene_id'] ?? '')),
            'prefix'            => trim((string) ($rows['captcha.aliyun.prefix'] ?? '')),
            'access_key_id'     => $decrypt(isset($rows['captcha.aliyun.access_key_id']) ? (string) $rows['captcha.aliyun.access_key_id'] : null),
            'access_key_secret' => $decrypt(isset($rows['captcha.aliyun.access_key_secret']) ? (string) $rows['captcha.aliyun.access_key_secret'] : null),
        ];
    }

    /**
     * 统一校验入口：按当前服务商分流（供各业务前置校验点调用）。
     *
     * local ：请求携带 captcha_id + captcha_code（一次性消费防重放）
     * aliyun：请求携带 captcha_verify_param（前端滑块回调透传，后端调阿里云二次校验）
     *
     * @param \think\Request $request 业务请求（param() 兼容 JSON body 与表单）
     */
    public static function verifyRequest(\think\Request $request): bool
    {
        if (self::provider() === self::PROVIDER_ALIYUN) {
            $param = trim((string) $request->param('captcha_verify_param', ''));
            return $param !== '' && self::aliyunVerify($param);
        }

        $captchaId   = trim((string) $request->param('captcha_id', ''));
        $captchaCode = trim((string) $request->param('captcha_code', ''));
        return $captchaId !== '' && $captchaCode !== ''
            && (new self())->verify($captchaId, $captchaCode, true);
    }

    /**
     * 调用阿里云验证码 2.0 服务端校验（VerifyIntelligentCaptcha，V3 签名）。
     *
     * fail-closed：配置缺失 / 网络异常 / 响应异常 / 校验不通过 一律返回 false。
     *
     * @param string $captchaVerifyParam 前端滑块验证通过回调透传的验证参数（禁止修改）
     */
    public static function aliyunVerify(string $captchaVerifyParam): bool
    {
        if (mb_strlen($captchaVerifyParam) > 8192) {
            return false; // 防御：异常超长参数直接拒绝
        }

        $cfg = self::aliyunConfig();
        if ($cfg['scene_id'] === '' || $cfg['access_key_id'] === '' || $cfg['access_key_secret'] === '') {
            \think\facade\Log::error('[captcha] 阿里云验证码配置不完整（scene_id/ak/sk），拒绝放行');
            return false;
        }

        $host = 'captcha.cn-shanghai.aliyuncs.com';
        $body = 'CaptchaVerifyParam=' . rawurlencode($captchaVerifyParam)
              . '&SceneId=' . rawurlencode($cfg['scene_id']);
        $bodyHash = hash('sha256', $body);

        // 参与签名的公共头（按名称升序）
        $headers = [
            'host'                   => $host,
            'x-acs-action'           => 'VerifyIntelligentCaptcha',
            'x-acs-content-sha256'   => $bodyHash,
            'x-acs-date'             => gmdate('Y-m-d\TH:i:s\Z'),
            'x-acs-signature-nonce'  => bin2hex(random_bytes(16)),
            'x-acs-version'          => '2023-03-05',
        ];

        // CanonicalRequest = METHOD \n URI \n Query \n CanonicalHeaders \n SignedHeaders \n BodyHash
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = "POST\n/\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $bodyHash;

        // StringToSign = Algorithm \n HexEncode(Hash(CanonicalRequest))
        $stringToSign = 'ACS3-HMAC-SHA256' . "\n" . hash('sha256', $canonicalRequest);

        // Signature = HexEncode(HMAC-SHA256(Secret, StringToSign))
        $signature = hash_hmac('sha256', $stringToSign, $cfg['access_key_secret']);
        $headers['Authorization'] = 'ACS3-HMAC-SHA256 Credential=' . $cfg['access_key_id']
            . ',SignedHeaders=' . $signedHeaders . ',Signature=' . $signature;
        $headers['content-type'] = 'application/x-www-form-urlencoded';

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init('https://' . $host . '/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr  = curl_error($ch);

        if ($raw === false || $httpCode !== 200) {
            \think\facade\Log::error('[captcha] 阿里云校验请求失败：HTTP ' . $httpCode . ' ' . $curlErr);
            return false;
        }

        $resp = json_decode((string) $raw, true);
        $ok = is_array($resp) && ($resp['VerifyResult'] ?? null) === true;
        if (!$ok) {
            \think\facade\Log::warning('[captcha] 阿里云校验未通过：' . mb_substr((string) $raw, 0, 500));
        }
        return $ok;
    }

    /**
     * 生成验证码图片
     *
     * @return array{captcha_id: string, image: string, expire: int}
     *         image 为 data URI 格式（data:image/png;base64,...），前端可直接 <img :src="image">
     */
    public function build(): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('captcha requires ext-gd');
        }

        $phrase = $this->randomPhrase();
        $image  = $this->render($phrase);
        $id     = bin2hex(random_bytes(16));

        // 存 hash，不存明文
        $hash = hash('sha256', strtoupper($phrase));
        \think\facade\Cache::set('captcha_' . $id, $hash, $this->ttl);

        return [
            'captcha_id' => $id,
            'image'      => 'data:image/png;base64,' . base64_encode($image),
            'expire'     => $this->ttl,
        ];
    }

    /**
     * 校验验证码（大小写不敏感）
     *
     * @param string $captchaId 前端提交的 captcha_id
     * @param string $code      用户输入的验证码
     * @param bool   $consume   是否一次性消费（默认 true，验证后即删除）
     * @return bool
     */
    public function verify(string $captchaId, string $code, bool $consume = true): bool
    {
        $captchaId = trim($captchaId);
        $code      = trim($code);

        if ($captchaId === '' || $code === '') {
            return false;
        }

        $cacheKey = 'captcha_' . $captchaId;
        $expected = \think\facade\Cache::get($cacheKey);
        if ($expected === null) {
            return false; // 过期或已被使用
        }

        $match = hash_equals((string) $expected, hash('sha256', strtoupper($code)));

        if ($consume) {
            \think\facade\Cache::delete($cacheKey);
        }

        return $match;
    }

    /**
     * 仅探测是否存在（用于前端判断验证码是否过期，可选使用）
     */
    public function exists(string $captchaId): bool
    {
        return \think\facade\Cache::has('captcha_' . $captchaId);
    }

    // ------------------------------------------------------------------
    // 以下为内部渲染实现（基于 imagestring，无 FreeType 依赖）
    // ------------------------------------------------------------------

    private function randomPhrase(): string
    {
        $phrase = '';
        $max = strlen(self::CHARS) - 1;
        for ($i = 0; $i < $this->length; $i++) {
            $phrase .= self::CHARS[random_int(0, $max)];
        }
        return $phrase;
    }

    /**
     * 渲染图片，返回 PNG 二进制
     */
    private function render(string $phrase): string
    {
        $im = imagecreatetruecolor($this->width, $this->height);

        // 背景：很浅的米白，带轻微渐变
        imagefill($im, 0, 0, imagecolorallocate($im, 245, 247, 250));
        for ($y = 0; $y < $this->height; $y++) {
            $ratio = $y / $this->height;
            $r = (int) (245 + (230 - 245) * $ratio);
            $g = (int) (247 + (236 - 247) * $ratio);
            $b = (int) (250 + (245 - 250) * $ratio);
            $c = imagecolorallocate($im, max(0, $r), max(0, $g), max(0, $b));
            imageline($im, 0, $y, $this->width, $y, $c);
        }

        // 干扰线
        $this->drawLines($im);
        // 干扰点
        $this->drawDots($im);
        // 字符
        $this->drawPhrase($im, $phrase);

        // 输出到字符串（PHP 8.5+ imagedestroy 已废弃，GC 会自动回收）
        ob_start();
        imagepng($im);
        return ob_get_clean();
    }

    private function drawLines(\GdImage $im): void
    {
        $colors = [
            imagecolorallocate($im, 120, 160, 210),
            imagecolorallocate($im, 180, 150, 200),
            imagecolorallocate($im, 150, 190, 180),
        ];
        for ($i = 0; $i < $this->lines; $i++) {
            $c = $colors[array_rand($colors)];
            $y1 = random_int(0, $this->height);
            $x1 = random_int(0, (int) ($this->width / 2));
            $y2 = random_int(0, $this->height);
            $x2 = random_int((int) ($this->width / 2), $this->width);
            imageline($im, $x1, $y1, $x2, $y2, $c);
        }
    }

    private function drawDots(\GdImage $im): void
    {
        $dotColor = imagecolorallocate($im, 100, 110, 130);
        for ($i = 0; $i < $this->dots; $i++) {
            $x = random_int(0, $this->width - 1);
            $y = random_int(0, $this->height - 1);
            imagesetpixel($im, $x, $y, $dotColor);
        }
    }

    private function drawPhrase(\GdImage $im, string $phrase): void
    {
        $length = strlen($phrase);
        // 字符区域宽度 = 总宽 - 两侧 padding
        $padX    = 12;
        $padY    = 14;
        $cellW   = (int) (($this->width - $padX * 2) / $length);
        $font    = 5; // GD 内置最大字体（~12px）
        $charH   = imagefontheight($font);
        $charW   = imagefontwidth($font);

        // 字符颜色池
        $colors = [
            imagecolorallocate($im, 30, 60, 120),
            imagecolorallocate($im, 50, 80, 150),
            imagecolorallocate($im, 80, 50, 140),
            imagecolorallocate($im, 40, 90, 110),
        ];

        for ($i = 0; $i < $length; $i++) {
            $char = $phrase[$i];
            $cx   = $padX + $i * $cellW + random_int(-2, 2);
            $cy   = $padY + random_int(-2, 2);
            $c    = $colors[array_rand($colors)];
            // 用 imagestring 水平输出
            imagestring($im, $font, $cx, $cy, $char, $c);
        }
    }

    // ------------------------------------------------------------------
    // Setter（按需自定义）
    // ------------------------------------------------------------------

    public function setLength(int $length): self   { $this->length = max(3, min(8, $length)); return $this; }
    public function setWidth(int $width): self     { $this->width  = max(80, min(400, $width)); return $this; }
    public function setHeight(int $height): self   { $this->height = max(30, min(200, $height)); return $this; }
    public function setLines(int $lines): self     { $this->lines  = max(0, min(30, $lines)); return $this; }
    public function setDots(int $dots): self       { $this->dots   = max(0, min(300, $dots)); return $this; }
    public function setTtl(int $ttl): self         { $this->ttl    = max(60, min(3600, $ttl)); return $this; }
}
