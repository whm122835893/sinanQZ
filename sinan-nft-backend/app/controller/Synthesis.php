<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 合成控制器
 */
class Synthesis extends BaseController
{
    /**
     * GET /api/synthesis/activities
     * 合成活动列表
     */
    public function activities()
    {
        // 显式字段并加别名：a.id/a.name 与 c.id/c.name 同名，SELECT * 会列覆盖取错值
        $list = Db::name('synthesis_activities')->alias('a')
            ->join('collectibles c', 'c.id = a.result_collectible_id')
            ->where(function ($q) {
                $q->where('a.end_time', '>=', date('Y-m-d H:i:s'))
                  ->whereOr('a.type', 'permanent');
            })
            ->order('a.start_time', 'desc')
            ->field('a.id, a.type, a.title, a.rules, a.start_time, a.end_time, a.result_collectible_id, a.per_user_limit, a.total_limit, a.used_count, a.image, c.name as c_name, c.image as c_image')
            ->select()
            ->toArray();

        return $this->success([
            'list' => array_map(fn ($a) => [
                'activityId'       => (int) $a['id'],
                'type'             => $a['type'],
                'title'            => $a['title'],
                'rules'            => $a['rules'],
                'startTime'        => $a['start_time'],
                'endTime'          => $a['end_time'],
                'resultCollectible'=> ['id' => (int) $a['result_collectible_id'], 'name' => $a['c_name'], 'image' => $a['c_image']],
                'image'            => $a['image'],
                'perUserLimit'     => (int) $a['per_user_limit'],
                'totalLimit'       => $a['total_limit'] === null ? null : (int) $a['total_limit'],
                'usedCount'        => (int) $a['used_count'],
            ], $list),
        ]);
    }

    /**
     * GET /api/synthesis/activities/:id
     * 合成公式详情
     */
    public function detail()
    {
        $userId = $this->userId();
        $id     = $this->intParam('id');
        $act    = Db::name('synthesis_activities')->where('id', $id)->find();
        if (!$act) return $this->fail(1002, '活动不存在');

        $result = Db::name('collectibles')->where('id', $act['result_collectible_id'])->find();
        $materials = Db::name('synthesis_materials')->alias('m')
            ->join('collectibles c', 'c.id = m.collectible_id')
            ->where('m.activity_id', $id)
            ->field([
                'm.collectible_id', 'm.count', 'c.name', 'c.image',
            ])->select()->toArray();

        $myCount = 0;
        $myAvailable = [];
        if ($userId) {
            $myCount = Db::name('synthesis_records')->where('activity_id', $id)->where('user_id', $userId)->count();
            // 单条分组查询替代循环逐个 count，避免 N+1
            $matIds = array_column($materials, 'collectible_id');
            if ($matIds) {
                $grouped = Db::name('user_collectibles')
                    ->where('user_id', $userId)
                    ->whereIn('collectible_id', $matIds)
                    ->where('status', 'held')
                    ->field('collectible_id, COUNT(*) as cnt')
                    ->group('collectible_id')
                    ->select()
                    ->toArray();
                $myAvailable = array_column($grouped, 'cnt', 'collectible_id');
            }
        }

        return $this->success([
            'activityId'  => (int) $act['id'],
            'type'        => $act['type'],
            'title'       => $act['title'],
            'rules'       => $act['rules'],
            'startTime'   => $act['start_time'],
            'endTime'     => $act['end_time'],
            'resultCollectible' => [
                'id'      => (int) $result['id'],
                'name'    => $result['name'],
                'image'   => $result['image'],
                'edition' => (int) $result['edition'],
            ],
            'materials' => array_map(function ($m) use ($myAvailable) {
                return [
                    'collectibleId' => (int) $m['collectible_id'],
                    'name'          => $m['name'],
                    'image'         => $m['image'],
                    'count'         => (int) $m['count'],
                    'myAvailable'   => $myAvailable[$m['collectible_id']] ?? 0,
                ];
            }, $materials),
            'myCount' => $myCount,
        ]);
    }

    /**
     * POST /api/synthesis/submit
     * 提交合成
     */
    public function submit()
    {
        $userId    = $this->userId();
        $activityId = $this->intParam('activityId');
        if (!$userId) return $this->fail(2001, '未登录');

        Db::startTrans();
        try {
            $act = Db::name('synthesis_activities')
                ->where('id', $activityId)
                ->lock(true)
                ->find();
            if (!$act) { Db::rollback(); return $this->fail(1002, '活动不存在'); }

            // 时间窗
            if ($act['type'] === 'limit') {
                if ($act['start_time'] && strtotime($act['start_time']) > time()) {
                    Db::rollback(); return $this->fail(1001, '活动尚未开始');
                }
                if ($act['end_time'] && strtotime($act['end_time']) < time()) {
                    Db::rollback(); return $this->fail(1001, '活动已结束');
                }
            }

            // 每人限次
            if ((int) $act['per_user_limit'] > 0) {
                $mine = Db::name('synthesis_records')->where('activity_id', $activityId)->where('user_id', $userId)->count();
                if ($mine >= (int) $act['per_user_limit']) {
                    Db::rollback(); return $this->fail(3003, '已达每人限次');
                }
            }

            // 总量限
            if ($act['total_limit'] !== null && (int) $act['used_count'] >= (int) $act['total_limit']) {
                Db::rollback(); return $this->fail(3001, '已达总量上限');
            }

            // 一次性取全部材料配置，避免循环内逐条查询（N+1）
            $materials = Db::name('synthesis_materials')
                ->where('activity_id', $activityId)
                ->field('collectible_id, count')
                ->select()
                ->toArray();

            // 防死循环：产物不在材料中
            $matCollectibleIds = array_column($materials, 'collectible_id');
            if (in_array((int) $act['result_collectible_id'], $matCollectibleIds)) {
                Db::rollback(); return $this->fail(1001, '活动配置错误：产物与材料冲突');
            }

            // 消耗材料
            $now = date('Y-m-d H:i:s.v');
            $consumedIds = [];
            foreach ($materials as $mat) {
                $matCount = (int) $mat['count'];
                $matCid   = (int) $mat['collectible_id'];
                // 取 held 状态的资产实例（行锁防并发重复消耗）
                $assets = Db::name('user_collectibles')
                    ->where('user_id', $userId)
                    ->where('collectible_id', $matCid)
                    ->where('status', 'held')
                    ->limit($matCount)
                    ->lock(true)
                    ->column('id');
                if (count($assets) < $matCount) {
                    Db::rollback();
                    return $this->fail(1001, '材料不足');
                }
                foreach ($assets as $aid) {
                    $consumedIds[] = (int) $aid;
                }
            }

            // 批量置 consumed（原子化消耗）
            Db::name('user_collectibles')->whereIn('id', $consumedIds)->update([
                'status'     => 'consumed',
                'updated_at' => $now,
            ]);

            // 更新活动计数
            Db::name('synthesis_activities')->where('id', $activityId)->update([
                'used_count'  => Db::raw('used_count + 1'),
                'updated_at'  => $now,
            ]);

            // 生成产物：先插占位行取自增ID，再回写编号（count+1 方式并发下会撞唯一索引）
            Db::name('user_collectibles')->insert([
                'user_id'        => $userId,
                'collectible_id' => $act['result_collectible_id'],
                'serial'         => gen_serial_placeholder(),
                'source'         => 'synthesis',
                'acquired_price' => 0,
                'acquired_at'    => $now,
                'status'         => 'held',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $resultUcId = (int) Db::name('user_collectibles')->getLastInsID();
            $resultSerial = 'SN-' . $act['result_collectible_id'] . '-' . str_pad((string) $resultUcId, 4, '0', STR_PAD_LEFT);
            Db::name('user_collectibles')->where('id', $resultUcId)->update([
                'serial'     => $resultSerial,
                'updated_at' => $now,
            ]);

            // 写合成记录
            Db::name('synthesis_records')->insert([
                'user_id'                    => $userId,
                'activity_id'                => $activityId,
                'result_user_collectible_id' => $resultUcId,
                'created_at'                 => $now,
            ]);
            $recordId = (int) Db::name('synthesis_records')->getLastInsID();

            // 写消耗明细
            foreach ($consumedIds as $cid) {
                Db::name('synthesis_record_items')->insert([
                    'synthesis_record_id' => $recordId,
                    'user_collectible_id' => $cid,
                    'created_at'          => $now,
                ]);
            }

            Db::commit();

            $resultC = Db::name('collectibles')->where('id', $act['result_collectible_id'])->find();
            return $this->success([
                'recordId' => $recordId,
                'resultCollectible' => [
                    'id'     => (int) $resultC['id'],
                    'name'   => $resultC['name'],
                    'image'  => $resultC['image'],
                    'no'     => $resultSerial,
                ],
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '合成失败：' . $e->getMessage());
        }
    }

    /**
     * GET /api/synthesis/records
     * 我的合成记录
     */
    public function records()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');
        $p = $this->pagination();

        $query = Db::name('synthesis_records')->alias('sr')
            ->join('synthesis_activities a', 'a.id = sr.activity_id')
            ->join('user_collectibles uc', 'uc.id = sr.result_user_collectible_id')
            ->join('collectibles c', 'c.id = uc.collectible_id')
            ->where('sr.user_id', $userId);

        $total = $query->count();
        $list  = $query
            ->order('sr.created_at', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'sr.id as record_id', 'a.title as activity_title',
                'c.name as result_name', 'c.image as result_image',
                'sr.created_at',
            ])->select()->toArray();

        return $this->paginate(array_map(fn ($r) => [
            'recordId'      => (int) $r['record_id'],
            'activityTitle' => $r['activity_title'],
            'resultName'    => $r['result_name'],
            'resultImage'   => $r['result_image'],
            'createdAt'     => $r['created_at'],
        ], $list), $total, $p['page'], $p['pageSize']);
    }
}
