<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;
use app\service\InboxService;

use think\facade\Db;

/**
 * 盲盒控制器
 */
class BlindBoxes extends BaseController
{
    /**
     * GET /api/blind-boxes
     * 盲盒列表
     */
    public function index()
    {
        $list = Db::name('blind_boxes')->alias('bb')
            ->join('collectibles c', 'c.id = bb.collectible_id')
            ->where('c.status', '<>', 'soldout')
            ->where('bb.is_openable', 1)
            ->whereNull('c.deleted_at')
            ->select()
            ->toArray();

        $items = [];
        foreach ($list as $bb) {
            $items[] = [
                'collectibleId' => (int) $bb['collectible_id'],
                'blindBoxId'    => (int) $bb['id'],
                'name'          => $bb['name'],
                'price'         => (float) $bb['price'],
                'image'         => $bb['image'],
                'edition'       => (int) $bb['edition'],
                'description'   => $bb['description'],
                'status'        => $bb['status'],
                'stock'         => (int) $bb['edition'] - (int) $bb['sold'] - (int) $bb['locked_quantity'],
            ];
        }

        return $this->success(['list' => $items]);
    }

    /**
     * 盲盒奖池的「展示口径」取数（C 端详情页「盲盒内容」用）
     *
     * 为什么单独成方法、且只此一份实现：
     * 概率在库里存的是 (0,1] 的小数（后台校验见 admin BlindBoxController::create），
     * 稀有度根本没有列、只能由概率推。这两件事只要有第二个地方各写一遍，
     * 同一件奖品就会在两个页面显示成两个标签。故 C 端一律走这里。
     * 注意与 open() 里的取数区分：那边要行锁、要看剩余限量，是「抽奖」而不是「展示」。
     */
    public static function poolOf(int $collectibleId): array
    {
        if ($collectibleId <= 0) {
            return [];
        }
        $boxId = (int) Db::name('blind_boxes')->where('collectible_id', $collectibleId)->value('id');
        if ($boxId <= 0) {
            return [];
        }
        // 奖品藏品被软删或已不存在时 prize_name 为空：整行跳过，不给 C 端留一行空壳
        // （deleted_at 条件挂在 join 后的 WHERE 上：软删的行 c2.deleted_at 非空会被剔掉，
        //   奖品记录彻底不存在的行 c2.* 全 NULL，落到下面的空名判断）
        $rows = Db::name('blind_box_items')->alias('bbi')
            ->field('bbi.prize_collectible_id, bbi.probability, c2.name AS prize_name, c2.image AS prize_image')
            ->join('collectibles c2', 'c2.id = bbi.prize_collectible_id', 'LEFT')
            ->where('bbi.blind_box_id', $boxId)
            ->whereNull('bbi.deleted_at')
            ->whereNull('c2.deleted_at')
            ->order('bbi.probability', 'desc')
            ->select()->toArray();

        $out = [];
        foreach ($rows as $r) {
            if (($r['prize_name'] ?? '') === '') {
                continue;
            }
            $prob = (float) $r['probability'];
            $out[] = [
                'prizeCollectibleId' => (int) $r['prize_collectible_id'],
                'name'               => (string) $r['prize_name'],
                'coverImage'         => (string) ($r['prize_image'] ?? ''),
                'probability'        => self::percentText($prob),
                'rarity'             => self::rarityOf($prob),
            ];
        }
        return $out;
    }

    /** 概率小数 → 百分数文案：1 → "100%"、0.1 → "10%"、0.0375 → "3.75%"（整数不带多余小数点） */
    public static function percentText(float $probability): string
    {
        $s = number_format($probability * 100, 2, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return ($s === '' ? '0' : $s) . '%';
    }

    /**
     * 稀有度按概率推导（库里没这一列，奖池配置也不含）：
     * 越难开出来越稀有。阈值与前端已有的四色角标（普通/稀有/史诗/传说）一一对应。
     */
    public static function rarityOf(float $probability): string
    {
        if ($probability >= 0.5)  return '普通';
        if ($probability >= 0.2)  return '稀有';
        if ($probability >= 0.05) return '史诗';
        return '传说';
    }

    /**
     * POST /api/blind-boxes/open
     * 开启盲盒
     */
    public function open()
    {
        $userId            = $this->userId();
        $userCollectibleId = $this->intParam('userCollectibleId');
        $paymentPassword   = $this->request->post('paymentPassword', '');

        if (!$userId) return $this->fail(2001, '未登录');

        $hash = Db::name('users')->where('id', $userId)->value('transaction_password');
        if (!$hash || !verify_password($paymentPassword, $hash)) {
            return $this->fail(2003, '交易密码错误');
        }

        Db::startTrans();
        try {
            // 显式字段：uc.id 与 bb.id 同名，SELECT * 会发生列覆盖导致取错值
            // BB35 修复：原实现限定 uc.source='purchase'，空投（airdrop）等来源的盲盒无法开启；
            // 开盒资格以"持有该盲盒藏品（bb.is_openable=1）且状态 held"为准，不区分获得渠道
            $uc = Db::name('user_collectibles')
                ->alias('uc')
                ->join('blind_boxes bb', 'bb.collectible_id = uc.collectible_id')
                ->where('uc.id', $userCollectibleId)
                ->where('uc.user_id', $userId)
                ->where('uc.status', 'held')
                ->where('bb.is_openable', 1)
                ->field('uc.id as uc_id, uc.collectible_id, bb.id as bb_id')
                ->lock(true)
                ->find();
            if (!$uc) {
                Db::rollback();
                return $this->fail(1001, '藏品不可开启或已开启');
            }

            // 奖池按盲盒ID（bb.id）查询，而非藏品ID
            $items = Db::name('blind_box_items')
                ->where('blind_box_id', (int) $uc['bb_id'])
                ->whereNull('deleted_at')
                ->select()
                ->toArray();
            if (!$items) {
                Db::rollback();
                return $this->fail(1001, '盲盒奖品配置缺失');
            }

            // 排除已抽完的奖品，兜底奖品也只能在未抽完的奖品中产生
            $available = array_values(array_filter($items, function ($item) {
                return $item['quantity_limit'] === null
                    || (int) $item['quantity_distributed'] < (int) $item['quantity_limit'];
            }));
            if (!$available) {
                Db::rollback();
                return $this->fail(3001, '盲盒奖品已发放完毕');
            }

            // 加密随机数源，概率配置读取自数据库（同一盲盒合计=1，业务层兜底归一化）
            $rand       = random_int(1, 100000000) / 100000000;
            $cumulative = 0;
            $winner     = null;

            foreach ($available as $item) {
                $cumulative += (float) $item['probability'];
                if ($rand <= $cumulative) {
                    $winner = $item;
                    break;
                }
            }
            if (!$winner) $winner = $available[array_key_last($available)];

            $now = date('Y-m-d H:i:s.v');

            // 消耗盲盒资产
            Db::name('user_collectibles')->where('id', $userCollectibleId)->update([
                'status'     => 'consumed',
                'updated_at' => $now,
            ]);

            // BB36 修复：开盒数 opened_count 与奖池发放总数对账口径一致（原实现未自增，
            // 导致开盒后管理端「开盒对账」恒报 opened_count ≠ quantity_distributed）
            Db::name('blind_boxes')->where('id', (int) $uc['bb_id'])->inc('opened_count')->update();

            // 更新奖品已发放：读-改-写在多用户并发开盒（各自锁自己的 uc 行，互不排斥）下会超发限量奖，
            // 改为守恒条件原子扣减并校验影响行数；中奖奖品被抢完时按剩余候选顺延
            $itemById = array_column($available, null, 'id');
            $tryOrder = array_merge([$winner['id']], array_column($available, 'id'));
            $pickedId = null;
            $tried    = [];
            foreach ($tryOrder as $itemId) {
                $itemId = (int) $itemId;
                if (isset($tried[$itemId])) continue;
                $tried[$itemId] = true;
                $affected = Db::name('blind_box_items')
                    ->where('id', $itemId)
                    ->whereRaw('(quantity_limit IS NULL OR quantity_distributed < quantity_limit)')
                    ->update([
                        'quantity_distributed' => Db::raw('quantity_distributed + 1'),
                        'updated_at'           => $now,
                    ]);
                if ($affected) {
                    $pickedId = $itemId;
                    break;
                }
            }
            if (!$pickedId) {
                Db::rollback();
                return $this->fail(3001, '盲盒奖品已发放完毕');
            }
            $winner = $itemById[$pickedId];

            // 生成奖品资产：编号走全项目唯一取号入口（1..发行量随机、不越界、不重号）
            // 旧写法用资产表自增行 id 当编号尾段，会让奖品的编号跑到自己发行量之外
            $prizeCollectibleId = (int) $winner['prize_collectible_id'];
            $serial = \app\service\SerialService::next($prizeCollectibleId);
            Db::name('user_collectibles')->insert([
                'user_id'           => $userId,
                'collectible_id'   => $prizeCollectibleId,
                'blind_box_item_id' => $winner['id'],
                'serial'           => $serial,
                'source'           => 'blindbox',
                'acquired_price'   => 0,
                'acquired_at'      => $now,
                'status'           => 'held',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            $newUcId = (int) Db::name('user_collectibles')->getLastInsID();

            // 奖品藏品流通量 +1（与发售/空投路径保持一致，保证 circulate = 资产行总数）
            Db::name('collectibles')->where('id', $prizeCollectibleId)->update([
                'circulate'  => Db::raw('circulate + 1'),
                'updated_at' => $now,
            ]);

            // 到账通知：开盒结果本页已经弹给看过，所以把 inboxId 一并回传，
            // 前端拿到就标记已读 —— 记录留档、后台可查，但不会再弹第二次（见 InboxPopup.vue）
            $prizeCollectible = Db::name('collectibles')->where('id', $prizeCollectibleId)->find();
            $inboxId = InboxService::pushCollectible($userId, 'blindbox', $prizeCollectible ?: [], [
                'quantity' => 1,
                'serial'   => $serial,
                'reason'   => '开盲盒',
            ], (int) $winner['id']);

            Db::commit();

            return $this->success([
                'prize' => [
                    'collectibleId' => $prizeCollectibleId,
                    'name'          => $prizeCollectible['name'] ?? '',
                    'image'         => $prizeCollectible['image'] ?? '',
                    'no'            => $serial,
                    'rarity'        => '抽奖奖品',
                    'probability'   => (float) $winner['probability'],
                ],
                'inboxId' => $inboxId,
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            \think\facade\Log::error('[blindbox][open] uid=' . $userId . ' err=' . $e->getMessage());
            return $this->fail(5001, '开盒失败，请稍后重试');
        }
    }
}
