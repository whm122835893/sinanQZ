<?php
declare(strict_types=1);

namespace app\admin\service;

use app\Request;
use think\facade\Db;

/**
 * 审计日志服务
 * 所有后台写操作统一经此类写入 nft_operation_logs（含 before/after 快照、IP），
 * 禁止在控制器内散落手写。文档 5.8。
 */
class AuditLogService
{
    /**
     * 记录一条操作日志
     *
     * @param int|null $adminId  操作人ID（AdminAuth 注入；系统级操作可为 null）
     * @param string  $adminName 操作人姓名快照
     * @param string  $module    模块：collectible/order/user/...（同权限 module）
     * @param string  $action    动作码：collectible.release / order.force_cancel ...
     * @param array   $opts     可选字段：target_type/target_id/target_desc/
     *                           before/after/reason/ip
     */
    public static function record(
        ?int $adminId,
        string $adminName,
        string $module,
        string $action,
        array $opts = []
    ): void {
        try {
            Db::name('operation_logs')->insert([
                'admin_id'    => $adminId ?? 0,
                'admin_name'  => $adminName ?: 'unknown',
                'module'      => $module,
                'action'      => $action,
                'target_type' => $opts['target_type'] ?? null,
                'target_id'   => isset($opts['target_id']) ? (string) $opts['target_id'] : null,
                'target_desc' => $opts['target_desc'] ?? null,
                'before_value' => isset($opts['before']) && $opts['before'] !== null
                    ? json_encode($opts['before'], JSON_UNESCAPED_UNICODE) : null,
                'after_value' => isset($opts['after']) && $opts['after'] !== null
                    ? json_encode($opts['after'], JSON_UNESCAPED_UNICODE) : null,
                'reason'      => $opts['reason'] ?? null,
                'ip'          => $opts['ip'] ?? self::currentIp(),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 审计写入失败不阻断业务，但记录到运行日志便于排查
            trace('AuditLog write failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 便捷方法：从当前请求上下文取操作人并记录
     */
    public static function log(Request $request, string $module, string $action, array $opts = []): void
    {
        self::record(
            $request->adminId ?? null,
            $request->adminName ?? '',
            $module,
            $action,
            $opts
        );
    }

    private static function currentIp(): string
    {
        $request = app()->request;
        return $request ? (string) $request->ip() : '';
    }
}
