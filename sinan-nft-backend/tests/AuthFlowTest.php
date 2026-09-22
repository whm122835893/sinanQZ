<?php
declare(strict_types=1);

namespace tests;

/**
 * 链路 1：认证（验证码 → 注册 → 登录 → 用户信息）
 */
class AuthFlowTest extends ApiTestCase
{
    public function testRegisterLoginProfile(): void
    {
        $phone = $this->phone();

        // 1. 发送验证码（mock 渠道回传 debugCode）
        $sent = $this->assertOk(
            $this->http('POST', '/api/auth/send-code', ['phone' => $phone, 'scene' => 'register']),
            '发送验证码'
        );
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $sent['debugCode']);

        // 2. 注册
        $this->assertOk(
            $this->http('POST', '/api/auth/register', [
                'phone'    => $phone,
                'code'     => $sent['debugCode'],
                'password' => 'Abc123456',
                'nickname' => '链路测试员',
            ]),
            '注册'
        );

        // 3. 重复注册被拒
        $dup = $this->http('POST', '/api/auth/register', [
            'phone' => $phone, 'code' => '000000', 'password' => 'Abc123456',
        ]);
        $this->assertSame(1001, $dup['code'], '重复注册应被拒绝');

        // 4. 错误验证码被拒（新手机号）
        $fresh = $this->phone();
        $bad = $this->http('POST', '/api/auth/register', [
            'phone' => $fresh, 'code' => '000000', 'password' => 'Abc123456',
        ]);
        $this->assertSame(1001, $bad['code'], '错误验证码应被拒绝');

        // 5. 登录（密码错误被拒 → 正确登录）
        $wrong = $this->http('POST', '/api/auth/login', ['phone' => $phone, 'password' => 'Wrong123']);
        $this->assertNotSame(0, $wrong['code'], '错误密码应被拒绝');

        $token = $this->login($phone);
        $this->assertNotEmpty($token);

        // 6. profile：昵称/实名状态/钱包已初始化
        $profile = $this->assertOk($this->http('GET', '/api/user/profile', null, $token), '获取用户信息');
        $this->assertSame('链路测试员', $profile['nickname']);
        $this->assertFalse($profile['isRealName']);
        $this->assertSame(0, $profile['realnameStatus']);
        $this->assertNotEmpty($profile['uid']);
        $this->assertNotEmpty($profile['inviteCode']);

        // 7. 未登录访问被拒
        $anon = $this->http('GET', '/api/user/profile');
        $this->assertSame(2001, $anon['code'], '未登录应返回 2001');
    }

    public function testLoginWrongPasswordRejected(): void
    {
        $user = $this->createUser();
        $res = $this->http('POST', '/api/auth/login', ['phone' => $user['phone'], 'password' => 'Nope12345']);
        $this->assertNotSame(0, $res['code']);
    }
}
