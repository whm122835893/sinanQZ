<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use app\service\DrawCodeService;
use app\service\RaffleService;
use think\facade\Db;

/**
 * 抽签发售控制器（C 端）
 * RF03 修复：RaffleService 此前仅被管理端/定时任务调用，C 端无报名与中签购买入口。
 * 活动状态：0草稿 1报名中 2抽签中 3已结束(已抽签) 4已取消
 */
class Raffle extends BaseController
{
    /**
     * GET /api/raffle/activities?status=
     * 抽签活动列表（公开；草稿/已取消不展示）
     */
    public function activities()
    {
        $p      = $this->pagination();
        $status = $this->intParam('status');

        $query = Db::name('raffle_activities')->alias('r')
            ->join('collectibles c', 'c.id = r.collectible_id', 'LEFT')
            ->whereNull('r.deleted_at');
        if ($status > 0) {
            $query->where('r.status', $status);
        } else {
            $query->whereIn('r.status', [1, 2, 3]);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('r.registration_start', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'r.id', 'r.name', 'r.description', 'r.collectible_id',
                'r.ticket_price', 'r.limit_per_user', 'r.winner_count', 'r.sale_quantity', 'r.sale_price',
                'r.registration_start', 'r.registration_end', 'r.draw_time',
                'r.purchase_start', 'r.purchase_end', 'r.status',
                'c.name as collectible_name', 'c.image as collectible_image',
            ])
            ->select()->toArray();

        $now = date('Y-m-d H:i:s');
        $items = array_map(function ($r) use ($now) {
            return [
                'activityId'         => (int) $r['id'],
                'name'              => $r['name'],
                'description'       => $r['description'] ?? '',
                'collectible'       => [
                    'id'    => (int) $r['collectible_id'],
                    'name'  => $r['collectible_name'] ?? '',
                    'image' => $r['collectible_image'] ?? '',
                ],
                'ticketPrice'       => (float) $r['ticket_price'],
                'limitPerUser'      => (int) $r['limit_per_user'],
                'winnerCount'       => (int) $r['winner_count'],
                'saleQuantity'      => (int) $r['sale_quantity'],
                'salePrice'         => (float) $r['sale_price'],
                'registrationStart' => $r['registration_start'],
                'registrationEnd'   => $r['registration_end'],
                'drawTime'          => $r['draw_time'],
                'purchaseStart'     => $r['purchase_start'],
                'purchaseEnd'       => $r['purchase_end'],
                'status'            => (int) $r['status'],
                // 展示阶段：upcoming报名未开始 / registering报名中 / drawn已抽签 / finished已结束
                'phase'             => $this->phase($r, $now),
                'createdAt'         => $r['registration_start'],
            ];
        }, $rows);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * GET /api/raffle/activities/:id
     * 活动详情（可选登录：返回我的报名/中签状态）
     */
    public function detail()
    {
        $userId = $this->userId();
        $id     = $this->intParam('id');

        $r = Db::name('raffle_activities')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$r) return $this->fail(1002, '活动不存在');
        if ((int) $r['status'] === 0) return $this->fail(1002, '活动不存在');

        // 关联藏品完整字段（对齐 /api/collections/:id 详情，供前端复用藏品详情页样式）
        $c = Db::name('collectibles')->where('id', (int) $r['collectible_id'])->whereNull('deleted_at')->find();
        $collectible = $c ? [
            'id'               => (int) $c['id'],
            'name'             => $c['name'],
            'subtitle'         => $c['subtitle'] ?? '',
            'image'            => $c['image'] ?? '',
            'price'            => (float) $c['price'],
            'edition'          => (int) $c['edition'],
            'issueCount'       => (int) $c['edition'],
            'circulationCount' => (int) $c['circulate'],
            'todayCount'       => (int) $c['vol'],
            'description'      => $c['description'] ?? '',
            'issuer'           => $c['issuer'] ?? '司南文创',
        ] : [
            'id'    => (int) $r['collectible_id'],
            'name'  => '',
            'image' => '',
        ];

        $now = date('Y-m-d H:i:s');
        $data = [
            'activityId'         => (int) $r['id'],
            'name'               => $r['name'],
            'description'        => $r['description'] ?? '',
            'collectible'        => $collectible,
            'ticketPrice'       => (float) $r['ticket_price'],
            'limitPerUser'      => (int) $r['limit_per_user'],
            'winnerCount'       => (int) $r['winner_count'],
            'saleQuantity'      => (int) $r['sale_quantity'],
            'salePrice'         => (float) $r['sale_price'],
            'registrationStart' => $r['registration_start'],
            'registrationEnd'   => $r['registration_end'],
            'drawTime'          => $r['draw_time'],
            'purchaseStart'     => $r['purchase_start'],
            'purchaseEnd'       => $r['purchase_end'],
            'status'            => (int) $r['status'],
            'phase'             => $this->phase($r, $now),
            'drawCodeEnabled'   => (int) ($r['draw_code_enabled'] ?? 0) === 1,
            'drawCodePrice'     => (float) ($r['draw_code_price'] ?? 0),
            'maxDrawCodes'      => (int) ($r['max_draw_codes'] ?? 0),
            'drawCodeCount'     => $userId ? DrawCodeService::count($userId) : 0,
            'drawCodes'         => $userId ? DrawCodeService::codes($userId) : [],
            'drawCodeCapped'    => $userId
                && (int) ($r['max_draw_codes'] ?? 0) > 0
                && count(DrawCodeService::activityCodes($userId, $id)) >= (int) ($r['max_draw_codes'] ?? 0),
        ];

        // 我的报名状态（draw_status 不暴露他人信息）
        if ($userId) {
            $reg = Db::name('raffle_registrations')
                ->where('activity_id', $id)
                ->where('user_id', $userId)
                ->find();
            if ($reg) {
                $data['myRegistration'] = [
                    'ticketCount'       => (int) $reg['ticket_count'],
                    'payAmount'          => (float) $reg['pay_amount'],
                    'payStatus'          => (int) $reg['pay_status'],
                    'drawCodes'          => DrawCodeService::activityCodes($userId, $id),
                    'drawStatus'         => (int) $reg['draw_status'],
                    'purchasedQuantity'  => (int) ($reg['purchased_quantity'] ?? 0),
                    'purchasable'        => $this->purchasable($r, $reg, $now),
                ];
            }
        }

        return $this->success($data);
    }

    /**
     * POST /api/raffle/activities/:id/register { ticketCount }
     * 用户报名（免费直接成功；收费从余额扣报名费）
     */
    public function register()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $id          = $this->intParam('id');
        $ticketCount = max(1, $this->intParam('ticketCount', 1));
        if ($id <= 0) return $this->fail(1001, '活动不存在');

        $activity = Db::name('raffle_activities')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$activity) return $this->fail(1002, '活动不存在');
        if ((int) $activity['status'] !== 1) return $this->fail(1001, '活动当前不在报名中');

        // 免费报名：报名成功即发放抽签码凭证（每 1 次报名发 1 码）
        try {
            $reg = RaffleService::register($id, $userId, $ticketCount);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            return $this->fail(1001, $msg ?: '报名失败');
        }

        return $this->success([
            'activityId'  => (int) $id,
            'ticketCount' => $reg['ticketCount'] ?? $ticketCount,
            'drawCodes'   => $reg['drawCodes'] ?? [],
        ]);
    }

    /**
     * POST /api/raffle/activities/:id/purchase-draw-code { quantity }
     * 购买抽签码：每活动开关（draw_code_enabled），单价 draw_code_price 与藏品 sale_price 分离，余额扣款
     */
    public function purchaseDrawCode()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $id  = $this->intParam('id');
        $qty = max(1, $this->intParam('quantity', 1));
        if ($id <= 0) return $this->fail(1001, '活动不存在');

        Db::startTrans();
        try {
            $act = Db::name('raffle_activities')->where('id', $id)->whereNull('deleted_at')->lock(true)->find();
            if (!$act) { Db::rollback(); return $this->fail(1002, '活动不存在'); }
            if ((int) $act['draw_code_enabled'] !== 1) {
                Db::rollback();
                return $this->fail(1001, '当前活动未开放购买抽签码');
            }

            $unitPrice  = (float) $act['draw_code_price'];
            if ($unitPrice <= 0) {
                Db::rollback();
                return $this->fail(1001, '抽签码价格未配置');
            }

            // 最大抽签码校验（0 = 不限）
            $maxCodes = (int) ($act['max_draw_codes'] ?? 0);
            if ($maxCodes > 0) {
                $current = count(DrawCodeService::activityCodes($userId, $id));
                if ($current + $qty > $maxCodes) {
                    Db::rollback();
                    return $this->fail(1001, "每人最多持有 {$maxCodes} 个抽签码");
                }
            }
            $totalPrice = round($unitPrice * $qty, 2);

            $wallet = Db::name('wallets')->where('user_id', $userId)->lock(true)->find();
            if (!$wallet || (float) $wallet['available'] < $totalPrice) {
                Db::rollback();
                return $this->fail(4003, '余额不足');
            }

            $nowV = date('Y-m-d H:i:s.v');
            Db::name('wallets')->where('user_id', $userId)->update([
                'balance'    => Db::raw("balance - {$totalPrice}"),
                'available'  => Db::raw("available - {$totalPrice}"),
                'updated_at' => $nowV,
            ]);
            Db::name('wallet_transactions')->insert([
                'user_id'       => $userId,
                'trans_type'    => 'buy',
                'title'         => '购买抽签码',
                'direction'     => 2,
                'amount'        => $totalPrice,
                'balance_after' => (float) $wallet['available'] - $totalPrice,
                'biz_no'        => 'DC-' . $id . '-' . date('ymdHis'),
                'created_at'    => $nowV,
            ]);

            $codes = [];
            for ($i = 0; $i < $qty; $i++) {
                $codes[] = DrawCodeService::grant($userId, DrawCodeService::SOURCE_PURCHASE, $id);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $msg = $e->getMessage();
            return $this->fail(1001, $msg ?: '购买失败');
        }

        return $this->success([
            'activityId' => (int) $id,
            'quantity'   => $qty,
            'payAmount'  => $totalPrice,
            'drawCodes'  => $codes,
        ]);
    }

    /**
     * GET /api/raffle/registrations/mine
     * 我的抽签报名记录（含中签状态）
     */
    public function mine()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $p = $this->pagination();

        $query = Db::name('raffle_registrations')->alias('reg')
            ->join('raffle_activities r', 'r.id = reg.activity_id', 'LEFT')
            ->join('collectibles c', 'c.id = r.collectible_id', 'LEFT')
            ->where('reg.user_id', $userId);

        $total = (clone $query)->count();
        $rows  = $query->order('reg.id', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'reg.id', 'reg.activity_id', 'reg.ticket_count', 'reg.pay_amount',
                'reg.pay_status', 'reg.draw_status', 'reg.purchased_quantity', 'reg.created_at',
                'r.name as activity_name', 'r.status as activity_status',
                'r.sale_price', 'r.sale_quantity', 'r.purchase_start', 'r.purchase_end',
                'c.name as collectible_name', 'c.image as collectible_image',
            ])
            ->select()->toArray();

        $now = date('Y-m-d H:i:s');
        $items = array_map(function ($r) use ($now) {
            return [
                'id'                => (int) $r['id'],
                'activityId'        => (int) $r['activity_id'],
                'activityName'      => $r['activity_name'] ?? '',
                'collectible'       => [
                    'name'  => $r['collectible_name'] ?? '',
                    'image' => $r['collectible_image'] ?? '',
                ],
                'ticketCount'       => (int) $r['ticket_count'],
                'payAmount'         => (float) $r['pay_amount'],
                'payStatus'         => (int) $r['pay_status'],
                'drawStatus'        => (int) $r['draw_status'],
                'purchasedQuantity' => (int) ($r['purchased_quantity'] ?? 0),
                'salePrice'         => (float) $r['sale_price'],
                'saleQuantity'      => (int) $r['sale_quantity'],
                'purchaseStart'     => $r['purchase_start'],
                'purchaseEnd'       => $r['purchase_end'],
                // 中签且未购满且在购买窗口内 → 可购买
                'purchasable'       => (int) $r['draw_status'] === 1
                    && (int) ($r['purchased_quantity'] ?? 0) < (int) $r['sale_quantity']
                    && $this->inWindow($r['purchase_start'], $r['purchase_end'], $now),
                'createdAt'         => $r['created_at'],
            ];
        }, $rows);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * POST /api/raffle/activities/:id/purchase { quantity }
     * 中签用户购买（有效期内、限购数量内、余额即时结算）
     */
    public function purchase()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $id  = $this->intParam('id');
        $qty = max(1, $this->intParam('quantity', 1));
        if ($id <= 0) return $this->fail(1001, '活动不存在');

        // 实名前置（与发售购买一致）
        $isRealname = Db::name('users')->where('id', $userId)->value('is_realname');
        if ((int) $isRealname !== 1) return $this->fail(1001, '请先完成实名认证');

        Db::startTrans();
        try {
            $act = Db::name('raffle_activities')->where('id', $id)->whereNull('deleted_at')->lock(true)->find();
            if (!$act) { Db::rollback(); return $this->fail(1002, '活动不存在'); }
            if ((int) $act['status'] !== 3) {
                Db::rollback();
                return $this->fail(1001, '活动尚未抽签或已取消，无法购买');
            }

            $now = date('Y-m-d H:i:s');
            if (!$this->inWindow($act['purchase_start'], $act['purchase_end'], $now)) {
                Db::rollback();
                return $this->fail(1001, '不在中签购买有效期内');
            }

            // 我的报名记录：必须中签且已支付
            $reg = Db::name('raffle_registrations')
                ->where('activity_id', $id)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();
            if (!$reg) { Db::rollback(); return $this->fail(1001, '您未报名该活动'); }
            if ((int) $reg['draw_status'] !== 1) { Db::rollback(); return $this->fail(3004, '很遗憾未中签'); }
            if ((int) $reg['pay_status'] !== 1) { Db::rollback(); return $this->fail(1001, '报名费未支付，无法购买'); }

            // 限购校验（原子条件更新防并发超购）
            $saleQuantity = (int) $act['sale_quantity'];
            $bumped = Db::name('raffle_registrations')
                ->where('id', $reg['id'])
                ->whereRaw('purchased_quantity + ' . $qty . ' <= ' . $saleQuantity)
                ->update([
                    'purchased_quantity' => Db::raw('purchased_quantity + ' . $qty),
                ]);
            if (!$bumped) {
                Db::rollback();
                return $this->fail(3003, "中签限购 {$saleQuantity} 件");
            }

            // 库存锁定（原子操作，受 CHECK sold+locked<=edition 兜底；即时成交直接占 sold）
            $unitPrice  = (float) $act['sale_price'];
            $totalPrice = round($unitPrice * $qty, 2);
            $affected = Db::name('collectibles')
                ->where('id', $act['collectible_id'])
                ->whereRaw('sold + locked_quantity + ' . $qty . ' <= edition')
                ->update([
                    'sold'       => Db::raw('sold + ' . $qty),
                    'circulate'  => Db::raw('circulate + ' . $qty),
                    'updated_at' => $now,
                ]);
            if (!$affected) { Db::rollback(); return $this->fail(3001, '库存不足'); }

            // 买家余额校验 + 扣款
            $wallet = Db::name('wallets')->where('user_id', $userId)->lock(true)->find();
            if (!$wallet || (float) $wallet['available'] < $totalPrice) {
                Db::rollback();
                return $this->fail(4003, '余额不足');
            }

            $nowV = date('Y-m-d H:i:s.v');
            $orderNo = gen_order_no();

            Db::name('wallets')->where('user_id', $userId)->update([
                'balance'    => Db::raw("balance - {$totalPrice}"),
                'available'  => Db::raw("available - {$totalPrice}"),
                'updated_at' => $nowV,
            ]);
            Db::name('wallet_transactions')->insert([
                'user_id'       => $userId,
                'trans_type'    => 'buy',
                'title'         => '抽签购购买',
                'direction'     => 2,
                'amount'        => $totalPrice,
                'balance_after' => (float) $wallet['available'] - $totalPrice,
                'biz_no'        => $orderNo,
                'created_at'    => $nowV,
            ]);

            // 生成订单（即时成交，发售来源口径）
            Db::name('orders')->insert([
                'order_no'          => $orderNo,
                'user_id'           => $userId,
                'collectible_id'    => $act['collectible_id'],
                'resale_listing_id' => null,
                'unit_price'        => $unitPrice,
                'quantity'          => $qty,
                'total_price'       => $totalPrice,
                'status'            => 'completed',
                'source'            => 'release',
                'created_at'        => $nowV,
                'paid_at'           => $nowV,
                'completed_at'      => $nowV,
                'expires_at'        => $nowV,
                'updated_at'        => $nowV,
            ]);
            $orderId = (int) Db::name('orders')->getLastInsID();

            Db::name('payments')->insert([
                'order_id'       => $orderId,
                'user_id'        => $userId,
                'amount'         => $totalPrice,
                'payment_method' => 'balance',
                'status'         => 'success',
                'paid_at'        => $nowV,
                'created_at'     => $nowV,
                'updated_at'     => $nowV,
            ]);

            // 生成持仓（行锁内基于 sold 序号，防并发重号）
            $collectible = Db::name('collectibles')->where('id', $act['collectible_id'])->lock(true)->find();
            $soldPrev = (int) $collectible['sold'];
            $serials = [];
            for ($i = 0; $i < $qty; $i++) {
                $seq = str_pad((string) ($soldPrev - $qty + $i + 1), 4, '0', STR_PAD_LEFT);
                $serial = 'SN-' . $act['collectible_id'] . '-' . $seq;
                Db::name('user_collectibles')->insert([
                    'user_id'        => $userId,
                    'collectible_id' => $act['collectible_id'],
                    'order_id'       => $orderId,
                    'serial'         => $serial,
                    'source'         => 'purchase',
                    'acquired_price' => $unitPrice,
                    'acquired_at'    => $nowV,
                    'status'         => 'held',
                    'created_at'     => $nowV,
                    'updated_at'     => $nowV,
                ]);
                $serials[] = $serial;
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '购买失败：' . $e->getMessage());
        }

        return $this->success(['orderNo' => $orderNo, 'quantity' => $qty, 'nos' => $serials]);
    }

    // ---------- 内部辅助 ----------

    /** 展示阶段 */
    private function phase(array $r, string $now): string
    {
        if ($now < $r['registration_start']) return 'upcoming';
        if ($now <= $r['registration_end'] && (int) $r['status'] === 1) return 'registering';
        if ((int) $r['status'] === 3) return 'drawn';
        if ((int) $r['status'] === 2) return 'drawing';
        return 'finished';
    }

    /** 购买窗口（NULL 视为该侧不限制） */
    private function inWindow(?string $start, ?string $end, string $now): bool
    {
        if ($start !== null && $start !== '' && $now < $start) return false;
        if ($end !== null && $end !== '' && $now > $end) return false;
        return true;
    }

    /** 我是否可购买（中签 + 未购满 + 窗口内） */
    private function purchasable(array $activity, array $reg, string $now): bool
    {
        if ((int) $activity['status'] !== 3 || (int) $reg['draw_status'] !== 1) return false;
        if ((int) ($reg['purchased_quantity'] ?? 0) >= (int) $activity['sale_quantity']) return false;
        return $this->inWindow($activity['purchase_start'], $activity['purchase_end'], $now);
    }
}
