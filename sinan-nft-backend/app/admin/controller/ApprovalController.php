<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台审批中心控制器
 *
 * 通用高风险操作审批工作流。当前真实触发点：
 * - 大额退款：RefundController@approve 审批退款单时，金额 ≥
 *   system_configs.large_refund_approval_threshold（默认 1000 元）的，
 *   不直接批准，而是生成审批单；由具备 approval:manage 权限的管理员
 *   （超管/风控）在审批中心复核。
 *
 * 审批通过 → 联动退款单置「已批准」（可执行退款）；
 * 审批驳回 → 退款单置「已拒绝」，订单回滚 completed。
 *
 * 严谨性设计：
 * - 审批人不能审批自己发起的申请（发起人/复核人分离）
 * - 待审批单处理采用乐观状态机（status=1 才可处理），重复处理返回 4220
 * - 全部动作写操作审计日志
 */
class ApprovalController extends BaseController
{
    /** 审批类型字典 */
    public const TYPES = [
        'large_refund'    => '大额退款',
        'asset_modify'    => '资产变更',
        'config_modify'   => '配置变更',
        'platform_cleanup' => '平台清库',
    ];

    /**
     * GET /admin/approvals
     * 筛选：type、status（1待审批 2已通过 3已驳回）、keyword（单号/标题）、时间区间
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('approval_requests')->alias('a');

        $type = (string) $this->request->param('type', '');
        if ($type !== '' && isset(self::TYPES[$type])) {
            $query->where('a.type', $type);
        }
        $status = $this->request->param('status');
        if ($status !== null && $status !== '' && in_array((int) $status, [1, 2, 3], true)) {
            $query->where('a.status', (int) $status);
        }
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('a.approval_no', "%{$keyword}%")
                  ->whereOr('a.title', 'like', "%{$keyword}%");
            });
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('a.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('a.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->order('a.id', 'desc')->page($page, $pageSize)->select()->toArray();

        $result = array_map(fn ($row) => $this->formatRow($row), $rows);

        // 待办统计（页头指标）
        $summary = [
            'pending'  => Db::name('approval_requests')->where('status', 1)->count(),
            'approved' => Db::name('approval_requests')->where('status', 2)->count(),
            'rejected' => Db::name('approval_requests')->where('status', 3)->count(),
        ];

        return $this->withSummary($result, $total, $page, $pageSize, $summary);
    }

    /**
     * GET /admin/approvals/:id
     */
    public function detail(int $id)
    {
        $row = Db::name('approval_requests')->where('id', $id)->find();
        if (!$row) {
            return $this->fail(4040, '审批单不存在');
        }
        $data = $this->formatRow($row);
        $data['detail'] = json_decode((string) ($row['detail'] ?? '{}'), true) ?: [];
        return $this->success($data);
    }

    /**
     * GET /admin/approvals/stats
     * 审批中心统计（待办/已通过/已驳回 + 按类型分布，供仪表盘卡片）
     */
    public function stats()
    {
        $byType = [];
        foreach (array_keys(self::TYPES) as $type) {
            $byType[$type] = [
                'name'    => self::TYPES[$type],
                'pending' => Db::name('approval_requests')->where('type', $type)->where('status', 1)->count(),
                'total'   => Db::name('approval_requests')->where('type', $type)->count(),
            ];
        }
        return $this->success([
            'pending'  => Db::name('approval_requests')->where('status', 1)->count(),
            'approved' => Db::name('approval_requests')->where('status', 2)->count(),
            'rejected' => Db::name('approval_requests')->where('status', 3)->count(),
            'byType'   => $byType,
        ]);
    }

    /**
     * POST /admin/approvals/:id/handle { action(approve/reject), reason }
     */
    public function handle(int $id)
    {
        $missing = $this->missingParams(['action']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $action = (string) $this->request->param('action');
        $reason = trim((string) $this->request->param('reason', ''));

        if (!in_array($action, ['approve', 'reject'], true)) {
            return $this->fail(4220, 'action 仅允许 approve / reject');
        }
        if ($action === 'reject' && $reason === '') {
            return $this->fail(4220, '驳回必须填写审批意见');
        }

        Db::startTrans();
        try {
            $request = Db::name('approval_requests')->where('id', $id)->where('status', 1)->lock(true)->find();
            if (!$request) {
                Db::rollback();
                return $this->fail(4220, '审批单不存在或已处理');
            }
            if ((int) $request['applicant_id'] === $this->adminId()) {
                Db::rollback();
                return $this->fail(4220, '不能审批自己发起的申请（发起人/复核人分离）');
            }

            $now = date('Y-m-d H:i:s');
            Db::name('approval_requests')->where('id', $id)->where('status', 1)->update([
                'status'       => $action === 'approve' ? 2 : 3,
                'handler_id'   => $this->adminId(),
                'handler_name' => $this->adminName(),
                'handle_time'  => $now,
                'reason'       => mb_substr($reason, 0, 255) ?: null,
                'updated_at'   => $now,
            ]);

            // 大额退款联动：通过 → 退款单已批准；驳回 → 退款单已拒绝 + 订单回滚
            if ($request['type'] === 'large_refund' && $request['target_type'] === 'refund') {
                $refundId = (int) $request['target_id'];
                $refund   = Db::name('refunds')->where('id', $refundId)->where('status', 1)->lock(true)->find();
                if ($refund) {
                    Db::name('refunds')->where('id', $refundId)->update([
                        'status'        => $action === 'approve' ? 2 : 4,
                        'approver_id'   => $this->adminId(),
                        'approver_name' => $this->adminName(),
                        'approved_at'   => $now,
                        'comment'       => mb_substr('审批中心' . ($action === 'approve' ? '通过' : '驳回') . ($reason !== '' ? '：' . $reason : ''), 0, 255),
                        'updated_at'    => $now,
                    ]);
                    if ($action === 'reject') {
                        Db::name('orders')->where('id', $refund['order_id'])->where('status', 'refunding')
                            ->update(['status' => 'completed', 'updated_at' => $now]);
                    }
                }
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '审批处理失败：' . $e->getMessage());
        }

        $this->audit('approval', 'handle_' . $action,
            ($action === 'approve' ? '通过' : '驳回') . '审批 ' . $request['approval_no'] . '（' . $request['title'] . '）',
            ['reason' => $reason], 'approval', $id);
        return $this->success(null, $action === 'approve' ? '审批已通过' : '审批已驳回');
    }

    /**
     * 行格式化（detail 不在列表页展开）
     */
    private function formatRow(array $row): array
    {
        $statusMap = [1 => '待审批', 2 => '已通过', 3 => '已驳回'];
        $row['status_text'] = $statusMap[(int) $row['status']] ?? '';
        $row['type_text'] = self::TYPES[$row['type']] ?? $row['type'];
        return camelize_keys($row);
    }

    /**
     * 附带 summary 的分页响应
     */
    private function withSummary(array $list, int $total, int $page, int $pageSize, array $summary)
    {
        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'lastPage' => (int) ceil($total / max(1, $pageSize)),
            'summary'  => $summary,
        ]);
    }
}
