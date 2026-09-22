<?php
declare(strict_types=1);

namespace tests;

/**
 * 链路 3+4：交易（发行购买 → 转赠 → 寄售挂单/购买）
 *
 * 余额支付依赖交易密码二次校验；寄售费率 1%（full_init 基线）
 */
class TradeFlowTest extends ApiTestCase
{
    public function testPrimarySalePayAndHold(): void
    {
        $buyer = $this->createUser(100.00);
        $cid   = $this->createCollectible(10.00);

        // 1. 下单（5 分钟有效期，下单即校验交易密码）
        $order = $this->assertOk(
            $this->http('POST', '/api/orders', [
                'collectibleId' => $cid, 'quantity' => 2, 'paymentPassword' => self::TX_PWD,
            ], $buyer['token']),
            '下单'
        );
        $this->assertSame(20.0, (float) $order['totalPrice']);
        $this->assertNotEmpty($order['orderNo']);

        // 2. 错误交易密码被拒
        $wrong = $this->http('POST', "/api/orders/{$order['orderNo']}/pay",
            ['paymentMethod' => 'balance', 'paymentPassword' => 'Wrong@1'], $buyer['token']);
        $this->assertNotSame(0, $wrong['code'], '错误交易密码应被拒绝');

        // 3. 余额支付成功
        $this->assertOk(
            $this->http('POST', "/api/orders/{$order['orderNo']}/pay",
                ['paymentMethod' => 'balance', 'paymentPassword' => self::TX_PWD], $buyer['token']),
            '余额支付'
        );

        // 4. 落库断言：订单完成 + 持仓 2 份 + 余额扣减 + 库存联动
        $orderRow = $this->fetch("SELECT status, quantity FROM nft_orders WHERE order_no = ?", [$order['orderNo']]);
        $this->assertSame('completed', $orderRow['status']);
        $this->assertSame(2, (int) $orderRow['quantity']);

        $held = $this->fetch(
            "SELECT COUNT(*) c FROM nft_user_collectibles WHERE user_id = ? AND collectible_id = ? AND status = 'held'",
            [$buyer['userId'], $cid]
        );
        $this->assertSame(2, (int) $held['c'], '应持有 2 份');

        $wallet = $this->fetch("SELECT balance, available FROM nft_wallets WHERE user_id = ?", [$buyer['userId']]);
        $this->assertEquals(80.0, (float) $wallet['balance']);
        $this->assertEquals(80.0, (float) $wallet['available']);

        $coll = $this->fetch("SELECT sold FROM nft_collectibles WHERE id = ?", [$cid]);
        $this->assertSame(2, (int) $coll['sold'], '发行售出数应联动 +2');
    }

    public function testTransferAcceptFlow(): void
    {
        $sender = $this->createUser(100.00);
        $this->buy($sender, $cid = $this->createCollectible(10.00), 1);
        $receiver = $this->createUser();

        $uc = $this->fetch(
            "SELECT id FROM nft_user_collectibles WHERE user_id = ? AND collectible_id = ? AND status = 'held'",
            [$sender['userId'], $cid]
        );
        $ucId = (int) $uc['id'];

        // 1. 发起转赠（资产冻结）
        $res = $this->assertOk(
            $this->http('POST', '/api/transfers', [
                'userCollectibleId' => $ucId, 'toPhone' => $receiver['phone'], 'paymentPassword' => self::TX_PWD,
            ], $sender['token']),
            '发起转赠'
        );
        $this->assertSame('pending', $res['status']);
        $frozen = $this->fetch("SELECT status FROM nft_user_collectibles WHERE id = ?", [$ucId]);
        $this->assertSame('frozen', $frozen['status']);

        // 2. 受赠方收下
        $transferId = (int) $this->fetch("SELECT id FROM nft_transfers WHERE user_collectible_id = ?", [$ucId])['id'];
        $this->assertOk(
            $this->http('POST', "/api/transfers/{$transferId}/handle", ['action' => 'accept'], $receiver['token']),
            '接受转赠'
        );

        // 3. 持有人变更
        $moved = $this->fetch("SELECT user_id, status FROM nft_user_collectibles WHERE id = ?", [$ucId]);
        $this->assertSame($receiver['userId'], (int) $moved['user_id']);
        $this->assertSame('held', $moved['status']);
    }

    public function testResaleListAndBuy(): void
    {
        $seller = $this->createUser(100.00);
        $this->buy($seller, $cid = $this->createCollectible(10.00), 1);
        $buyer = $this->createUser(50.00);

        $ucId = (int) $this->fetch(
            "SELECT id FROM nft_user_collectibles WHERE user_id = ? AND collectible_id = ? AND status = 'held'",
            [$seller['userId'], $cid]
        )['id'];

        // 1. 挂单（15 元，1% 手续费 → 实收 14.85）
        $listing = $this->assertOk(
            $this->http('POST', '/api/resale/listings', [
                'userCollectibleId' => $ucId, 'price' => 15, 'paymentPassword' => self::TX_PWD,
            ], $seller['token']),
            '寄售挂单'
        );
        $this->assertEquals(0.15, (float) $listing['feeAmount']);
        $this->assertEquals(14.85, (float) $listing['actualAmount']);
        $consigned = $this->fetch("SELECT status, is_consigned FROM nft_user_collectibles WHERE id = ?", [$ucId]);
        $this->assertSame('consigned', $consigned['status']);

        // 2. 市场购买（走 resale 单）
        $order = $this->assertOk(
            $this->http('POST', '/api/orders', [
                'resaleListingId' => $listing['listingId'], 'paymentPassword' => self::TX_PWD,
            ], $buyer['token']),
            '购买寄售单'
        );
        $this->assertEquals(15.0, (float) $order['totalPrice']);
        $this->assertOk(
            $this->http('POST', "/api/orders/{$order['orderNo']}/pay",
                ['paymentMethod' => 'balance', 'paymentPassword' => self::TX_PWD], $buyer['token']),
            '支付寄售单'
        );

        // 3. 资产过户 + 双方钱包结算（买家 50-15=35；卖家 90+14.85=104.85）
        $moved = $this->fetch("SELECT user_id, status FROM nft_user_collectibles WHERE id = ?", [$ucId]);
        $this->assertSame($buyer['userId'], (int) $moved['user_id']);
        $this->assertSame('held', $moved['status']);

        $sellerWallet = $this->fetch("SELECT available FROM nft_wallets WHERE user_id = ?", [$seller['userId']]);
        $buyerWallet  = $this->fetch("SELECT available FROM nft_wallets WHERE user_id = ?", [$buyer['userId']]);
        $this->assertEquals(104.85, (float) $sellerWallet['available']);
        $this->assertEquals(35.0, (float) $buyerWallet['available']);

        $listingRow = $this->fetch("SELECT status FROM nft_resale_listings WHERE id = ?", [$listing['listingId']]);
        $this->assertSame('sold', $listingRow['status']);
    }

    public function testResaleCancelRestoresAsset(): void
    {
        $seller = $this->createUser(100.00);
        $this->buy($seller, $cid = $this->createCollectible(10.00), 1);

        $ucId = (int) $this->fetch(
            "SELECT id FROM nft_user_collectibles WHERE user_id = ? AND collectible_id = ? AND status = 'held'",
            [$seller['userId'], $cid]
        )['id'];
        $listing = $this->assertOk(
            $this->http('POST', '/api/resale/listings', [
                'userCollectibleId' => $ucId, 'price' => 12, 'paymentPassword' => self::TX_PWD,
            ], $seller['token']),
            '挂单'
        );

        // 下架 → 资产回到 held
        $this->assertOk(
            $this->http('POST', "/api/resale/listings/{$listing['listingId']}/cancel", null, $seller['token']),
            '下架'
        );
        $restored = $this->fetch("SELECT status FROM nft_user_collectibles WHERE id = ?", [$ucId]);
        $this->assertSame('held', $restored['status']);
    }

    /** 快捷：真实购买 N 份（下单 + 余额支付） */
    private function buy(array $user, int $cid, int $qty): void
    {
        $order = $this->assertOk(
            $this->http('POST', '/api/orders', [
                'collectibleId' => $cid, 'quantity' => $qty, 'paymentPassword' => self::TX_PWD,
            ], $user['token']),
            '下单'
        );
        $this->assertOk(
            $this->http('POST', "/api/orders/{$order['orderNo']}/pay",
                ['paymentMethod' => 'balance', 'paymentPassword' => self::TX_PWD], $user['token']),
            '支付'
        );
    }
}
