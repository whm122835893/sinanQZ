<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 客服工单控制器
 *
 * - 工单列表（ticket:list）：筛选/分页/详情（含沟通时间线）
 * - 工单处理（ticket:manage）：领取分配、回复（可见/内部备注）、状态流转、关闭
 *
 * 严谨性设计：
 * - 状态机：1待处理 → 2处理中 → 3待用户确认 → 4已解决 / 5已关闭（已关闭终态）
 * - 回复区分可见性（is_internal=1 用户不可见，仅内部协查）
 * - 分配给谁谁可回复（超管/风控不受限）
 */
class TicketController extends BaseController
{
    /** 工单类型 */
    private const TICKET_TYPES = [
        1 => '支付异常', 2 => '藏品丢失', 3 => '盲盒问题', 4 => '转赠纠纷', 5 => '账号问题', 6 => '其他',
    ];

    /** 优先级 */
    private const PRIORITIES = [1 => '紧急', 2 => '高', 3 => '中', 4 => '低'];

    /** 工单状态 */
    private const STATUSES = [1 => '待处理', 2 => '处理中', 3 => '待用户确认', 4 => '已解决', 5 => '已关闭'];

    /**
     * GET /admin/tickets
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('support_tickets')->alias('t')
            ->field('t.*, u.uid, u.phone')
            ->leftJoin('users u', 'u.id = t.user_id');

        if ($this->request->param('status') !== null && $this->request->param('status') !== '') {
            $query->where('t.status', (int) $this->request->param('status'));
        }
        if ($this->request->param('ticket_type') !== null && $this->request->param('ticket_type') !== '') {
            $query->where('t.ticket_type', (int) $this->request->param('ticket_type'));
        }
        if ($this->request->param('priority') !== null && $this->request->param('priority') !== '') {
            $query->where('t.priority', (int) $this->request->param('priority'));
        }
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('t.ticket_no', '%' . $keyword . '%')
                    ->whereOr('t.title', 'like', '%' . $keyword . '%')
                    ->whereOr('u.uid', 'like', '%' . $keyword . '%');
            });
        }
        if ($range = $this->dateRange()) {
            $query->whereBetween('t.created_at', $range);
        }

        $total = (clone $query)->count();
        $items = $query->order('t.priority', 'asc')->order('t.id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        foreach ($items as &$item) {
            $item['ticket_type_name'] = self::TICKET_TYPES[(int) $item['ticket_type']] ?? '未知';
            $item['priority_name']    = self::PRIORITIES[(int) $item['priority']] ?? '未知';
            $item['status_name']      = self::STATUSES[(int) $item['status']] ?? '未知';
            $item['phone'] = $item['phone'] ? mask_phone((string) $item['phone']) : null;
            // 最新一条回复预览
            $lastReply = Db::name('ticket_replies')->where('ticket_id', $item['id'])->order('id', 'desc')->find();
            $item['last_reply_at']   = $lastReply['created_at'] ?? null;
            $item['last_reply_by']    = $lastReply['sender_name'] ?? null;
            $item['reply_count']      = Db::name('ticket_replies')->where('ticket_id', $item['id'])->count();
        }

        $summary = [
            'pending'   => Db::name('support_tickets')->where('status', 1)->count(),
            'handling'  => Db::name('support_tickets')->where('status', 2)->count(),
            'confirming'=> Db::name('support_tickets')->where('status', 3)->count(),
            'solved'    => Db::name('support_tickets')->where('status', 4)->count(),
            'closed'    => Db::name('support_tickets')->where('status', 5)->count(),
            'urgentOpen'=> Db::name('support_tickets')->whereIn('status', [1, 2, 3])->where('priority', 1)->count(),
        ];

        return $this->success([
            'list'     => $items,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'lastPage' => (int) ceil($total / max($pageSize, 1)),
            'summary'  => $summary,
            'typeMap'  => self::TICKET_TYPES,
        ]);
    }

    /**
     * GET /admin/tickets/:id（详情 + 沟通时间线）
     */
    public function detail()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $ticket = Db::name('support_tickets')->alias('t')
            ->field('t.*, u.uid, u.phone, u.username')
            ->leftJoin('users u', 'u.id = t.user_id')
            ->where('t.id', $id)
            ->find();
        if (!$ticket) {
            return $this->fail(4040, '工单不存在');
        }

        $ticket['ticket_type_name'] = self::TICKET_TYPES[(int) $ticket['ticket_type']] ?? '未知';
        $ticket['priority_name']    = self::PRIORITIES[(int) $ticket['priority']] ?? '未知';
        $ticket['status_name']      = self::STATUSES[(int) $ticket['status']] ?? '未知';
        $ticket['phone'] = $ticket['phone'] ? mask_phone((string) $ticket['phone']) : null;

        // 关联订单/藏品信息（泛关联展示）
        if ($ticket['related_order_id']) {
            $ticket['related_order_no'] = Db::name('orders')->where('id', $ticket['related_order_id'])->value('order_no');
        }
        if ($ticket['related_collectible_id']) {
            $ticket['related_collectible_name'] = Db::name('collectibles')->where('id', $ticket['related_collectible_id'])->value('name');
        }

        // 沟通时间线（含内部备注，管理端可见全部）
        $replies = Db::name('ticket_replies')->where('ticket_id', $id)->order('id', 'asc')->select()->toArray();

        return $this->success([
            'ticket'  => $ticket,
            'replies' => $replies,
            'typeMap' => self::TICKET_TYPES,
        ]);
    }

    /**
     * POST /admin/tickets/:id/assign { assignee_id? }（默认分配给自己）
     */
    public function assign()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $ticket = Db::name('support_tickets')->where('id', $id)->find();
        if (!$ticket) {
            return $this->fail(4040, '工单不存在');
        }
        if ((int) $ticket['status'] === 5) {
            return $this->fail(4220, '工单已关闭，不可分配');
        }

        $assigneeId = (int) ($this->request->param('assignee_id') ?: $this->adminId());
        $assignee = Db::name('admin_users')->where('id', $assigneeId)->whereNull('deleted_at')->where('status', 1)->find();
        if (!$assignee) {
            return $this->fail(4040, '分配对象不存在或已禁用');
        }

        $update = [
            'assignee_id'   => $assigneeId,
            'assignee_name' => $assignee['real_name'] ?: $assignee['username'],
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        // 待处理工单被领取后自动进入处理中
        if ((int) $ticket['status'] === 1) {
            $update['status'] = 2;
        }

        Db::name('support_tickets')->where('id', $id)->update($update);

        $this->audit('ticket', 'assign', '工单 ' . $ticket['ticket_no'] . ' 分配给 ' . $update['assignee_name'],
            ['assignee_id' => $assigneeId], 'ticket', $id);
        return $this->success(null, '工单已分配');
    }

    /**
     * POST /admin/tickets/:id/reply { content, is_internal? }
     */
    public function reply()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $content = trim((string) $this->request->param('content', ''));
        if ($content === '') {
            return $this->fail(4220, '回复内容不能为空');
        }
        if (mb_strlen($content) > 5000) {
            return $this->fail(4220, '回复内容过长（上限 5000 字）');
        }

        $ticket = Db::name('support_tickets')->where('id', $id)->find();
        if (!$ticket) {
            return $this->fail(4040, '工单不存在');
        }
        if ((int) $ticket['status'] === 5) {
            return $this->fail(4220, '工单已关闭，不可回复');
        }

        // 分配限制：非超管且工单已分配给他人时不可回复
        $admin = $this->admin();
        $isSuper = !empty($admin['is_super']);
        if (!$isSuper && (int) $ticket['assignee_id'] > 0 && (int) $ticket['assignee_id'] !== $this->adminId()) {
            return $this->fail(4220, '该工单已分配给 ' . $ticket['assignee_name'] . '，仅处理人或超级管理员可回复');
        }

        $isInternal = (int) $this->request->param('is_internal', 0) === 1 ? 1 : 0;

        Db::name('ticket_replies')->insert([
            'ticket_id'   => $id,
            'sender_type' => 2, // 2=客服
            'sender_id'   => $this->adminId(),
            'sender_name' => $this->adminName(),
            'content'     => $content,
            'is_internal' => $isInternal,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // 可见回复：状态推进（待处理→处理中；用户无需再确认的保持）
        $update = ['updated_at' => date('Y-m-d H:i:s')];
        if (!$isInternal) {
            if ((int) $ticket['status'] === 1) {
                $update['status'] = 2;
            }
            // 分配给自己（若未分配）
            if ((int) $ticket['assignee_id'] === 0) {
                $update['assignee_id']   = $this->adminId();
                $update['assignee_name'] = $this->adminName();
            }
        }
        Db::name('support_tickets')->where('id', $id)->update($update);

        $this->audit('ticket', 'reply',
            '回复工单 ' . $ticket['ticket_no'] . ($isInternal ? '（内部备注）' : ''),
            [], 'ticket', $id);
        return $this->success(null, $isInternal ? '内部备注已添加' : '回复已发送');
    }

    /**
     * POST /admin/tickets/:id/status { status: 3待用户确认/4已解决/5已关闭 }
     */
    public function changeStatus()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $newStatus = (int) $this->request->param('status', 0);
        if (!in_array($newStatus, [3, 4, 5], true)) {
            return $this->fail(4220, 'status 仅允许 3待用户确认/4已解决/5已关闭');
        }

        $ticket = Db::name('support_tickets')->where('id', $id)->find();
        if (!$ticket) {
            return $this->fail(4040, '工单不存在');
        }
        if ((int) $ticket['status'] === 5) {
            return $this->fail(4220, '工单已关闭（终态）');
        }

        $now = date('Y-m-d H:i:s');
        $update = ['status' => $newStatus, 'updated_at' => $now];
        if ($newStatus === 4) {
            $update['solved_at'] = $now;
        }
        if ($newStatus === 5) {
            $update['closed_at'] = $now;
        }

        Db::name('support_tickets')->where('id', $id)->update($update);

        $this->audit('ticket', 'status', '工单 ' . $ticket['ticket_no'] . ' 状态变更为「' . self::STATUSES[$newStatus] . '」',
            ['status' => $newStatus], 'ticket', $id);
        return $this->success(null, '工单状态已更新为「' . self::STATUSES[$newStatus] . '」');
    }
}
