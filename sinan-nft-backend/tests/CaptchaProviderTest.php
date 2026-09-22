<?php
declare(strict_types=1);

namespace tests;

/**
 * 验证码服务商集成测试（HTTP 级黑盒）
 *
 * 所有配置变更均通过管理端 PUT /admin/system/security-config/:key 接口，
 * 服务器内部会自动 Cache::delete('cfg_*') 清缓存，确保后续请求读到最新值。
 */
class CaptchaProviderTest extends \tests\ApiTestCase
{
    private const PROV_KEY   = 'captcha.provider';
    private const SCENES_KEY = 'captcha.scenes';
    private const ENABLE_KEY = 'captcha.enable';

    private ?string $adminToken = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // 基线：provider=local、总开关=1（captcha 测试需要）、场景全开（admin_login=0 避开管理端登录拦截）
        self::pdo()->prepare("UPDATE nft_system_configs SET config_value='local' WHERE config_key=?")
            ->execute([self::PROV_KEY]);
        self::pdo()->prepare("UPDATE nft_system_configs SET config_value='1' WHERE config_key=?")
            ->execute([self::ENABLE_KEY]);
        self::pdo()->prepare("UPDATE nft_system_configs SET config_value=? WHERE config_key=?")
            ->execute([json_encode([
                'auth_login_password' => '1',
                'auth_login_sms'      => '1',
                'auth_register'       => '1',
                'auth_forgot'         => '1',
                'user_change_pwd'     => '1',
                'user_op_pwd'         => '1',
                'user_cancel'         => '1',
                'admin_login'         => '0',
            ], JSON_UNESCAPED_UNICODE), self::SCENES_KEY]);
    }

    /** 套件结束后复原 captcha.enable=0（其他测试基线，开发默认关闭） */
    public static function tearDownAfterClass(): void
    {
        self::pdo()->prepare("UPDATE nft_system_configs SET config_value='0' WHERE config_key=?")
            ->execute([self::ENABLE_KEY]);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminToken = $this->adminLogin();

        // 先 PDO 清阿里云残留（securitySave 禁止写空串），再用 saveCfg 写主开关/provider/scenes
        foreach (['captcha.aliyun.scene_id', 'captcha.aliyun.prefix',
            'captcha.aliyun.access_key_id', 'captcha.aliyun.access_key_secret'] as $k) {
            $this->pdo()->prepare("UPDATE nft_system_configs SET config_value='' WHERE config_key=?")->execute([$k]);
        }

        // security-save 会 Cache::delete，让服务器下一次请求直查 DB
        $this->saveCfg(self::ENABLE_KEY, '1');
        $this->saveCfg(self::PROV_KEY, 'local');
        $this->saveCfg(self::SCENES_KEY, json_encode([
            'auth_login_password' => '1',
            'auth_login_sms'      => '1',
            'auth_register'       => '1',
            'auth_forgot'         => '1',
            'user_change_pwd'     => '1',
            'user_op_pwd'         => '1',
            'user_cancel'         => '1',
            'admin_login'         => '0',
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        try {
            foreach (['captcha.aliyun.scene_id', 'captcha.aliyun.prefix',
                'captcha.aliyun.access_key_id', 'captcha.aliyun.access_key_secret'] as $k) {
                $this->pdo()->prepare("UPDATE nft_system_configs SET config_value='' WHERE config_key=?")->execute([$k]);
            }
            $this->http('PUT', '/admin/system/security-config/' . self::PROV_KEY,
                ['value' => 'local'], $this->adminToken ?? '');
        } catch (\Throwable) {}
        parent::tearDown();
    }

    /** 通过 security-save 设单参数（内部自动清服务器缓存） */
    private function saveCfg(string $key, string $value): void
    {
        $res = $this->http('PUT', '/admin/system/security-config/' . $key,
            ['value' => $value], $this->adminToken ?? '');
        $this->assertContains($res['code'] ?? null, [0, 200],
            "设置 {$key} 应成功：" . json_encode($res, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 配好阿里云 4 个参数（全部走 security-save：明文 scene_id/prefix 直接写，
     * secret 类型由服务器内部 aes_encrypt 落库，确保密钥与运行时一致）。
     * security-save 内部 Cache::delete 让 aliyunConfig() 每次直查 DB，无需额外清缓存。
     */
    private function prepareAliyunParams(): void
    {
        $this->saveCfg('captcha.aliyun.scene_id', 'demo_scene');
        $this->saveCfg('captcha.aliyun.prefix', 'demo_prefix');
        $this->saveCfg('captcha.aliyun.access_key_id', 'LTAIdemoAKL');
        $this->saveCfg('captcha.aliyun.access_key_secret', 'demoSecret_12345678');
    }

    // ========== 测试 ==========

    /** 默认 local + 非法值回退 local */
    public function testDefaultAndInvalidProviderFallBackToLocal(): void
    {
        // 用 security-save 存 local（清缓存），让后续 enabled 读 DB
        $this->saveCfg(self::PROV_KEY, 'local');
        $data = $this->assertOk($this->http('GET', '/api/captcha/enabled'), '默认探测');
        $this->assertSame('local', $data['provider']);

        // 非法值
        $this->pdo()->prepare("INSERT INTO nft_system_configs (config_key, config_value, description)
            VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)")
            ->execute([self::PROV_KEY, 'cloudflare', 'test']);
        $this->http('PUT', '/admin/system/security-config/' . self::PROV_KEY,
            ['value' => 'local'], $this->adminToken ?? '');
        $data = $this->assertOk($this->http('GET', '/api/captcha/enabled'), '非法值应回退');
        $this->assertSame('local', $data['provider']);
    }

    /** enabled 接口返回 aliyun 元数据 */
    public function testEnabledResponseIncludesAliyunMeta(): void
    {
        $this->prepareAliyunParams();
        $this->saveCfg(self::PROV_KEY, 'aliyun');

        $data = $this->assertOk(
            $this->http('GET', '/api/captcha/enabled?scene=auth_register'),
            'enabled 接口 aliyun 元数据'
        );
        $this->assertSame('aliyun', $data['provider']);
        $this->assertSame('demo_scene', $data['aliyun']['sceneId']);
        $this->assertSame('demo_prefix', $data['aliyun']['prefix']);
        $this->assertSame('cn', $data['aliyun']['region']);
    }

    /** 切 aliyun 前参数不齐备被拒绝 */
    public function testSwitchToAliyunRequiresFullAliyunConfig(): void
    {
        // 只配 scene_id，清空其他
        $this->saveCfg('captcha.aliyun.scene_id', 'partial_scene');
        $this->pdo()->prepare("UPDATE nft_system_configs SET config_value='' WHERE config_key='captcha.aliyun.prefix'")->execute();
        $this->pdo()->prepare("UPDATE nft_system_configs SET config_value='' WHERE config_key='captcha.aliyun.access_key_id'")->execute();
        $this->pdo()->prepare("UPDATE nft_system_configs SET config_value='' WHERE config_key='captcha.aliyun.access_key_secret'")->execute();
        // 触发缓存刷新
        $this->http('PUT', '/admin/system/security-config/' . self::PROV_KEY,
            ['value' => 'local'], $this->adminToken ?? '');

        $res = $this->http('PUT', '/admin/system/security-config/' . self::PROV_KEY,
            ['value' => 'aliyun'], $this->adminToken ?? '');
        $this->assertContains($res['code'] ?? null, [4220, 14220], '参数不齐备时应拒绝切换');
        $this->assertStringContainsString('阿里云', $res['message'] ?? '');
    }

    /** aliyun 模式下缺失 captcha_verify_param 被拦截 */
    public function testAliyunModeRejectsMissingVerifyParam(): void
    {
        $this->prepareAliyunParams();
        $this->saveCfg(self::PROV_KEY, 'aliyun');

        $phone = $this->phone();
        $res = $this->http('POST', '/api/auth/send-code', ['phone' => $phone, 'scene' => 'register']);
        $this->assertNotContains($res['code'] ?? null, [0, 200], 'aliyun 模式应拦截缺失 captcha_verify_param');
        $this->assertStringContainsString('滑块', $res['message'] ?? '',
            '错误提示应含滑块，实际：' . ($res['message'] ?? ''));
    }

    /** local 模式图形码图片仍能正常拉取 */
    public function testLocalModeImageFlow(): void
    {
        $this->saveCfg(self::PROV_KEY, 'local');
        // 场景 auth_register 已在 setUpBeforeClass 开了

        $img = $this->assertOk(
            $this->http('GET', '/api/captcha/image?scene=auth_register'),
            '拉图片'
        );
        $this->assertNotEmpty($img['captcha_id'] ?? null);
        $this->assertNotEmpty($img['image'] ?? null);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $img['image']);

        // verify 接口：错误验证码 ok=false
        $res = $this->http('POST', '/api/captcha/verify', [
            'captcha_id' => $img['captcha_id'],
            'captcha_code' => 'WRONG',
        ]);
        $this->assertContains($res['code'] ?? null, [0, 200]);
        $this->assertSame(false, ($res['data']['ok'] ?? null));
    }
}
