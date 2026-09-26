<?php
declare(strict_types=1);

namespace tests;

/**
 * 合并演练后新增的安全回归用例
 *
 * 钉住两处与 origin/main 合并时引入/暴露的 Critical：
 *   1. nft_users.logout_before 是 UTC 语义列（JwtAuth 按 UTC 解析后与令牌 iat 比较），
 *      管理端三处写入必须是 gmdate；写成服务器本地时间会让强制登出多锁 8 小时。
 *   2. refunds.status 占用额度只认 1待审批/2已批准/3已退款，4=已拒绝必须放行再次发起。
 */
class MergedSecurityRegressionTest extends ApiTestCase
{
    public function testForceLogoutStampsUtcAndFreshTokenWorks(): void
    {
        $user  = $this->createUser();
        $admin = $this->adminLogin();

        $this->assertOk(
            $this->http('POST', "/admin/users/{$user['userId']}/force-logout",
                ['reason' => 'UTC 口径回归'], $admin),
            '强制登出'
        );

        $stamp = (string) $this->fetch("SELECT logout_before FROM nft_users WHERE id = ?", [$user['userId']])['logout_before'];
        $this->assertNotSame('', $stamp, '强制登出应写入 logout_before');

        // 按 UTC 解析后应贴近当前瞬时；回退成 date()（Asia/Shanghai）会整体偏移 +8h
        $ts = strtotime($stamp . ' UTC');
        $this->assertNotFalse($ts, 'logout_before 应为 YYYY-MM-DD HH:mm:ss：' . $stamp);
        $this->assertLessThan(
            120,
            abs(time() - $ts),
            "logout_before 必须是 UTC 瞬时（实测偏移 " . (time() - $ts) . 's，本地时间写入会偏 28800s）'
        );

        // 强制登出前签发的令牌立即失效
        $stale = $this->http('GET', '/api/user/profile', null, $user['token']);
        $this->assertSame(2001, (int) ($stale['code'] ?? 0), '旧令牌应被拒绝：' . json_encode($stale, JSON_UNESCAPED_UNICODE));

        // 紧接着重新登录必须可用（UTC 口径下 iat 很快越过 logout_before；
        // 若写成本地时间，新令牌会被“未来”的 logout_before 继续锁 8 小时）
        sleep(2);
        $fresh = $this->http('GET', '/api/user/profile', null, $this->login($user['phone']));
        $this->assertSame(0, (int) ($fresh['code'] ?? 0), '重新登录后应立即恢复访问：' . json_encode($fresh, JSON_UNESCAPED_UNICODE));
    }

    public function testRejectedRefundFreesOrderForNewRefund(): void
    {
        $user  = $this->createUser(100.0);
        $cid   = $this->createCollectible(10.0);
        $order = $this->assertOk(
            $this->http('POST', '/api/orders', [
                'collectibleId' => $cid, 'quantity' => 1, 'paymentPassword' => self::TX_PWD,
            ], $user['token']),
            '下单'
        );
        $this->assertOk(
            $this->http('POST', "/api/orders/{$order['orderNo']}/pay",
                ['paymentMethod' => 'balance', 'paymentPassword' => self::TX_PWD], $user['token']),
            '支付'
        );
        $oid = (int) $this->fetch("SELECT id FROM nft_orders WHERE order_no = ?", [$order['orderNo']])['id'];
        $admin = $this->adminLogin();

        $first = $this->assertOk(
            $this->http('POST', "/admin/orders/{$oid}/refund", ['id' => $oid, 'reason' => '首次发起'], $admin),
            '退款发起'
        );
        $rf1 = (int) $first['refund_id'];
        $this->assertGreaterThan(0, $rf1);

        // 待审批单占用额度：并发/重复提交必须被拒
        $dup = $this->http('POST', "/admin/orders/{$oid}/refund", ['id' => $oid, 'reason' => '重复发起'], $admin);
        $this->assertSame(4220, (int) ($dup['code'] ?? 0), '待审批期间不应产生第二张退款单');

        $this->assertOk(
            $this->http('POST', "/admin/refunds/{$rf1}/approve",
                ['id' => $rf1, 'action' => 'reject', 'comment' => '不满足退款条件'], $admin),
            '驳回退款'
        );
        $this->assertSame('4', (string) $this->fetch("SELECT status FROM nft_refunds WHERE id = ?", [$rf1])['status'], '驳回应写入 4=已拒绝');
        $this->assertSame('completed', $this->fetch("SELECT status FROM nft_orders WHERE id = ?", [$oid])['status'], '驳回后订单应回滚 completed');

        // 回归点：4=已拒绝不占额度，驳回后客服可再次发起（曾把 4 也计入占用而永久堵死）
        $second = $this->http('POST', "/admin/orders/{$oid}/refund", ['id' => $oid, 'reason' => '驳回后重新发起'], $admin);
        $data = $this->assertOk($second, '驳回后再次发起');
        $rf2 = (int) $data['refund_id'];
        $this->assertGreaterThan($rf1, $rf2, '再次发起应产生独立的新退款单');
        $this->assertSame('1', (string) $this->fetch("SELECT status FROM nft_refunds WHERE id = ?", [$rf2])['status'], '新单应为待审批');
        $this->assertSame('4', (string) $this->fetch("SELECT status FROM nft_refunds WHERE id = ?", [$rf1])['status'], '旧拒绝单应保持已拒绝，不被复用');

        // 收尾：第二单同样驳回，避免留下待审批单占用该订单
        $this->assertOk(
            $this->http('POST', "/admin/refunds/{$rf2}/approve",
                ['id' => $rf2, 'action' => 'reject', 'comment' => '测试收尾'], $admin),
            '收尾驳回'
        );
    }
}
