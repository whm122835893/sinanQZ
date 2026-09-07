<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\service\ActivityRewardService;
use app\service\RewardGrantService;
use think\facade\Db;

/**
 * 管理后台营销活动控制器
 *
 * 覆盖：优先购活动与白名单、签到奖励配置、邀请活动、抽奖活动与奖项、
 * 合成活动与素材、活动空投发放、注册活动（实名前N名档位）、奖励名单导出与统一发放。
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
    private const CHECKIN_KEYS = [
        'checkin_enabled', 'checkin_activity_name', 'checkin_start_time', 'checkin_end_time',
        'checkin_eligibility_type', 'checkin_eligibility_config', 'checkin_grant_mode',
    ];

    /**
     * GET /admin/marketing/checkin
     * 返回：活动开关/名称/起止时间 + 奖励规则（旧版司南币 + 新版六类奖励）+ 参与资格 + 发放方式 + 今日签到统计
     */
    public function checkinConfig()
    {
        $configs = Db::name('system_configs')
            ->whereIn('config_key', self::CHECKIN_KEYS)
            ->column('config_value', 'config_key');

        $rewards = Db::name('system_configs')->where('config_key', 'checkin_rewards')->value('config_value');
        $rewards = $rewards ? json_decode((string) $rewards, true) : [];

        // 新版奖励配置：{day: [rewards...]}（六类奖励，覆盖旧版纯司南币）
        $rewardConfig = Db::name('system_configs')->where('config_key', 'checkin_reward_config')->value('config_value');
        $rewardConfig = $rewardConfig ? json_decode((string) $rewardConfig, true) : [];

        $eligibilityConfig = (string) ($configs['checkin_eligibility_config'] ?? '');
        $eligibilityConfig = $eligibilityConfig ? json_decode($eligibilityConfig, true) : new \stdClass();

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
            'rewardConfig' => $rewardConfig ?: new \stdClass(),
            'eligibilityType'  => (string) ($configs['checkin_eligibility_type'] ?? 'all'),
            'eligibilityConfig' => $eligibilityConfig,
            'grantMode'   => (string) ($configs['checkin_grant_mode'] ?? 'realtime'),
            'todayCount'  => $todayCount,
            'trend'       => array_column($stats, 'cnt', 'd'),
        ]);
    }

    /**
     * POST /admin/marketing/checkin
     * 支持提交：
     *   { enabled?, name?, start_time?, end_time?,
     *     rewards?: {1:5,...}（旧版司南币）,
     *     reward_config?: {1:[{type,...}], 7:[{type,...}]}（新版六类奖励，覆盖旧版）,
     *     eligibility_type?, eligibility_config?, grant_mode? }
     */
    public function checkinSave()
    {
        $now = date('Y-m-d H:i:s');
        $changed = [];

        // ---- 活动信息（enabled / name / start_time / end_time）----
        if ($this->request->has('enabled')) {
            $enabled = (int) $this->request->param('enabled') === 1 ? 1 : 0;
            $this->upsertConfig('checkin_enabled', (string) $enabled);
            $changed['enabled'] = $enabled === 1;
        }
        if ($this->request->has('name')) {
            $name = mb_substr(trim((string) $this->request->param('name')), 0, 100);
            if ($name === '') {
                return $this->fail(4220, '签到活动名称不能为空');
            }
            $this->upsertConfig('checkin_activity_name', $name);
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
                $this->upsertConfig($key, $val);
                $changed[$param] = $val;
            }
        }

        // ---- 参与资格 / 发放方式 ----
        if ($this->request->has('eligibility_type')) {
            try {
                $eligibilityConfig = $this->request->param('eligibility_config', []);
                $eligibility = ActivityRewardService::validateEligibility(
                    (string) $this->request->param('eligibility_type', 'all'),
                    is_array($eligibilityConfig) ? $eligibilityConfig : []
                );
            } catch (\Throwable $e) {
                return $this->fail(4220, $e->getMessage());
            }
            $this->upsertConfig('checkin_eligibility_type', $eligibility['type']);
            $this->upsertConfig('checkin_eligibility_config', $eligibility['config'] ? json_encode($eligibility['config'], JSON_UNESCAPED_UNICODE) : '');
            $changed['eligibility'] = $eligibility['type'];
        }
        if ($this->request->has('grant_mode')) {
            $grantMode = in_array($this->request->param('grant_mode', 'realtime'), ['realtime', 'manual'], true)
                ? (string) $this->request->param('grant_mode', 'realtime') : 'realtime';
            $this->upsertConfig('checkin_grant_mode', $grantMode);
            $changed['grant_mode'] = $grantMode;
        }

        // ---- 奖励规则（旧版：天数 → 司南币）----
        $rewards = $this->request->param('rewards');
        if ($rewards !== null && $this->request->has('rewards') && !$this->request->has('reward_config')) {
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
            $this->upsertConfig('checkin_rewards', json_encode($clean));
            $changed['rewards'] = $clean;
        }

        // ---- 奖励规则（新版：天数 → 六类奖励列表）----
        if ($this->request->has('reward_config')) {
            $rewardConfig = $this->request->param('reward_config', []);
            if (!is_array($rewardConfig)) {
                return $this->fail(4220, 'reward_config 格式不合法（天数 → 奖励列表）');
            }
            $clean = [];
            foreach ($rewardConfig as $day => $rewardsList) {
                $day = (int) $day;
                if ($day < 1 || $day > 7) {
                    return $this->fail(4220, '连续签到天数仅支持 1~7');
                }
                if (!is_array($rewardsList) || !$rewardsList) {
                    continue; // 该天无奖励
                }
                try {
                    $clean[$day] = ActivityRewardService::normalizeRewards($rewardsList);
                } catch (\Throwable $e) {
                    return $this->fail(4220, "第 {$day} 天奖励配置错误：" . $e->getMessage());
                }
            }
            ksort($clean);
            $this->upsertConfig('checkin_reward_config', json_encode($clean, JSON_UNESCAPED_UNICODE));
            $changed['reward_config'] = $clean;
        }

        if (!$changed) {
            return $this->fail(4220, '无可保存内容（enabled/name/start_time/end_time/rewards/reward_config/eligibility/grant_mode 至少其一）');
        }

        $this->audit('marketing', 'checkin_save', '更新签到活动配置', array_keys($changed));
        return $this->success(null, '签到活动配置已保存');
    }

    /** system_configs upsert（键存在更新，不存在插入） */
    private function upsertConfig(string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');
        if (Db::name('system_configs')->where('config_key', $key)->find()) {
            Db::name('system_configs')->where('config_key', $key)->update(['config_value' => $value, 'updated_at' => $now]);
        } else {
            Db::name('system_configs')->insert(['config_key' => $key, 'config_value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }
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
     * POST /admin/marketing/invite-save
     * { id?, name, status, start_time?, end_time?, total_limit?, description?,
     *   tiers?: [{inviteCount:1-50, rewards:[{type,...}]}],
     *   invitee_reward_config?: {type, ...},
     *   invitee_conditions?: ['realname','wallet','checkin','consume'],
     *   grant_mode?: realtime|manual,
     *   —— 兼容旧字段：inviter_collectible_id?, inviter_quantity, invitee_collectible_id?, invitee_quantity, airdrop_mode }
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

        // ---- 新版：档位奖励（1-50人）+ 被邀请人奖励与完成条件 + 发放方式 ----
        $tiers = $this->request->param('tiers', []);
        $inviteeReward = $this->request->param('invitee_reward_config');
        $inviteeConditions = $this->request->param('invitee_conditions', []);
        $hasNewConfig = (is_array($tiers) && $tiers) || (is_array($inviteeReward) && $inviteeReward);

        try {
            $cleanTiers = [];
            if (is_array($tiers) && $tiers) {
                if (count($tiers) > 20) {
                    throw new \Exception('邀请档位最多 20 档');
                }
                $seenCounts = [];
                foreach ($tiers as $tier) {
                    $inviteCount = (int) ($tier['inviteCount'] ?? 0);
                    if ($inviteCount < 1 || $inviteCount > 50) {
                        throw new \Exception('邀请档位人数需在 1~50');
                    }
                    if (isset($seenCounts[$inviteCount])) {
                        throw new \Exception('邀请档位人数重复：' . $inviteCount);
                    }
                    $seenCounts[$inviteCount] = true;
                    $rewards = (array) ($tier['rewards'] ?? []);
                    if (!$rewards) {
                        throw new \Exception('每档需至少配置一项奖励');
                    }
                    $cleanTiers[] = [
                        'inviteCount' => $inviteCount,
                        'rewards'     => ActivityRewardService::normalizeRewards($rewards),
                    ];
                }
                usort($cleanTiers, fn ($a, $b) => $a['inviteCount'] <=> $b['inviteCount']);
            }
            $cleanInviteeReward = null;
            if (is_array($inviteeReward) && !empty($inviteeReward['type'])) {
                $cleanInviteeReward = RewardGrantService::validate((string) $inviteeReward['type'], $inviteeReward);
            }
            $cleanConditions = [];
            if (is_array($inviteeConditions)) {
                $cleanConditions = array_values(array_intersect(
                    array_map('strval', $inviteeConditions),
                    ActivityRewardService::INVITEE_CONDITIONS
                ));
            }
        } catch (\Throwable $e) {
            return $this->fail(4220, $e->getMessage());
        }

        // 启用校验：新版需档位或被邀请人奖励至少其一；旧版需至少一方空投藏品
        $inviterCid = $this->positiveInt('inviter_collectible_id');
        $inviteeCid = $this->positiveInt('invitee_collectible_id');
        if ($status === 'enabled') {
            if ($hasNewConfig) {
                if (!$cleanTiers && !$cleanInviteeReward) {
                    return $this->fail(4220, '启用邀请活动需配置邀请档位奖励或被邀请人奖励至少其一');
                }
            } elseif ($inviterCid === null && $inviteeCid === null) {
                return $this->fail(4220, '启用邀请活动需配置邀请人/被邀请人至少一方的空投藏品');
            }
        }
        foreach ([['inviter', $inviterCid], ['invitee', $inviteeCid]] as [$label, $cid]) {
            if ($cid !== null && !Db::name('collectibles')->where('id', $cid)->whereNull('deleted_at')->find()) {
                return $this->fail(4220, $label . ' 空投藏品不存在');
            }
        }

        $grantMode = in_array($this->request->param('grant_mode', 'realtime'), ['realtime', 'manual'], true)
            ? (string) $this->request->param('grant_mode', 'realtime') : 'realtime';

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
            'tiers'                 => $cleanTiers ? json_encode($cleanTiers, JSON_UNESCAPED_UNICODE) : null,
            'invitee_reward_config' => $cleanInviteeReward ? json_encode($cleanInviteeReward, JSON_UNESCAPED_UNICODE) : null,
            'invitee_conditions'    => $cleanConditions ? json_encode($cleanConditions, JSON_UNESCAPED_UNICODE) : null,
            'grant_mode'    => $grantMode,
            'total_limit'   => $this->positiveInt('total_limit'),
            'description'   => (string) $this->request->param('description', '') ?: null,
            'updated_at'    => $now,
        ];

        $id = $this->positiveInt('id');
        Db::startTrans();
        try {
            if ($id !== null) {
                if (!Db::name('invite_activities')->where('id', $id)->whereNull('deleted_at')->find()) {
                    Db::rollback();
                    return $this->fail(4040, '邀请活动不存在');
                }
                Db::name('invite_activities')->where('id', $id)->update($data);
            } else {
                $data['used_count'] = 0;
                $data['created_at'] = $now;
                $id = (int) Db::name('invite_activities')->insertGetId($data);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '保存失败：' . $e->getMessage());
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

        // 活动实体表（名称/状态/时间/参与资格/发放方式）；兼容旧数据：无实体记录的活动兜底"第 N 期"且视为启用
        $actEntities = Db::name('lucky_draw_activities')->whereNull('deleted_at')
            ->column('name,status,start_time,end_time,eligibility_type,eligibility_config,grant_mode', 'id');

        // 无奖项但已有活动实体的（新建活动尚未配奖项）也需展示
        $activities = [];
        foreach ($actEntities as $actId => $ent) {
            $eligibilityConfig = json_decode((string) ($ent['eligibility_config'] ?? ''), true);
            $activities[(int) $actId] = [
                'activityId' => (int) $actId,
                'name'       => (string) $ent['name'],
                'status'     => (int) $ent['status'],
                'startTime'  => $ent['start_time'],
                'endTime'    => $ent['end_time'],
                'eligibilityType'   => (string) ($ent['eligibility_type'] ?: 'all'),
                'eligibilityConfig' => $eligibilityConfig ?: new \stdClass(),
                'grantMode'  => (string) ($ent['grant_mode'] ?: 'realtime'),
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
     * POST /admin/marketing/lucky-activity
     * { id?, name, status, start_time?, end_time?, eligibility_type?, eligibility_config?, grant_mode? }
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

        // 参与资格 / 发放方式
        try {
            $eligibilityType = (string) $this->request->param('eligibility_type', 'all');
            $eligibilityConfig = $this->request->param('eligibility_config');
            $eligibility = ActivityRewardService::validateEligibility(
                $eligibilityType,
                is_array($eligibilityConfig) ? $eligibilityConfig : []
            );
            $grantMode = in_array($this->request->param('grant_mode', 'realtime'), ['realtime', 'manual'], true)
                ? (string) $this->request->param('grant_mode', 'realtime') : 'realtime';
        } catch (\Throwable $e) {
            return $this->fail(4220, $e->getMessage());
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
            'eligibility_type'   => $eligibility['type'],
            'eligibility_config' => $eligibility['config'] ? json_encode($eligibility['config'], JSON_UNESCAPED_UNICODE) : null,
            'grant_mode' => $grantMode,
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
     * POST /admin/marketing/lucky-save
     * { activity_id, prizes: [{id?, tier_name, prize_name?, prize_image?, prize_type, collectible_id?, coin_amount?, reward_config?, total, probability, sort_order?}] }
     * 奖项整体覆盖式保存（概率合计必须为 1）
     * prize_type：collectible/points/draw_chance/priority_qualification/eligibility_qualification/blindbox/none
     * reward_config：非藏品类奖项的奖励配置（按类型：amount/quantity/prioritySaleId/collectibleId/blindboxId/expiresAt）
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
        $validatedPrizes = [];
        foreach ($prizes as $prize) {
            $type = (string) ($prize['prize_type'] ?? 'collectible');
            $allowedTypes = array_merge(RewardGrantService::TYPES, ['none']);
            if (!in_array($type, $allowedTypes, true)) {
                return $this->fail(4220, 'prize_type 仅允许：' . implode('/', $allowedTypes));
            }
            $rewardConfig = null;
            if ($type !== 'none') {
                try {
                    // 藏品类奖项兼容旧参数 collectible_id；其余走 reward_config
                    $cfg = is_array($prize['reward_config'] ?? null) ? $prize['reward_config'] : [];
                    if ($type === 'collectible' && !empty($prize['collectible_id'])) {
                        $cfg['collectibleId'] = (int) $prize['collectible_id'];
                    }
                    if ($type === 'points' && isset($prize['coin_amount'])) {
                        $cfg['amount'] = (float) $prize['coin_amount'];
                    }
                    $cfg['quantity'] = (int) ($cfg['quantity'] ?? 1);
                    $rewardConfig = RewardGrantService::validate($type, $cfg);
                } catch (\Throwable $e) {
                    return $this->fail(4220, '奖项「' . ($prize['tier_name'] ?? '') . '」配置错误：' . $e->getMessage());
                }
            }
            $total = (int) ($prize['total'] ?? 0);
            if ($total < 1) {
                return $this->fail(4220, '奖项库存需 ≥ 1');
            }
            $probabilitySum += (float) ($prize['probability'] ?? 0);
            $prize['validated_reward'] = $rewardConfig;
            $validatedPrizes[] = $prize;
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
            foreach ($validatedPrizes as $prize) {
                $type = (string) ($prize['prize_type'] ?? 'collectible');
                $reward = $prize['validated_reward'];
                $data = [
                    'activity_id'    => $activityId,
                    'tier_name'      => mb_substr((string) ($prize['tier_name'] ?? ''), 0, 20) ?: '奖项',
                    'prize_name'     => mb_substr(trim((string) ($prize['prize_name'] ?? '')), 0, 100) ?: null,
                    'prize_image'    => trim((string) ($prize['prize_image'] ?? '')) ?: null,
                    'prize_type'     => $type,
                    'collectible_id' => $type === 'collectible' ? (int) ($reward['collectibleId'] ?? 0) : null,
                    'coin_amount'    => $type === 'points' ? (float) ($reward['amount'] ?? 0) : null,
                    'reward_config'  => $reward ? json_encode($reward, JSON_UNESCAPED_UNICODE) : null,
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
     * POST /admin/marketing/synthesis-save
     * { id?, type, title, rules, result_collectible_id, result_quantity, materials:[{collectible_id, count}],
     *   per_user_limit, total_limit?, start_time?, end_time?, image?,
     *   eligibility_type?, eligibility_config?, grant_mode? }
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
        $resultQty = max(1, (int) $this->request->param('result_quantity', 1));
        if ($resultQty > 100) {
            return $this->fail(4220, '每次合成产出数量需在 1~100');
        }
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

        // 参与资格 / 发放方式
        try {
            $eligibilityType = (string) $this->request->param('eligibility_type', 'all');
            $eligibilityConfig = $this->request->param('eligibility_config');
            $eligibility = ActivityRewardService::validateEligibility(
                $eligibilityType,
                is_array($eligibilityConfig) ? $eligibilityConfig : []
            );
            $grantMode = in_array($this->request->param('grant_mode', 'realtime'), ['realtime', 'manual'], true)
                ? (string) $this->request->param('grant_mode', 'realtime') : 'realtime';
        } catch (\Throwable $e) {
            return $this->fail(4220, $e->getMessage());
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
                'result_quantity' => $resultQty,
                'per_user_limit' => max(0, (int) $this->request->param('per_user_limit')),
                'total_limit'   => $this->positiveInt('total_limit'),
                'image'         => trim((string) $this->request->param('image', '')) ?: null,
                'eligibility_type'   => $eligibility['type'],
                'eligibility_config' => $eligibility['config'] ? json_encode($eligibility['config'], JSON_UNESCAPED_UNICODE) : null,
                'grant_mode'    => $grantMode,
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

    // ==================== 注册活动（实名前N名档位奖励） ====================

    /**
     * GET /admin/marketing/register
     * 注册活动列表（含档位与已发放统计）
     */
    public function registerList()
    {
        $rows = Db::name('register_activities')->whereNull('deleted_at')
            ->order('id', 'desc')->select()->toArray();
        foreach ($rows as &$row) {
            $row['tiers_parsed'] = ActivityRewardService::parseJson($row['tiers'] ?? null);
            // 已发放人数（奖励名单）
            $row['granted_count'] = Db::name('activity_reward_records')
                ->where('activity_type', 'register')->where('activity_id', $row['id'])->count();
            // 平台累计实名人数（档位容量参考）
            $row['realname_count'] = (int) Db::name('users')->where('is_realname', 1)->whereNull('deleted_at')->count();
        }
        return $this->success(camelize_keys($rows));
    }

    /**
     * POST /admin/marketing/register-save
     * { id?, name, status: disabled|enabled, start_time?, end_time?,
     *   tiers: [{rankLimit: N, rewards: [{type,...}]}],
     *   grant_mode?: realtime|manual, description? }
     */
    public function registerSave()
    {
        $missing = $this->missingParams(['name', 'status', 'tiers']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $name   = mb_substr(trim((string) $this->request->param('name')), 0, 100);
        $status = (string) $this->request->param('status');
        if ($name === '') {
            return $this->fail(4220, '活动名称不能为空');
        }
        if (!in_array($status, ['disabled', 'enabled'], true)) {
            return $this->fail(4220, 'status 仅允许 disabled/enabled');
        }

        // 档位校验：实名前N名，N 升序不重复
        $tiers = $this->request->param('tiers', []);
        if (!is_array($tiers) || count($tiers) < 1) {
            return $this->fail(4220, '至少配置一个实名档位（如：实名前1000名）');
        }
        if (count($tiers) > 10) {
            return $this->fail(4220, '实名档位最多 10 档');
        }
        $cleanTiers = [];
        $seenRanks = [];
        foreach ($tiers as $tier) {
            $rankLimit = (int) ($tier['rankLimit'] ?? 0);
            if ($rankLimit < 1 || $rankLimit > 1000000) {
                return $this->fail(4220, '实名档位名次需在 1~1000000（如：前1000名 → 1000）');
            }
            if (isset($seenRanks[$rankLimit])) {
                return $this->fail(4220, '实名档位名次重复：前 ' . $rankLimit . ' 名');
            }
            $seenRanks[$rankLimit] = true;
            try {
                $rewards = (array) ($tier['rewards'] ?? []);
                if (!$rewards) {
                    throw new \Exception('每档需至少配置一项奖励');
                }
                $cleanTiers[] = [
                    'rankLimit' => $rankLimit,
                    'rewards'   => ActivityRewardService::normalizeRewards($rewards),
                ];
            } catch (\Throwable $e) {
                return $this->fail(4220, '档位「前' . $rankLimit . '名」配置错误：' . $e->getMessage());
            }
        }
        usort($cleanTiers, fn ($a, $b) => $a['rankLimit'] <=> $b['rankLimit']);

        $grantMode = in_array($this->request->param('grant_mode', 'realtime'), ['realtime', 'manual'], true)
            ? (string) $this->request->param('grant_mode', 'realtime') : 'realtime';

        $now = date('Y-m-d H:i:s');
        $data = [
            'name'        => $name,
            'status'      => $status,
            'start_time'  => $this->optionalDate('start_time'),
            'end_time'    => $this->optionalDate('end_time'),
            'tiers'       => json_encode($cleanTiers, JSON_UNESCAPED_UNICODE),
            'grant_mode'  => $grantMode,
            'description' => (string) $this->request->param('description', '') ?: null,
            'updated_at'  => $now,
        ];

        $id = $this->positiveInt('id');
        Db::startTrans();
        try {
            if ($id !== null) {
                if (!Db::name('register_activities')->where('id', $id)->whereNull('deleted_at')->find()) {
                    Db::rollback();
                    return $this->fail(4040, '注册活动不存在');
                }
                Db::name('register_activities')->where('id', $id)->update($data);
            } else {
                $data['used_count'] = 0;
                $data['created_at'] = $now;
                $id = (int) Db::name('register_activities')->insertGetId($data);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '保存失败：' . $e->getMessage());
        }

        $this->audit('marketing', 'register_save', '保存注册活动「' . $name . '」', ['id' => $id, 'tiers' => count($cleanTiers)], 'register_activity', $id);
        return $this->success(['id' => $id], '注册活动已保存');
    }

    /**
     * DELETE /admin/marketing/register-delete { id }
     */
    public function registerDelete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $act = Db::name('register_activities')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$act) {
            return $this->fail(4040, '注册活动不存在');
        }
        // 已产生发放记录的活动仅停用不删除
        $granted = Db::name('activity_reward_records')->where('activity_type', 'register')->where('activity_id', $id)->count();
        if ($granted > 0) {
            Db::name('register_activities')->where('id', $id)->update([
                'status' => 'disabled', 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->audit('marketing', 'register_delete', '注册活动「' . $act['name'] . '」已产生发放记录，转为停用', ['id' => $id], 'register_activity', $id);
            return $this->success(null, '该活动已产生发放记录，已转为停用');
        }
        Db::name('register_activities')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('marketing', 'register_delete', '删除注册活动「' . $act['name'] . '」', ['id' => $id], 'register_activity', $id);
        return $this->success(null, '注册活动已删除');
    }

    // ==================== 奖励名单（导出/统一发放） ====================

    /**
     * GET /admin/marketing/reward-records
     * 查询参数：activity_type?, activity_id?, status?, user_id?, phone?, keyword(活动名/手机号)?
     */
    public function rewardRecords()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('activity_reward_records');
        $activityType = (string) $this->request->param('activity_type', '');
        if ($activityType !== '' && in_array($activityType, ActivityRewardService::ACTIVITY_TYPES, true)) {
            $query->where('activity_type', $activityType);
        }
        $activityId = $this->positiveInt('activity_id');
        if ($activityId !== null) {
            $query->where('activity_id', $activityId);
        }
        $status = (string) $this->request->param('status', '');
        if ($status !== '' && in_array($status, ['pending', 'issued', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }
        $userId = $this->positiveInt('user_id');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        $phone = trim((string) $this->request->param('phone', ''));
        if ($phone !== '') {
            $query->whereLike('phone', "%{$phone}%");
        }
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('activity_title', "%{$keyword}%");
        }

        $total = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        // 统计卡片
        $stats = Db::name('activity_reward_records')
            ->field('status, COUNT(*) AS cnt')
            ->group('status')->select()->toArray();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize, [
            'stats' => array_column($stats, 'cnt', 'status'),
        ]);
    }

    /**
     * GET /admin/marketing/reward-records/export
     * 导出名单 CSV（同上筛选参数）
     */
    public function rewardRecordsExport()
    {
        $query = Db::name('activity_reward_records');
        $activityType = (string) $this->request->param('activity_type', '');
        if ($activityType !== '' && in_array($activityType, ActivityRewardService::ACTIVITY_TYPES, true)) {
            $query->where('activity_type', $activityType);
        }
        $activityId = $this->positiveInt('activity_id');
        if ($activityId !== null) {
            $query->where('activity_id', $activityId);
        }
        $status = (string) $this->request->param('status', '');
        if ($status !== '' && in_array($status, ['pending', 'issued', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }
        $rows = $query->order('id', 'asc')->limit(50000)->select()->toArray();

        $typeNames = [
            'synthesis' => '合成', 'lucky_draw' => '抽奖', 'checkin' => '签到',
            'invite' => '邀请', 'register' => '注册',
        ];
        $statusNames = ['pending' => '待发放', 'issued' => '已发放', 'failed' => '发放失败', 'cancelled' => '已取消'];

        $filename = '奖励名单_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        // BOM 头（Excel 乱码）
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['记录ID', '活动类型', '活动ID', '活动名称', '用户ID', '手机号', '奖励类型', '奖励内容', '状态', '发放时间', '发放结果', '创建时间']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $typeNames[$row['activity_type']] ?? $row['activity_type'],
                $row['activity_id'],
                $row['activity_title'],
                $row['user_id'],
                $row['phone'],
                ActivityRewardService::TYPE_LABELS[$row['reward_type']] ?? $row['reward_type'],
                $row['reward_label'],
                $statusNames[$row['status']] ?? $row['status'],
                $row['issued_at'] ?: '',
                $row['issue_result'] ?: '',
                $row['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    /**
     * POST /admin/marketing/reward-records/issue
     * 待发放名单统一发放
     * { activity_type?, activity_id?, record_ids?: [id...] }
     */
    public function rewardRecordsIssue()
    {
        $activityType = (string) $this->request->param('activity_type', '');
        $activityId   = $this->positiveInt('activity_id');
        $recordIds    = $this->request->param('record_ids', []);
        if (!is_array($recordIds)) {
            $recordIds = [];
        }

        $result = ActivityRewardService::issuePendingRecords(
            $activityType !== '' ? $activityType : '',
            $activityId ?? 0,
            array_map('intval', $recordIds)
        );

        $this->audit('marketing', 'reward_records_issue', '奖励名单统一发放（成功 ' . $result['success'] . ' / 失败 ' . $result['failed'] . '）', $result);
        return $this->success($result, '统一发放完成：成功 ' . $result['success'] . ' 条，失败 ' . $result['failed'] . ' 条');
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
