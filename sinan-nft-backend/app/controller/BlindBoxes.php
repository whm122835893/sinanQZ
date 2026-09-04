<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

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
            $uc = Db::name('user_collectibles')
                ->alias('uc')
                ->join('blind_boxes bb', 'bb.collectible_id = uc.collectible_id')
                ->where('uc.id', $userCollectibleId)
                ->where('uc.user_id', $userId)
                ->where('uc.status', 'held')
                ->where('uc.source', 'purchase')
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

            // 更新奖品已发放
            Db::name('blind_box_items')->where('id', $winner['id'])->update([
                'quantity_distributed' => Db::raw('quantity_distributed + 1'),
                'updated_at'           => $now,
            ]);

            // 生成奖品资产：先插占位行取自增ID，再回写编号（count+1 方式并发下会撞唯一索引）
            $prizeCollectibleId = (int) $winner['prize_collectible_id'];
            Db::name('user_collectibles')->insert([
                'user_id'           => $userId,
                'collectible_id'   => $prizeCollectibleId,
                'blind_box_item_id' => $winner['id'],
                'serial'           => gen_serial_placeholder(),
                'source'           => 'blindbox',
                'acquired_price'   => 0,
                'acquired_at'      => $now,
                'status'           => 'held',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            $newUcId = (int) Db::name('user_collectibles')->getLastInsID();
            $serial  = 'SN-' . $prizeCollectibleId . '-' . str_pad((string) $newUcId, 4, '0', STR_PAD_LEFT);
            Db::name('user_collectibles')->where('id', $newUcId)->update([
                'serial'     => $serial,
                'updated_at' => $now,
            ]);

            Db::commit();

            $prizeCollectible = Db::name('collectibles')->where('id', $prizeCollectibleId)->find();
            return $this->success([
                'prize' => [
                    'collectibleId' => $prizeCollectibleId,
                    'name'          => $prizeCollectible['name'] ?? '',
                    'image'         => $prizeCollectible['image'] ?? '',
                    'no'            => $serial,
                    'rarity'        => '抽奖奖品',
                    'probability'   => (float) $winner['probability'],
                ],
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '开盒失败：' . $e->getMessage());
        }
    }
}
