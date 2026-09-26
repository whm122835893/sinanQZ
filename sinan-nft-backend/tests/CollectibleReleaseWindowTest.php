<?php
declare(strict_types=1);

namespace tests;

/**
 * 链路 1.1：藏品发售窗口（开售时间 / 结束时间）
 *
 * 覆盖 aa71aea 的两处缺陷：
 *   1. 后台「发售时间」曾写入 release_date——全仓库没有任何读取方，运营填了等于没填；
 *      现在必须落在 onsale_at，C 端 saleTime 与下单校验都读这一列。
 *   2. 强制下架会把 off_sale_at 写成下架那一刻，重新上架若不清除，
 *      C 端会按已过期的结束时间把藏品渲染成「已售罄」且永远买不了。
 */
class CollectibleReleaseWindowTest extends ApiTestCase
{
    /** @return array{status: string, is_release: int, onsale_at: ?string, off_sale_at: ?string, release_date: ?string} */
    private function windowOf(int $id): array
    {
        $row = $this->fetch(
            "SELECT status, is_release, onsale_at, off_sale_at, release_date FROM nft_collectibles WHERE id = ?",
            [$id]
        );
        $this->assertNotNull($row, "藏品 #{$id} 应存在");
        return $row;
    }

    /** 走真实后台建品接口（名称保持 TEST- 前缀，tearDown 才会清掉） */
    private function createViaAdmin(string $admin, array $extra = []): int
    {
        $data = $this->assertOk($this->http('POST', '/admin/collectibles', array_merge([
            'name'        => 'TEST-' . uniqid(),
            'category_id' => 1,
            'image'       => '/test.png',
            'price'       => 10.00,
            'edition'     => 100,
        ], $extra), $admin), '后台创建藏品');
        return (int) $data['id'];
    }

    /** C 端发售区里的对应条目，未展示则 null */
    private function featuredItem(int $id): ?array
    {
        $data = $this->assertOk($this->http('GET', '/api/collections/featured?page=1&pageSize=100'), 'C 端发售区');
        foreach ($data['list'] ?? [] as $item) {
            if ((int) $item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /** @return array{code: int, message: string} 下单结果（只关心是否放行） */
    private function tryBuy(int $collectibleId): array
    {
        $buyer = $this->createUser(100.00);
        return $this->http('POST', '/api/orders', [
            'collectibleId'   => $collectibleId,
            'quantity'        => 1,
            'paymentPassword' => self::TX_PWD,
        ], $buyer['token']);
    }

    public function testFutureSaleTimeIsStoredOnOnsaleAtAndBlocksPurchase(): void
    {
        $admin  = $this->adminLogin();
        $future = time() + 7200;

        $id  = $this->createViaAdmin($admin, ['onsale_at' => date('Y-m-d H:i:s', $future)]);
        $row = $this->windowOf($id);
        // 运营填的时间必须落在真正生效的列上
        $this->assertSame($future, strtotime((string) $row['onsale_at']));
        $this->assertNull($row['release_date'], 'release_date 无读取方，不应再被写入');
        $this->assertSame('upcoming', $row['status']);

        // 上架但不传时间：已设定的未来时间要保留，否则定时开售会被上架动作冲成立即开售
        $this->assertOk(
            $this->http('POST', "/admin/collectibles/{$id}/release", ['status' => 'onsale'], $admin),
            '上架发售'
        );
        $row = $this->windowOf($id);
        $this->assertSame($future, strtotime((string) $row['onsale_at']), '未传 onsale_at 时应保留未来开售时间');
        $this->assertSame(1, (int) $row['is_release']);

        // 服务端拦截 + C 端拿到未来的发售时间（前端据此渲染倒计时）
        $rejected = $this->tryBuy($id);
        $this->assertSame(1001, $rejected['code']);
        $this->assertSame('尚未开售', $rejected['message']);

        $item = $this->featuredItem($id);
        $this->assertNotNull($item, '已上架藏品应出现在 C 端发售区');
        $this->assertSame($future, strtotime((string) $item['saleTime']));
    }

    public function testBlankSaleTimeOpensImmediatelyOnRelease(): void
    {
        $admin = $this->adminLogin();
        $id    = $this->createViaAdmin($admin, ['onsale_at' => '']);

        $this->assertNull($this->windowOf($id)['onsale_at'], '留空即无预约时间');

        $before = time();
        $this->assertOk(
            $this->http('POST', "/admin/collectibles/{$id}/release", ['status' => 'onsale'], $admin),
            '上架发售'
        );
        $on = strtotime((string) $this->windowOf($id)['onsale_at']);
        $this->assertGreaterThanOrEqual($before, $on, '留空应以上架时刻作为开售时间');
        $this->assertLessThanOrEqual(time() + 2, $on);

        $this->assertSame(0, $this->tryBuy($id)['code'], '即时开售后可直接下单');
    }

    public function testReReleaseAfterForceOffClearsExpiredSaleEnd(): void
    {
        $admin = $this->adminLogin();
        $id    = $this->createViaAdmin($admin);
        $this->assertOk($this->http('POST', "/admin/collectibles/{$id}/release", ['status' => 'onsale'], $admin), '首次上架');

        // 强制下架：status=off 且 off_sale_at 被写成下架那一刻
        $this->assertOk($this->http('POST', "/admin/collectibles/{$id}/manage", ['action' => 'off'], $admin), '强制下架');
        $off = $this->windowOf($id);
        $this->assertSame('off', $off['status']);
        $this->assertLessThanOrEqual(time(), strtotime((string) $off['off_sale_at']));

        // 重新上架（不传结束时间）：过期的结束时间必须清掉，否则 C 端判定为已售罄
        $this->assertOk($this->http('POST', "/admin/collectibles/{$id}/release", ['status' => 'onsale'], $admin), '重新上架');
        $again = $this->windowOf($id);
        $this->assertSame('onsale', $again['status']);
        $this->assertNull($again['off_sale_at'], '重新上架应清除上一次的过期结束时间');

        $item = $this->featuredItem($id);
        $this->assertNotNull($item, '重新上架后应回到 C 端发售区');
        $this->assertNull($item['saleEndTime']);
        $this->assertSame('onsale', $item['status']);

        $this->assertSame(0, $this->tryBuy($id)['code'], '重新上架后应可继续购买');
    }

    public function testFutureSaleEndTimeSurvivesReRelease(): void
    {
        $admin = $this->adminLogin();
        $id    = $this->createViaAdmin($admin);
        $end   = time() + 86400;
        $this->assertOk($this->http('POST', "/admin/collectibles/{$id}/release", [
            'status' => 'onsale', 'off_sale_at' => date('Y-m-d H:i:s', $end),
        ], $admin), '设定未来结束时间');

        // 仍在有效期内的结束时间不该被"清除过期值"分支误删
        $this->assertOk($this->http('POST', "/admin/collectibles/{$id}/release", ['status' => 'onsale'], $admin), '重新上架');
        $this->assertSame($end, strtotime((string) $this->windowOf($id)['off_sale_at']));
    }

    public function testInvertedSaleWindowIsRejected(): void
    {
        $admin = $this->adminLogin();
        $id    = $this->createViaAdmin($admin);

        $bad = $this->http('POST', "/admin/collectibles/{$id}/release", [
            'status'      => 'onsale',
            'onsale_at'   => date('Y-m-d H:i:s', time() + 7200),
            'off_sale_at' => date('Y-m-d H:i:s', time() + 3600),
        ], $admin);
        $this->assertSame(4220, $bad['code'], '结束时间早于开售时间应被拒绝');
        $this->assertSame('发售结束时间必须晚于开售时间', $bad['message']);
        $this->assertSame('upcoming', $this->windowOf($id)['status'], '被拒绝的请求不得改动藏品状态');

        // 编辑接口同样不能把开售时间挪到已有结束时间之后
        $this->assertOk(
            $this->http('POST', "/admin/collectibles/{$id}/release", [
                'status' => 'onsale', 'off_sale_at' => date('Y-m-d H:i:s', time() + 86400),
            ], $admin),
            '设定结束时间'
        );
        $later = $this->http('PUT', "/admin/collectibles/{$id}", [
            'onsale_at' => date('Y-m-d H:i:s', time() + 172800),
        ], $admin);
        $this->assertSame(4220, $later['code']);
        $this->assertStringContainsString('开售时间必须早于现有发售结束时间', $later['message']);
    }
}
