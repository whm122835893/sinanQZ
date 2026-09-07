<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\service\ActivityRewardService;
use think\facade\Db;

/**
 * 管理后台实名认证控制器
 *
 * 审核工作流：用户 C 端提交（realname_status=1 待审核）
 *   → 管理员审核通过（=2，is_realname=1，解锁购买/寄售/转赠）
 *   → 管理员驳回（=3，记录原因，用户可重新提交）
 *
 * 数据安全：
 * - 列表默认返回完整姓名/证件号（前端默认脱敏展示）
 * - 「查看完整」走 detail 接口：需 realname:full 权限 + 强制审计
 */
class RealnameController extends BaseController
{
    /** 状态值 → 文案 */
    private const STATUS_MAP = [
        0 => '未提交',
        1 => '待审核',
        2 => '已通过',
        3 => '已驳回',
    ];

    /**
     * GET /admin/realname/users
     * 审核列表：status 筛选（pending/approved/rejected），keyword（手机号/UID/姓名）
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('users')->alias('u')->whereNull('u.deleted_at');

        // 前端语义化状态映射到 realname_status
        $status = trim((string) $this->request->param('status', ''));
        $statusMap = ['pending' => 1, 'approved' => 2, 'rejected' => 3];
        if (isset($statusMap[$status])) {
            $query->where('u.realname_status', $statusMap[$status]);
        }

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('u.phone', "%{$keyword}%")
                  ->whereOr('u.uid', $keyword)
                  ->whereLike('u.username', "%{$keyword}%");
            });
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('u.realname_submitted_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('u.realname_submitted_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('u.id, u.uid, u.phone, u.username, u.avatar, u.is_realname, u.realname_status,
                               u.real_name, u.id_card, u.realname_submitted_at, u.realname_reject_reason,
                               u.last_login_at, u.created_at')
            ->order('u.realname_submitted_at', 'desc')->order('u.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        $items = array_map(function ($row) {
            $realName = $row['real_name'] ? (aes_decrypt((string) $row['real_name']) ?? '') : '';
            $idCard   = $row['id_card'] ? (aes_decrypt((string) $row['id_card']) ?? '') : '';
            $statusInt = (int) $row['realname_status'];
            return [
                'id'               => (int) $row['id'],
                'uid'              => $row['uid'],
                'phone'            => (string) $row['phone'],
                'nickname'         => $row['username'],
                'avatar'           => $row['avatar'],
                'realnameStatus'   => ['0' => 'none', '1' => 'pending', '2' => 'approved', '3' => 'rejected'][(string) $statusInt],
                'realnameName'     => $realName,
                'realnameIdNo'     => $idCard,
                'rejectReason'     => (string) ($row['realname_reject_reason'] ?? ''),
                'submitTime'       => $row['realname_submitted_at'],
                'lastLoginTime'    => $row['last_login_at'],
                'registerTime'     => $row['created_at'],
                'statusText'       => self::STATUS_MAP[$statusInt] ?? '未知',
            ];
        }, $rows);

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * GET /admin/realname/users/:userId
     * 完整实名信息（需 realname:full 权限，路由层已校验；此处二次防御 + 强制审计）
     */
    public function detail(int $userId)
    {
        $admin = $this->admin();
        if (empty($admin['is_super']) && !in_array('realname:full', $admin['permissions'] ?? [], true)) {
            return $this->fail(4003, '无完整实名信息查看权限');
        }

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) {
            return $this->fail(4040, '用户不存在');
        }

        $realName = $user['real_name'] ? (aes_decrypt((string) $user['real_name']) ?? '') : '';
        $idCard   = $user['id_card'] ? (aes_decrypt((string) $user['id_card']) ?? '') : '';

        // 敏感操作：每次查看完整实名信息写审计（需求明确要求）
        $this->audit('realname', 'view_full', '查看完整实名信息（UID ' . $user['uid'] . '）', [], 'user', $userId);

        $statusInt = (int) $user['realname_status'];
        return $this->success([
            'id'             => (int) $user['id'],
            'uid'            => $user['uid'],
            'phone'          => (string) $user['phone'],
            'nickname'       => $user['username'],
            'avatar'         => $user['avatar'],
            'realnameStatus' => ['0' => 'none', '1' => 'pending', '2' => 'approved', '3' => 'rejected'][(string) $statusInt],
            'realName'       => $realName,
            'realnameName'   => $realName,
            'idCard'         => $idCard,
            'realnameIdNo'   => $idCard,
            'rejectReason'   => (string) ($user['realname_reject_reason'] ?? ''),
            'realnameTime'   => $user['realname_submitted_at'],
            'lastLoginTime'  => $user['last_login_at'],
            'registerTime'   => $user['created_at'],
            'statusText'     => self::STATUS_MAP[$statusInt] ?? '未知',
        ]);
    }

    /**
     * POST /admin/realname/audit { user_id, action(approve/reject), reason }
     * 审核通过：realname_status=2 + is_realname=1（解锁交易能力）
     * 审核驳回：realname_status=3 + 记录原因（用户可重新提交）
     * 注：方法名 doAudit 避免与 BaseController::audit()（审计日志助手）签名冲突
     */
    public function doAudit()
    {
        $missing = $this->missingParams(['user_id', 'action']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $userId = (int) $this->request->param('user_id');
        $action = trim((string) $this->request->param('action'));
        $reason = trim((string) $this->request->param('reason', ''));

        if (!in_array($action, ['approve', 'reject'], true)) {
            return $this->fail(4220, 'action 仅允许 approve / reject');
        }
        if ($action === 'reject' && $reason === '') {
            return $this->fail(4220, '驳回必须填写原因');
        }

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) {
            return $this->fail(4040, '用户不存在');
        }
        if ((int) $user['realname_status'] !== 1) {
            return $this->fail(4220, '该用户当前不在待审核状态');
        }

        $now = date('Y-m-d H:i:s');
        if ($action === 'approve') {
            Db::name('users')->where('id', $userId)->update([
                'realname_status' => 2,
                'is_realname'     => 1,
                'realname_verified_at' => $now,
                'realname_reject_reason' => null,
                'updated_at'      => $now,
            ]);
            $this->audit('realname', 'audit_approve',
                '实名审核通过（UID ' . $user['uid'] . '）', ['user_id' => $userId], 'user', $userId);

            // 实名通过 → 注册活动（实名前N名档位）+ 邀请活动（被邀请人完成实名）结算
            // 独立事务：奖励发放失败不阻断审核（记日志，可在奖励名单中排查）
            $settled = ['register' => null, 'invite' => null];
            ActivityRewardService::settleQuietly(function () use ($userId, &$settled) {
                $settled['register'] = ActivityRewardService::settleRegisterReward($userId);
                $settled['invite']   = ActivityRewardService::settleInviteReward($userId);
            });

            return $this->success([
                'status' => 'approved',
                'reward' => $settled['register'] !== null
                    ? '已命中注册活动奖励（实名排位第 ' . $settled['register']['rank'] . ' 名）'
                    : null,
            ], '已通过实名审核');
        }

        Db::name('users')->where('id', $userId)->update([
            'realname_status' => 3,
            'is_realname'     => 0,
            'realname_reject_reason' => mb_substr($reason, 0, 255),
            'updated_at'      => $now,
        ]);
        $this->audit('realname', 'audit_reject',
            '实名审核驳回（UID ' . $user['uid'] . '）：' . $reason,
            ['user_id' => $userId, 'reason' => $reason], 'user', $userId);
        return $this->success(['status' => 'rejected'], '已驳回，用户可重新提交');
    }

    /**
     * GET /admin/realname/stats
     * 实名统计（列表页顶部卡片）
     */
    public function stats()
    {
        $base = Db::name('users')->whereNull('deleted_at');
        $total    = (clone $base)->count();
        $pending  = (clone $base)->where('realname_status', 1)->count();
        $approved = (clone $base)->where('realname_status', 2)->count();
        $rejected = (clone $base)->where('realname_status', 3)->count();
        $today    = (clone $base)->where('realname_status', 2)
            ->whereBetweenTime('realname_submitted_at', date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'))->count();

        return $this->success([
            'total'     => $total,
            'pending'   => $pending,
            'realnamed' => $approved,
            'rejected'  => $rejected,
            'rate'      => $total > 0 ? round($approved / $total * 100, 1) : 0,
            'today'     => $today,
        ]);
    }
}
