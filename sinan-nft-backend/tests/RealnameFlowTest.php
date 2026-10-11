<?php
declare(strict_types=1);

namespace tests;

/**
 * 链路 2：实名认证（人工审核 / 自动通过 / 驳回重提）
 */
class RealnameFlowTest extends ApiTestCase
{
    private const NAME = '测试实名';
    /**
     * 固定证号：只给「就是同一张证号」语义的用例用。
     * 其余用例改用 $this->idCard()（每次一张新的合法证号）—— 2026-10-10 起一证一号，
     * 全类共用一个常量会让后一个账号被前一个挡住，测到的是去重而不是被测功能（假红）。
     */
    private const IDCARD = '110101199003077578';

    protected function setUp(): void
    {
        parent::setUp();
        // 显式钉住 manual：库里种子值自 2026-10-10 起改为 auto
        // （migrations/20261010_realname_audit_mode_auto.sql）。本类多数用例断言的是
        // 「提交后进待审核」这条人工链路，不钉住就会随默认值漂移而误报。
        $this->exec("UPDATE nft_system_configs SET config_value = 'manual' WHERE config_key = 'realname_audit_mode'");
    }

    protected function tearDown(): void
    {
        // 还原 auto（当前库内默认值），避免影响其他用例
        $this->exec("UPDATE nft_system_configs SET config_value = 'auto' WHERE config_key = 'realname_audit_mode'");
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
        $res = $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => $this->idCard()], $token);
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
            $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => $this->idCard()], $token),
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
            $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => $this->idCard()], $token),
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
        $res = $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => $this->idCard()], $token);
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
        $res = $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => $this->idCard()], $token);
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
        // 本用例只测姓名格式，两个账号共用一张证号没问题：待审核不占用证号（一证一号只认「认证通过」）
        $idCard = $this->idCard();

        foreach ([
            '单个汉字'      => '张',
            '纯数字'        => '123456',
            '含空格'        => '张 伟',
            '含标点'        => '张·伟!@#',
            '超长 16 字'    => '张'.str_repeat('伟', 15),
            '空字符串'      => '',
        ] as $label => $badName) {
            $res = $this->http('POST', '/api/user/realname', ['realName' => $badName, 'idCard' => $idCard], $token);
            $this->assertSame(1001, $res['code'], "{$label} 应被拒绝");
            $this->assertSame('请输入 2-15 位真实姓名', $res['message'], "{$label} 应命中姓名校验");
        }

        // 被拒的提交不得留下任何审核痕迹（不能先落库再校验）
        $row = $this->fetch("SELECT real_name, realname_status FROM nft_users WHERE phone = ?", [$phone]);
        $this->assertSame('', (string) $row['real_name'], '格式非法时不得写入证件');
        $this->assertSame(0, (int) $row['realname_status']);

        // 合法：2 字中文
        $data = $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => '张三', 'idCard' => $idCard], $token),
            '「张三」应通过'
        );
        $this->assertSame('pending', $data['status']);

        // 含中文间隔号的少数民族姓名（另一个账号，避免撞上「审核中不得重复提交」）
        $phone2 = $this->phone();
        $token2 = $this->registerAndLogin($phone2);
        $data = $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => ' 阿卜杜拉·买买提 ', 'idCard' => $idCard], $token2),
            '「阿卜杜拉·买买提」应通过'
        );
        $this->assertSame('pending', $data['status']);

        $stored = aes_decrypt((string) $this->fetch("SELECT real_name FROM nft_users WHERE phone = ?", [$phone2])['real_name']);
        $this->assertSame('阿卜杜拉·买买提', $stored, '前后空格应被 trim 掉');
    }

    /**
     * 身份证格式：后端与 C 端 Realname.vue 同口径
     *
     * 前端原先只校验「18 位数字 + 末位 X」，11010119901307757X（13 月）这种能过前端、
     * 提交才被后端弹回，报错晚一步；而且 auto 模式下格式校验是唯一防线，两端必须同一条正则。
     */
    public function testIdCardFormatMatchesFrontendRule(): void
    {
        $phone = $this->phone();
        $token = $this->registerAndLogin($phone);

        foreach ([
            '地区码首位为 0' => '010101199003077578',
            '月份 13'        => '11010119901307757X',
            '日期 34'        => '110101199003347578',
            '出生年 1234'    => '110101123403077578',
            '只有 17 位'     => '11010119900307757',
            '乱填 18 位数字' => '123456789012345678',
            '末尾是字母 A'   => '11010119900307757A',
        ] as $label => $badId) {
            $res = $this->http('POST', '/api/user/realname', ['realName' => '张三', 'idCard' => $badId], $token);
            $this->assertSame(1001, $res['code'], "{$label} 应被拒绝");
            $this->assertSame('身份证号格式错误', $res['message'], "{$label} 应命中身份证校验");
        }

        // 被拒的提交不得先落库再校验
        $row = $this->fetch("SELECT id_card, realname_status FROM nft_users WHERE phone = ?", [$phone]);
        $this->assertSame('', (string) $row['id_card'], '格式非法时不得写入证件');
        $this->assertSame(0, (int) $row['realname_status']);

        // 合法：末位小写 x 也应接受（C 端提交前会 toUpperCase，后端不区分大小写、原样存）
        // 证号取本用例独有的那张，只把末位换成小写：固定字面值会被「一证一号」的其他夹具占掉
        $lowerId = substr($this->idCard(), 0, 17) . 'x';
        $data = $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => '张三', 'idCard' => $lowerId], $token),
            '末位小写 x 应通过'
        );
        $this->assertSame('pending', $data['status']);
        $stored = aes_decrypt((string) $this->fetch("SELECT id_card FROM nft_users WHERE phone = ?", [$phone])['id_card']);
        $this->assertSame($lowerId, $stored, '原样存小写，不擅自改写用户输入');
    }

    // ============================================================
    // 一证一号（2026-10-10）：同一张身份证只能认证一个账号
    // 背景：id_card 是随机 IV 的密文，同一证号两次密文不同，密文列上加唯一索引拦不住重复，
    //      所以比对列是 id_card_hash = HMAC(归一化证号, APP_KEY)，判定收敛在 RealnameService::approve()。
    // ============================================================

    /**
     * auto 模式：第二张同证号的账号必须被拒，且库里不留任何实名痕迹
     */
    public function testSameIdCardCannotVerifyTwoAccountsInAutoMode(): void
    {
        $admin = $this->adminLogin();
        $this->assertOk(
            $this->http('PUT', '/admin/system/configs/realname_audit_mode', ['config_value' => 'auto'], $admin),
            '切换自动审核'
        );

        $idCard = $this->idCard();
        $phoneA = $this->phone();
        $tokenA = $this->registerAndLogin($phoneA);
        $a = $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => $idCard], $tokenA),
            'A 先用这张证号认证'
        );
        $this->assertSame('approved', $a['status']);

        $phoneB = $this->phone();
        $tokenB = $this->registerAndLogin($phoneB);
        $res = $this->http('POST', '/api/user/realname', ['realName' => '测试乙', 'idCard' => $idCard], $tokenB);
        $this->assertSame(1001, $res['code'], '同一证号的第二个账号必须被拒');
        // 断言字面提示而非取常量：文案改动要被人看见；这句话必须与 RealnameService::OCCUPIED_MSG_PUBLIC 一致
        $this->assertSame('该身份证已绑定其他账号，如需处理请联系客服', $res['message']);
        $this->assertStringNotContainsString('UID', $res['message'], 'C 端不得透出对方 UID（防拿报错接口探测他人实名）');

        // 被拒的账号不能留下任何审核痕迹或能力
        $rowB = $this->fetch("SELECT realname_status, is_realname, id_card, id_card_hash FROM nft_users WHERE phone = ?", [$phoneB]);
        $this->assertSame(0, (int) $rowB['realname_status'], '被拒不应进入审核流程');
        $this->assertSame(0, (int) $rowB['is_realname'], '被拒不应拿到实名能力');
        $this->assertSame('', (string) $rowB['id_card'], '被拒不得写入证件');
        $this->assertNull($rowB['id_card_hash'], '被拒不得写指纹');

        // A 的指纹按当前密钥落库（后面 realname:id-hash 统计依赖它）
        $rowA = $this->fetch("SELECT id_card_hash FROM nft_users WHERE phone = ?", [$phoneA]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rowA['id_card_hash'], '认证通过必须写指纹');
        $this->assertSame(id_card_fingerprint($idCard), $rowA['id_card_hash'], '指纹口径必须与 realname:id-hash 一致');

        // B 换一张自己的证号照样能认证：拦的是「证号重复」，不是这个账号
        $b = $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => '测试乙', 'idCard' => $this->idCard()], $tokenB),
            'B 用自己的证号仍可认证'
        );
        $this->assertSame('approved', $b['status']);
    }

    /**
     * 末位 x / X 归一为同一张证号（后端存原文不改写，只在比对时归一）
     */
    public function testLowercaseXCountsAsTheSameIdCard(): void
    {
        $admin = $this->adminLogin();
        $this->assertOk(
            $this->http('PUT', '/admin/system/configs/realname_audit_mode', ['config_value' => 'auto'], $admin),
            '切换自动审核'
        );

        $upper = $this->idCard();                  // idCard() 末位固定是大写 X
        $lower = substr($upper, 0, 17) . 'x';
        $this->assertNotSame($upper, $lower, '两个写法字面不同，只差末位大小写');

        $tokenA = $this->registerAndLogin($this->phone());
        $this->assertSame('approved', $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => self::NAME, 'idCard' => $upper], $tokenA),
            'A 用大写 X 认证'
        )['status']);

        $tokenB = $this->registerAndLogin($this->phone());
        $res = $this->http('POST', '/api/user/realname', ['realName' => '测试乙', 'idCard' => $lower], $tokenB);
        $this->assertSame(1001, $res['code'], '小写 x 与大写 X 是同一张证号，第二个账号必须被拦');
        $this->assertSame('该身份证已绑定其他账号，如需处理请联系客服', $res['message']);
    }

    /**
     * 占用口径：待审核不占坑（两个账号可以同时提交同一证号），
     * 但管理端点「通过」时由 RealnameService 兜住第二个，账号留在待审队列并写审计
     */
    public function testPendingDoesNotOccupyButAdminApproveBlocksDuplicate(): void
    {
        // setUp 已把审核模式钉在 manual
        $idCard = $this->idCard();
        $phoneA = $this->phone();
        $phoneB = $this->phone();
        $tokenA = $this->registerAndLogin($phoneA);
        $tokenB = $this->registerAndLogin($phoneB);

        $uidOf = fn (string $phone) => (string) $this->fetch("SELECT uid FROM nft_users WHERE phone = ?", [$phone])['uid'];
        $idOf  = fn (string $phone) => (int) $this->fetch("SELECT id FROM nft_users WHERE phone = ?", [$phone])['id'];

        // 两个账号提交同一证号，都只到「待审核」——认证通过才占用，所以第二个不会被挡
        foreach ([$tokenA, $tokenB] as $i => $token) {
            $res = $this->assertOk(
                $this->http('POST', '/api/user/realname', ['realName' => $i ? '测试乙' : self::NAME, 'idCard' => $idCard], $token),
                "第 " . ($i + 1) . " 个账号提交同一证号"
            );
            $this->assertSame('pending', $res['status'], '待审核不占用证号，真主人不该被抢注者锁在门外');
        }

        $admin = $this->adminLogin();
        $ok = $this->assertOk(
            $this->http('POST', '/admin/realname/audit', ['user_id' => $idOf($phoneA), 'action' => 'approve'], $admin),
            '先通过 A'
        );
        $this->assertSame('approved', $ok['status']);

        // B 还在队列里，管理员点通过时被一证一号拦住：报错带对方 UID（管理端可见），状态不动
        $res = $this->http('POST', '/admin/realname/audit', ['user_id' => $idOf($phoneB), 'action' => 'approve'], $admin);
        $this->assertSame(4220, $res['code'], '人工通过同一证号的第二个账号应被拦');
        $this->assertStringContainsString($uidOf($phoneA), $res['message'], '管理端提示要写明撞在哪个账号上');

        $rowB = $this->fetch("SELECT realname_status, is_realname, id_card_hash FROM nft_users WHERE phone = ?", [$phoneB]);
        $this->assertSame(1, (int) $rowB['realname_status'], '被拦后仍留在待审队列，由人工决定');
        $this->assertSame(0, (int) $rowB['is_realname'], '被拦不应拿到实名能力');
        $this->assertNull($rowB['id_card_hash'], '被拦不写指纹');

        // 拦截要留痕：运营能在操作日志里看到「谁撞了谁的证号」
        $log = $this->fetch(
            "SELECT action, action_desc FROM nft_admin_operation_logs
              WHERE module = 'realname' AND action = 'audit_conflict' AND target_id = ? ORDER BY id DESC LIMIT 1",
            [$idOf($phoneB)]
        );
        $this->assertNotNull($log, '一证一号拦截应写审计日志');
        $this->assertStringContainsString('一证一号', (string) $log['action_desc']);

        // 驳回 B，让它拿自己的证号重来：证明拦的是重复而不是这个账号
        $this->assertOk(
            $this->http('POST', '/admin/realname/audit', [
                'user_id' => $idOf($phoneB), 'action' => 'reject', 'reason' => '该证件已绑定其他账号',
            ], $admin),
            '驳回 B'
        );
        $res = $this->assertOk(
            $this->http('POST', '/api/user/realname', ['realName' => '测试乙', 'idCard' => $this->idCard()], $tokenB),
            'B 用自己的证号重新提交'
        );
        $this->assertSame('pending', $res['status']);
    }

    public function testInvalidAuditModeRejected(): void
    {
        $admin = $this->adminLogin();
        $res = $this->http('PUT', '/admin/system/configs/realname_audit_mode', ['config_value' => 'xxx'], $admin);
        $this->assertSame(4220, $res['code'], '非法审核模式应被拦截');
    }
}
