<?php
declare(strict_types=1);

namespace tests;

/**
 * 缺 nft_wallets 行时的资金入口回归（全链路走查缺陷 13）
 *
 * 存量库存在「有用户、无钱包行」的数据（早期夹具与直连 SQL 播种，未走注册流程）。
 * 修复前 Wallet::recharge / Orders::pay / BuyRequest::accept 等处
 * lock(true)->find() 拿到 null 后直接取列，PHP 8 把该告警升级为 ErrorException，
 * 被业务 catch 转成 5001「操作失败」——充值 / 余额支付 / 结算对这批用户整体不可用。
 * 现在统一走 WalletService::ensureLocked：入账类入口就地开通空钱包，
 * 扣款类入口给出真实的「余额不足」。
 */
class MissingWalletFlowTest extends ApiTestCase
{
    private function assertNoWallet(int $userId, string $context): void
    {
        $this->assertNull(
            $this->fetch("SELECT id FROM nft_wallets WHERE user_id = ?", [$userId]),
            "{$context}：前置条件失效，测试用户不应有钱包行"
        );
    }

    private function dropWallet(int $userId): void
    {
        $this->exec("DELETE FROM nft_wallets WHERE user_id = ?", [$userId]);
        $this->assertNoWallet($userId, '删行后');
    }

    public function testRechargeProvisionsWalletRow(): void
    {
        $user = $this->createUser(0.0);
        $this->dropWallet($user['userId']);

        $data = $this->assertOk(
            $this->http('POST', '/api/wallet/recharge', ['amount' => 50], $user['token']),
            '无钱包行用户的模拟充值'
        );

        $wallet = $this->fetch("SELECT balance, available FROM nft_wallets WHERE user_id = ?", [$user['userId']]);
        $this->assertNotNull($wallet, '充值应就地开通钱包行，而不是回滚成 5001');
        $this->assertEquals(50.0, (float) $wallet['balance']);
        $this->assertEquals(50.0, (float) $wallet['available']);

        // 首笔流水的余额快照以 0 为基线（修复前正是在这里读 null 抛错）
        $tx = $this->fetch(
            "SELECT amount, balance_after FROM nft_wallet_transactions WHERE id = ?",
            [$data['transactionId']]
        );
        $this->assertEquals(50.0, (float) $tx['balance_after'], '空钱包入账后快照应等于本笔金额');
    }

    public function testPayWithoutWalletReportsInsufficientBalance(): void
    {
        $user  = $this->createUser(30.00);
        $cid   = $this->createCollectible(10.00);
        $order = $this->assertOk(
            $this->http('POST', '/api/orders', [
                'collectibleId' => $cid, 'quantity' => 1, 'paymentPassword' => self::TX_PWD,
            ], $user['token']),
            '下单'
        );

        // 下单不校验余额，支付才校验：此处删行模拟支付瞬间无钱包行的存量用户
        $this->dropWallet($user['userId']);

        $res = $this->http('POST', "/api/orders/{$order['orderNo']}/pay",
            ['paymentMethod' => 'balance', 'paymentPassword' => self::TX_PWD], $user['token']);
        $this->assertSame(
            4003,
            (int) ($res['code'] ?? 0),
            '无钱包行应给出「余额不足」而非 5001 系统错误：' . json_encode($res, JSON_UNESCAPED_UNICODE)
        );
        $this->assertNoWallet($user['userId'], '支付失败回滚后');
    }

    public function testSellerSettlementProvisionsWalletRow(): void
    {
        $seller = $this->createUser(0.0);
        $cid    = $this->createCollectible(10.00);
        // 卖家先花 10 元买入 1 份（余额 0 → 10-10=0，此处用充值后余额走真实流程）
        $this->assertOk(
            $this->http('POST', '/api/wallet/recharge', ['amount' => 20], $seller['token']),
            '卖家充值以完成首发购买'
        );
        $this->buyRelease($seller, $cid, 1);
        $ucId = (int) $this->fetch(
            "SELECT id FROM nft_user_collectibles WHERE user_id = ? AND collectible_id = ? AND status = 'held'",
            [$seller['userId'], $cid]
        )['id'];
        $listing = $this->assertOk(
            $this->http('POST', '/api/resale/listings', [
                'userCollectibleId' => $ucId, 'price' => 20, 'paymentPassword' => self::TX_PWD,
            ], $seller['token']),
            '寄售挂单'
        );

        // 成交结算前删掉卖家钱包行：结算必须能落账而不是抛错回滚
        $this->dropWallet($seller['userId']);

        $buyer = $this->createUser(50.00);
        $this->buyListing($buyer, (int) $listing['listingId']);

        // 费率 1%（full_init 基线）：到账 = 20 - 0.20
        $wallet = $this->fetch("SELECT balance, available FROM nft_wallets WHERE user_id = ?", [$seller['userId']]);
        $this->assertNotNull($wallet, '寄售成交结算应为卖家开通钱包行');
        $this->assertEquals(19.80, (float) $wallet['balance']);
        $this->assertEquals(19.80, (float) $wallet['available']);

        $tx = $this->fetch(
            "SELECT trans_type, balance_after FROM nft_wallet_transactions
              WHERE user_id = ? AND trans_type = 'reward' ORDER BY id DESC LIMIT 1",
            [$seller['userId']]
        );
        $this->assertNotNull($tx, '结算流水应落库');
        $this->assertEquals(19.80, (float) $tx['balance_after'], '空钱包结算后快照应等于到账额');
    }

    // ============================================================
    // 流程小工具
    // ============================================================

    /** 发行购买 N 份（下单 + 余额支付） */
    private function buyRelease(array $user, int $cid, int $quantity): void
    {
        $order = $this->assertOk(
            $this->http('POST', '/api/orders', [
                'collectibleId' => $cid, 'quantity' => $quantity, 'paymentPassword' => self::TX_PWD,
            ], $user['token']),
            '发行下单'
        );
        $this->pay($user, $order['orderNo']);
    }

    /** 购买指定寄售挂单 */
    private function buyListing(array $user, int $listingId): void
    {
        $order = $this->assertOk(
            $this->http('POST', '/api/orders', [
                'resaleListingId' => $listingId, 'paymentPassword' => self::TX_PWD,
            ], $user['token']),
            '市场下单'
        );
        $this->pay($user, $order['orderNo']);
    }

    private function pay(array $user, string $orderNo): void
    {
        $this->assertOk(
            $this->http('POST', "/api/orders/{$orderNo}/pay",
                ['paymentMethod' => 'balance', 'paymentPassword' => self::TX_PWD], $user['token']),
            '余额支付'
        );
    }
}
