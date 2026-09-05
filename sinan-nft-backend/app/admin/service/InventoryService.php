<?php
declare(strict_types=1);

namespace app\admin\service;

use app\admin\service\InventoryException;
use think\facade\Db;

/**
 * 库存服务（文档 4.3 库存体系，所有模块共用）
 *
 * 库存池（实时计算，不落库，文档 4.3.1）：
 *   edition − sold − locked_quantity − reserved_count − airdropped_count − destroyed_count
 * 流通量 circulate = sold + airdropped_count（支付成功/空投发放时累加）
 * 实际可卖 = min( release_quantity − sold（若配置）, 库存池 )
 *
 * 计数器更新统一走「事务 + 条件更新（WHERE 守恒式）+ CHECK 约束 chk_c_stock」
 * 三重保险防并发超发（与 C 端下单做法一致，文档 4.3.2）。
 *
 * 配额类型 quota_type：1优先购 2活动空投 3签到 4注册 5邀请 6抽奖 7其他
 */
class InventoryService
{
    // =====================================================================
    // 只读计算（4.3.1 / 4.3.3）
    // =====================================================================

    /**
     * 库存池（传入藏品行，避免二次查询；盲盒行同公式，D-3）
     */
    public static function stockPool(array $c): int
    {
        return (int) $c['edition']
            - (int) $c['sold']
            - (int) $c['locked_quantity']
            - (int) $c['reserved_count']
            - (int) $c['airdropped_count']
            - (int) $c['destroyed_count'];
    }

    /**
     * 按 ID 实时计算库存池
     */
    public static function stockPoolById(int $collectibleId): int
    {
        $c = Db::name('collectibles')->where('id', $collectibleId)->find();
        if (!$c) {
            throw new InventoryException('藏品不存在');
        }
        return self::stockPool($c);
    }

    /**
     * 实际可卖 = min(release_quantity − sold（若配置）, 库存池)（文档 4.3.1）
     */
    public static function saleable(array $c): int
    {
        $pool = self::stockPool($c);
        if ($c['release_quantity'] !== null && (int) $c['release_quantity'] > 0) {
            return max(0, min((int) $c['release_quantity'] - (int) $c['sold'], $pool));
        }
        return max(0, $pool);
    }

    /**
     * 库存计数器快照（详情/审计/列表通用，驼峰输出）
     */
    public static function counters(array $c): array
    {
        return [
            'edition'        => (int) $c['edition'],
            'sold'           => (int) $c['sold'],
            'lockedQuantity' => (int) $c['locked_quantity'],
            'reservedCount'  => (int) $c['reserved_count'],
            'airdroppedCount' => (int) $c['airdropped_count'],
            'destroyedCount' => (int) $c['destroyed_count'],
            'circulate'      => (int) $c['circulate'],
            'stockPool'      => self::stockPool($c),
        ];
    }

    // =====================================================================
    // 配额（文档 4.3.2）
    // =====================================================================

    /**
     * 新增 / 增量配额（POST /collectibles/:id/quotas）
     *
     * quotas[] 每项：
     *   id?: int            已有配额ID（提供则为增量，planned_quantity 表示本次追加量）
     *   quota_type: 1-7     不提供 id 时必填（新建）
     *   quota_name: string  配额名称
     *   planned_quantity: int 本次新增/增量数量（>0）
     *   activity_id? / activity_type? / remark?
     *
     * 规则：Σ增量 ≤ 当前库存池，超发拦截「库存池不足，当前库存池为 X」；
     * 成功后 reserved_count += Σ增量（事务内条件更新与 CHECK 双保险）。
     */
    public static function addQuotas(int $collectibleId, array $quotas, int $adminId): array
    {
        $items   = [];
        $totalDelta = 0;
        foreach ($quotas as $q) {
            if (!is_array($q)) {
                throw new InventoryException('配额参数格式错误');
            }
            $qty = (int) ($q['planned_quantity'] ?? 0);
            if ($qty <= 0) {
                throw new InventoryException('配额数量必须大于 0');
            }
            $totalDelta += $qty;
            $items[] = [
                'id'            => isset($q['id']) && $q['id'] !== '' ? (int) $q['id'] : 0,
                'quota_type'    => (int) ($q['quota_type'] ?? 0),
                'quota_name'    => trim((string) ($q['quota_name'] ?? '')),
                'quantity'      => $qty,
                'activity_id'   => isset($q['activity_id']) && $q['activity_id'] !== '' ? (int) $q['activity_id'] : null,
                'activity_type' => isset($q['activity_type']) && $q['activity_type'] !== '' ? (string) $q['activity_type'] : null,
                'remark'        => isset($q['remark']) && $q['remark'] !== '' ? (string) $q['remark'] : null,
            ];
        }
        if (!$items) {
            throw new InventoryException('配额配置不能为空');
        }

        Db::startTrans();
        try {
            // 行锁读取库存池（快照在锁内，防并发竞争）
            $c = Db::name('collectibles')->where('id', $collectibleId)->lock(true)->find();
            if (!$c) {
                throw new InventoryException('藏品不存在');
            }
            $pool = self::stockPool($c);
            if ($totalDelta > $pool) {
                throw new InventoryException("库存池不足，当前库存池为 {$pool}");
            }

            $now = date('Y-m-d H:i:s');
            $result = [];
            foreach ($items as $item) {
                if ($item['id'] > 0) {
                    // 增量：追加到已有配额
                    $quota = Db::name('inventory_quotas')
                        ->where('id', $item['id'])
                        ->where('collectible_id', $collectibleId)
                        ->lock(true)
                        ->find();
                    if (!$quota) {
                        throw new InventoryException("配额 #{$item['id']} 不存在或不属于该藏品");
                    }
                    if ((int) $quota['status'] !== 1) {
                        throw new InventoryException("配额「{$quota['quota_name']}」已停用，无法追加");
                    }
                    Db::name('inventory_quotas')->where('id', $item['id'])->update([
                        'planned_quantity' => Db::raw("planned_quantity + {$item['quantity']}"),
                        'updated_at'       => $now,
                    ]);
                    $result[] = [
                        'id' => $item['id'], 'mode' => 'increment',
                        'name' => $quota['quota_name'], 'quantity' => $item['quantity'],
                    ];
                } else {
                    // 新建配额
                    if ($item['quota_type'] < 1 || $item['quota_type'] > 7) {
                        throw new InventoryException('配额类型必须为 1-7');
                    }
                    if ($item['quota_name'] === '') {
                        throw new InventoryException('新建配额必须提供 quota_name');
                    }
                    $id = Db::name('inventory_quotas')->insertGetId([
                        'collectible_id' => $collectibleId,
                        'quota_type'     => $item['quota_type'],
                        'quota_name'     => $item['quota_name'],
                        'planned_quantity' => $item['quantity'],
                        'used_quantity'  => 0,
                        'status'         => 1,
                        'activity_id'    => $item['activity_id'],
                        'activity_type'  => $item['activity_type'],
                        'remark'         => $item['remark'],
                        'created_by'     => $adminId,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);
                    $result[] = [
                        'id' => (int) $id, 'mode' => 'create',
                        'name' => $item['quota_name'], 'quantity' => $item['quantity'],
                    ];
                }
            }

            // 冻结库存池（条件更新守恒式 + CHECK 双保险）
            $ok = Db::name('collectibles')
                ->where('id', $collectibleId)
                ->whereRaw("sold + locked_quantity + reserved_count + {$totalDelta} + airdropped_count + destroyed_count <= edition")
                ->update([
                    'reserved_count' => Db::raw("reserved_count + {$totalDelta}"),
                    'updated_at'     => $now,
                ]);
            if (!$ok) {
                throw new InventoryException("库存池不足，当前库存池为 {$pool}（并发竞争，请重试）");
            }

            Db::commit();
            return [
                'quotas'         => $result,
                'frozenTotal'    => $totalDelta,
                'stockPoolAfter' => $pool - $totalDelta,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 修改配额（PUT /collectibles/:id/quotas/:quota_id）
     *
     * newPlanned ≥ used_quantity（已使用不可减）；减少的差额自动释放回库存池；
     * status=0 停用释放全部未用部分；status=1 重新启用需重新冻结（校验库存池）。
     */
    public static function updateQuota(int $collectibleId, int $quotaId, array $params): array
    {
        Db::startTrans();
        try {
            $quota = Db::name('inventory_quotas')
                ->where('id', $quotaId)
                ->where('collectible_id', $collectibleId)
                ->lock(true)
                ->find();
            if (!$quota) {
                throw new InventoryException('配额不存在或不属于该藏品');
            }

            $now  = date('Y-m-d H:i:s');
            $used = (int) $quota['used_quantity'];
            $planned = (int) $quota['planned_quantity'];
            $wasActive = (int) $quota['status'] === 1;
            $updates = [];
            $frozenDelta = 0; // reserved_count 变化量（正=冻结，负=释放）

            // 名称/备注修改
            if (isset($params['quota_name']) && trim((string) $params['quota_name']) !== '') {
                $updates['quota_name'] = trim((string) $params['quota_name']);
            }
            if (array_key_exists('remark', $params)) {
                $updates['remark'] = $params['remark'] === '' ? null : (string) $params['remark'];
            }

            // 计划数量修改（已使用不可减，差额回库存池）
            $newPlanned = null;
            if (isset($params['planned_quantity']) && $params['planned_quantity'] !== '') {
                $newPlanned = (int) $params['planned_quantity'];
                if ($newPlanned < 0) {
                    throw new InventoryException('计划数量不能为负');
                }
                if ($newPlanned < $used) {
                    throw new InventoryException("已使用数量不可减：该配额已使用 {$used} 份，计划数量不能低于此值");
                }
            }

            // 状态切换（停用释放未用部分 / 启用重新冻结）
            $newStatus = null;
            if (isset($params['status']) && $params['status'] !== '') {
                $newStatus = (int) $params['status'] === 1 ? 1 : 0;
            }

            // 计算 reserved_count 净变化
            $effectivePlanned = $newPlanned ?? $planned;
            if ($newStatus === 0) {
                // 停用：释放全部未用部分（planned - used）
                if ($wasActive) {
                    $frozenDelta -= ($planned - $used);
                }
            } elseif ($newStatus === 1) {
                if (!$wasActive) {
                    // 重新启用：冻结未用部分
                    $frozenDelta += ($effectivePlanned - $used);
                } elseif ($newPlanned !== null) {
                    $frozenDelta += ($newPlanned - $planned);
                }
            } elseif ($newPlanned !== null && $wasActive) {
                // 状态不变，仅改计划数量
                $frozenDelta += ($newPlanned - $planned);
            }

            if ($frozenDelta > 0) {
                // 需要冻结：校验库存池
                $c = Db::name('collectibles')->where('id', $collectibleId)->lock(true)->find();
                if (!$c) {
                    throw new InventoryException('藏品不存在');
                }
                $pool = self::stockPool($c);
                if ($frozenDelta > $pool) {
                    throw new InventoryException("库存池不足，当前库存池为 {$pool}");
                }
                $ok = Db::name('collectibles')
                    ->where('id', $collectibleId)
                    ->whereRaw("sold + locked_quantity + reserved_count + {$frozenDelta} + airdropped_count + destroyed_count <= edition")
                    ->update([
                        'reserved_count' => Db::raw("reserved_count + {$frozenDelta}"),
                        'updated_at'     => $now,
                    ]);
                if (!$ok) {
                    throw new InventoryException("库存池不足，当前库存池为 {$pool}（并发竞争，请重试）");
                }
            } elseif ($frozenDelta < 0) {
                // 释放回库存池（防止 reserved_count 减为负）
                $release = abs($frozenDelta);
                $ok = Db::name('collectibles')
                    ->where('id', $collectibleId)
                    ->where('reserved_count', '>=', $release)
                    ->update([
                        'reserved_count' => Db::raw("reserved_count - {$release}"),
                        'updated_at'     => $now,
                    ]);
                if (!$ok) {
                    throw new InventoryException('配额预留计数异常，释放失败（reserved_count 不足）');
                }
            }

            if ($newPlanned !== null) {
                $updates['planned_quantity'] = $newPlanned;
            }
            if ($newStatus !== null) {
                $updates['status'] = $newStatus;
            }
            if ($updates) {
                $updates['updated_at'] = $now;
                Db::name('inventory_quotas')->where('id', $quotaId)->update($updates);
            }

            Db::commit();
            return [
                'quotaId'     => $quotaId,
                'planned'     => $newPlanned ?? $planned,
                'used'        => $used,
                'status'      => $newStatus ?? ((int) $quota['status']),
                'frozenDelta' => $frozenDelta,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 活动实际发放时消耗配额（文档 4.3.2：空投/签到/邀请/抽奖/盲盒奖品/合成产物共用）
     * 动态校验 used_quantity + 发放量 ≤ planned_quantity，先到先得；原子更新 used_quantity。
     * 不足时拦截「配额预留不足，当前剩余 X 份」。
     *
     * @param int    $collectibleId 藏品ID
     * @param int    $quotaType    配额类型 1-7
     * @param int    $quantity     发放数量
     * @param string $activityType 活动类型（airdrop/checkin/invite/lucky_draw/synthesis/register）
     * @param int|null $activityId 关联活动ID（可选，优先精确匹配）
     */
    public static function consumeQuota(int $collectibleId, int $quotaType, int $quantity, string $activityType = '', ?int $activityId = null): void
    {
        if ($quantity <= 0) {
            return;
        }
        // 候选配额：启用的、类型匹配的（优先 activity_id 精确匹配）
        $query = Db::name('inventory_quotas')
            ->where('collectible_id', $collectibleId)
            ->where('quota_type', $quotaType)
            ->where('status', 1);
        if ($activityId !== null) {
            $query->where(function ($q) use ($activityId) {
                $q->where('activity_id', $activityId)->whereOr('activity_id', null);
            });
        }
        $quotas = $query->order('id')->lock(true)->select()->toArray();
        if (!$quotas) {
            throw new InventoryException("配额预留不足，当前剩余 0 份（该藏品未配置类型 {$quotaType} 配额）");
        }

        $remain = $quantity;
        foreach ($quotas as $quota) {
            if ($remain <= 0) {
                break;
            }
            $avail = (int) $quota['planned_quantity'] - (int) $quota['used_quantity'];
            if ($avail <= 0) {
                continue;
            }
            $take = min($avail, $remain);
            $ok = Db::name('inventory_quotas')
                ->where('id', $quota['id'])
                ->where('status', 1)
                ->whereRaw('used_quantity + ' . $take . ' <= planned_quantity')
                ->update([
                    'used_quantity' => Db::raw('used_quantity + ' . $take),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            if ($ok) {
                $remain -= $take;
            }
        }
        if ($remain > 0) {
            $left = 0;
            foreach ($quotas as $quota) {
                $left += max(0, (int) $quota['planned_quantity'] - (int) $quota['used_quantity']);
            }
            throw new InventoryException("配额预留不足，当前剩余 {$left} 份");
        }
    }

    // =====================================================================
    // 独立空投（红线 6：不绑定活动；文档 8.6 #44 / 11.1）
    // =====================================================================

    /**
     * 独立空投：每个手机号发放 quantity 份
     * 校验：手机号已注册；总量 ≤ 库存池；发放走 airdropped_count/circulate 计数器
     *
     * @param array  $operator    ['id'=>管理员ID, 'name'=>管理员姓名, 'ip'=>IP]
     * @param string $assetSource 资产行 source（藏品=airdrop；盲盒=purchase，
     *                            盲盒为可开商品，保持 C 端现有开盒可用性，空投溯源靠 airdrop_record_id 外键）
     * @return array 发放统计
     */
    public static function airdrop(int $collectibleId, array $phones, int $quantityPerUser, array $operator, string $assetSource = 'airdrop'): array
    {
        if ($quantityPerUser <= 0) {
            throw new InventoryException('每个用户的空投数量必须大于 0');
        }
        $phones = array_values(array_unique(array_filter(array_map('trim', $phones))));
        if (!$phones) {
            throw new InventoryException('空投手机号不能为空');
        }
        $total = $quantityPerUser * count($phones);

        // 手机号格式与注册校验（未注册直接拦截并提示号码，文档 11.1）
        foreach ($phones as $phone) {
            if (!preg_match('/^1\d{10}$/', $phone)) {
                throw new InventoryException("手机号 {$phone} 格式不正确");
            }
        }
        $users = Db::name('users')->whereIn('phone', $phones)->whereNull('deleted_at')
            ->column('id', 'phone');
        foreach ($phones as $phone) {
            if (!isset($users[$phone])) {
                throw new InventoryException("手机号 {$phone} 尚未注册");
            }
        }

        Db::startTrans();
        try {
            $c = Db::name('collectibles')->where('id', $collectibleId)->lock(true)->find();
            if (!$c) {
                throw new InventoryException('藏品不存在');
            }
            $pool = self::stockPool($c);
            if ($total > $pool) {
                throw new InventoryException("库存池不足，当前库存池为 {$pool}，本次需发放 {$total}");
            }

            $now = date('Y-m-d H:i:s.v');

            // 计数器：airdropped_count + total / circulate + total（条件更新守恒式）
            $ok = Db::name('collectibles')
                ->where('id', $collectibleId)
                ->whereRaw("sold + locked_quantity + reserved_count + airdropped_count + {$total} + destroyed_count <= edition")
                ->update([
                    'airdropped_count' => Db::raw("airdropped_count + {$total}"),
                    'circulate'        => Db::raw("circulate + {$total}"),
                    'updated_at'       => $now,
                ]);
            if (!$ok) {
                throw new InventoryException("库存池不足，当前库存池为 {$pool}（并发竞争，请重试）");
            }

            $issued = [];
            foreach ($phones as $phone) {
                $userId = (int) $users[$phone];
                for ($i = 0; $i < $quantityPerUser; $i++) {
                    // 空投台账（独立空投 activity_id = NULL，红线 6）
                    $recordId = Db::name('airdrop_records')->insertGetId([
                        'activity_id' => null,
                        'user_id'     => $userId,
                        'phone'       => $phone,
                        'collectible_id' => $collectibleId,
                        'quantity'    => 1,
                        'status'      => 'issued',
                        'issued_at'   => $now,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]);
                    // 资产行（先占位取 ID 再回写编号，与 C 端开盒一致防并发重号）
                    Db::name('user_collectibles')->insert([
                        'user_id'           => $userId,
                        'collectible_id'    => $collectibleId,
                        'airdrop_record_id' => $recordId,
                        'serial'            => gen_serial_placeholder(),
                        'source'            => $assetSource,
                        'acquired_price'     => 0,
                        'acquired_at'        => $now,
                        'status'            => 'held',
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ]);
                    $ucId = (int) Db::name('user_collectibles')->getLastInsID();
                    $serial = 'SN-' . $collectibleId . '-' . str_pad((string) $ucId, 4, '0', STR_PAD_LEFT);
                    Db::name('user_collectibles')->where('id', $ucId)->update([
                        'serial'     => $serial,
                        'updated_at' => $now,
                    ]);
                    Db::name('airdrop_records')->where('id', $recordId)->update([
                        'user_collectible_id' => $ucId,
                        'updated_at'          => $now,
                    ]);
                    $issued[] = ['phone' => $phone, 'userId' => $userId, 'userCollectibleId' => $ucId, 'serial' => $serial];
                }
            }

            Db::commit();
            return [
                'users'          => count($phones),
                'perUser'        => $quantityPerUser,
                'total'          => $total,
                'stockPoolAfter' => $pool - $total,
                'issued'         => $issued,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    // =====================================================================
    // 销毁（文档 8.6 #42 / 8.7 #61；不可逆，写 destroy_records）
    // =====================================================================

    /**
     * 销毁库存：quantity ≤ 库存池；destroyed_count += quantity（不可逆）
     *
     * @param int    $targetType 1藏品 2盲盒（盲盒=target_id 为 nft_blind_boxes.id）
     * @param int    $targetId   藏品场景为藏品ID；盲盒场景为盲盒ID
     */
    public static function destroy(int $collectibleId, int $quantity, string $reason, array $operator, int $targetType = 1, int $targetId = 0): array
    {
        if ($quantity <= 0) {
            throw new InventoryException('销毁数量必须大于 0');
        }

        Db::startTrans();
        try {
            $c = Db::name('collectibles')->where('id', $collectibleId)->lock(true)->find();
            if (!$c) {
                throw new InventoryException('藏品不存在');
            }
            $pool = self::stockPool($c);
            if ($quantity > $pool) {
                throw new InventoryException("销毁数量超出库存池，当前库存池为 {$pool}");
            }

            $now = date('Y-m-d H:i:s.v');
            $ok = Db::name('collectibles')
                ->where('id', $collectibleId)
                ->whereRaw("sold + locked_quantity + reserved_count + airdropped_count + destroyed_count + {$quantity} <= edition")
                ->update([
                    'destroyed_count' => Db::raw("destroyed_count + {$quantity}"),
                    'updated_at'      => $now,
                ]);
            if (!$ok) {
                throw new InventoryException("销毁数量超出库存池，当前库存池为 {$pool}（并发竞争，请重试）");
            }

            Db::name('destroy_records')->insert([
                'target_type'   => $targetType,
                'target_id'     => $targetId ?: $collectibleId,
                'collectible_id' => $collectibleId,
                'target_name'   => (string) $c['name'],
                'quantity'      => $quantity,
                'reason'        => $reason !== '' ? $reason : null,
                'admin_id'      => (int) ($operator['id'] ?? 0),
                'admin_name'    => (string) ($operator['name'] ?? ''),
                'ip'            => (string) ($operator['ip'] ?? ''),
                'created_at'    => $now,
            ]);

            Db::commit();
            return [
                'destroyed'      => $quantity,
                'stockPoolAfter' => $pool - $quantity,
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    // =====================================================================
    // 强制售罄（文档 6.1：不清零任何计数器，仅停止发售，剩余留在库存池）
    // =====================================================================

    /**
     * 强制售罄：状态置 soldout；计数器全部保持不变
     */
    public static function forceSoldout(int $collectibleId): array
    {
        Db::startTrans();
        try {
            $c = Db::name('collectibles')->where('id', $collectibleId)->lock(true)->find();
            if (!$c) {
                throw new InventoryException('藏品不存在');
            }
            if (!in_array($c['status'], ['upcoming', 'onsale'], true)) {
                throw new InventoryException("当前状态 {$c['status']} 不允许强制售罄（仅待发售/发售中）");
            }

            Db::name('collectibles')->where('id', $collectibleId)->update([
                'status'     => 'soldout',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            Db::commit();
            return [
                'stockPool'  => self::stockPool($c),
                'counters'  => self::counters($c),
                'note'      => '计数器未清零，剩余量保留在库存池',
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    // =====================================================================
    // 强制回收的计数器回退（文档 4.3.4）
    // =====================================================================

    /**
     * 回收/撤销时按资产来源回退藏品计数器（调用方须已完成 held 状态校验并锁定资产行）
     *
     * | source                          | 回退动作                                     | circulate |
     * | purchase                        | sold −1                                      | −1        |
     * | airdrop                         | airdropped_count −1                           | −1        |
     * | blindbox                        | blind_box_items.quantity_distributed −1      | −1        |
     * | lucky_draw / synthesis           | 对应配额 used_quantity −1                    | −1        |
     * | transfer                        | 追溯原始来源（行上外键保留）同上处理          | −1        |
     *
     * 追溯规则：外键字段优先（order_id/airdrop_record_id/blind_box_item_id 在转赠与
     * 盲盒空投场景下均保留原始来源信息），无外键时按 source 字段兜底。
     *
     * @param array $uc user_collectibles 行（含 id/collectible_id/source/order_id/airdrop_record_id/blind_box_item_id）
     * @return array 回退明细
     */
    public static function revertOnRecover(array $uc): array
    {
        $collectibleId = (int) $uc['collectible_id'];
        $now = date('Y-m-d H:i:s');

        // 追溯原始来源：外键优先（transfer 行与盲盒空投行的外键均保留原始信息）
        $origin = null;
        if (!empty($uc['order_id'])) {
            $origin = 'purchase';
        } elseif (!empty($uc['airdrop_record_id'])) {
            $origin = 'airdrop';
        } elseif (!empty($uc['blind_box_item_id'])) {
            $origin = 'blindbox';
        } elseif (Db::name('lucky_draw_records')->where('user_collectible_id', $uc['id'])->count() > 0) {
            $origin = 'lucky_draw';
        } elseif (Db::name('synthesis_record_items')->where('user_collectible_id', $uc['id'])->count() > 0) {
            $origin = 'synthesis';
        }

        $source = $origin ?? (string) $uc['source'];
        if ($source === 'transfer' || !in_array($source, ['purchase', 'airdrop', 'blindbox', 'lucky_draw', 'synthesis'], true)) {
            $source = 'purchase'; // 无法追溯时按购买路径回退（兜底）
        }

        $detail = ['source' => $source, 'reverted' => false];

        switch ($source) {
            case 'purchase':
                $ok = Db::name('collectibles')
                    ->where('id', $collectibleId)
                    ->where('sold', '>=', 1)
                    ->where('circulate', '>=', 1)
                    ->update([
                        'sold'      => Db::raw('sold - 1'),
                        'circulate' => Db::raw('circulate - 1'),
                        'updated_at' => $now,
                    ]);
                $detail['counter'] = 'sold';
                break;

            case 'airdrop':
                $ok = Db::name('collectibles')
                    ->where('id', $collectibleId)
                    ->where('airdropped_count', '>=', 1)
                    ->where('circulate', '>=', 1)
                    ->update([
                        'airdropped_count' => Db::raw('airdropped_count - 1'),
                        'circulate'        => Db::raw('circulate - 1'),
                        'updated_at'       => $now,
                    ]);
                $detail['counter'] = 'airdropped_count';
                break;

            case 'blindbox':
                // 盲盒奖品：quantity_distributed −1（活动台账）
                if (!empty($uc['blind_box_item_id'])) {
                    Db::name('blind_box_items')
                        ->where('id', (int) $uc['blind_box_item_id'])
                        ->where('quantity_distributed', '>=', 1)
                        ->update([
                            'quantity_distributed' => Db::raw('quantity_distributed - 1'),
                            'updated_at' => $now,
                        ]);
                }
                $ok = Db::name('collectibles')
                    ->where('id', $collectibleId)
                    ->where('circulate', '>=', 1)
                    ->update([
                        'circulate'  => Db::raw('circulate - 1'),
                        'updated_at' => $now,
                    ]);
                $detail['counter'] = 'blind_box_items.quantity_distributed';
                break;

            case 'lucky_draw':
            case 'synthesis':
                // 配额消耗回退：used_quantity −1（quota_type 6抽奖 / 7其他-合成）
                $quotaType = $source === 'lucky_draw' ? 6 : 7;
                self::revertQuotaUsage($collectibleId, $quotaType);
                $ok = Db::name('collectibles')
                    ->where('id', $collectibleId)
                    ->where('circulate', '>=', 1)
                    ->update([
                        'circulate'  => Db::raw('circulate - 1'),
                        'updated_at' => $now,
                    ]);
                $detail['counter'] = "quota[{$quotaType}].used_quantity";
                break;

            default:
                $ok = false;
                break;
        }

        $detail['reverted'] = (bool) $ok;
        return $detail;
    }

    /**
     * 回退配额已使用量（找类型匹配且 used>0 的配额扣减 1）
     */
    private static function revertQuotaUsage(int $collectibleId, int $quotaType): void
    {
        $quota = Db::name('inventory_quotas')
            ->where('collectible_id', $collectibleId)
            ->where('quota_type', $quotaType)
            ->where('used_quantity', '>=', 1)
            ->order('id')
            ->find();
        if ($quota) {
            Db::name('inventory_quotas')
                ->where('id', $quota['id'])
                ->where('used_quantity', '>=', 1)
                ->update([
                    'used_quantity' => Db::raw('used_quantity - 1'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
        }
        // 无配额记录（历史数据/未配置配额）时静默跳过，仅回退 circulate
    }

    // =====================================================================
    // 库存守恒审计（文档 4.3.1 / 8.6 #48）
    // =====================================================================

    /**
     * 库存守恒校验：
     *   ① edition = 库存池 + locked + reserved + sold + airdropped + destroyed
     *   ② circulate = 资产行总数（held+consigned+frozen+transferred+consumed，按 source 分组核对）
     */
    public static function audit(int $collectibleId): array
    {
        $c = Db::name('collectibles')->where('id', $collectibleId)->find();
        if (!$c) {
            throw new InventoryException('藏品不存在');
        }

        $counters = self::counters($c);
        $actualSum = $counters['stockPool'] + $counters['lockedQuantity'] + $counters['reservedCount']
            + $counters['sold'] + $counters['airdroppedCount'] + $counters['destroyedCount'];

        // 资产分布统计（按状态与来源）
        $byStatus = Db::name('user_collectibles')
            ->where('collectible_id', $collectibleId)
            ->group('status')
            ->field('status, COUNT(*) AS cnt')
            ->select()
            ->toArray();
        $bySource = Db::name('user_collectibles')
            ->where('collectible_id', $collectibleId)
            ->group('source')
            ->field('source, COUNT(*) AS cnt')
            ->select()
            ->toArray();
        $assetTotal = 0;
        $statusMap = [];
        foreach ($byStatus as $row) {
            $statusMap[$row['status']] = (int) $row['cnt'];
            $assetTotal += (int) $row['cnt'];
        }
        $sourceMap = [];
        foreach ($bySource as $row) {
            $sourceMap[$row['source']] = (int) $row['cnt'];
        }

        $holding = [
            'held'      => $statusMap['held'] ?? 0,
            'consigned' => $statusMap['consigned'] ?? 0,
            'frozen'    => $statusMap['frozen'] ?? 0,
            'transferred' => $statusMap['transferred'] ?? 0,
            'consumed'  => $statusMap['consumed'] ?? 0,
        ];

        // 配额一览（审计上下文）
        $quotas = Db::name('inventory_quotas')
            ->where('collectible_id', $collectibleId)
            ->order('id')
            ->select()
            ->toArray();
        $quotaList = [];
        $quotaPlanned = 0;
        $quotaUsed = 0;
        foreach ($quotas as $q) {
            $quotaList[] = [
                'id'              => (int) $q['id'],
                'quotaType'       => (int) $q['quota_type'],
                'quotaName'       => $q['quota_name'],
                'plannedQuantity' => (int) $q['planned_quantity'],
                'usedQuantity'    => (int) $q['used_quantity'],
                'status'          => (int) $q['status'],
                'activityType'    => $q['activity_type'],
            ];
            if ((int) $q['status'] === 1) {
                $quotaPlanned += (int) $q['planned_quantity'];
            }
            $quotaUsed += (int) $q['used_quantity'];
        }

        return [
            'counters' => $counters,
            'conservation' => [
                'ok'       => $actualSum === (int) $c['edition'],
                'expected' => (int) $c['edition'],
                'actual'   => $actualSum,
                'formula'  => '库存池 + locked + reserved + sold + airdropped + destroyed = edition',
            ],
            'holding' => [
                'ok'        => $assetTotal === (int) $c['circulate'],
                'expected'  => (int) $c['circulate'],
                'actual'    => $assetTotal,
                'byStatus'  => $holding,
                'bySource'  => $sourceMap,
                'formula'   => '资产总数(held+consigned+frozen+transferred+consumed) = circulate',
            ],
            'quotas' => [
                'list'         => $quotaList,
                'activePlanned' => $quotaPlanned,
                'usedTotal'    => $quotaUsed,
                'reservedMatch' => $quotaPlanned === (int) $c['reserved_count'],
            ],
            'ok' => ($actualSum === (int) $c['edition']) && ($assetTotal === (int) $c['circulate']),
        ];
    }
}
