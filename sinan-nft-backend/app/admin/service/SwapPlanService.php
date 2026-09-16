<?php
declare(strict_types=1);

namespace app\admin\service;

use app\service\InventoryException;
use app\service\InventoryService;
use think\facade\Db;

/**
 * 统一置换服务（admin）
 *
 * 流程：选择一个或多个源藏品并配置各自置换比例（每持有 1 份 → 空投 N 份新藏品）
 *      → 统一回收所有源藏品有效持仓（held/consigned/frozen）
 *      → 按「Σ(持有数量 × 比例)」向持有人空投目标新藏品
 *      → 记录计划（nft_swap_plans）/ 源配置（items）/ 用户名单（users）/ 资产明细（details）
 *
 * 例：a 藏品比例 2、b 藏品比例 3
 *   用户甲持有 1 份 b        → 空投 3 份
 *   用户乙持有 a、b 各 1 份  → 空投 2 + 3 = 5 份
 *
 * 资产守恒：回收按来源回退计数器（InventoryService::revertOnRecover），
 *          空投走 airdropped_count/circulate 条件更新守恒式，全程单事务。
 */
class SwapPlanService
{
    /** 单计划源藏品数量上限 */
    public const MAX_SOURCES = 10;
    /** 比例上限（每份源藏品最多置换的新藏品数） */
    public const MAX_RATIO = 100;
    /** 预览名单最大返回条数（全量统计见 summary） */
    public const PREVIEW_LIMIT = 200;

    // =====================================================================
    // 参数解析（预览与执行共用）
    // =====================================================================

    /**
     * 解析源藏品配置 + 目标藏品
     *
     * @param array $itemsRaw [{collectible_id, ratio}, ...]
     * @param mixed $newIdRaw 目标新藏品ID
     * @param string $reason 置换原因
     * @return array{items: array<int, array{collectible_id:int, ratio:int}>, newId: int, reason: string}
     * @throws InventoryException
     */
    public static function parseParams(array $itemsRaw, mixed $newIdRaw, string $reason): array
    {
        if (!is_array($itemsRaw) || !$itemsRaw) {
            throw new InventoryException('请至少配置一个源（被回收）藏品');
        }
        if (count($itemsRaw) > self::MAX_SOURCES) {
            throw new InventoryException('单次置换最多支持 ' . self::MAX_SOURCES . ' 个源藏品');
        }

        $newId = (int) $newIdRaw;
        if ($newId <= 0) {
            throw new InventoryException('请选择置换目标（新）藏品');
        }

        $items = [];
        $seen  = [];
        foreach ($itemsRaw as $row) {
            if (!is_array($row)) {
                throw new InventoryException('源藏品配置格式错误');
            }
            $cid = (int) ($row['collectible_id'] ?? $row['collectibleId'] ?? 0);
            $ratio = (int) ($row['ratio'] ?? 0);
            if ($cid <= 0) {
                throw new InventoryException('源藏品 ID 不正确');
            }
            if (isset($seen[$cid])) {
                throw new InventoryException('源藏品不可重复配置（ID: ' . $cid . '）');
            }
            if ($ratio < 1 || $ratio > self::MAX_RATIO) {
                throw new InventoryException('置换比例需为 1~' . self::MAX_RATIO . ' 的整数');
            }
            if ($cid === $newId) {
                throw new InventoryException('源藏品不能与目标新藏品相同（ID: ' . $cid . '）');
            }
            $seen[$cid] = true;
            $items[] = ['collectible_id' => $cid, 'ratio' => $ratio];
        }

        $reason = trim($reason);
        if ($reason === '') {
            $reason = '藏品置换';
        }
        if (mb_strlen($reason) > 200) {
            $reason = mb_substr($reason, 0, 200);
        }

        return ['items' => $items, 'newId' => $newId, 'reason' => $reason];
    }

    // =====================================================================
    // 预览（只读，不写入）
    // =====================================================================

    /**
     * 预览受影响名单：每用户持有各源藏品数量 × 比例 → 空投份数
     *
     * @return array{items: array, newCollectible: array, users: array, summary: array}
     */
    public static function preview(array $items, int $newId): array
    {
        $sources = self::loadSources($items);
        $newC = Db::name('collectibles')->where('id', $newId)->whereNull('deleted_at')->find();
        if (!$newC) {
            throw new InventoryException('目标新藏品不存在');
        }

        [$users, $perSource, $summary] = self::computeUsers($items, $sources);

        $itemList = [];
        foreach ($items as $item) {
            $c = $sources[$item['collectible_id']];
            $itemList[] = [
                'collectibleId' => (int) $c['id'],
                'name'          => (string) $c['name'],
                'image'         => (string) ($c['image'] ?? ''),
                'ratio'         => $item['ratio'],
                'holdingCount'  => $perSource[$item['collectible_id']]['count'] ?? 0,
                'holdingUsers'  => $perSource[$item['collectible_id']]['users'] ?? 0,
            ];
        }

        return [
            'items' => $itemList,
            'newCollectible' => [
                'id'       => (int) $newC['id'],
                'name'     => (string) $newC['name'],
                'image'    => (string) ($newC['image'] ?? ''),
                'stockPool' => InventoryService::stockPool($newC),
            ],
            // 名单按空投份数降序，截断预览（全量以 summary.userCount 为准）
            'users' => array_slice($users, 0, self::PREVIEW_LIMIT),
            'summary' => $summary,
        ];
    }

    // =====================================================================
    // 执行（单事务：回收 + 按比例空投 + 记录名单明细）
    // =====================================================================

    /**
     * 执行统一置换
     *
     * @param array  $items    [{collectible_id, ratio}, ...]
     * @param int    $newId    目标新藏品ID
     * @param string $reason   置换原因
     * @param array  $operator ['id'=>管理员ID, 'name'=>管理员姓名, 'ip'=>IP]
     * @return array 执行结果（含计划ID与统计）
     */
    public static function execute(array $items, int $newId, string $reason, array $operator): array
    {
        $sources = self::loadSources($items);

        $now = date('Y-m-d H:i:s');
        $planNo = 'SP' . date('ymdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

        Db::startTrans();
        try {
            $newC = Db::name('collectibles')->where('id', $newId)->whereNull('deleted_at')->lock(true)->find();
            if (!$newC) {
                throw new InventoryException('目标新藏品不存在');
            }

            // 行锁查询所有源藏品的有效持仓（held/consigned/frozen，防并发买卖/转赠/开盒）
            $holders = Db::name('user_collectibles')
                ->whereIn('collectible_id', array_column($items, 'collectible_id'))
                ->whereIn('status', ['held', 'consigned', 'frozen'])
                ->lock(true)
                ->select()
                ->toArray();
            if (!$holders) {
                throw new InventoryException('所选源藏品当前均无有效持仓，无需置换');
            }

            // 计算名单：每用户 持有数量 × 比例 → 空投份数
            [$users, $perSource, $summary] = self::computeUsers($items, $sources, $holders);
            $totalAirdrop = $summary['totalAirdrop'];

            // 目标藏品库存池校验（行锁内快照 + 条件更新守恒式双保险）
            $pool = InventoryService::stockPool($newC);
            if ($totalAirdrop > $pool) {
                throw new InventoryException("目标藏品库存池不足：可空投 {$pool} 份，本次需 {$totalAirdrop} 份（{$summary['userCount']} 人按比例合计）");
            }

            // 持仓用户有效性校验（未删除）
            $userIds = array_map('intval', array_column($users, 'userId'));
            $userRows = Db::name('users')->whereIn('id', $userIds)->whereNull('deleted_at')
                ->field('id, phone, uid')->select()->toArray();
            $userMap = [];
            foreach ($userRows as $u) {
                $userMap[(int) $u['id']] = $u;
            }
            $missing = array_diff($userIds, array_keys($userMap));
            if ($missing) {
                throw new InventoryException('部分持仓用户已不存在或已删除（ID: ' . implode(',', $missing) . '），请先清理异常数据');
            }

            // ---------- 置换计划快照 ----------
            $planId = (int) Db::name('swap_plans')->insertGetId([
                'plan_no'              => $planNo,
                'new_collectible_id'   => $newId,
                'new_collectible_name' => (string) $newC['name'],
                'reason'               => $reason,
                'user_count'           => $summary['userCount'],
                'total_recovered'      => $summary['totalRecovered'],
                'total_airdropped'     => $totalAirdrop,
                'admin_id'             => (int) ($operator['id'] ?? 0),
                'admin_name'          => (string) ($operator['name'] ?? ''),
                'ip'                   => (string) ($operator['ip'] ?? ''),
                'created_at'           => $now,
            ]);
            $planItems = [];
            foreach ($items as $item) {
                $planItems[] = [
                    'plan_id'              => $planId,
                    'old_collectible_id'   => $item['collectible_id'],
                    'old_collectible_name' => (string) $sources[$item['collectible_id']]['name'],
                    'ratio'                => $item['ratio'],
                    'recovered_count'      => $perSource[$item['collectible_id']]['count'] ?? 0,
                    'created_at'            => $now,
                ];
            }
            Db::name('swap_plan_items')->insertAll($planItems);

            // ---------- 第一步：统一回收所有源藏品持仓 ----------
            $recoverDetails = [];
            foreach ($holders as $uc) {
                $cid = (int) $uc['collectible_id'];
                $ratio = 0;
                foreach ($items as $item) {
                    if ($item['collectible_id'] === $cid) {
                        $ratio = $item['ratio'];
                        break;
                    }
                }
                // 寄售中：联动下架在售挂单
                if ($uc['status'] === 'consigned') {
                    $listing = Db::name('resale_listings')
                        ->where('user_collectible_id', $uc['id'])
                        ->where('status', 'selling')
                        ->find();
                    if ($listing) {
                        Db::name('resale_listings')->where('id', $listing['id'])->update([
                            'status'             => 'cancelled',
                            'is_system_delisted' => 1,
                            'system_delisted_at' => $now,
                            'delist_reason'      => '统一置换回收：' . $reason,
                            'updated_at'         => $now,
                        ]);
                    }
                }
                // 转赠中：联动取消待确认转赠（资产回收后转赠无法继续）
                if ($uc['status'] === 'frozen') {
                    Db::name('transfers')
                        ->where('user_collectible_id', $uc['id'])
                        ->where('status', 'pending')
                        ->update([
                            'status'     => 'cancelled',
                            'updated_at' => $now,
                        ]);
                }
                // 资产置已回收
                Db::name('user_collectibles')->where('id', $uc['id'])->update([
                    'status'       => 'recovered',
                    'is_consigned' => 0,
                    'updated_at'   => $now,
                ]);
                // 按来源回退藏品计数器；回退失败即中止，避免守恒审计漂移
                $revert = InventoryService::revertOnRecover($uc);
                if (empty($revert['reverted'])) {
                    throw new InventoryException('回收资产计数器回退失败（资产ID：' . $uc['id'] . '，来源：' . ($revert['source'] ?? 'unknown') . '），已中止并整体回滚');
                }

                $recoverDetails[] = [
                    'plan_id'             => $planId,
                    'user_id'             => (int) $uc['user_id'],
                    'action'              => 1,
                    'collectible_id'      => $cid,
                    'collectible_name'    => (string) $sources[$cid]['name'],
                    'serial'              => (string) $uc['serial'],
                    'user_collectible_id' => (int) $uc['id'],
                    'ratio'               => $ratio,
                    'created_at'           => $now,
                ];
            }
            Db::name('swap_plan_details')->insertAll($recoverDetails);

            // ---------- 第二步：按比例向持有人空投新藏品 ----------
            // 计数器先行（条件更新守恒式 + CHECK 双保险）
            $ok = Db::name('collectibles')
                ->where('id', $newId)
                ->whereRaw("sold + locked_quantity + reserved_count + airdropped_count + {$totalAirdrop} + destroyed_count <= edition")
                ->update([
                    'airdropped_count' => Db::raw("airdropped_count + {$totalAirdrop}"),
                    'circulate'        => Db::raw("circulate + {$totalAirdrop}"),
                    'updated_at'       => $now,
                ]);
            if (!$ok) {
                throw new InventoryException("目标藏品库存池不足（并发竞争，请重试）");
            }

            // 用户名单批量落库（持有明细快照；事务内任一失败整体回滚）
            $planUsers = [];
            foreach ($users as $u) {
                $userId = (int) $u['userId'];
                $info = $userMap[$userId];
                $planUsers[] = [
                    'plan_id'          => $planId,
                    'user_id'          => $userId,
                    'phone'            => (string) ($info['phone'] ?? ''),
                    'uid'             => (string) ($info['uid'] ?? ''),
                    'holdings'         => json_encode($u['holdings'], JSON_UNESCAPED_UNICODE),
                    'recovered_total'  => $u['recoveredTotal'],
                    'airdrop_quantity' => $u['airdropQuantity'],
                    'created_at'       => $now,
                ];
            }
            Db::name('swap_plan_users')->insertAll($planUsers);

            // 逐份空投资产：airdrop_records ↔ user_collectibles 为 1:1 双向外键，
            // serial 依赖自增 ID（沿用全项目 SN-{藏品ID}-{资产ID} 序列号规范），故逐条插入
            $airdropDetails = [];
            foreach ($users as $u) {
                $userId = (int) $u['userId'];
                $info = $userMap[$userId];

                for ($i = 0; $i < $u['airdropQuantity']; $i++) {
                    // 空投台账（独立空投语义：activity/task 为空，溯源靠计划明细）
                    $recordId = (int) Db::name('airdrop_records')->insertGetId([
                        'activity_id' => null,
                        'task_id'     => null,
                        'user_id'     => $userId,
                        'phone'       => (string) ($info['phone'] ?? ''),
                        'collectible_id' => $newId,
                        'quantity'    => 1,
                        'status'      => 'issued',
                        'issued_at'   => $now,
                        'created_at'  => $now,
                        'updated_at' => $now,
                    ]);
                    // 资产行（先占位取 ID 再回写编号，与空投/开盒一致防并发重号）
                    $ucId = (int) Db::name('user_collectibles')->insertGetId([
                        'user_id'           => $userId,
                        'collectible_id'    => $newId,
                        'airdrop_record_id' => $recordId,
                        'serial'            => gen_serial_placeholder(),
                        'source'            => 'airdrop',
                        'acquired_price'    => 0,
                        'acquired_at'       => $now,
                        'status'            => 'held',
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ]);
                    $serial = 'SN-' . $newId . '-' . str_pad((string) $ucId, 4, '0', STR_PAD_LEFT);
                    Db::name('user_collectibles')->where('id', $ucId)->update([
                        'serial'     => $serial,
                        'updated_at' => $now,
                    ]);
                    Db::name('airdrop_records')->where('id', $recordId)->update([
                        'user_collectible_id' => $ucId,
                        'updated_at'          => $now,
                    ]);

                    $airdropDetails[] = [
                        'plan_id'             => $planId,
                        'user_id'             => $userId,
                        'action'              => 2,
                        'collectible_id'      => $newId,
                        'collectible_name'    => (string) $newC['name'],
                        'serial'              => $serial,
                        'user_collectible_id' => $ucId,
                        'ratio'               => null,
                        'created_at'          => $now,
                    ];
                }
            }
            Db::name('swap_plan_details')->insertAll($airdropDetails);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return [
            'planNo'        => $planNo,
            'userCount'     => $summary['userCount'],
            'recoveredCount' => $summary['totalRecovered'],
            'airdropQuantity' => $summary['totalAirdrop'],
        ];
    }

    // =====================================================================
    // 内部工具
    // =====================================================================

    /**
     * 加载并校验源藏品
     * @return array<int, array> [collectible_id => collectible row]
     */
    private static function loadSources(array $items): array
    {
        $ids = array_map(static fn ($i) => $i['collectible_id'], $items);
        $rows = Db::name('collectibles')->whereIn('id', $ids)->whereNull('deleted_at')->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = $r;
        }
        foreach ($ids as $id) {
            if (!isset($map[$id])) {
                throw new InventoryException("源藏品不存在（ID: {$id}）");
            }
        }
        return $map;
    }

    /**
     * 计算受影响用户名单
     *
     * @param array      $items  [{collectible_id, ratio}]
     * @param array      $sources [collectible_id => row]
     * @param array|null $holders 已锁定的持仓行（执行时传入；预览时自行查询）
     * @return array{0: array 用户名单, 1: array 每源藏品统计, 2: array 汇总}
     */
    private static function computeUsers(array $items, array $sources, ?array $holders = null): array
    {
        $ratioMap = [];
        foreach ($items as $item) {
            $ratioMap[$item['collectible_id']] = $item['ratio'];
        }

        if ($holders === null) {
            $holders = Db::name('user_collectibles')
                ->whereIn('collectible_id', array_keys($ratioMap))
                ->whereIn('status', ['held', 'consigned', 'frozen'])
                ->select()
                ->toArray();
        }

        // user_id => [collectible_id => qty]
        $matrix = [];
        $perSource = [];
        foreach ($holders as $uc) {
            $uid = (int) $uc['user_id'];
            $cid = (int) $uc['collectible_id'];
            $matrix[$uid][$cid] = ($matrix[$uid][$cid] ?? 0) + 1;
            $perSource[$cid]['count'] = ($perSource[$cid]['count'] ?? 0) + 1;
            $perSource[$cid]['users'] = ($perSource[$cid]['users'] ?? 0) + (($matrix[$uid][$cid] ?? 0) === 1 ? 1 : 0);
        }

        ksort($matrix);
        $users = [];
        $totalRecovered = 0;
        $totalAirdrop = 0;
        foreach ($matrix as $uid => $byCollectible) {
            ksort($byCollectible);
            $holdings = [];
            $recoveredTotal = 0;
            $airdropQty = 0;
            foreach ($byCollectible as $cid => $qty) {
                $ratio = $ratioMap[$cid];
                $newQty = $qty * $ratio;
                $holdings[] = [
                    'collectibleId' => $cid,
                    'name'          => (string) $sources[$cid]['name'],
                    'image'         => (string) ($sources[$cid]['image'] ?? ''),
                    'quantity'      => $qty,
                    'ratio'         => $ratio,
                    'newQuantity'   => $newQty,
                ];
                $recoveredTotal += $qty;
                $airdropQty += $newQty;
            }
            $users[] = [
                'userId'         => $uid,
                'holdings'       => $holdings,
                'recoveredTotal' => $recoveredTotal,
                'airdropQuantity' => $airdropQty,
            ];
            $totalRecovered += $recoveredTotal;
            $totalAirdrop += $airdropQty;
        }

        // 名单按空投份数降序（大用户在前，便于管理员核对）
        usort($users, static fn ($a, $b) => $b['airdropQuantity'] <=> $a['airdropQuantity'] ?: $a['userId'] <=> $b['userId']);

        $summary = [
            'userCount'      => count($users),
            'totalRecovered' => $totalRecovered,
            'totalAirdrop'   => $totalAirdrop,
        ];
        return [$users, $perSource, $summary];
    }
}
