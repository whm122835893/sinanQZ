<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台营销活动控制器
 *
 * 覆盖：优先购活动与白名单、签到奖励配置、邀请活动、抽奖活动与奖项、
 * 合成活动与素材、活动空投发放、注册福利配置。
 */
class MarketingController extends BaseController
{
    // ==================== 优先购 ====================

    /**
     * GET /admin/marketing/priority
     */
    public function priorityList()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('priority_activities')->alias('pa')
            ->join('collectibles c', 'c.id = pa.collectible_id');
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('pa.name', "%{$keyword}%");
        }
        $status = (string) $this->request->param('status', '');
        if ($status !== '' && in_array($status, ['disabled', 'enabled', 'ended'], true)) {
            $query->where('pa.status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->field('pa.*, c.name AS collectible_name, c.image,
                               (SELECT COUNT(*) FROM nft_priority_whitelists w WHERE w.activity_id = pa.id) AS whitelist_count')
            ->order('pa.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * POST /admin/marketing/priority-save { id?, collectible_id, name, start_time?, end_time?, status, remark?, whitelist(user_ids) }
     */
    public function prioritySave()
    {
        $missing = $this->missingParams(['collectible_id', 'name', 'status']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $collectibleId = (int) $this->request->param('collectible_id');
        $name   = trim((string) $this->request->param('name'));
        $status = (string) $this->request->param('status');

        if (!in_array($status, ['disabled', 'enabled', 'ended'], true)) {
            return $this->fail(4220, 'status 仅允许 disabled/enabled/ended');
        }
        if (!Db::name('collectibles')->where('id', $collectibleId)->whereNull('deleted_at')->find()) {
            return $this->fail(4040, '藏品不存在');
        }
        // 唯一性：一个藏品仅一个优先购活动
        $dup = Db::name('priority_activities')->where('collectible_id', $collectibleId);
        $id  = $this->positiveInt('id');
        if ($id !== null) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->find()) {
            return $this->fail(4220, '该藏品已存在优先购活动（一物一活动）');
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'collectible_id' => $collectibleId,
            'name'           => mb_substr($name, 0, 100),
            'start_time'     => $this->optionalDate('start_time'),
            'end_time'       => $this->optionalDate('end_time'),
            'status'         => $status,
            'remark'         => mb_substr(trim((string) $this->request->param('remark', '')), 0, 255) ?: null,
            'updated_at'     => $now,
        ];

        Db::startTrans();
        try {
            if ($id !== null) {
                Db::name('priority_activities')->where('id', $id)->update($data);
            } else {
                $data['created_at'] = $now;
                $id = (int) Db::name('priority_activities')->insertGetId($data);
            }
            // 白名单覆盖式更新
            $whitelist = $this->request->param('whitelist', []);
            if (is_array($whitelist)) {
                $userIds = array_values(array_unique(array_map('intval', array_filter($whitelist, 'is_numeric'))));
                if (count($userIds) > 1000) {
                    throw new \Exception('白名单最多 1000 人');
                }
                $maxQuantity = max(1, (int) $this->request->param('whitelist_max_quantity', 1));
                $validIds = Db::name('users')->whereIn('id', $userIds)->whereNull('deleted_at')->column('phone', 'id');
                Db::name('priority_whitelists')->where('activity_id', $id)->delete();
                foreach (array_keys($validIds) as $uid) {
                    Db::name('priority_whitelists')->insert([
                        'activity_id'  => $id,
                        'user_id'      => $uid,
                        'phone'        => $validIds[$uid],
                        'max_quantity' => $maxQuantity,
                        'status'       => 1,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ]);
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '保存失败：' . $e->getMessage());
        }

        $this->audit('marketing', 'priority_save', '保存优先购活动「' . $name . '」', ['id' => $id], 'priority_activity', $id);
        return $this->success(['id' => $id], '优先购活动已保存');
    }

    // ==================== 签到配置 ====================

    /** 签到活动配置键（system_configs） */
    private const CHECKIN_KEYS = ['checkin_enabled', 'checkin_activity_name', 'checkin_start_time', 'checkin_end_time'];

    /**
     * GET /admin/marketing/checkin
     * 返回：活动开关/名称/起止时间 + 奖励规则 + 今日签到统计
     */
    public function checkinConfig()
    {
        $configs = Db::name('system_configs')
            ->whereIn('config_key', self::CHECKIN_KEYS)
            ->column('config_value', 'config_key');

        $rewards = Db::name('system_configs')->where('config_key', 'checkin_rewards')->value('config_value');
        $rewards = $rewards ? json_decode((string) $rewards, true) : [];

        $stats = Db::name('check_in_records')->field("DATE(created_at) AS d, COUNT(*) AS cnt")
            ->where('created_at', '>=', date('Y-m-d 00:00:00', strtotime('-29 days')))
            ->group('d')->select()->toArray();
        $todayCount = Db::name('check_in_records')->whereBetweenTime('created_at', date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'))->count();

        return $this->success([
            'enabled'     => (int) ($configs['checkin_enabled'] ?? 0) === 1,
            'name'        => (string) ($configs['checkin_activity_name'] ?? '每日签到'),
            'startTime'   => (string) ($configs['checkin_start_time'] ?? ''),
            'endTime'     => (string) ($configs['checkin_end_time'] ?? ''),
            'rewards'     => $rewards ?: new \stdClass(),
            'todayCount'  => $todayCount,
            'trend'       => array_column($stats, 'cnt', 'd'),
        ]);
    }

    /**
     * POST /admin/marketing/checkin
     * 支持两种提交：
     *   1) { rewards: {1:5,...} }             —— 仅更新奖励规则（兼容旧版）
     *   2) { enabled?, name?, start_time?, end_time?, rewards? } —— 活动信息 + 规则
     */
    public function checkinSave()
    {
        $now = date('Y-m-d H:i:s');
        $changed = [];

        // ---- 活动信息（enabled / name / start_time / end_time）----
        if ($this->request->has('enabled')) {
            $enabled = (int) $this->request->param('enabled') === 1 ? 1 : 0;
            Db::name('system_configs')->where('config_key', 'checkin_enabled')
                ->update(['config_value' => (string) $enabled, 'updated_at' => $now]);
            $changed['enabled'] = $enabled === 1;
        }
        if ($this->request->has('name')) {
            $name = mb_substr(trim((string) $this->request->param('name')), 0, 100);
            if ($name === '') {
                return $this->fail(4220, '签到活动名称不能为空');
            }
            Db::name('system_configs')->where('config_key', 'checkin_activity_name')
                ->update(['config_value' => $name, 'updated_at' => $now]);
            $changed['name'] = $name;
        }
        foreach (['start_time' => 'checkin_start_time', 'end_time' => 'checkin_end_time'] as $param => $key) {
            if ($this->request->has($param)) {
                $val = trim((string) $this->request->param($param, ''));
                if ($val !== '' && !strtotime($val)) {
                    return $this->fail(4220, $param . ' 时间格式不合法');
                }
                if ($val !== '') {
                    $val = date('Y-m-d H:i:s', strtotime($val));
                }
                Db::name('system_configs')->where('config_key', $key)
                    ->update(['config_value' => $val, 'updated_at' => $now]);
                $changed[$param] = $val;
            }
        }

        // ---- 奖励规则 ----
        $rewards = $this->request->param('rewards');
        if ($rewards !== null) {
            if (!is_array($rewards) || !$rewards) {
                return $this->fail(4220, '请提供 rewards 配置（天数 → 奖励）');
            }
            $clean = [];
            foreach ($rewards as $day => $amount) {
                $day = (int) $day;
                $amount = (int) $amount;
                if ($day < 1 || $day > 7) {
                    return $this->fail(4220, '连续签到天数仅支持 1~7');
                }
                if ($amount < 0 || $amount > 10000) {
                    return $this->fail(4220, '奖励数值需在 0~10000');
                }
                $clean[$day] = $amount;
            }
            ksort($clean);
            Db::name('system_configs')->where('config_key', 'checkin_rewards')
                ->update(['config_value' => json_encode($clean), 'updated_at' => $now]);
            $changed['rewards'] = $clean;
        }

        if (!$changed) {
            return $this->fail(4220, '无可保存内容（enabled/name/start_time/end_time/rewards 至少其一）');
        }

        $this->audit('marketing', 'checkin_save', '更新签到活动配置', $changed);
        return $this->success(null, '签到活动配置已保存');
    }

    // ==================== 邀请活动 ====================

    /**
     * GET /admin/marketing/invite
     */
    public function inviteList()
    {
        $rows = Db::name('invite_activities')->whereNull('deleted_at')->select()->toArray();
        foreach ($rows as &$row) {
            $row['invitee_count'] = Db::name('invite_records')->where('inviter_id', '>', 0)->count();
            $row['inviter_collectible_name'] = $row['inviter_collectible_id']
                ? Db::name('collectibles')->where('id', $row['inviter_collectible_id'])->value('name') : null;
            $row['invitee_collectible_name'] = $row['invitee_collectible_id']
                ? Db::name('collectibles')->where('id', $row['invitee_collectible_id'])->value('name') : null;
        }
        return $this->success(camelize_keys($rows));
    }

    /**
     * POST /admin/marketing/invite-save { id?, name, status, inviter_collectible_id?, inviter_quantity, invitee_collectible_id?, invitee_quantity, airdrop_mode, total_limit?, start_time?, end_time?, description? }
     */
    public function inviteSave()
    {
        $missing = $this->missingParams(['name', 'status']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $name   = trim((string) $this->request->param('name'));
        $status = (string) $this->request->param('status');
        if (!in_array($status, ['disabled', 'enabled'], true)) {
            return $this->fail(4220, 'status 仅允许 disabled/enabled');
        }

        // 启用校验：必须配置至少一方空投
        $inviterCid = $this->positiveInt('inviter_collectible_id');
        $inviteeCid = $this->positiveInt('invitee_collectible_id');
        if ($status === 'enabled' && $inviterCid === null && $inviteeCid === null) {
            return $this->fail(4220, '启用邀请活动需配置邀请人/被邀请人至少一方的空投藏品');
        }
        foreach ([['inviter', $inviterCid], ['invitee', $inviteeCid]] as [$label, $cid]) {
            if ($cid !== null && !Db::name('collectibles')->where('id', $cid)->whereNull('deleted_at')->find()) {
                return $this->fail(4220, $label . ' 空投藏品不存在');
            }
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'name'          => mb_substr($name, 0, 100),
            'status'        => $status,
            'start_time'    => $this->optionalDate('start_time'),
            'end_time'      => $this->optionalDate('end_time'),
            'inviter_collectible_id' => $inviterCid,
            'inviter_quantity' => max(1, (int) $this->request->param('inviter_quantity', 1)),
            'invitee_collectible_id' => $inviteeCid,
            'invitee_quantity' => max(1, (int) $this->request->param('invitee_quantity', 1)),
            'airdrop_mode'  => in_array($this->request->param('airdrop_mode', 'realtime'), ['realtime', 'batch'], true)
                ? (string) $this->request->param('airdrop_mode', 'realtime') : 'realtime',
            'total_limit'   => $this->positiveInt('total_limit'),
            'description'   => (string) $this->request->param('description', '') ?: null,
            'updated_at'    => $now,
        ];

        $id = $this->positiveInt('id');
        if ($id !== null) {
            if (!Db::name('invite_activities')->where('id', $id)->find()) {
                return $this->fail(4040, '邀请活动不存在');
            }
            Db::name('invite_activities')->where('id', $id)->update($data);
        } else {
            $data['created_at'] = $now;
            $id = (int) Db::name('invite_activities')->insertGetId($data);
        }

        $this->audit('marketing', 'invite_save', '保存邀请活动「' . $name . '」', ['id' => $id], 'invite_activity', $id);
        return $this->success(['id' => $id], '邀请活动已保存');
    }

    // ==================== 抽奖活动 ====================

    /**
     * GET /admin/marketing/lucky
     * 活动列表（按 activity_id 分组聚合）+ 各活动奖项
     */
    public function luckyList()
    {
        $prizes = Db::name('lucky_draw_prizes')->alias('p')
            ->field('p.*, c.name AS collectible_name, c.image')
            ->join('collectibles c', 'c.id = p.collectible_id', 'LEFT')
            ->whereNull('p.deleted_at')
            ->order('p.activity_id', 'asc')->order('p.sort_order', 'asc')
            ->select()->toArray();

        // 活动实体表（名称/状态/时间）；兼容旧数据：无实体记录的活动兜底"第 N 期"且视为启用
        $actEntities = Db::name('lucky_draw_activities')->whereNull('deleted_at')
            ->column('name,status,start_time,end_time', 'id');

        // 无奖项但已有活动实体的（新建活动尚未配奖项）也需展示
        $activities = [];
        foreach ($actEntities as $actId => $ent) {
            $activities[(int) $actId] = [
                'activityId' => (int) $actId,
                'name'       => (string) $ent['name'],
                'status'     => (int) $ent['status'],
                'startTime'  => $ent['start_time'],
                'endTime'    => $ent['end_time'],
                'totalStock' => 0,
                'totalWon'   => 0,
                'probabilitySum' => 0.0,
                'drawCount'  => (int) Db::name('lucky_draw_records')->where('prize_id', 'in', function ($q) use ($actId) {
                    // 注意：闭包子查询必须用 field() 指定单列（column() 会渲染成 SELECT * 触发基数违规）
                    $q->name('lucky_draw_prizes')->where('activity_id', $actId)->field('id');
                })->count(),
                'prizes'     => [],
            ];
        }
        foreach ($prizes as $prize) {
            $actId = (int) $prize['activity_id'];
            if (!isset($activities[$actId])) {
                $activities[$actId] = [
                    'activityId' => $actId,
                    'name'       => '第 ' . $actId . ' 期抽奖',
                    'status'     => 1,
                    'startTime'  => null,
                    'endTime'    => null,
                    'totalStock' => 0,
                    'totalWon'   => 0,
                    'probabilitySum' => 0.0,
                    'drawCount'  => (int) Db::name('lucky_draw_records')->where('prize_id', 'in', function ($q) use ($actId) {
                        // 注意：闭包子查询必须用 field() 指定单列（column() 会渲染成 SELECT * 触发基数违规）
                        $q->name('lucky_draw_prizes')->where('activity_id', $actId)->field('id');
                    })->count(),
                    'prizes'     => [],
                ];
            }
            $activities[$actId]['totalStock'] += (int) $prize['total'];
            $activities[$actId]['totalWon'] += (int) $prize['won'];
            $activities[$actId]['probabilitySum'] += (float) $prize['probability'];
            $prizeItem = camelize_keys($prize);
            unset($prizeItem['deletedAt']);
            $activities[$actId]['prizes'][] = $prizeItem;
        }
        foreach ($activities as &$act) {
            $act['probabilitySum'] = round($act['probabilitySum'], 4);
            $act['probabilityOk'] = abs($act['probabilitySum'] - 1) <= 0.0001;
        }
        ksort($activities);

        return $this->success(array_values($activities));
    }

    /**
     * POST /admin/marketing/lucky-activity { id?, name, status, start_time?, end_time? }
     * 新建/编辑抽奖活动（新建后通过 lucky-save 为该活动配置奖项）
     */
    public function luckyActivitySave()
    {
        $missing = $this->missingParams(['name', 'status']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $name = mb_substr(trim((string) $this->request->param('name')), 0, 100);
        if ($name === '') {
            return $this->fail(4220, '活动名称不能为空');
        }
        $status = (int) $this->request->param('status');
        if (!in_array($status, [0, 1], true)) {
            return $this->fail(4220, 'status 仅允许 0（停用）/1（启用）');
        }
        $startTime = $this->optionalDate('start_time');
        $endTime   = $this->optionalDate('end_time');
        if ($startTime && $endTime && $startTime > $endTime) {
            return $this->fail(4220, '开始时间不能晚于结束时间');
        }

        // 停用/启用联动校验：启用需已配置奖项（概率合计为 1）
        $id = $this->positiveInt('id');
        if ($status === 1 && $id !== null) {
            $probSum = (float) Db::name('lucky_draw_prizes')->where('activity_id', $id)->whereNull('deleted_at')->sum('probability');
            if (abs($probSum - 1) > 0.0001) {
                return $this->fail(4220, '启用抽奖活动前需先配置完整奖项（概率合计=1，当前 ' . round($probSum, 4) . '）');
            }
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'name'       => $name,
            'status'     => $status,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'updated_at' => $now,
        ];

        Db::startTrans();
        try {
            if ($id !== null) {
                $act = Db::name('lucky_draw_activities')->where('id', $id)->whereNull('deleted_at')->find();
                if (!$act) {
                    return $this->fail(4040, '抽奖活动不存在');
                }
                Db::name('lucky_draw_activities')->where('id', $id)->update($data);
                $isNew = false;
            } else {
                $data['created_at'] = $now;
                $id = (int) Db::name('lucky_draw_activities')->insertGetId($data);
                $isNew = true;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '保存失败：' . $e->getMessage());
        }

        $this->audit('marketing', 'lucky_activity_save', ($isNew ? '新建' : '保存') . '抽奖活动「' . $name . '」', ['id' => $id], 'lucky_activity', $id);
        return $this->success(['id' => $id], '抽奖活动已保存');
    }

    /**
     * POST /admin/marketing/lucky-save { activity_id, prizes: [{id?, tier_name, prize_type, collectible_id?, coin_amount?, total, probability, sort_order?}] }
     * 奖项整体覆盖式保存（概率合计必须为 1）
     */
    public function luckySave()
    {
        $missing = $this->missingParams(['activity_id', 'prizes']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $activityId = (int) $this->request->param('activity_id');
        $prizes     = $this->request->param('prizes', []);
        if ($activityId < 1) {
            return $this->fail(4220, 'activity_id 不合法');
        }
        if (!is_array($prizes) || count($prizes) < 1) {
            return $this->fail(4220, '至少配置一个奖项');
        }

        $probabilitySum = 0.0;
        foreach ($prizes as $prize) {
            $type = (string) ($prize['prize_type'] ?? 'collectible');
            if (!in_array($type, ['collectible', 'points', 'none'], true)) {
                return $this->fail(4220, 'prize_type 仅允许 collectible/points/none');
            }
            if ($type === 'collectible') {
                $cid = (int) ($prize['collectible_id'] ?? 0);
                if ($cid <= 0 || !Db::name('collectibles')->where('id', $cid)->whereNull('deleted_at')->find()) {
                    return $this->fail(4220, '奖品藏品不存在（ID ' . $cid . '）');
                }
            }
            if ($type === 'points' && (float) ($prize['coin_amount'] ?? 0) <= 0) {
                return $this->fail(4220, '司南币奖项需配置 coin_amount');
            }
            $total = (int) ($prize['total'] ?? 0);
            if ($total < 1) {
                return $this->fail(4220, '奖项库存需 ≥ 1');
            }
            $probabilitySum += (float) ($prize['probability'] ?? 0);
        }
        if (abs($probabilitySum - 1) > 0.0001) {
            return $this->fail(4220, '概率合计必须为 1（当前 ' . round($probabilitySum, 4) . '）');
        }

        // 活动实体兜底：旧数据仅 prizes 有 activity_id 无实体记录时自动补建（默认停用，名称"第N期"）
        $now = date('Y-m-d H:i:s');
        if (!Db::name('lucky_draw_activities')->where('id', $activityId)->whereNull('deleted_at')->find()) {
            Db::name('lucky_draw_activities')->insert([
                'id'         => $activityId,
                'name'       => '第 ' . $activityId . ' 期抽奖',
                'status'     => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Db::startTrans();
        try {
            $existing = Db::name('lucky_draw_prizes')->where('activity_id', $activityId)->whereNull('deleted_at')
                ->column('won', 'id');
            $keepIds = [];
            foreach ($prizes as $prize) {
                $data = [
                    'activity_id'    => $activityId,
                    'tier_name'      => mb_substr((string) ($prize['tier_name'] ?? ''), 0, 20) ?: '奖项',
                    'prize_type'     => (string) ($prize['prize_type'] ?? 'collectible'),
                    'collectible_id' => ($prize['prize_type'] ?? '') === 'collectible' ? (int) $prize['collectible_id'] : null,
                    'coin_amount'    => ($prize['prize_type'] ?? '') === 'points' ? (float) $prize['coin_amount'] : null,
                    'probability'    => (float) $prize['probability'],
                    'sort_order'     => (int) ($prize['sort_order'] ?? 0),
                    'updated_at'     => $now,
                ];
                $oldId = (int) ($prize['id'] ?? 0);
                if ($oldId > 0 && isset($existing[$oldId])) {
                    $keepIds[] = $oldId;
                    $won = (int) $existing[$oldId];
                    $total = (int) $prize['total'];
                    if ($total < $won) {
                        throw new \Exception('奖项「' . $data['tier_name'] . '」库存不能低于已中奖数 ' . $won);
                    }
                    $data['total'] = $total;
                    Db::name('lucky_draw_prizes')->where('id', $oldId)->update($data);
                } else {
                    $data['total'] = (int) $prize['total'];
                    $data['won'] = 0;
                    $data['created_at'] = $now;
                    Db::name('lucky_draw_prizes')->insert($data);
                }
            }
            // 删除未保留的旧奖项（已中奖的禁止删）
            $removeIds = array_diff(array_keys($existing), $keepIds);
            foreach ($removeIds as $removeId) {
                if ((int) $existing[$removeId] > 0) {
                    throw new \Exception('奖项（ID ' . $removeId . '）已有人中奖，禁止删除');
                }
            }
            if ($removeIds) {
                Db::name('lucky_draw_prizes')->whereIn('id', $removeIds)->update(['deleted_at' => $now]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '保存失败：' . $e->getMessage());
        }

        $this->audit('marketing', 'lucky_save', '保存抽奖配置（第 ' . $activityId . ' 期，' . count($prizes) . ' 个奖项）',
            ['prizes' => $prizes]);
        return $this->success(null, '抽奖奖项已保存');
    }

    // ==================== 合成活动 ====================

    /**
     * GET /admin/marketing/synthesis
     */
    public function synthesisList()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('synthesis_activities')->alias('a')
            ->join('collectibles c', 'c.id = a.result_collectible_id');
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('a.title', "%{$keyword}%");
        }
        $type = (string) $this->request->param('type', '');
        if ($type !== '' && in_array($type, ['limit', 'permanent'], true)) {
            $query->where('a.type', $type);
        }

        $total = (clone $query)->count();
        $rows = $query->field('a.*, c.name AS result_name, c.image AS result_image')
            ->order('a.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        foreach ($rows as &$row) {
            $materials = Db::name('synthesis_materials')->alias('m')
                ->field('m.collectible_id, m.count, c.name, c.image')
                ->join('collectibles c', 'c.id = m.collectible_id')
                ->where('m.activity_id', $row['id'])
                ->select()->toArray();
            $row['materials'] = $materials;
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * POST /admin/marketing/synthesis-save { id?, type, title, rules, result_collectible_id, materials:[{collectible_id, count}], per_user_limit, total_limit?, start_time?, end_time?, image? }
     */
    public function synthesisSave()
    {
        $missing = $this->missingParams(['type', 'title', 'rules', 'result_collectible_id', 'materials', 'per_user_limit']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $type = (string) $this->request->param('type');
        if (!in_array($type, ['limit', 'permanent'], true)) {
            return $this->fail(4220, 'type 仅允许 limit/permanent');
        }
        $resultCid = (int) $this->request->param('result_collectible_id');
        $materials = $this->request->param('materials', []);
        if (!is_array($materials) || count($materials) < 1) {
            return $this->fail(4220, '至少配置一种合成素材');
        }
        if (!Db::name('collectibles')->where('id', $resultCid)->whereNull('deleted_at')->find()) {
            return $this->fail(4040, '合成结果藏品不存在');
        }

        $materialIds = [];
        foreach ($materials as $mat) {
            $cid = (int) ($mat['collectible_id'] ?? 0);
            $count = (int) ($mat['count'] ?? 0);
            if ($cid <= 0 || !Db::name('collectibles')->where('id', $cid)->whereNull('deleted_at')->find()) {
                return $this->fail(4220, '素材藏品不存在（ID ' . $cid . '）');
            }
            if ($cid === $resultCid) {
                return $this->fail(4220, '素材不能包含合成结果藏品本身（防死循环）');
            }
            if ($count < 1 || $count > 100) {
                return $this->fail(4220, '素材数量需在 1~100');
            }
            $materialIds[] = $cid;
        }
        if (count(array_unique($materialIds)) !== count($materialIds)) {
            return $this->fail(4220, '素材列表存在重复藏品');
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            $data = [
                'type'          => $type,
                'title'         => mb_substr(trim((string) $this->request->param('title')), 0, 100),
                'status'        => (int) $this->request->param('status', 1) === 1 ? 1 : 0,
                'start_time'    => $this->optionalDate('start_time'),
                'end_time'      => $this->optionalDate('end_time'),
                'rules'         => (string) $this->request->param('rules'),
                'result_collectible_id' => $resultCid,
                'per_user_limit' => max(0, (int) $this->request->param('per_user_limit')),
                'total_limit'   => $this->positiveInt('total_limit'),
                'image'         => trim((string) $this->request->param('image', '')) ?: null,
                'updated_at'    => $now,
            ];
            $id = $this->positiveInt('id');
            if ($id !== null) {
                $act = Db::name('synthesis_activities')->where('id', $id)->find();
                if (!$act) {
                    throw new \Exception('合成活动不存在');
                }
                if ((int) $act['used_count'] > 0 && (int) $this->request->param('total_limit', 0) < (int) $act['used_count']) {
                    throw new \Exception('总限量不能低于已完成合成数 ' . $act['used_count']);
                }
                Db::name('synthesis_activities')->where('id', $id)->update($data);
                Db::name('synthesis_materials')->where('activity_id', $id)->delete();
            } else {
                $data['used_count'] = 0;
                $data['created_at'] = $now;
                $id = (int) Db::name('synthesis_activities')->insertGetId($data);
            }
            foreach ($materials as $mat) {
                Db::name('synthesis_materials')->insert([
                    'activity_id'   => $id,
                    'collectible_id' => (int) $mat['collectible_id'],
                    'count'         => (int) $mat['count'],
                    'created_at'    => $now,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '保存失败：' . $e->getMessage());
        }

        $this->audit('marketing', 'synthesis_save', '保存合成活动「' . $this->request->param('title') . '」', ['id' => $id], 'synthesis_activity', $id);
        return $this->success(['id' => $id], '合成活动已保存');
    }

    // ==================== 活动空投 ====================

    /**
     * GET /admin/marketing/airdrop
     * 空投活动列表 + 发放进度
     */
    public function airdropList()
    {
        $rows = Db::name('airdrop_activities')->whereNull('deleted_at')
            ->order('id', 'desc')->limit(100)->select()->toArray();
        foreach ($rows as &$row) {
            $row['collectible_name'] = Db::name('collectibles')->where('id', $row['collectible_id'])->value('name');
            $row['eligible_count'] = Db::name('airdrop_eligibilities')->where('activity_id', $row['id'])->where('status', 'eligible')->count();
            $row['issued_count'] = Db::name('airdrop_eligibilities')->where('activity_id', $row['id'])->where('status', 'issued')->count();
        }
        return $this->success(camelize_keys($rows));
    }

    /**
     * POST /admin/marketing/airdrop-save { id?, name, type, status, collectible_id, quantity_per_user, total_limit?, start_time?, end_time?, snapshot_collectible_id?, checkin_days?, condition_config?, description? }
     */
    public function airdropSave()
    {
        $missing = $this->missingParams(['name', 'type', 'collectible_id', 'quantity_per_user']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $type   = (string) $this->request->param('type');
        $name   = trim((string) $this->request->param('name'));
        $cid    = (int) $this->request->param('collectible_id');
        $qty    = (int) $this->request->param('quantity_per_user');
        $status = (string) $this->request->param('status', 'draft');

        if (!in_array($type, ['direct', 'hold', 'checkin', 'register', 'login', 'invite'], true)) {
            return $this->fail(4220, 'type 不合法');
        }
        if (!in_array($status, ['draft', 'active', 'paused', 'ended'], true)) {
            return $this->fail(4220, 'status 不合法');
        }
        if (!Db::name('collectibles')->where('id', $cid)->whereNull('deleted_at')->find()) {
            return $this->fail(4040, '空投藏品不存在');
        }
        if ($qty < 1 || $qty > 100) {
            return $this->fail(4220, '每人发放数量需在 1~100');
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'name'          => mb_substr($name, 0, 100),
            'type'          => $type,
            'status'        => $status,
            'airdrop_mode'  => in_array($this->request->param('airdrop_mode', 'realtime'), ['realtime', 'batch'], true)
                ? (string) $this->request->param('airdrop_mode', 'realtime') : 'realtime',
            'collectible_id' => $cid,
            'quantity_per_user' => $qty,
            'total_limit'   => $this->positiveInt('total_limit'),
            'start_time'    => $this->optionalDate('start_time'),
            'end_time'      => $this->optionalDate('end_time'),
            'snapshot_collectible_id' => $this->positiveInt('snapshot_collectible_id'),
            'checkin_days'  => $this->positiveInt('checkin_days'),
            'condition_config' => (string) $this->request->param('condition_config', '') ?: null,
            'description'   => (string) $this->request->param('description', '') ?: null,
            'updated_at'    => $now,
        ];

        $id = $this->positiveInt('id');
        if ($id !== null) {
            $act = Db::name('airdrop_activities')->where('id', $id)->find();
            if (!$act) {
                return $this->fail(4040, '空投活动不存在');
            }
            if ((int) $act['issued_count'] > 0 && $cid !== (int) $act['collectible_id']) {
                return $this->fail(4220, '该活动已发放过藏品，禁止更换空投藏品');
            }
            Db::name('airdrop_activities')->where('id', $id)->update($data);
        } else {
            $data['issued_count'] = 0;
            $data['created_at'] = $now;
            $id = (int) Db::name('airdrop_activities')->insertGetId($data);
        }

        $this->audit('marketing', 'airdrop_save', '保存空投活动「' . $name . '」', ['id' => $id], 'airdrop_activity', $id);
        return $this->success(['id' => $id], '空投活动已保存');
    }

    /**
     * POST /admin/marketing/airdrop-issue { activity_id }
     * 活动空投批量发放：向全部 eligible 未发放用户发放（受库存池与总限量约束）
     */
    public function airdropIssue()
    {
        $activityId = $this->positiveInt('activity_id');
        if ($activityId === null) {
            return $this->fail(4220, 'activity_id 参数不正确');
        }
        $act = Db::name('airdrop_activities')->where('id', $activityId)->whereNull('deleted_at')->find();
        if (!$act) {
            return $this->fail(4040, '空投活动不存在');
        }
        if ($act['status'] !== 'active') {
            return $this->fail(4220, '仅「进行中」的活动可执行发放');
        }

        $eligibles = Db::name('airdrop_eligibilities')->where('activity_id', $activityId)->where('status', 'eligible')->select()->toArray();
        if (!$eligibles) {
            return $this->fail(4220, '没有待发放的用户（无资格记录或已全部发放）');
        }

        // 库存校验
        $c = Db::name('collectibles')->where('id', $act['collectible_id'])->find();
        $pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity']
              - (int) $c['reserved_count'] - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];
        $needTotal = count($eligibles) * (int) $act['quantity_per_user'];
        $remainLimit = $act['total_limit'] !== null ? (int) $act['total_limit'] - (int) $act['issued_count'] : PHP_INT_MAX;
        $canIssue = min((int) floor($pool / (int) $act['quantity_per_user']), (int) floor($remainLimit / (int) $act['quantity_per_user']), count($eligibles));
        if ($canIssue <= 0) {
            return $this->fail(4220, '库存池或总限量不足以发放（可发放人数 ' . $canIssue . '）');
        }

        $now   = date('Y-m-d H:i:s');
        $issued = 0;
        Db::startTrans();
        try {
            $taskNo = 'ADA' . date('ymdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
            $taskId = (int) Db::name('airdrop_tasks')->insertGetId([
                'task_no'        => $taskNo,
                'target_type'    => 3, // 3=空投活动
                'target_id'      => $activityId,
                'target_name'    => $act['name'],
                'total_quantity' => $canIssue * (int) $act['quantity_per_user'],
                'user_count'     => $canIssue,
                'admin_id'       => $this->adminId(),
                'admin_name'     => $this->adminName(),
                'ip'             => (string) $this->request->ip(),
                'created_at'     => $now,
            ]);

            foreach (array_slice($eligibles, 0, $canIssue) as $elig) {
                for ($i = 0; $i < (int) $act['quantity_per_user']; $i++) {
                    $ucid = (int) Db::name('user_collectibles')->insertGetId([
                        'user_id'        => $elig['user_id'],
                        'collectible_id' => $act['collectible_id'],
                        'serial'         => gen_serial_placeholder(),
                        'source'         => 'airdrop',
                        'acquired_price' => 0,
                        'acquired_at'    => $now,
                        'status'         => 'held',
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);
                    Db::name('user_collectibles')->where('id', $ucid)->update([
                        'serial' => 'SN-' . $act['collectible_id'] . '-' . str_pad((string) $ucid, 4, '0', STR_PAD_LEFT),
                    ]);
                    Db::name('airdrop_records')->insert([
                        'activity_id' => $activityId,
                        'task_id'     => $taskId,
                        'user_id'     => $elig['user_id'],
                        'phone'       => $elig['phone'],
                        'collectible_id' => $act['collectible_id'],
                        'user_collectible_id' => $ucid,
                        'quantity'    => 1,
                        'status'      => 'issued',
                        'issued_at'   => $now,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]);
                }
                Db::name('airdrop_eligibilities')->where('id', $elig['id'])->update([
                    'status' => 'issued', 'updated_at' => $now,
                ]);
                $issued++;
            }

            $issuedQty = $issued * (int) $act['quantity_per_user'];
            Db::name('collectibles')->where('id', $act['collectible_id'])->update([
                'airdropped_count' => Db::raw('airdropped_count + ' . $issuedQty),
                'circulate'        => Db::raw('circulate + ' . $issuedQty),
                'updated_at'       => $now,
            ]);
            Db::name('airdrop_activities')->where('id', $activityId)->update([
                'issued_count' => Db::raw('issued_count + ' . $issuedQty),
                'updated_at'   => $now,
            ]);
            Db::name('airdrop_tasks')->where('id', $taskId)->update(['success_count' => $issued * (int) $act['quantity_per_user']]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '发放失败：' . $e->getMessage());
        }

        $this->audit('marketing', 'airdrop_issue', '活动空投发放「' . $act['name'] . '」' . $issued . ' 人',
            ['task_no' => $taskNo], 'airdrop_activity', $activityId);
        return $this->success(['issued' => $issued, 'task_no' => $taskNo], '已向 ' . $issued . ' 位用户发放完成');
    }

    // ==================== 注册福利 ====================

    /**
     * GET /admin/marketing/register
     */
    public function registerConfig()
    {
        $points = (int) (Db::name('system_configs')->where('config_key', 'register_reward_points')->value('config_value') ?: 0);
        $cid  = (int) (Db::name('system_configs')->where('config_key', 'register_reward_collectible_id')->value('config_value') ?: 0);
        $qty  = (int) (Db::name('system_configs')->where('config_key', 'register_reward_collectible_qty')->value('config_value') ?: 0);

        return $this->success([
            'points' => $points,
            'collectibleId' => $cid,
            'collectibleName' => $cid ? Db::name('collectibles')->where('id', $cid)->value('name') : null,
            'quantity' => $qty,
        ]);
    }

    /**
     * POST /admin/marketing/register { points?, collectible_id?, quantity? }
     */
    public function registerSave()
    {
        $now = date('Y-m-d H:i:s');
        $configs = [];

        $points = $this->request->param('points');
        if ($points !== null && $points !== '') {
            $points = (int) $points;
            if ($points < 0 || $points > 100000) {
                return $this->fail(4220, '注册赠送司南币需在 0~100000');
            }
            $configs['register_reward_points'] = (string) $points;
        }

        $cid = $this->request->param('collectible_id');
        if ($cid !== null && $cid !== '') {
            $cid = (int) $cid;
            if ($cid > 0 && !Db::name('collectibles')->where('id', $cid)->whereNull('deleted_at')->find()) {
                return $this->fail(4040, '注册赠送藏品不存在');
            }
            $configs['register_reward_collectible_id'] = (string) $cid;
        }

        $qty = $this->request->param('quantity');
        if ($qty !== null && $qty !== '') {
            $qty = (int) $qty;
            if ($qty < 0 || $qty > 10) {
                return $this->fail(4220, '注册赠送藏品数量需在 0~10');
            }
            $configs['register_reward_collectible_qty'] = (string) $qty;
        }

        if (!$configs) {
            return $this->fail(4220, '没有需要保存的配置');
        }

        foreach ($configs as $key => $value) {
            if (Db::name('system_configs')->where('config_key', $key)->find()) {
                Db::name('system_configs')->where('config_key', $key)->update(['config_value' => $value, 'updated_at' => $now]);
            } else {
                Db::name('system_configs')->insert(['config_key' => $key, 'config_value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $this->audit('marketing', 'register_save', '更新注册福利配置', $configs);
        return $this->success(null, '注册福利配置已保存');
    }

    private function optionalDate(string $key): ?string
    {
        $value = trim((string) $this->request->param($key, ''));
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
