<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 风控安全控制器
 *
 * - 黑名单管理（security:blacklist）：nft_blacklist 加入/解除（用户级/IP级/设备级）
 * - 风控告警（security:alert）：nft_risk_alerts 列表/处理/忽略
 * - 安全事件（security:event）：nft_security_events 列表/确认/处理/误报
 *
 * 严谨性设计：
 * - 加入黑名单同步更新用户 is_blacklisted（C 端中间件实时拦截）
 * - 解除黑名单双向同步（记录表 + 用户表）
 * - 用户级黑名单唯一（uk_user_type），重复加入幂等提示
 * - 告警处理写处理人与时间，流转状态不可逆（已处理不可回到未处理）
 */
class SecurityController extends BaseController
{
    /** 黑名单类型 */
    private const BLACKLIST_TYPES = [1 => '用户级', 2 => 'IP级', 3 => '设备级'];

    /** 告警类型 */
    private const ALERT_TYPES = [
        1 => '大额充值', 2 => '频繁小额充值', 3 => '余额突变', 4 => '高频API',
        5 => '异常时间操作', 6 => '异地登录', 7 => '批量注册', 8 => '异常价格', 9 => '其他',
    ];

    /** 安全事件类型 */
    private const EVENT_TYPES = [1 => '越权尝试', 2 => '支付回调异常', 3 => 'Token异常', 4 => '暴力破解', 5 => '其他'];

    // ============================================================
    // 一、黑名单管理（security:blacklist）
    // ============================================================

    /**
     * GET /admin/security/blacklist
     */
    public function blacklist()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('blacklist')->alias('b')
            ->field('b.*, u.uid, u.phone, u.username')
            ->leftJoin('users u', 'u.id = b.user_id');

        $status = $this->request->param('status');
        if ($status !== null && $status !== '') {
            $query->where('b.status', (int) $status === 1 ? 1 : 0);
        }
        $type = $this->request->param('blacklist_type');
        if ($type !== null && $type !== '') {
            $query->where('b.blacklist_type', (int) $type);
        }
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('b.target_value', '%' . $keyword . '%')
                    ->whereOr('u.uid', 'like', '%' . $keyword . '%')
                    ->whereOr('b.reason', 'like', '%' . $keyword . '%');
            });
        }
        if ($range = $this->dateRange()) {
            $query->whereBetween('b.created_at', $range);
        }

        $total = (clone $query)->count();
        $items = $query->order('b.id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($items as &$item) {
            $item['blacklist_type_name'] = self::BLACKLIST_TYPES[(int) $item['blacklist_type']] ?? '未知';
            $item['phone'] = $item['phone'] ? mask_phone((string) $item['phone']) : null;
        }

        // 概览统计
        $summary = [
            'active'  => Db::name('blacklist')->where('status', 1)->count(),
            'lifted'  => Db::name('blacklist')->where('status', 0)->count(),
            'user'    => Db::name('blacklist')->where('status', 1)->where('blacklist_type', 1)->count(),
            'ip'      => Db::name('blacklist')->where('status', 1)->where('blacklist_type', 2)->count(),
            'device'  => Db::name('blacklist')->where('status', 1)->where('blacklist_type', 3)->count(),
        ];

        return $this->success([
            'list'     => $items,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'lastPage' => (int) ceil($total / max($pageSize, 1)),
            'summary'  => $summary,
            'typeMap'  => self::BLACKLIST_TYPES,
        ]);
    }

    /**
     * POST /admin/security/blacklist
     * { user_id, blacklist_type, reason, evidence?, expires_at? }
     */
    public function blacklistAdd()
    {
        $missing = $this->missingParams(['user_id', 'blacklist_type', 'reason']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $userId = (int) $this->request->param('user_id');
        $type   = (int) $this->request->param('blacklist_type');
        $reason = trim((string) $this->request->param('reason'));

        if (!isset(self::BLACKLIST_TYPES[$type])) {
            return $this->fail(4220, 'blacklist_type 仅允许 1用户级/2IP级/3设备级');
        }
        if (mb_strlen($reason) < 2 || mb_strlen($reason) > 255) {
            return $this->fail(4220, '拉黑原因需为 2~255 字');
        }

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) {
            return $this->fail(4040, '用户不存在');
        }

        // 目标值：按类型取用户UID/请求IP/设备号（默认用户UID）
        $targetValue = trim((string) $this->request->param('target_value', ''));
        if ($targetValue === '') {
            $targetValue = $type === 2 ? (string) $this->request->ip() : (string) $user['uid'];
        }

        // 幂等：同用户同类型已存在生效记录则提示
        $exists = Db::name('blacklist')->where('user_id', $userId)->where('blacklist_type', $type)->find();
        if ($exists) {
            if ((int) $exists['status'] === 1) {
                return $this->fail(4220, '该用户已存在同类型生效黑名单记录（ID ' . $exists['id'] . '）');
            }
            // 已解除的历史记录：复用更新
            Db::name('blacklist')->where('id', $exists['id'])->update([
                'reason'        => mb_substr($reason, 0, 255),
                'evidence'      => $this->request->param('evidence') !== null ? mb_substr((string) $this->request->param('evidence'), 0, 65535) : null,
                'status'        => 1,
                'lifted_at'     => null,
                'lifted_by'     => null,
                'lifted_reason' => null,
                'expires_at'    => $this->optExpires(),
                'admin_id'      => $this->adminId(),
                'admin_name'    => $this->adminName(),
                'target_value'  => mb_substr($targetValue, 0, 255),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            $recordId = (int) $exists['id'];
        } else {
            $recordId = (int) Db::name('blacklist')->insertGetId([
                'user_id'       => $userId,
                'blacklist_type' => $type,
                'target_value'  => mb_substr($targetValue, 0, 255),
                'reason'        => mb_substr($reason, 0, 255),
                'evidence'      => $this->request->param('evidence') !== null ? mb_substr((string) $this->request->param('evidence'), 0, 65535) : null,
                'admin_id'      => $this->adminId(),
                'admin_name'    => $this->adminName(),
                'status'        => 1,
                'expires_at'    => $this->optExpires(),
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }

        // 用户级黑名单同步用户表标记（C 端实时拦截）
        if ($type === 1) {
            Db::name('users')->where('id', $userId)->update([
                'is_blacklisted' => 1,
            ]);
        }

        $this->audit('security', 'blacklist_add',
            '将用户 ' . $user['uid'] . ' 加入' . self::BLACKLIST_TYPES[$type] . '黑名单（原因：' . $reason . '）',
            ['user_id' => $userId, 'type' => $type], 'blacklist', $recordId);
        return $this->success(['id' => $recordId], '已加入黑名单');
    }

    /**
     * POST /admin/security/blacklist/:id/lift { lift_reason }
     */
    public function blacklistLift()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $reason = trim((string) $this->request->param('lift_reason', ''));
        if (mb_strlen($reason) < 2) {
            return $this->fail(4220, '解除原因不能少于 2 字');
        }

        $record = Db::name('blacklist')->where('id', $id)->find();
        if (!$record) {
            return $this->fail(4040, '黑名单记录不存在');
        }
        if ((int) $record['status'] !== 1) {
            return $this->fail(4220, '该记录已解除，无需重复操作');
        }

        Db::name('blacklist')->where('id', $id)->update([
            'status'        => 0,
            'lifted_at'     => date('Y-m-d H:i:s'),
            'lifted_by'     => $this->adminId(),
            'lifted_reason' => mb_substr($reason, 0, 255),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        // 用户级：若该用户已无其他生效黑名单，同步解除用户表标记
        if ((int) $record['blacklist_type'] === 1) {
            $remain = Db::name('blacklist')
                ->where('user_id', $record['user_id'])
                ->where('blacklist_type', 1)
                ->where('status', 1)
                ->count();
            if ($remain === 0) {
                Db::name('users')->where('id', $record['user_id'])->update([
                    'is_blacklisted' => 0,
                ]);
            }
        }

        $uid = Db::name('users')->where('id', $record['user_id'])->value('uid');
        $this->audit('security', 'blacklist_lift',
            '解除用户 ' . ($uid ?? $record['user_id']) . ' 的' . self::BLACKLIST_TYPES[(int) $record['blacklist_type']] . '黑名单（原因：' . $reason . '）',
            ['user_id' => $record['user_id']], 'blacklist', $id);
        return $this->success(null, '黑名单已解除');
    }

    // ============================================================
    // 二、风控告警（security:alert）
    // ============================================================

    /**
     * GET /admin/security/risk-alerts
     */
    public function riskAlerts()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('risk_alerts')->alias('ra')
            ->field('ra.*, u.uid, u.phone')
            ->leftJoin('users u', 'u.id = ra.user_id');

        if ($this->request->param('status') !== null && $this->request->param('status') !== '') {
            $query->where('ra.status', (int) $this->request->param('status'));
        }
        if ($this->request->param('alert_type') !== null && $this->request->param('alert_type') !== '') {
            $query->where('ra.alert_type', (int) $this->request->param('alert_type'));
        }
        if ($this->request->param('alert_level') !== null && $this->request->param('alert_level') !== '') {
            $query->where('ra.alert_level', (int) $this->request->param('alert_level'));
        }
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('ra.title', '%' . $keyword . '%');
        }
        if ($range = $this->dateRange()) {
            $query->whereBetween('ra.created_at', $range);
        }

        $total = (clone $query)->count();
        $items = $query->order('ra.alert_level', 'desc')->order('ra.id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        foreach ($items as &$item) {
            $item['alert_type_name'] = self::ALERT_TYPES[(int) $item['alert_type']] ?? '未知';
            $item['phone'] = $item['phone'] ? mask_phone((string) $item['phone']) : null;
            if ($item['evidence'] !== null) {
                $item['evidence'] = json_decode((string) $item['evidence'], true);
            }
        }

        // 概览
        $summary = [
            'pending'  => Db::name('risk_alerts')->where('status', 1)->count(),
            'handling' => Db::name('risk_alerts')->where('status', 2)->count(),
            'handled'  => Db::name('risk_alerts')->where('status', 3)->count(),
            'ignored'  => Db::name('risk_alerts')->where('status', 4)->count(),
            'highLevel' => Db::name('risk_alerts')->whereIn('status', [1, 2])->whereIn('alert_level', [3, 4])->count(),
        ];

        return $this->success([
            'list'     => $items,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'lastPage' => (int) ceil($total / max($pageSize, 1)),
            'summary'  => $summary,
            'typeMap'  => self::ALERT_TYPES,
        ]);
    }

    /**
     * POST /admin/security/risk-alerts/:id/handle { status: 2处理中/3已处理, handle_comment }
     */
    public function riskAlertHandle()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $newStatus = (int) $this->request->param('status', 0);
        if (!in_array($newStatus, [2, 3, 4], true)) {
            return $this->fail(4220, 'status 仅允许 2处理中/3已处理/4已忽略');
        }
        $comment = trim((string) $this->request->param('handle_comment', ''));
        if ($comment === '') {
            return $this->fail(4220, '请填写处理意见');
        }

        $alert = Db::name('risk_alerts')->where('id', $id)->find();
        if (!$alert) {
            return $this->fail(4040, '告警不存在');
        }
        if ((int) $alert['status'] === 3 || (int) $alert['status'] === 4) {
            return $this->fail(4220, '该告警已终态（已处理/已忽略），不可再次流转');
        }

        Db::name('risk_alerts')->where('id', $id)->update([
            'status'         => $newStatus,
            'handler_id'     => $this->adminId(),
            'handler_name'   => $this->adminName(),
            'handled_at'     => date('Y-m-d H:i:s'),
            'handle_comment' => mb_substr($comment, 0, 255),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->audit('security', 'risk_alert_handle',
            '处理风控告警「' . $alert['title'] . '」→ ' . [2 => '处理中', 3 => '已处理', 4 => '已忽略'][$newStatus],
            ['comment' => $comment], 'risk_alert', $id);
        return $this->success(null, '告警状态已更新');
    }

    // ============================================================
    // 三、安全事件（security:event）
    // ============================================================

    /**
     * GET /admin/security/events
     */
    public function securityEvents()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('security_events');

        if ($this->request->param('status') !== null && $this->request->param('status') !== '') {
            $query->where('status', (int) $this->request->param('status'));
        }
        if ($this->request->param('event_type') !== null && $this->request->param('event_type') !== '') {
            $query->where('event_type', (int) $this->request->param('event_type'));
        }
        if ($this->request->param('event_level') !== null && $this->request->param('event_level') !== '') {
            $query->where('event_level', (int) $this->request->param('event_level'));
        }
        if ($this->request->param('ip') !== null && $this->request->param('ip') !== '') {
            $query->where('ip', 'like', '%' . trim((string) $this->request->param('ip')) . '%');
        }
        if ($range = $this->dateRange()) {
            $query->whereBetween('created_at', $range);
        }

        $total = (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($items as &$item) {
            $item['event_type_name'] = self::EVENT_TYPES[(int) $item['event_type']] ?? '未知';
        }

        $summary = [
            'pending' => Db::name('security_events')->where('status', 1)->count(),
            'confirmed' => Db::name('security_events')->where('status', 2)->count(),
            'handled' => Db::name('security_events')->where('status', 3)->count(),
            'falsePositive' => Db::name('security_events')->where('status', 4)->count(),
        ];

        return $this->success([
            'list'     => $items,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'lastPage' => (int) ceil($total / max($pageSize, 1)),
            'summary'  => $summary,
            'typeMap'  => self::EVENT_TYPES,
        ]);
    }

    /**
     * POST /admin/security/events/:id/handle { status: 2已确认/3已处理/4误报, handle_comment }
     */
    public function securityEventHandle()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $newStatus = (int) $this->request->param('status', 0);
        if (!in_array($newStatus, [2, 3, 4], true)) {
            return $this->fail(4220, 'status 仅允许 2已确认/3已处理/4误报');
        }
        $comment = trim((string) $this->request->param('handle_comment', ''));
        if ($comment === '') {
            return $this->fail(4220, '请填写处理意见');
        }

        $event = Db::name('security_events')->where('id', $id)->find();
        if (!$event) {
            return $this->fail(4040, '安全事件不存在');
        }
        if ((int) $event['status'] === 3) {
            return $this->fail(4220, '该事件已处理终态，不可再次流转');
        }

        Db::name('security_events')->where('id', $id)->update([
            'status'         => $newStatus,
            'handle_comment' => mb_substr($comment, 0, 255),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->audit('security', 'event_handle',
            '处理安全事件「' . mb_substr((string) $event['description'], 0, 50) . '」→ ' . [2 => '已确认', 3 => '已处理', 4 => '误报'][$newStatus],
            ['comment' => $comment], 'security_event', $id);
        return $this->success(null, '安全事件状态已更新');
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 可选过期时间校验（Y-m-d H:i:s，须晚于当前）
     */
    private function optExpires(): ?string
    {
        $expiresAt = trim((string) $this->request->param('expires_at', ''));
        if ($expiresAt === '') {
            return null;
        }
        $ts = strtotime($expiresAt);
        if ($ts === false || $ts <= time()) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
