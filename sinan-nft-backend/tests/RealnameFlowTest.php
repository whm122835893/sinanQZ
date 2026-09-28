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

    /**
     * 实名信息一经审核通过即与账号身份绑定，任何入口都不得再改
     */
    public function testApprovedRealnameCannotBeChanged(): void
    {
        $phone = $this->phone();
        $uid   = fn () => (int) $this->fetch("SELECT id FROM nft_users WHERE phone = ?", [$phone])['id'];
        $token = $this->registerAndLogin($phone);
        $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => self::IDCARD], $token),
            '提交实名'
        );

        $admin = $this->adminLogin();
        $this->assertOk(
            $this->http('POST', '/admin/realname/audit', ['user_id' => $uid(), 'action' => 'approve'], $admin),
            '管理员审核通过'
        );

        $before = $this->fetch("SELECT real_name, id_card, is_realname, realname_status FROM nft_users WHERE id = ?", [$uid()]);
        $this->assertNotEmpty($before['real_name']);

        // 换一套材料重新提交：必须拒绝，且密文原封不动
        $res = $this->http('POST', '/api/user/realname', [
            'realName' => '冒用姓名', 'idCard' => '310101198801011234',
        ], $token);
        $this->assertSame(1001, $res['code'], '已实名用户重新提交应被拒绝');
        $this->assertSame('实名认证已通过，实名信息不可修改，如需变更请联系客服', $res['message']);

        $after = $this->fetch("SELECT real_name, id_card, is_realname, realname_status FROM nft_users WHERE id = ?", [$uid()]);
        $this->assertSame($before['real_name'], $after['real_name'], '姓名密文不得被覆盖');
        $this->assertSame($before['id_card'], $after['id_card'], '身份证密文不得被覆盖');
        $this->assertSame(1, (int) $after['is_realname'], '实名能力不得被撤销');
        $this->assertSame(2, (int) $after['realname_status'], '工作流态不得回退到待审核');

        // 自动通过模式同样不得绕过该限制
        $this->assertOk(
            $this->http('PUT', '/admin/system/configs/realname_audit_mode', ['config_value' => 'auto'], $admin),
            '切换自动审核'
        );
        $res = $this->http('POST', '/api/user/realname', [
            'realName' => '冒用姓名', 'idCard' => '310101198801011234',
        ], $token);
        $this->assertSame(1001, $res['code'], 'auto 模式下已实名用户同样拒绝');
        $after = $this->fetch("SELECT real_name, id_card FROM nft_users WHERE id = ?", [$uid()]);
        $this->assertSame($before['real_name'], $after['real_name']);
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

        // 能力位与工作流态必须成对（qa 夹具当年只写 is_realname，C 端两页就自相矛盾）：
        // 驳回即收回交易能力，realname_status=3 供后台「已驳回」列表与 C 端驳回原因取数
        $row = $this->fetch("SELECT is_realname, realname_status FROM nft_users WHERE id = ?", [$uid()]);
        $this->assertSame(0, (int) $row['is_realname'], '驳回后应失去实名能力');
        $this->assertSame(3, (int) $row['realname_status'], '驳回后工作流态应为已驳回');

        // 重新提交 → 再次进入待审核
        $res = $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => self::IDCARD], $token);
        $this->assertSame('pending', $this->assertOk($res, '重新提交')['status']);

        $row = $this->fetch("SELECT is_realname, realname_status FROM nft_users WHERE id = ?", [$uid()]);
        $this->assertSame(0, (int) $row['is_realname'], '待审核期间不应有实名能力');
        $this->assertSame(1, (int) $row['realname_status'], '重新提交应回到待审核');
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

    /**
     * 姓名格式：后端与 C 端 Realname.vue 的 /^[\u4e00-\u9fa5·a-zA-Z]{2,15}$/ 同口径
     * （原先 strlen() 数字节，1 个汉字或 "123456" 都能通过）
     */
    public function testRealNameFormatMatchesFrontendRule(): void
    {
        $phone = $this->phone();
        $token = $this->registerAndLogin($phone);

        foreach ([
            '单个汉字'      => '张',
            '纯数字'        => '123456',
            '含空格'        => '张 伟',
            '含标点'        => '张·伟!@#',
            '超长 16 字'    => '张'.str_repeat('伟', 15),
            '空字符串'      => '',
        ] as $label => $badName) {
            $res = $this->http('POST', '/api/user/realname', ['realName' => $badName, 'idCard' => self::IDCARD], $token);
            $this->assertSame(1001, $res['code'], "{$label} 应被拒绝");
            $this->assertSame('请输入 2-15 位真实姓名', $res['message'], "{$label} 应命中姓名校验");
        }

        // 被拒的提交不得留下任何审核痕迹（不能先落库再校验）
        $row = $this->fetch("SELECT real_name, realname_status FROM nft_users WHERE phone = ?", [$phone]);
        $this->assertSame('', (string) $row['real_name'], '格式非法时不得写入证件');
        $this->assertSame(0, (int) $row['realname_status']);

        // 合法：2 字中文
        $data = $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => '张三', 'idCard' => self::IDCARD], $token),
            '「张三」应通过'
        );
        $this->assertSame('pending', $data['status']);

        // 含中文间隔号的少数民族姓名（另一个账号，避免撞上「审核中不得重复提交」）
        $phone2 = $this->phone();
        $token2 = $this->registerAndLogin($phone2);
        $data = $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => ' 阿卜杜拉·买买提 ', 'idCard' => self::IDCARD], $token2),
            '「阿卜杜拉·买买提」应通过'
        );
        $this->assertSame('pending', $data['status']);

        $stored = aes_decrypt((string) $this->fetch("SELECT real_name FROM nft_users WHERE phone = ?", [$phone2])['real_name']);
        $this->assertSame('阿卜杜拉·买买提', $stored, '前后空格应被 trim 掉');
    }

    public function testInvalidAuditModeRejected(): void
    {
        $admin = $this->adminLogin();
        $res = $this->http('PUT', '/admin/system/configs/realname_audit_mode', ['config_value' => 'xxx'], $admin);
        $this->assertSame(4220, $res['code'], '非法审核模式应被拦截');
    }
}
