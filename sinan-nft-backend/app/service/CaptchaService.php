<?php
declare(strict_types=1);

namespace app\service;

/**
 * 图形验证码服务
 *
 * 设计参考 gregwar/captcha（GitHub 20k+ stars，Packagist 500k+ 下载），
 * 但使用 PHP 内置 GD imagestring 作为后备驱动——在未编译 FreeType 的环境下仍可工作。
 * 生产环境如需更强抗 OCR 能力，可在 build() 中切换为 Gregwar\Captcha\CaptchaBuilder。
 *
 * 存储：验证码明文 sha256 后存 file cache（不暴露原值给前端），
 *       前端只拿 captcha_id，提交时比对哈希。
 * TTL ：默认 5 分钟。验证成功后一次性删除（防重放）。
 */
final class CaptchaService
{
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
        // 1) 总开关
        $key = 'captcha.enable';
        $cached = \think\facade\Cache::get('cfg_' . $key);
        if ($cached !== null) {
            $enabled = (bool) $cached;
        } else {
            try {
                $val = \think\facade\Db::name('system_configs')
                    ->where('config_key', $key)
                    ->value('config_value');
                $enabled = $val !== null ? (bool) $val : true; // 没配置默认开启
            } catch (\Throwable $e) {
                \think\facade\Log::warning('[captcha] isEnabled 查询失败，按开启处理: ' . $e->getMessage());
                $enabled = true;
            }
            \think\facade\Cache::set('cfg_' . $key, $enabled, 10);
        }
        if (!$enabled || $scene === null) {
            return $enabled;
        }

        // 2) 场景开关（captcha.scenes JSON）
        return self::isSceneEnabled($scene);
    }

    /**
     * 读取场景级开关。未配置 / 解析失败 → 默认开启（fail-closed）。
     */
    public static function isSceneEnabled(string $scene): bool
    {
        $key = 'captcha.scenes';
        $cached = \think\facade\Cache::get('cfg_' . $key);
        if ($cached === null) {
            try {
                $raw = \think\facade\Db::name('system_configs')
                    ->where('config_key', $key)
                    ->value('config_value');
                $cached = (string) ($raw ?? '');
            } catch (\Throwable $e) {
                \think\facade\Log::warning('[captcha] scenes 查询失败，按开启处理: ' . $e->getMessage());
                $cached = '';
            }
            \think\facade\Cache::set('cfg_' . $key, $cached, 10);
        }
        if ($cached === '') {
            return true; // 未配置 → 默认开启
        }
        $map = json_decode($cached, true);
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
