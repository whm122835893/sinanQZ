<?php
declare(strict_types=1);

namespace tests;

/**
 * 链路 2：实名认证（人工审核 / 自动通过 / 驳回重提）
 */
class RealnameFlowTest extends ApiTestCase
{
    private const NAME = '测试实名';
    private const IDCARD = '110101199003077578';

    protected function tearDown(): void
    {
        // 还原默认 manual 模式，避免影响其他用例
        $this->exec("UPDATE nft_system_configs SET config_value = 'manual' WHERE config_key = 'realname_audit_mode'");
        parent::tearDown();
    }

    public function testManualSubmitThenAdminApprove(): void
    {
        $phone = $this->phone();
        $uid   = fn () => (int) $this->fetch("SELECT id FROM nft_users WHERE phone = ?", [$phone])['id'];
        $token = $this->registerAndLogin($phone);

        // 设置交易密码，越过交易密码校验，聚焦实名前置（订单校验顺序：交易密码 → 实名）
        $this->exec("UPDATE nft_users SET transaction_password = ? WHERE phone = ?", [
            password_hash(self::TX_PWD, PASSWORD_DEFAULT), $phone,
        ]);

        // 未实名时购买被拦截（实名是交易前置）
        $cid = $this->createCollectible(10.00);
        $blocked = $this->http('POST', '/api/orders', [
            'collectibleId' => $cid, 'quantity' => 1, 'paymentPassword' => self::TX_PWD,
        ], $token);
        $this->assertSame(1001, $blocked['code']);
        $this->assertSame('请先完成实名认证', $blocked['message']);

        // manual 模式提交 → pending
        $res = $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => self::IDCARD], $token);
        $data = $this->assertOk($res, '提交实名');
        $this->assertSame('pending', $data['status']);

        // profile 显示审核中
        $profile = $this->assertOk($this->http('GET', '/api/user/profile', null, $token), '获取用户信息');
        $this->assertSame(1, $profile['realnameStatus']);

        // 管理员审核通过
        $admin = $this->adminLogin();
        $audit = $this->assertOk(
            $this->http('POST', '/admin/realname/audit', ['user_id' => $uid(), 'action' => 'approve'], $admin),
            '管理员审核通过'
        );
        $this->assertSame('approved', $audit['status']);

        // 落库断言：状态 + 解锁 + 密文落库
        $row = $this->fetch("SELECT realname_status, is_realname, realname_verified_at, real_name, id_card FROM nft_users WHERE id = ?", [$uid()]);
        $this->assertSame(2, (int) $row['realname_status']);
        $this->assertSame(1, (int) $row['is_realname']);
        $this->assertNotNull($row['realname_verified_at']);
        $this->assertNotEmpty($row['real_name'], '姓名应 AES 密文落库');
        $this->assertNotSame(self::NAME, $row['real_name'], '姓名不得明文存储');

        // 审核通过后购买前置放行
        $after = $this->http('POST', '/api/orders', [
            'collectibleId' => $cid, 'quantity' => 1, 'paymentPassword' => self::TX_PWD,
        ], $token);
        $this->assertNotSame('请先完成实名认证', $after['message']);
    }

    public function testAdminRejectThenResubmit(): void
    {
        $phone = $this->phone();
        $uid   = fn () => (int) $this->fetch("SELECT id FROM nft_users WHERE phone = ?", [$phone])['id'];
        $token = $this->registerAndLogin($phone);
        $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => self::IDCARD], $token),
            '提交实名'
        );

        // 驳回 + 原因
        $admin = $this->adminLogin();
        $this->assertOk(
            $this->http('POST', '/admin/realname/audit', [
                'user_id' => $uid(), 'action' => 'reject', 'reason' => '证件照片模糊',
            ], $admin),
            '管理员驳回'
        );
        $profile = $this->assertOk($this->http('GET', '/api/user/profile', null, $token), '获取用户信息');
        $this->assertSame(3, $profile['realnameStatus']);
        $this->assertSame('证件照片模糊', $profile['realnameRejectReason']);

        // 重新提交 → 再次进入待审核
        $res = $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => self::IDCARD], $token);
        $this->assertSame('pending', $this->assertOk($res, '重新提交')['status']);
    }

    public function testAutoModeApprovesImmediately(): void
    {
        // 管理端切换为自动通过
        $admin = $this->adminLogin();
        $this->assertOk(
            $this->http('PUT', '/admin/system/configs/realname_audit_mode', ['config_value' => 'auto'], $admin),
            '切换自动审核'
        );

        $phone = $this->phone();
        $uid   = fn () => (int) $this->fetch("SELECT id FROM nft_users WHERE phone = ?", [$phone])['id'];
        $token = $this->registerAndLogin($phone);
        $res = $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => self::IDCARD], $token);
        $data = $this->assertOk($res, '自动通过');
        $this->assertSame('approved', $data['status']);

        $row = $this->fetch("SELECT realname_status, is_realname, realname_verified_at FROM nft_users WHERE id = ?", [$uid()]);
        $this->assertSame(2, (int) $row['realname_status']);
        $this->assertSame(1, (int) $row['is_realname']);
        $this->assertNotNull($row['realname_verified_at']);
    }

    public function testInvalidAuditModeRejected(): void
    {
        $admin = $this->adminLogin();
        $res = $this->http('PUT', '/admin/system/configs/realname_audit_mode', ['config_value' => 'xxx'], $admin);
        $this->assertSame(4220, $res['code'], '非法审核模式应被拦截');
    }
}
