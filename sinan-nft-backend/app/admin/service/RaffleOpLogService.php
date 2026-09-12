<?php
declare(strict_types=1);

namespace app\admin\service;

use think\facade\Db;
use think\Request;

/**
 * 抽签独立操作日志服务
 *
 * 覆盖：设置/取消必中（含手机号设必中）、批量必中、修改抽签名额、修改购买码上限/邀请得码配置、
 * 新增/导入/作废/删除抽签码、开奖、标记付款/核销等。
 *
 * 设计（按需求）：
 * - 写入独立表 nft_raffle_operation_logs，与通用 admin_operation_logs 分离
 * - 日志只增不删：不提供任何删除/清空入口
 * - fire-and-forget：日志写库失败不阻断业务（try/catch 吞异常）
 */
class RaffleOpLogService
{
    /** 动作标识常量 */
    public const ACTION_SET_FORCE_WIN       = 'set_force_win';
    public const ACTION_CANCEL_FORCE_WIN    = 'cancel_force_win';
    public const ACTION_CHANGE_QUOTA        = 'change_quota';
    public const ACTION_CHANGE_BUY_LIMIT    = 'change_buy_limit';
    public const ACTION_CHANGE_TOTAL_SUPPLY = 'change_total_supply';
    public const ACTION_DRAW                = 'draw';
    public const ACTION_SAVE                = 'save_activity';
    public const ACTION_STATUS              = 'change_status';
    public const ACTION_CODE_CREATE         = 'code_create';
    public const ACTION_CODE_IMPORT         = 'code_import';
    public const ACTION_CODE_INVALIDATE     = 'code_invalidate';
    public const ACTION_CODE_DELETE         = 'code_delete';
    public const ACTION_MARK_PAID           = 'mark_paid';
    public const ACTION_VERIFY              = 'verify';

    /**
     * 记录抽签操作日志
     *
     * @param Request     $request    当前请求（取管理员上下文与 IP）
     * @param string      $action     动作标识（见常量）
     * @param string      $actionDesc 动作中文描述
     * @param int|null    $activityId 关联抽签活动 ID
     * @param array       $detail     变更明细（前后值/名单等）
     */
    public static function record(
        Request $request,
        string $action,
        string $actionDesc,
        ?int $activityId = null,
        array $detail = []
    ): void {
        try {
            $admin = $request->admin ?? [];
            Db::name('raffle_operation_logs')->insert([
                'admin_id'    => (int) ($admin['admin_id'] ?? 0) ?: null,
                'admin_name'  => (string) ($admin['real_name'] ?: ($admin['username'] ?? '系统')),
                'activity_id' => $activityId ?: null,
                'action'      => $action,
                'action_desc' => mb_substr($actionDesc, 0, 255),
                'detail'      => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                'ip'          => (string) $request->ip(),
                'created_at'  => date('Y-m-d H:i:s.v'),
            ]);
        } catch (\Throwable $e) {
            // 独立日志写库失败不阻断业务
        }
    }
}
