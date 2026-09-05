<?php
declare(strict_types=1);

namespace app\service;

use app\admin\service\InventoryService;
use think\facade\Db;

/**
 * 奖励统一发放服务（文档 5.4 奖励类型统一）
 *
 * 六类奖励：
 *   collectible              藏品（配额 used + 发放量 ≤ planned 先到先得，或库存池充足）
 *   priority_qualification   优先购资格（活动存在且未结束）
 *   eligibility_qualification 资格购资格（目标藏品存在）
 *   draw_chance              抽奖次数（lucky_draw_chances 台账）
 *   blindbox                 盲盒（盲盒库存池充足）
 *   points                   司南币（钱包流水）
 *
 * 供管理端活动空投（#123）与 C 端发放点（签到/注册/邀请/抽奖，文档第 10 章配套收敛）复用。
 * 所有方法须在调用方事务内执行。
 */
class RewardGrantService
{
    /** 资产类奖励（写 airdrop_records 发放台账） */
    public const ASSET_TYPES = ['collectible', 'blindbox'];

    /** 奖励类型全集 */
    public const TYPES = [
        'collectible', 'priority_qualification', 'eligibility_qualification',
        'draw_chance', 'blindbox', 'points',
    ];

    /** 场景 → 配额类型（文档 4.3.2：1优先购 2活动空投 3签到 4注册 5邀请 6抽奖 7其他） */
    private const SCENE_QUOTA = [
        'airdrop'    => 2,
        'checkin'    => 3,
        'register'   => 4,
        'invite'     => 5,
        'lucky_draw' => 6,
    ];

    /** 场景 → lucky_draw_chances.source（台账来源枚举） */
    private const CHANCE_SOURCE = [
        'checkin'    => 'checkin',
        'invite'     => 'invite',
        'airdrop'    => 'airdrop',
        'register'   => 'register',
        'lucky_draw' => 'free',
    ];

    /**
     * 校验并规范化奖励配置（管理端配置保存 / 发放前复用）
     *
     * @param string $rewardType 六类之一
     * @param array  $config     奖励配置（按类型见各分支）
     * @param bool   $checkRef  是否校验引用对象存在（默认 true）
     * @return array 规范化配置（含 rewardType）
     * @throws RewardGrantException 校验失败
     */
    public static function validate(string $rewardType, array $config, bool $checkRef = true): array
    {
        if (!in_array($rewardType, self::TYPES, true)) {
            throw new RewardGrantException("未知奖励类型 {$rewardType}");
        }

        $qty = max(1, (int) ($config['quantity'] ?? 1));
        $result = ['rewardType' => $rewardType];

        switch ($rewardType) {
            case 'collectible':
                $cid = (int) ($config['collectibleId'] ?? 0);
                if ($cid <= 0) {
                    throw new RewardGrantException('藏品奖励必须指定 collectibleId');
                }
                if ($qty > 9999) {
                    throw new RewardGrantException('奖励数量必须为 1~9999');
                }
                if ($checkRef) {
                    $c = Db::name('collectibles')->where('id', $cid)->whereNull('deleted_at')->find();
                    if (!$c) {
                        throw new RewardGrantException("藏品 #{$cid} 不存在");
                    }
                    if ((int) $c['is_blind_box'] === 1 && !isset($config['allowBlindBox'])) {
                        throw new RewardGrantException("藏品 #{$cid} 是盲盒，请选择 blindbox 奖励类型");
                    }
                }
                $result += ['collectibleId' => $cid, 'quantity' => $qty];
                break;

            case 'priority_qualification':
                $saleId = (int) ($config['prioritySaleId'] ?? 0);
                if ($saleId <= 0) {
                    throw new RewardGrantException('优先购资格奖励必须指定 prioritySaleId');
                }
                if ($checkRef) {
                    $sale = Db::name('priority_sales')->where('id', $saleId)->find();
                    if (!$sale) {
                        throw new RewardGrantException("优先购活动 #{$saleId} 不存在");
                    }
                    if (strtotime((string) $sale['end_time']) < time()) {
                        throw new RewardGrantException('优先购活动已结束，无法发放资格');
                    }
                }
                $result += ['prioritySaleId' => $saleId, 'quantity' => $qty, 'expiresAt' => self::validateExpiresAt($config)];
                break;

            case 'eligibility_qualification':
                $cid = (int) ($config['collectibleId'] ?? 0);
                if ($cid <= 0) {
                    throw new RewardGrantException('资格购资格奖励必须指定目标藏品 collectibleId');
                }
                if ($checkRef) {
                    if (!Db::name('collectibles')->where('id', $cid)->whereNull('deleted_at')->find()) {
                        throw new RewardGrantException("目标藏品 #{$cid} 不存在");
                    }
                }
                $result += ['collectibleId' => $cid, 'quantity' => $qty, 'expiresAt' => self::validateExpiresAt($config)];
                break;

            case 'draw_chance':
                $result += ['quantity' => $qty];
                break;

            case 'blindbox':
                $bid = (int) ($config['blindboxId'] ?? 0);
                if ($bid <= 0) {
                    throw new RewardGrantException('盲盒奖励必须指定 blindboxId');
                }
                if ($checkRef) {
                    $bb = Db::name('blind_boxes')->alias('bb')
                        ->join('collectibles c', 'c.id = bb.collectible_id')
                        ->where('bb.id', $bid)
                        ->whereNull('c.deleted_at')
                        ->field('bb.*,c.sold,c.locked_quantity,c.reserved_count,c.airdropped_count,c.destroyed_count')
                        ->find();
                    if (!$bb) {
                        throw new RewardGrantException("盲盒 #{$bid} 不存在");
                    }
                }
                $result += ['blindboxId' => $bid, 'quantity' => $qty];
                break;

            case 'points':
                $amount = (float) ($config['amount'] ?? 0);
                if ($amount <= 0) {
                    throw new RewardGrantException('司南币奖励金额必须大于 0');
                }
                if ($amount > 1000000) {
                    throw new RewardGrantException('司南币奖励金额超出上限');
                }
                $result += ['amount' => round($amount, 2)];
                break;
        }

        return $result;
    }

    /**
     * 发放奖励（六类统一入口，文档 5.4；调用方事务内执行）
     *
     * @param string $rewardType  六类之一
     * @param array  $rewardConfig validate() 规范化后的配置
     * @param int    $userId      接收用户
     * @param string $scene       airdrop/checkin/register/invite/lucky_draw
     * @param array  $context     ['title'=>描述, 'activityId'=>关联活动, 'relatedId'=>来源业务ID, 'activityType'=>活动类型]
     * @return array 发放结果 ['rewardType'=>.., 'issued'=>发放明细]
     * @throws RewardGrantException 配额/库存不足等业务拦截
     */
    public static function grant(string $rewardType, array $rewardConfig, int $userId, string $scene, array $context = []): array
    {
        $now = date('Y-m-d H:i:s');
        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) {
            throw new RewardGrantException("用户 #{$userId} 不存在");
        }

        switch ($rewardType) {
            case 'collectible':
                return self::grantCollectible((int) $rewardConfig['collectibleId'], (int) $rewardConfig['quantity'], $userId, (string) $user['phone'], $scene, $context, false);

            case 'blindbox':
                return self::grantCollectible((int) $rewardConfig['blindboxId'], (int) $rewardConfig['quantity'], $userId, (string) $user['phone'], $scene, $context, true);

            case 'points':
                return self::grantPoints((float) $rewardConfig['amount'], $userId, (string) ($context['title'] ?? '活动奖励'));

            case 'draw_chance':
                return self::grantDrawChances((int) $rewardConfig['quantity'], $userId, $scene, $context);

            case 'priority_qualification':
                return self::grantPriorityQualification((int) $rewardConfig['prioritySaleId'], (int) $rewardConfig['quantity'], (string) ($rewardConfig['expiresAt'] ?? ''), $userId, (string) $user['phone']);

            case 'eligibility_qualification':
                return self::grantEligibilityQualification((int) $rewardConfig['collectibleId'], (int) $rewardConfig['quantity'], (string) ($rewardConfig['expiresAt'] ?? ''), $userId, (string) $user['phone'], $context);
        }

        throw new RewardGrantException("未知奖励类型 {$rewardType}");
    }

    // =====================================================================
    // collectible / blindbox：配额先到先得（4.3.2），无配额时走库存池（5.4「或库存池充足」）
    // =====================================================================

    /**
     * 发放藏品/盲盒资产
     *
     * 配额路径：consumeQuota（used + N ≤ planned，不足拦截「配额预留不足」）+ circulate +N；
     * 无配额路径：airdropped_count +N + circulate +N（条件更新守恒式，池不足拦截）。
     * 盲盒资产行 source=purchase（保持 C 端开盒可用，溯源靠 airdrop_record_id，与独立空投一致）。
     *
     * @param bool $isBlindbox true=按盲盒ID发放（blindboxId 为 nft_blind_boxes.id）
     */
    private static function grantCollectible(int $targetId, int $quantity, int $userId, string $phone, string $scene, array $context, bool $isBlindbox): array
    {
        // 盲盒 → 解析其藏品行；藏品 → 直接用
        if ($isBlindbox) {
            $bb = Db::name('blind_boxes')->where('id', $targetId)->find();
            if (!$bb) {
                throw new RewardGrantException("盲盒 #{$targetId} 不存在");
            }
            $collectibleId = (int) $bb['collectible_id'];
        } else {
            $collectibleId = $targetId;
        }

        $c = Db::name('collectibles')->where('id', $collectibleId)->lock(true)->find();
        if (!$c) {
            throw new RewardGrantException("藏品 #{$collectibleId} 不存在");
        }

        $quotaType = self::SCENE_QUOTA[$scene] ?? 7;
        $hasQuota = Db::name('inventory_quotas')
            ->where('collectible_id', $collectibleId)
            ->where('quota_type', $quotaType)
            ->where('status', 1)
            ->count() > 0;

        $now = date('Y-m-d H:i:s.v');
        $issued = [];

        if ($hasQuota) {
            // 配额路径（文档 4.3.2：活动实际发放动态校验，先到先得）
            InventoryService::consumeQuota(
                $collectibleId,
                $quotaType,
                $quantity,
                (string) ($context['activityType'] ?? $scene),
                isset($context['activityId']) ? (int) $context['activityId'] : null
            );
            // 配额消耗计入 circulate（文档 4.3.1）
            Db::name('collectibles')
                ->where('id', $collectibleId)
                ->where('circulate + ' . $quantity . ' <= edition')
                ->update(['circulate' => Db::raw("circulate + {$quantity}"), 'updated_at' => $now]);
            $via = 'quota';
        } else {
            // 无配额 → 库存池路径（文档 5.4「或库存池充足」）
            $ok = Db::name('collectibles')
                ->where('id', $collectibleId)
                ->whereRaw("sold + locked_quantity + reserved_count + airdropped_count + {$quantity} + destroyed_count <= edition")
                ->update([
                    'airdropped_count' => Db::raw("airdropped_count + {$quantity}"),
                    'circulate'        => Db::raw("circulate + {$quantity}"),
                    'updated_at'       => $now,
                ]);
            if (!$ok) {
                $pool = InventoryService::stockPool($c);
                throw new RewardGrantException("库存池不足，当前库存池为 {$pool}，本次需发放 {$quantity}");
            }
            $via = 'stock_pool';
        }

        for ($i = 0; $i < $quantity; $i++) {
            // 发放台账（airdrop_records，activity_id 关联来源活动）
            $recordId = Db::name('airdrop_records')->insertGetId([
                'activity_id'  => isset($context['activityId']) && $context['activityId'] > 0 ? (int) $context['activityId'] : null,
                'user_id'     => $userId,
                'phone'       => $phone,
                'collectible_id' => $collectibleId,
                'quantity'    => 1,
                'status'      => 'issued',
                'reward_type' => $isBlindbox ? 'blindbox' : 'collectible',
                'issued_at'   => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            // 资产行（先占位取 ID 再回写编号，防并发重号）
            Db::name('user_collectibles')->insert([
                'user_id'           => $userId,
                'collectible_id'    => $collectibleId,
                'airdrop_record_id' => $recordId,
                'serial'            => gen_serial_placeholder(),
                'source'            => $isBlindbox ? 'purchase' : 'airdrop',
                'acquired_price'    => 0,
                'acquired_at'       => $now,
                'status'            => 'held',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $ucId = (int) Db::name('user_collectibles')->getLastInsID();
            $serial = 'SN-' . $collectibleId . '-' . str_pad((string) $ucId, 4, '0', STR_PAD_LEFT);
            Db::name('user_collectibles')->where('id', $ucId)->update(['serial' => $serial, 'updated_at' => $now]);
            Db::name('airdrop_records')->where('id', $recordId)->update([
                'user_collectible_id' => $ucId,
                'updated_at'          => $now,
            ]);
            $issued[] = ['airdropRecordId' => $recordId, 'userCollectibleId' => $ucId, 'serial' => $serial];
        }

        return ['rewardType' => $isBlindbox ? 'blindbox' : 'collectible', 'via' => $via, 'quantity' => $quantity, 'issued' => $issued];
    }

    // =====================================================================
    // points：钱包司南币 + 流水
    // =====================================================================

    private static function grantPoints(float $amount, int $userId, string $title): array
    {
        $now = date('Y-m-d H:i:s.v');
        Db::name('wallets')->where('user_id', $userId)->update([
            'points'     => Db::raw('points + ' . $amount),
            'updated_at' => $now,
        ]);
        $after = (float) Db::name('wallets')->where('user_id', $userId)->value('points');
        Db::name('wallet_transactions')->insert([
            'user_id'       => $userId,
            'trans_type'   => 'reward',
            'title'        => mb_substr($title !== '' ? $title : '活动奖励', 0, 64),
            'direction'    => 1,
            'amount'       => $amount,
            'balance_after' => $after,
            'created_at'   => $now,
        ]);
        return ['rewardType' => 'points', 'amount' => $amount];
    }

    // =====================================================================
    // draw_chance：抽奖次数台账
    // =====================================================================

    private static function grantDrawChances(int $quantity, int $userId, string $scene, array $context): array
    {
        // 抽奖次数必须挂靠一个抽奖活动：显式指定或取当前启用中的
        $activityId = (int) ($context['luckyDrawActivityId'] ?? 0);
        if ($activityId <= 0) {
            $act = Db::name('lucky_draw_activities')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->find();
            if (!$act) {
                throw new RewardGrantException('无进行中的抽奖活动，无法发放抽奖次数');
            }
            $activityId = (int) $act['id'];
        }

        $now = date('Y-m-d H:i:s');
        $id = Db::name('lucky_draw_chances')->insertGetId([
            'user_id'       => $userId,
            'activity_id'   => $activityId,
            'source'        => self::CHANCE_SOURCE[$scene] ?? 'airdrop',
            'total_quantity' => $quantity,
            'used_quantity' => 0,
            'related_id'    => isset($context['relatedId']) && $context['relatedId'] > 0 ? (int) $context['relatedId'] : null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        return ['rewardType' => 'draw_chance', 'quantity' => $quantity, 'luckyDrawActivityId' => $activityId, 'chanceId' => $id];
    }

    // =====================================================================
    // priority_qualification：优先购白名单（upsert 叠加次数）
    // =====================================================================

    private static function grantPriorityQualification(int $saleId, int $quantity, string $expiresAt, int $userId, string $phone): array
    {
        $sale = Db::name('priority_sales')->where('id', $saleId)->lock(true)->find();
        if (!$sale) {
            throw new RewardGrantException("优先购活动 #{$saleId} 不存在");
        }
        if (strtotime((string) $sale['end_time']) < time()) {
            throw new RewardGrantException('优先购活动已结束，无法发放资格');
        }
        $expires = $expiresAt !== '' ? $expiresAt : (string) $sale['end_time'];

        $now = date('Y-m-d H:i:s');
        $exists = Db::name('priority_sale_whitelists')
            ->where('priority_sale_id', $saleId)
            ->where('user_id', $userId)
            ->lock(true)
            ->find();
        if ($exists) {
            Db::name('priority_sale_whitelists')->where('id', $exists['id'])->update([
                'max_quantity' => Db::raw('max_quantity + ' . $quantity),
                'expires_at'   => $expires,
                'status'       => 1,
                'updated_at'   => $now,
            ]);
            $wid = (int) $exists['id'];
        } else {
            $wid = (int) Db::name('priority_sale_whitelists')->insertGetId([
                'priority_sale_id' => $saleId,
                'user_id'          => $userId,
                'phone'            => $phone,
                'max_quantity'     => $quantity,
                'used_quantity'    => 0,
                'expires_at'       => $expires,
                'status'           => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
        return ['rewardType' => 'priority_qualification', 'quantity' => $quantity, 'whitelistId' => $wid, 'prioritySaleId' => $saleId, 'expiresAt' => $expires];
    }

    // =====================================================================
    // eligibility_qualification：资格购白名单（upsert 有效期）
    // =====================================================================

    private static function grantEligibilityQualification(int $collectibleId, int $quantity, string $expiresAt, int $userId, string $phone, array $context): array
    {
        // 定位目标藏品的资格购配置（未开启资格购则自动开启一条默认配置？否——拦截）
        $configId = (int) ($context['qualificationConfigId'] ?? 0);
        $config = null;
        if ($configId > 0) {
            $config = Db::name('qualification_configs')
                ->where('id', $configId)
                ->where('collectible_id', $collectibleId)
                ->find();
        }
        if (!$config) {
            $config = Db::name('qualification_configs')
                ->where('collectible_id', $collectibleId)
                ->where('is_enabled', 1)
                ->find();
        }
        if (!$config) {
            throw new RewardGrantException("目标藏品 #{$collectibleId} 未开启资格购，无法发放资格");
        }

        $now = date('Y-m-d H:i:s');
        $expires = $expiresAt !== ''
            ? $expiresAt
            : ((string) ($config['valid_end_at'] ?: date('Y-m-d H:i:s', time() + 30 * 86400)));

        $exists = Db::name('qualification_whitelists')
            ->where('config_id', (int) $config['id'])
            ->where('user_id', $userId)
            ->lock(true)
            ->find();
        if ($exists) {
            Db::name('qualification_whitelists')->where('id', $exists['id'])->update([
                'expires_at' => $expires,
                'status'     => 1,
                'updated_at' => $now,
            ]);
            $wid = (int) $exists['id'];
        } else {
            $wid = (int) Db::name('qualification_whitelists')->insertGetId([
                'config_id'  => (int) $config['id'],
                'user_id'    => $userId,
                'phone'      => $phone,
                'status'     => 1,
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return ['rewardType' => 'eligibility_qualification', 'quantity' => $quantity, 'whitelistId' => $wid, 'configId' => (int) $config['id'], 'expiresAt' => $expires];
    }

    /**
     * 有效期校验（YYYY-MM-DD HH:mm:ss，精确到时分秒，文档 5.4）
     */
    private static function validateExpiresAt(array $config): string
    {
        $expires = trim((string) ($config['expiresAt'] ?? ''));
        if ($expires !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires) || !strtotime($expires))) {
            throw new RewardGrantException('有效期必须为「YYYY-MM-DD HH:mm:ss」格式（精确到时分秒）');
        }
        return $expires;
    }
}
