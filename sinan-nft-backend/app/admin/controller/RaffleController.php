<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\RaffleOpLogService;
use app\admin\traits\ExcelExport;
use app\service\DrawCodeService;
use app\service\RaffleService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\facade\Db;

/**
 * 抽签发售管理（admin/raffle:*）
 *
 * 覆盖后台模块：
 * - 抽签活动管理：新建/编辑（总发行量、抽签名额、邀请/购买码配置）、名额校验、启停、单阶段开奖
 * - 报名记录：设为必中/取消必中（单个+批量+手机号）、实时必中统计、必中总数 ≤ 抽签名额
 * - 抽签码管理：手动新增、Excel 批量导入（管理员发放不受邀请/购买档位上限限制）、作废/删除
 * - 中签记录：中签/未中签列表、标记付款、核销、导出 Excel
 * - 抽签操作日志：只读列表（不可删除）
 */
class RaffleController extends BaseController
{
    use ExcelExport;

    // ==================================================================
    // 一、抽签活动管理
    // ==================================================================

    /**
     * GET /admin/raffle/list
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('raffle_activities')->alias('ra')->whereNull('ra.deleted_at')
            ->join('collectibles c', 'c.id = ra.collectible_id', 'LEFT')
            ->field('ra.*, c.name AS collectible_name, c.image AS collectible_image, c.sold AS collectible_sold, c.edition AS collectible_edition');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('ra.name', "%{$keyword}%")->whereOrLike('c.name', "%{$keyword}%");
            });
        }
        $status = $this->enumParam('status', ['0','1','2','3','4']);
        if ($status !== null) $query->where('ra.status', (int) $status);
        $collectibleId = $this->positiveInt('collectibleId');
        if ($collectibleId !== null) $query->where('ra.collectible_id', $collectibleId);

        $total = (clone $query)->count();
        $rows = $query->order('ra.id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['registration_count'] = Db::name('raffle_registrations')->where('activity_id', $row['id'])->count();
            $row['winner_count_done']  = Db::name('raffle_registrations')->where('activity_id', $row['id'])->where('draw_status', 1)->count();
            $row['force_win_count']    = Db::name('raffle_registrations')->where('activity_id', $row['id'])->where('is_force_win', 1)->count();
            $row['code_count']         = Db::name('user_draw_codes')->where('activity_id', $row['id'])->count();
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/raffle/detail/:id
     */
    public function detail()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);

        $row = Db::name('raffle_activities')->alias('ra')->where('ra.id', $id)
            ->whereNull('ra.deleted_at')
            ->join('collectibles c', 'c.id = ra.collectible_id', 'LEFT')
            ->field('ra.*, c.name AS collectible_name, c.image AS collectible_image, c.price AS collectible_price, c.sold AS collectible_sold, c.edition AS collectible_edition')
            ->find();
        if (!$row) return $this->fail(4040, '活动不存在');

        $row['registrations'] = Db::name('raffle_registrations')->alias('reg')
            ->join('users u', 'u.id = reg.user_id', 'LEFT')
            ->where('reg.activity_id', $id)
            ->field('reg.*, u.username, u.phone')
            ->order('reg.id', 'desc')
            ->select()->toArray();
        $row['force_win_count'] = Db::name('raffle_registrations')
            ->where('activity_id', $id)->where('is_force_win', 1)->count();

        return $this->success(camelize_keys($row));
    }

    /**
     * POST /admin/raffle/save  新建 or 编辑
     *
     * 新字段：totalSupply（藏品总发行量）、drawWinCount（本次抽签中签名额，按「签」计）、
     * buyCodeLimit（购买抽签码上限，drawCodeEnabled=1 时须>0）、
     * inviteEnabled / inviteCodeLimit / inviteUserNeeded（邀请好友参与得码：开关/上限/每 N 人得 1 码）、
     * maxWinsPerUser（单用户最大中签数，0=不限按码数封顶）。
     * 修改抽签名额/购买与邀请配置/总发行量均写入独立操作日志。
     */
    public function save()
    {
        $missing = $this->missingParams(['collectibleId', 'name', 'winnerCount', 'registrationStart', 'registrationEnd', 'drawTime', 'salePrice']);
        if ($missing) return $this->failMissing($missing);

        $isEdit = !empty($this->request->param('id'));
        $before = null;
        if ($isEdit) {
            $before = Db::name('raffle_activities')->where('id', (int) $this->request->param('id'))->find();
            if (!$before) return $this->fail(4040, '活动不存在');
        }

        try {
            $result = RaffleService::saveActivity($this->adminId(), $this->request->param());
        } catch (\Throwable $e) {
            return $this->fail(4220, $e->getMessage());
        }

        // 通用审计日志
        $this->audit('抽签发售', 'save', ($isEdit ? '编辑' : '新建') . '抽签活动', $result);

        // 独立操作日志：名额/码上限/总发行量变更
        if ($before) {
            $this->logQuotaChange($before, $this->request->param(), (int) $result['id']);
        }
        RaffleOpLogService::record(
            $this->request,
            RaffleOpLogService::ACTION_SAVE,
            ($isEdit ? '编辑' : '新建') . '抽签活动「' . ($this->request->param('name') ?: '') . '」',
            (int) $result['id'],
            ['adminInput' => $this->request->only(['name', 'totalSupply', 'drawWinCount', 'buyCodeLimit', 'inviteEnabled', 'inviteCodeLimit', 'inviteUserNeeded', 'maxWinsPerUser'])]
        );

        return $this->success($result);
    }

    /**
     * POST /admin/raffle/:id/start  手动开启报名
     */
    public function start()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        RaffleService::changeStatus($id, 1);
        $this->audit('抽签发售', 'start', '开启报名', ['id' => $id]);
        RaffleOpLogService::record($this->request, RaffleOpLogService::ACTION_STATUS, '开启报名（活动#' . $id . '）', $id);
        return $this->success(['id' => $id]);
    }

    /**
     * POST /admin/raffle/:id/pause  停用活动（回到草稿，暂停报名）
     */
    public function pause()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        $act = Db::name('raffle_activities')->where('id', $id)->find();
        if (!$act) return $this->fail(4040, '活动不存在');
        if ((int) ($act['win_locked'] ?? 0) === 1) return $this->fail(4220, '该活动已开奖，不可停用');
        RaffleService::changeStatus($id, 0);
        $this->audit('抽签发售', 'pause', '停用抽签活动', ['id' => $id]);
        RaffleOpLogService::record($this->request, RaffleOpLogService::ACTION_STATUS, '停用抽签活动（回到草稿）', $id);
        return $this->success(['id' => $id]);
    }

    /**
     * POST /admin/raffle/:id/draw  手动触发单阶段开奖
     * 必中用户直接中签 + 剩余名额随机抽取，一次完成；开奖后名额锁定。
     */
    public function draw()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);

        // 开奖前预检：已开奖直接友好报错（draw() 事务内还有行锁兜底，防并发双开奖）
        $act = Db::name('raffle_activities')->where('id', $id)->find();
        if (!$act) return $this->fail(4040, '活动不存在');
        if ((int) ($act['win_locked'] ?? 0) === 1) return $this->fail(4220, '该活动已开奖，不可重复开奖');

        try {
            // draw() 事务内行锁防重并直接置为已结束(3)，无需先切抽签中(2)，避免失败时卡在中间态
            $result = RaffleService::draw($id);
            $forced = count(array_filter($result, fn ($r) => (int) ($r['is_force_win'] ?? 0) === 1));
            $totalWins = array_sum(array_map(fn ($r) => (int) ($r['win_count'] ?? 0), $result));
            $this->audit('抽签发售', 'draw', '执行抽签', ['id' => $id, 'winners' => count($result), 'totalWins' => $totalWins]);
            RaffleOpLogService::record(
                $this->request,
                RaffleOpLogService::ACTION_DRAW,
                '执行开奖（活动#' . $id . '，必中 ' . $forced . ' 人 + 随机 ' . (count($result) - $forced) . ' 人，共 ' . $totalWins . ' 签）',
                $id,
                ['winners' => count($result), 'forcedWinners' => $forced, 'totalWins' => $totalWins, 'result' => $result]
            );
            return $this->success(['winners' => $result, 'count' => count($result), 'forcedCount' => $forced, 'totalWins' => $totalWins]);
        } catch (\Throwable $e) {
            return $this->fail(5000, $e->getMessage());
        }
    }

    /**
     * POST /admin/raffle/:id/cancel
     */
    public function cancel()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        RaffleService::changeStatus($id, 4);
        $this->audit('抽签发售', 'cancel', '取消抽签活动', ['id' => $id]);
        RaffleOpLogService::record($this->request, RaffleOpLogService::ACTION_STATUS, '取消抽签活动', $id);
        return $this->success(['id' => $id]);
    }

    /**
     * DELETE /admin/raffle/:id  软删除
     */
    public function delete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        Db::name('raffle_activities')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->audit('抽签发售', 'delete', '删除活动', ['id' => $id]);
        RaffleOpLogService::record($this->request, RaffleOpLogService::ACTION_STATUS, '删除抽签活动（软删除）', $id);
        return $this->success(['id' => $id]);
    }

    // ==================================================================
    // 二、报名记录（设为必中 / 批量必中 / 实时统计）
    // ==================================================================

    /**
     * GET /admin/raffle/registrations?activityId=&drawStatus=&forceWin=&keyword=
     * 报名记录列表（含实时统计：必中人数、报名人数、中签人数）
     */
    public function registrations()
    {
        [$page, $pageSize] = $this->pageParams();
        $activityId = $this->positiveInt('activityId');

        $query = Db::name('raffle_registrations')->alias('reg')
            ->join('users u', 'u.id = reg.user_id', 'LEFT')
            ->join('raffle_activities ra', 'ra.id = reg.activity_id', 'LEFT')
            ->field('reg.*, u.username, u.phone, ra.name AS activity_name, ra.win_locked, ra.draw_win_count, ra.winner_count');

        if ($activityId !== null) $query->where('reg.activity_id', $activityId);
        $drawStatus = $this->enumParam('drawStatus', ['0','1','2']);
        if ($drawStatus !== null) $query->where('reg.draw_status', (int) $drawStatus);
        $forceWin = $this->enumParam('forceWin', ['0','1']);
        if ($forceWin !== null) $query->where('reg.is_force_win', (int) $forceWin);
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('u.username', "%{$keyword}%")->whereOrLike('u.phone', "%{$keyword}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->order('reg.id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['code_count'] = Db::name('user_draw_codes')
                ->where('activity_id', $row['activity_id'])
                ->where('user_id', $row['user_id'])
                ->where('status', '<>', DrawCodeService::STATUS_INVALID)
                ->count();
        }

        // 实时统计（按活动范围）
        $stats = ['registrationCount' => 0, 'forceWinCount' => 0, 'winnerCount' => 0, 'totalWinCount' => 0, 'drawWinQuota' => 0, 'winLocked' => 0];
        if ($activityId !== null) {
            $act = Db::name('raffle_activities')->where('id', $activityId)->find();
            if ($act) {
                $stats['registrationCount'] = Db::name('raffle_registrations')->where('activity_id', $activityId)->count();
                $stats['forceWinCount']     = Db::name('raffle_registrations')->where('activity_id', $activityId)->where('is_force_win', 1)->count();
                $stats['winnerCount']       = Db::name('raffle_registrations')->where('activity_id', $activityId)->where('draw_status', 1)->count();
                $stats['totalWinCount']     = (int) Db::name('raffle_registrations')->where('activity_id', $activityId)->where('draw_status', 1)->sum('win_count');
                $stats['drawWinQuota']      = (int) ($act['draw_win_count'] ?: $act['winner_count']);
                $stats['winLocked']         = (int) ($act['win_locked'] ?? 0);
            }
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize, ['stats' => $stats]);
    }

    /**
     * POST /admin/raffle/registrations/force-win
     * 设为必中 / 取消必中（支持单个、批量、手机号）
     * Body: { activityId, registrationIds?: [1,2,...], phones?: ["138...",...], userIds?: [101,...], forceWin: true|false }
     * phones 支持数组或逗号/换行分隔文本；按手机号定位用户后须已报名该活动
     * 校验：活动未开奖；设置后必中总人数 ≤ 本次抽签名额
     */
    public function forceWin()
    {
        $activityId = $this->positiveInt('activityId');
        if ($activityId === null) return $this->failMissing(['activityId']);

        // ---------- 解析目标：报名记录 ID / 用户 ID / 手机号 ----------
        $ids = $this->request->param('registrationIds', []);
        if (is_string($ids)) $ids = array_filter(array_map('intval', explode(',', $ids)));
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $userIds = $this->request->param('userIds', []);
        if (is_string($userIds)) $userIds = array_filter(array_map('intval', preg_split('/[\s,，;；]+/u', $userIds)));
        if (!is_array($userIds)) $userIds = [];
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        $phones = $this->request->param('phones', []);
        if (is_string($phones)) $phones = preg_split('/[\s,，;；]+/u', trim($phones));
        if (!is_array($phones)) $phones = [];
        $phones = array_values(array_unique(array_filter(array_map('trim', $phones))));
        $notFoundPhones = []; // 手机号无法匹配到本活动报名记录

        $act = Db::name('raffle_activities')->where('id', $activityId)->whereNull('deleted_at')->find();
        if (!$act) return $this->fail(4040, '活动不存在');

        // 手机号 / 用户 ID → 报名记录
        $identifierUserIds = $userIds;
        if (!empty($phones)) {
            $phoneUsers = Db::name('users')->whereIn('phone', $phones)->column('id', 'phone');
            foreach ($phones as $p) {
                if (empty($phoneUsers[$p])) $notFoundPhones[] = $p;
            }
            $identifierUserIds = array_merge($identifierUserIds, array_values(array_map('intval', $phoneUsers)));
        }
        if (!empty($identifierUserIds)) {
            $idRegs = Db::name('raffle_registrations')
                ->where('activity_id', $activityId)
                ->whereIn('user_id', array_values(array_unique($identifierUserIds)))
                ->column('id');
            $ids = array_values(array_unique(array_merge($ids, array_map('intval', $idRegs))));
        }

        if (empty($ids)) {
            $msg = !empty($notFoundPhones)
                ? '未匹配到报名记录：手机号 ' . implode('、', array_slice($notFoundPhones, 0, 10)) . ' 不存在或未报名该活动'
                : '缺少参数：registrationIds / phones / userIds 至少提供一项';
            return $this->fail(4220, $msg);
        }

        $forceWin = !empty($this->request->param('forceWin'));

        if ((int) ($act['win_locked'] ?? 0) === 1) {
            return $this->fail(4220, '该活动已开奖，名额已锁定，不可再设置必中');
        }
        $quota = (int) ($act['draw_win_count'] ?: $act['winner_count']);

        Db::startTrans();
        try {
            $regs = Db::name('raffle_registrations')
                ->where('activity_id', $activityId)
                ->whereIn('id', $ids)
                ->lock(true)
                ->select()->toArray();

            $targetIds = array_column($regs, 'id');
            if (count($targetIds) !== count($ids)) {
                throw new \RuntimeException('部分报名记录不存在或不属于该活动');
            }

            if ($forceWin) {
                // 必中总数不能超过本次抽签名额上限
                $currentForce = Db::name('raffle_registrations')
                    ->where('activity_id', $activityId)
                    ->where('is_force_win', 1)
                    ->count();
                $newly = count(array_filter($regs, fn ($r) => (int) $r['is_force_win'] !== 1));
                if ($currentForce + $newly > $quota) {
                    throw new \RuntimeException("强制中签总人数（{$currentForce} + 新增 {$newly}）不能超过本次抽签名额上限（{$quota}）");
                }
                Db::name('raffle_registrations')->whereIn('id', $targetIds)->update([
                    'is_force_win' => 1,
                    'force_set_by' => $this->adminId(),
                    'force_set_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                Db::name('raffle_registrations')->whereIn('id', $targetIds)->update([
                    'is_force_win' => 0,
                    'force_set_by' => null,
                    'force_set_at' => null,
                ]);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(4220, $e->getMessage());
        }

        // 通用审计 + 独立操作日志
        $action = $forceWin ? '设为必中' : '取消必中';
        $byPhone = !empty($phones) ? '（含按手机号 ' . count($phones) . ' 个）' : '';
        $this->audit('抽签发售', $forceWin ? 'force_win' : 'cancel_force_win', $action . '（' . count($ids) . " 条）{$byPhone}", ['activityId' => $activityId, 'registrationIds' => $ids, 'phones' => $phones]);
        RaffleOpLogService::record(
            $this->request,
            $forceWin ? RaffleOpLogService::ACTION_SET_FORCE_WIN : RaffleOpLogService::ACTION_CANCEL_FORCE_WIN,
            "{$action}（活动#{$activityId}，" . count($ids) . " 条报名记录{$byPhone}）",
            $activityId,
            ['registrationIds' => $ids, 'userIds' => array_column($regs, 'user_id'), 'phones' => $phones, 'notFoundPhones' => $notFoundPhones]
        );

        $forceCount = Db::name('raffle_registrations')->where('activity_id', $activityId)->where('is_force_win', 1)->count();
        return $this->success([
            'updated'        => count($ids),
            'forceWinCount'  => $forceCount,
            'quota'          => $quota,
            'notFoundPhones' => $notFoundPhones,
        ]);
    }

    // ==================================================================
    // 三、抽签码管理
    // ==================================================================

    /**
     * GET /admin/raffle/codes?activityId=&status=&userId=&keyword=
     * 抽签码列表（码串、绑定用户、状态）
     */
    public function codes()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('user_draw_codes')->alias('dc')
            ->join('users u', 'u.id = dc.user_id', 'LEFT')
            ->join('raffle_activities ra', 'ra.id = dc.activity_id', 'LEFT')
            ->field('dc.*, u.username, u.phone, ra.name AS activity_name');

        $activityId = $this->positiveInt('activityId');
        if ($activityId !== null) $query->where('dc.activity_id', $activityId);
        $status = $this->enumParam('status', ['1','2','3','4']);
        if ($status !== null) $query->where('dc.status', (int) $status);
        $userId = $this->positiveInt('userId');
        if ($userId !== null) $query->where('dc.user_id', $userId);
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('dc.code', "%{$keyword}%")
                  ->whereOrLike('u.username', "%{$keyword}%")
                  ->whereOrLike('u.phone', "%{$keyword}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->order('dc.id', 'desc')->page($page, $pageSize)->select()->toArray();

        $stats = ['total' => $total, 'unused' => 0, 'registered' => 0, 'invalid' => 0, 'won' => 0];
        $statQuery = Db::name('user_draw_codes');
        if ($activityId !== null) $statQuery->where('activity_id', $activityId);
        $stats['unused']      = (clone $statQuery)->where('status', 1)->count();
        $stats['registered']  = (clone $statQuery)->where('status', 2)->count();
        $stats['invalid']     = (clone $statQuery)->where('status', 3)->count();
        $stats['won']         = (clone $statQuery)->where('status', 4)->count();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize, ['stats' => $stats]);
    }

    /**
     * POST /admin/raffle/codes  手动新增单个/多个抽签码
     * Body: { activityId, userId | phone, count = 1, code?, remark? }
     * 校验：用户存在（管理员发放不受用户邀请/购买档位上限限制）
     */
    public function codeCreate()
    {
        $activityId = $this->positiveInt('activityId');
        if ($activityId === null) return $this->failMissing(['activityId']);
        $count = min(100, max(1, (int) $this->request->param('count', 1)));
        $customCode = trim((string) $this->request->param('code', ''));
        $remark = trim((string) $this->request->param('remark', ''));
        if ($customCode !== '' && $count > 1) {
            return $this->fail(4220, '自定义码串时一次只能新增 1 个');
        }

        $act = Db::name('raffle_activities')->where('id', $activityId)->whereNull('deleted_at')->find();
        if (!$act) return $this->fail(4040, '活动不存在');

        $user = $this->resolveUser();
        if ($user instanceof \think\Response) return $user; // 参数错误响应
        if (!$user) return $this->fail(4040, '用户不存在');

        Db::startTrans();
        try {
            $codes = [];
            for ($i = 0; $i < $count; $i++) {
                $codes[] = DrawCodeService::grant(
                    (int) $user['id'],
                    DrawCodeService::SOURCE_ADMIN,
                    $activityId,
                    $customCode !== '' ? $customCode : null,
                    $remark !== '' ? $remark : '后台手动新增'
                );
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(4220, $e->getMessage());
        }

        $this->audit('抽签发售', 'code_create', '新增抽签码（' . count($codes) . ' 个）', ['activityId' => $activityId, 'userId' => $user['id']]);
        RaffleOpLogService::record(
            $this->request,
            RaffleOpLogService::ACTION_CODE_CREATE,
            "手动新增抽签码（活动#{$activityId}，用户 {$user['username']}，" . count($codes) . ' 个）',
            $activityId,
            ['userId' => (int) $user['id'], 'codes' => $codes]
        );

        return $this->success(['codes' => $codes, 'count' => count($codes)]);
    }

    /**
     * POST /admin/raffle/codes/import  Excel 批量导入抽签码
     * multipart: file = .xlsx；列：用户ID或手机号 | 数量(可选) | 抽签码(可选) | 备注(可选)
     * 管理员发放不受用户邀请/购买档位上限限制
     */
    public function codeImport()
    {
        $activityId = $this->positiveInt('activityId');
        if ($activityId === null) return $this->failMissing(['activityId']);

        $act = Db::name('raffle_activities')->where('id', $activityId)->whereNull('deleted_at')->find();
        if (!$act) return $this->fail(4040, '活动不存在');

        $file = $this->request->file('file');
        if (!$file) return $this->failMissing(['file']);

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
        } catch (\Throwable $e) {
            return $this->fail(4220, 'Excel 解析失败：' . $e->getMessage());
        }

        // 去掉表头行
        array_shift($rows);

        $success = 0;
        $skipped = [];

        Db::startTrans();
        try {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // Excel 实际行号（含表头）
                $identifier = trim((string) ($row['A'] ?? ''));
                $count = max(1, (int) ($row['B'] ?? 1));
                $customCode = trim((string) ($row['C'] ?? ''));
                $remark = trim((string) ($row['D'] ?? ''));

                if ($identifier === '') continue; // 空行跳过

                $user = $this->findUserByIdentifier($identifier);
                if (!$user) {
                    $skipped[] = "第 {$line} 行：用户 {$identifier} 不存在";
                    continue;
                }
                if ($customCode !== '' && Db::name('user_draw_codes')->where('code', $customCode)->find()) {
                    $skipped[] = "第 {$line} 行：抽签码 {$customCode} 已存在";
                    continue;
                }

                for ($j = 0; $j < $count; $j++) {
                    DrawCodeService::grant(
                        (int) $user['id'],
                        DrawCodeService::SOURCE_ADMIN,
                        $activityId,
                        ($customCode !== '' && $j === 0) ? $customCode : null,
                        $remark !== '' ? $remark : '后台批量导入'
                    );
                    $success++;
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '导入失败：' . $e->getMessage());
        }

        $this->audit('抽签发售', 'code_import', '批量导入抽签码（成功 ' . $success . '）', ['activityId' => $activityId]);
        RaffleOpLogService::record(
            $this->request,
            RaffleOpLogService::ACTION_CODE_IMPORT,
            "Excel 批量导入抽签码（活动#{$activityId}，成功 {$success} 个，跳过 " . count($skipped) . ' 行）',
            $activityId,
            ['success' => $success, 'skipped' => $skipped]
        );

        return $this->success(['success' => $success, 'skippedCount' => count($skipped), 'skipped' => array_slice($skipped, 0, 50)]);
    }

    /**
     * POST /admin/raffle/codes/:id/invalidate  作废抽签码
     */
    public function codeInvalidate()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        $reason = trim((string) $this->request->param('reason', ''));

        $code = Db::name('user_draw_codes')->where('id', $id)->find();
        if (!$code) return $this->fail(4040, '抽签码不存在');
        if ((int) $code['status'] === DrawCodeService::STATUS_INVALID) return $this->fail(4220, '该抽签码已失效');

        if (!DrawCodeService::invalidate($id, $reason)) {
            return $this->fail(5000, '作废失败');
        }

        $this->audit('抽签发售', 'code_invalidate', '作废抽签码 ' . $code['code'], ['id' => $id]);
        RaffleOpLogService::record(
            $this->request,
            RaffleOpLogService::ACTION_CODE_INVALIDATE,
            "作废抽签码 {$code['code']}（活动#{$code['activity_id']}）",
            (int) $code['activity_id'],
            ['codeId' => $id, 'code' => $code['code'], 'reason' => $reason]
        );
        return $this->success(['id' => $id]);
    }

    /**
     * DELETE /admin/raffle/codes/:id  删除抽签码（物理删除）
     */
    public function codeDelete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);

        $code = Db::name('user_draw_codes')->where('id', $id)->find();
        if (!$code) return $this->fail(4040, '抽签码不存在');

        Db::name('user_draw_codes')->where('id', $id)->delete();

        $this->audit('抽签发售', 'code_delete', '删除抽签码 ' . $code['code'], ['id' => $id]);
        RaffleOpLogService::record(
            $this->request,
            RaffleOpLogService::ACTION_CODE_DELETE,
            "删除抽签码 {$code['code']}（活动#{$code['activity_id']}）",
            (int) $code['activity_id'],
            ['codeId' => $id, 'code' => $code['code']]
        );
        return $this->success(['id' => $id]);
    }

    // ==================================================================
    // 四、中签记录（付款 / 核销 / 导出）
    // ==================================================================

    /**
     * GET /admin/raffle/winners?activityId=&result=win|lose&keyword=
     * 中签/未中签列表
     */
    public function winners()
    {
        [$page, $pageSize] = $this->pageParams();
        $activityId = $this->positiveInt('activityId');

        $query = Db::name('raffle_registrations')->alias('reg')
            ->join('users u', 'u.id = reg.user_id', 'LEFT')
            ->join('raffle_activities ra', 'ra.id = reg.activity_id', 'LEFT')
            ->field('reg.*, u.username, u.phone, ra.name AS activity_name, ra.sale_price, ra.sale_quantity, ra.drawn_at');

        if ($activityId !== null) $query->where('reg.activity_id', $activityId);
        $result = $this->enumParam('result', ['win','lose']);
        if ($result === 'win') $query->where('reg.draw_status', 1);
        if ($result === 'lose') $query->where('reg.draw_status', 2);
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('u.username', "%{$keyword}%")->whereOrLike('u.phone', "%{$keyword}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->order('reg.draw_status', 'asc')->order('reg.id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * POST /admin/raffle/winners/:id/mark-paid  标记用户付款
     */
    public function winnerMarkPaid()
    {
        return $this->winnerFlag('win_paid', 'win_paid_at', '标记已付款', '标记付款');
    }

    /**
     * POST /admin/raffle/winners/:id/verify  核销
     */
    public function winnerVerify()
    {
        return $this->winnerFlag('win_verified', 'win_verified_at', '核销', '核销');
    }

    /**
     * GET /admin/raffle/winners/export?activityId=&result=  导出中签记录 Excel
     */
    public function winnersExport()
    {
        $activityId = $this->positiveInt('activityId');
        if ($activityId === null) return $this->failMissing(['activityId']);
        $result = $this->enumParam('result', ['win','lose']);

        $query = Db::name('raffle_registrations')->alias('reg')
            ->join('users u', 'u.id = reg.user_id', 'LEFT')
            ->join('raffle_activities ra', 'ra.id = reg.activity_id', 'LEFT')
            ->where('reg.activity_id', $activityId)
            ->field('reg.*, u.username, u.phone, ra.name AS activity_name, ra.sale_price, ra.sale_quantity');
        if ($result === 'win') $query->where('reg.draw_status', 1);
        if ($result === 'lose') $query->where('reg.draw_status', 2);
        $rows = $query->order('reg.draw_status', 'asc')->order('reg.id', 'asc')->select()->toArray();

        $data = array_map(function ($r) {
            return [
                $r['id'],
                $r['username'] ?? '',
                $r['phone'] ?? '',
                (int) $r['ticket_count'],
                (int) $r['draw_status'] === 1 ? '中签' : '未中签',
                (int) $r['draw_status'] === 1 ? max(1, (int) ($r['win_count'] ?? 0)) : 0,
                (int) $r['is_force_win'] === 1 ? '是' : '否',
                (int) $r['win_paid'] === 1 ? '已付款' : '未付款',
                (int) $r['win_verified'] === 1 ? '已核销' : '未核销',
                (int) $r['purchased_quantity'],
                (string) $r['sale_price'],
                $r['created_at'],
            ];
        }, $rows);

        return $this->excelExport("中签记录_活动{$activityId}", [
            [
                'sheet'   => '中签记录',
                'headers' => ['记录ID', '用户名', '手机号', '报名票数', '抽签结果', '中签次数', '强制必中', '付款状态', '核销状态', '已购数量', '中签价', '报名时间'],
                'rows'    => $data,
            ],
        ]);
    }

    // ==================================================================
    // 六、抽签独立操作日志（只读，不可删除）
    // ==================================================================

    /**
     * GET /admin/raffle/logs?activityId=&action=&keyword=
     */
    public function logs()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('raffle_operation_logs');
        $activityId = $this->positiveInt('activityId');
        if ($activityId !== null) $query->where('activity_id', $activityId);
        $action = trim((string) $this->request->param('action', ''));
        if ($action !== '') $query->whereLike('action', "%{$action}%");
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('admin_name', "%{$keyword}%")->whereOrLike('action_desc', "%{$keyword}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    // ==================================================================
    // 内部辅助
    // ==================================================================

    /** 中签记录付款/核销通用标记 */
    private function winnerFlag(string $field, string $timeField, string $desc, string $doneDesc)
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);

        $reg = Db::name('raffle_registrations')->where('id', $id)->find();
        if (!$reg) return $this->fail(4040, '报名记录不存在');
        if ((int) $reg['draw_status'] !== 1) return $this->fail(4220, '仅中签用户可' . $desc);
        if ((int) $reg[$field] === 1) return $this->fail(4220, '该记录已' . $doneDesc);

        Db::name('raffle_registrations')->where('id', $id)->update([
            $field      => 1,
            $timeField  => date('Y-m-d H:i:s'),
        ]);

        $this->audit('抽签发售', $field, $desc . '（报名记录#' . $id . '）', ['id' => $id]);
        RaffleOpLogService::record(
            $this->request,
            $field === 'win_paid' ? RaffleOpLogService::ACTION_MARK_PAID : RaffleOpLogService::ACTION_VERIFY,
            "{$desc}（活动#{$reg['activity_id']}，用户ID {$reg['user_id']}）",
            (int) $reg['activity_id'],
            ['registrationId' => $id, 'userId' => (int) $reg['user_id']]
        );
        return $this->success(['id' => $id]);
    }

    /**
     * 解析用户参数：userId 或 phone（二选一）
     * 成功返回用户行数组；失败返回 null（不存在）或 Response（参数错误）
     */
    private function resolveUser(): array|null|\think\Response
    {
        $userId = $this->positiveInt('userId');
        $phone = trim((string) $this->request->param('phone', ''));

        if ($userId === null && $phone === '') {
            return $this->failMissing(['userId 或 phone']);
        }
        if ($phone !== '' && !preg_match('/^1\d{10}$/', $phone)) {
            return $this->fail(4220, '手机号格式不正确');
        }

        return $this->findUserByIdentifier($phone !== '' ? $phone : (string) $userId);
    }

    /** 按用户 ID 或手机号查用户 */
    private function findUserByIdentifier(string $identifier): ?array
    {
        $user = null;
        if (ctype_digit($identifier)) {
            $user = Db::name('users')->where('id', (int) $identifier)->whereNull('deleted_at')->find();
        }
        if (!$user && preg_match('/^1\d{10}$/', $identifier)) {
            $user = Db::name('users')->where('phone', $identifier)->whereNull('deleted_at')->find();
        }
        return $user;
    }

    /** 保存活动时：名额/码上限/总发行量变更写入独立操作日志 */
    private function logQuotaChange(array $before, array $input, int $activityId): void
    {
        $oldQuota  = (int) ($before['draw_win_count'] ?: $before['winner_count']);
        $newQuota  = (int) ($input['drawWinCount'] ?? $before['winner_count']);
        if ($newQuota !== $oldQuota) {
            RaffleOpLogService::record(
                $this->request,
                RaffleOpLogService::ACTION_CHANGE_QUOTA,
                "修改本次抽签名额（活动#{$activityId}）：{$oldQuota} → {$newQuota}",
                $activityId,
                ['from' => $oldQuota, 'to' => $newQuota]
            );
        }

        $oldBuyLimit = (int) ($before['buy_code_limit'] ?? 0);
        $newBuyLimit = (int) ($input['buyCodeLimit'] ?? 0);
        if ($newBuyLimit !== $oldBuyLimit) {
            RaffleOpLogService::record(
                $this->request,
                RaffleOpLogService::ACTION_CHANGE_BUY_LIMIT,
                "修改购买抽签码上限（活动#{$activityId}）：{$oldBuyLimit} → {$newBuyLimit}",
                $activityId,
                ['from' => $oldBuyLimit, 'to' => $newBuyLimit]
            );
        }

        $oldInviteEn = (int) ($before['invite_enabled'] ?? 0);
        $newInviteEn = !empty($input['inviteEnabled']) ? 1 : 0;
        $oldInviteLim = (int) ($before['invite_code_limit'] ?? 0);
        $newInviteLim = (int) ($input['inviteCodeLimit'] ?? 0);
        $oldInviteNed = (int) ($before['invite_user_needed'] ?? 1);
        $newInviteNed = (int) ($input['inviteUserNeeded'] ?? 1);
        if ($newInviteEn !== $oldInviteEn || $newInviteLim !== $oldInviteLim || $newInviteNed !== $oldInviteNed) {
            RaffleOpLogService::record(
                $this->request,
                RaffleOpLogService::ACTION_CHANGE_QUOTA,
                "修改邀请好友得码配置（活动#{$activityId}）：开关 {$oldInviteEn}→{$newInviteEn}，上限 {$oldInviteLim}→{$newInviteLim}，门槛 {$oldInviteNed}→{$newInviteNed}",
                $activityId,
                ['from' => ['enabled' => $oldInviteEn, 'limit' => $oldInviteLim, 'needed' => $oldInviteNed], 'to' => ['enabled' => $newInviteEn, 'limit' => $newInviteLim, 'needed' => $newInviteNed]]
            );
        }

        $oldSupply = (int) ($before['total_supply'] ?? 0);
        $newSupply = (int) ($input['totalSupply'] ?? 0);
        if ($newSupply !== $oldSupply) {
            RaffleOpLogService::record(
                $this->request,
                RaffleOpLogService::ACTION_CHANGE_TOTAL_SUPPLY,
                "修改藏品总发行量（活动#{$activityId}）：{$oldSupply} → {$newSupply}",
                $activityId,
                ['from' => $oldSupply, 'to' => $newSupply]
            );
        }
    }
}
