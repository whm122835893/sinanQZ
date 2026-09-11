<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 分解/熔炼控制器（C 端执行入口）
 *
 * DC03 修复：分解规则此前仅有管理端配置入口（admin/decompose/*），
 * C 端无执行入口，decompose_records 台账全链路无写入点，分解功能闭环缺失。
 *
 * 规则语义：分解 1 件源藏品 → 按 decompose_items 产出 1~N 件指定藏品。
 * 记录粒度：一次分解消耗 1 件源资产，写 1 条 decompose_records（含源资产编号）。
 */
class Decompose extends BaseController
{
    /**
     * GET /api/decompose/rules
     * 当前生效的分解规则列表（可选登录：附我可分解持有数与已用次数）
     */
    public function rules()
    {
        $userId = $this->userId();
        $now    = date('Y-m-d H:i:s');

        $list = Db::name('decompose_rules')->alias('r')
            ->join('collectibles c', 'c.id = r.source_collectible_id', 'LEFT')
            ->whereNull('r.deleted_at')
            ->where('r.enabled', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('r.start_time')->whereOr('r.start_time', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('r.end_time')->whereOr('r.end_time', '>=', $now);
            })
            ->whereNull('c.deleted_at')
            ->order('r.id', 'desc')
            ->field([
                'r.id', 'r.name', 'r.source_collectible_id',
                'r.per_user_limit', 'r.daily_limit',
                'r.start_time', 'r.end_time',
                'c.name as source_name', 'c.image as source_image',
            ])
            ->select()->toArray();

        if (!$list) return $this->success(['list' => []]);

        $ruleIds = array_column($list, 'id');

        // 产出明细
        $rows = Db::name('decompose_items')->alias('di')
            ->join('collectibles c', 'c.id = di.result_collectible_id', 'LEFT')
            ->whereIn('di.rule_id', $ruleIds)
            ->field('di.rule_id, di.result_collectible_id, di.quantity_per, c.name, c.image')
            ->select()->toArray();
        $itemsByRule = [];
        foreach ($rows as $it) {
            $itemsByRule[(int) $it['rule_id']][] = [
                'collectibleId' => (int) $it['result_collectible_id'],
                'name'          => $it['name'] ?? '',
                'image'         => $it['image'] ?? '',
                'quantityPer'   => (int) $it['quantity_per'],
            ];
        }

        // 我的持有数 / 已分解次数（按规则源藏品与规则聚合，单条分组查询防 N+1）
        $myHeld = [];
        $myUsed = [];
        if ($userId) {
            $srcIds = array_values(array_unique(array_column($list, 'source_collectible_id')));
            $grouped = Db::name('user_collectibles')
                ->where('user_id', $userId)
                ->whereIn('collectible_id', $srcIds)
                ->where('status', 'held')
                ->field('collectible_id, COUNT(*) as cnt')
                ->group('collectible_id')
                ->select()->toArray();
            $myHeld = array_column($grouped, 'cnt', 'collectible_id');

            $used = Db::name('decompose_records')
                ->whereIn('rule_id', $ruleIds)
                ->where('user_id', $userId)
                ->field('rule_id, COUNT(*) as cnt')
                ->group('rule_id')
                ->select()->toArray();
            $myUsed = array_column($used, 'cnt', 'rule_id');
        }

        $items = array_map(function ($r) use ($itemsByRule, $myHeld, $myUsed) {
            return [
                'ruleId'       => (int) $r['id'],
                'name'         => $r['name'],
                'source'       => [
                    'collectibleId' => (int) $r['source_collectible_id'],
                    'name'          => $r['source_name'] ?? '',
                    'image'         => $r['source_image'] ?? '',
                ],
                'results'      => $itemsByRule[(int) $r['id']] ?? [],
                'myHeldCount'  => (int) ($myHeld[$r['source_collectible_id']] ?? 0),
                'myUsedCount'  => (int) ($myUsed[$r['id']] ?? 0),
                'perUserLimit' => (int) $r['per_user_limit'],
                'startTime'    => $r['start_time'],
                'endTime'      => $r['end_time'],
            ];
        }, $list);

        return $this->success(['list' => $items]);
    }

    /**
     * POST /api/decompose/execute { ruleId, userCollectibleId }
     * 执行分解：消耗 1 件持有的源藏品 → 发放全部产出
     */
    public function execute()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $ruleId            = $this->intParam('ruleId');
        $userCollectibleId = $this->intParam('userCollectibleId');
        if ($ruleId <= 0 || $userCollectibleId <= 0) {
            return $this->fail(1001, '参数不正确');
        }

        Db::startTrans();
        try {
            $rule = Db::name('decompose_rules')
                ->where('id', $ruleId)
                ->whereNull('deleted_at')
                ->lock(true)
                ->find();
            if (!$rule || (int) $rule['enabled'] !== 1) {
                Db::rollback();
                return $this->fail(1002, '分解规则不存在或已停用');
            }

            $now = date('Y-m-d H:i:s');
            if ($rule['start_time'] && strtotime((string) $rule['start_time']) > time()) {
                Db::rollback();
                return $this->fail(1001, '分解活动尚未开始');
            }
            if ($rule['end_time'] && strtotime((string) $rule['end_time']) < time()) {
                Db::rollback();
                return $this->fail(1001, '分解活动已结束');
            }

            // 每人限次
            if ((int) $rule['per_user_limit'] > 0) {
                $mine = Db::name('decompose_records')
                    ->where('rule_id', $ruleId)
                    ->where('user_id', $userId)
                    ->count();
                if ($mine >= (int) $rule['per_user_limit']) {
                    Db::rollback();
                    return $this->fail(3003, '已达每人分解次数上限');
                }
            }

            // 平台每日上限
            if ((int) $rule['daily_limit'] > 0) {
                $todayCount = Db::name('decompose_records')
                    ->where('rule_id', $ruleId)
                    ->where('created_at', '>=', date('Y-m-d 00:00:00'))
                    ->count();
                if ($todayCount >= (int) $rule['daily_limit']) {
                    Db::rollback();
                    return $this->fail(3001, '今日分解次数已达上限');
                }
            }

            // 源资产：必须属于我、属于规则源藏品、held（行锁防并发转赠/寄售/重复分解）
            $asset = Db::name('user_collectibles')
                ->where('id', $userCollectibleId)
                ->where('user_id', $userId)
                ->where('collectible_id', $rule['source_collectible_id'])
                ->where('status', 'held')
                ->lock(true)
                ->find();
            if (!$asset) {
                Db::rollback();
                return $this->fail(1001, '藏品不可分解：未持有或状态异常');
            }

            // 产出明细
            $items = Db::name('decompose_items')->where('rule_id', $ruleId)->select()->toArray();
            if (!$items) {
                Db::rollback();
                return $this->fail(1001, '分解规则未配置产出');
            }
            // 防死循环：产出不得包含源藏品
            foreach ($items as $it) {
                if ((int) $it['result_collectible_id'] === (int) $rule['source_collectible_id']) {
                    Db::rollback();
                    return $this->fail(1001, '分解配置错误：产出不能包含源藏品');
                }
            }

            $nowV = date('Y-m-d H:i:s.v');

            // 消耗源资产（条件更新：仅 held 可消耗，防状态机跳变）
            $consumed = Db::name('user_collectibles')
                ->where('id', $userCollectibleId)
                ->where('status', 'held')
                ->update([
                    'status'     => 'consumed',
                    'updated_at' => $nowV,
                ]);
            if (!$consumed) {
                Db::rollback();
                return $this->fail(3001, '藏品状态异常，分解失败');
            }

            // 发放产物：先插占位行取自增ID，再回写编号（与合成/开盒路径一致）
            // 注：user_collectibles.source 枚举无 decompose 值，熔炼系产物统一走 synthesis 口径
            $resultList = [];
            foreach ($items as $it) {
                $qty = max(1, (int) $it['quantity_per']);
                for ($i = 0; $i < $qty; $i++) {
                    Db::name('user_collectibles')->insert([
                        'user_id'        => $userId,
                        'collectible_id' => $it['result_collectible_id'],
                        'serial'         => gen_serial_placeholder(),
                        'source'         => 'synthesis',
                        'acquired_price' => 0,
                        'acquired_at'    => $nowV,
                        'status'         => 'held',
                        'created_at'     => $nowV,
                        'updated_at'     => $nowV,
                    ]);
                    $ucId   = (int) Db::name('user_collectibles')->getLastInsID();
                    $serial = 'SN-' . $it['result_collectible_id'] . '-' . str_pad((string) $ucId, 4, '0', STR_PAD_LEFT);
                    Db::name('user_collectibles')->where('id', $ucId)->update([
                        'serial'     => $serial,
                        'updated_at' => $nowV,
                    ]);
                    $resultList[] = [
                        'collectibleId' => (int) $it['result_collectible_id'],
                        'serial'        => $serial,
                    ];
                }
                // 产物藏品流通量 +qty（与发售/空投/合成路径保持一致）
                Db::name('collectibles')->where('id', $it['result_collectible_id'])->update([
                    'circulate'  => Db::raw('circulate + ' . $qty),
                    'updated_at' => $nowV,
                ]);
            }

            // 写分解台账（此前全链路无写入点，admin/decompose/records 永远为空）
            Db::name('decompose_records')->insert([
                'rule_id'               => $ruleId,
                'user_id'               => $userId,
                'source_collectible_id' => $rule['source_collectible_id'],
                'source_serial'         => $asset['serial'],
                'created_at'            => $nowV,
            ]);
            $recordId = (int) Db::name('decompose_records')->getLastInsID();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '分解失败：' . $e->getMessage());
        }

        return $this->success([
            'recordId'      => $recordId,
            'consumedSerial' => $asset['serial'],
            'results'       => $resultList,
        ]);
    }

    /**
     * GET /api/decompose/records
     * 我的分解记录（产出明细按规则公式展示）
     */
    public function records()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $p = $this->pagination();

        $query = Db::name('decompose_records')->alias('dr')
            ->join('decompose_rules r', 'r.id = dr.rule_id', 'LEFT')
            ->join('collectibles c', 'c.id = dr.source_collectible_id', 'LEFT')
            ->where('dr.user_id', $userId);

        $total = (clone $query)->count();
        $rows  = $query->order('dr.id', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'dr.id as record_id', 'dr.rule_id', 'dr.source_serial', 'dr.created_at',
                'r.name as rule_name', 'c.name as source_name', 'c.image as source_image',
            ])
            ->select()->toArray();

        // 产出明细按规则聚合展示
        $ruleIds    = array_values(array_unique(array_column($rows, 'rule_id')));
        $itemsByRule = [];
        if ($ruleIds) {
            $items = Db::name('decompose_items')->alias('di')
                ->join('collectibles c', 'c.id = di.result_collectible_id', 'LEFT')
                ->whereIn('di.rule_id', $ruleIds)
                ->field('di.rule_id, di.result_collectible_id, di.quantity_per, c.name, c.image')
                ->select()->toArray();
            foreach ($items as $it) {
                $itemsByRule[(int) $it['rule_id']][] = [
                    'collectibleId' => (int) $it['result_collectible_id'],
                    'name'          => $it['name'] ?? '',
                    'image'         => $it['image'] ?? '',
                    'quantityPer'   => (int) $it['quantity_per'],
                ];
            }
        }

        $list = array_map(function ($r) use ($itemsByRule) {
            return [
                'recordId'     => (int) $r['record_id'],
                'ruleName'     => $r['rule_name'] ?? '',
                'source'       => [
                    'name'  => $r['source_name'] ?? '',
                    'image' => $r['source_image'] ?? '',
                ],
                'sourceSerial' => $r['source_serial'],
                'results'      => $itemsByRule[(int) $r['rule_id']] ?? [],
                'createdAt'    => $r['created_at'],
            ];
        }, $rows);

        return $this->paginate($list, $total, $p['page'], $p['pageSize']);
    }
}
