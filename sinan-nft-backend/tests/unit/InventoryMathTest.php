<?php
declare(strict_types=1);

namespace tests\unit;

use app\service\InventoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 库存恒等式与可卖量计算（InventoryService 的只读纯计算部分）
 *
 * 后端多处超发修复都建立在 stockPool / saleable 之上，且发行量恒等式
 * edition = 库存池 + 已售 + 锁定 + 预留 + 空投 + 销毁 一旦算错，
 * 上架、空投、销毁、配额都会跟着错，因此必须在无 DB 的情况下钉死。
 */
class InventoryMathTest extends TestCase
{
    /** 藏品行模板：DB 取出的是字符串数值，必须与整数入参同结果 */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'edition'          => '100',
            'sold'             => '0',
            'locked_quantity'  => '0',
            'reserved_count'   => '0',
            'airdropped_count' => '0',
            'destroyed_count'  => '0',
            'circulate'        => '0',
            'release_quantity' => null,
        ], $overrides);
    }

    public function testStockPoolSubtractsEveryOccupiedBucket(): void
    {
        $pool = InventoryService::stockPool(self::row([
            'sold' => '10', 'locked_quantity' => '5', 'reserved_count' => '3',
            'airdropped_count' => '2', 'destroyed_count' => '1',
        ]));
        $this->assertSame(79, $pool);
    }

    public function testStockPoolAcceptsIntegerColumnsFromDb(): void
    {
        $pool = InventoryService::stockPool(self::row([
            'edition' => 100, 'sold' => 40, 'locked_quantity' => 60,
        ]));
        $this->assertSame(0, $pool);
    }

    /** 恒等式：任何计数组合下，库存池加各类占用必须等于发行总量 */
    public function testStockPoolKeepsIssuanceIdentity(): void
    {
        $cases = [
            ['sold' => '100'],
            ['locked_quantity' => '100'],
            ['sold' => '33', 'locked_quantity' => '22', 'reserved_count' => '11', 'airdropped_count' => '4', 'destroyed_count' => '30'],
            ['airdropped_count' => '100'],
        ];
        foreach ($cases as $overrides) {
            $row  = self::row($overrides);
            $used = array_sum(array_map('intval', array_values($overrides)));
            $this->assertSame(
                (int) $row['edition'],
                InventoryService::stockPool($row) + $used,
                '组合 ' . json_encode($overrides) . ' 违反发行量恒等式'
            );
        }
    }

    /** 可卖量 = min(上架份数 − 已售, 库存池)，两者都不足时归零而非负数 */
    #[DataProvider('saleableProvider')]
    public function testSaleableNeverReturnsNegative(array $row, int $expected): void
    {
        $this->assertSame($expected, InventoryService::saleable($row));
    }

    public static function saleableProvider(): array
    {
        return [
            '未分批上架时等于库存池'      => [self::row(['sold' => '20']), 80],
            '全量上架（release_quantity=0）按库存池' => [self::row(['release_quantity' => 0, 'sold' => '20']), 80],
            '分批上架取较小者'            => [self::row(['edition' => '100', 'sold' => '0', 'locked_quantity' => '95', 'release_quantity' => 50]), 5],
            '上架份数已售罄归零'          => [self::row(['sold' => '30', 'release_quantity' => 30]), 0],
            '已售超过上架份数不得变负'    => [self::row(['sold' => '40', 'release_quantity' => 30]), 0],
            '库存池被锁空则无货可卖'      => [self::row(['sold' => '0', 'locked_quantity' => '100', 'release_quantity' => 50]), 0],
            '超卖导致库存池为负时仍归零'  => [self::row(['sold' => '120']), 0],
        ];
    }

    public function testCountersExposeCamelCaseSnapshotWithPool(): void
    {
        $counters = InventoryService::counters(self::row([
            'sold' => '10', 'locked_quantity' => '5', 'reserved_count' => '3',
            'airdropped_count' => '2', 'destroyed_count' => '1', 'circulate' => '12',
        ]));

        $this->assertSame([
            'edition'         => 100,
            'sold'            => 10,
            'lockedQuantity'  => 5,
            'reservedCount'   => 3,
            'airdroppedCount' => 2,
            'destroyedCount'  => 1,
            'circulate'       => 12,
            'stockPool'       => 79,
        ], $counters);
    }

    /** 盲盒与藏品共用同一套库存池公式（D-3） */
    public function testBlindBoxRowUsesSameStockPoolFormula(): void
    {
        $blindBox = [
            'edition' => '50', 'sold' => '20', 'locked_quantity' => '10',
            'reserved_count' => '5', 'airdropped_count' => '0', 'destroyed_count' => '0',
        ];
        $this->assertSame(15, InventoryService::stockPool($blindBox));
    }
}
