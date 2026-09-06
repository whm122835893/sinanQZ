<?php
declare(strict_types=1);

namespace app\admin\service;

use think\facade\Db;
use think\Request;

/**
 * 管理后台操作审计日志服务
 *
 * 覆盖（按需求）：后台修改、批量导入、发放空投、配置活动、白名单操作、
 * 实名查看、强制回收、撤销转赠、平台清库、盲盒配置修改等全部写审计。
 *
 * 设计：
 * - fire-and-forget：日志写库失败不阻断业务（try/catch 吞异常）
 * - detail 敏感字段自动脱敏（password/access_secret/token 等）
 * - 请求上下文（method/path/ip/user_agent）自动采集
 */
class AdminLogService
{
    /** detail 序列化时需要脱敏/剔除的字段 */
    private const SENSITIVE_KEYS = [
        'password', 'old_password', 'new_password', 'access_secret', 'app_secret',
        'token', 'refresh_token', 'secret_key', 'config',
    ];

    /**
     * 记录操作日志
     *
     * @param Request    $request    当前请求（取认证上下文与请求元数据）
     * @param string     $module     模块标识，如 user / collectible / order / system
     * @param string     $action     动作标识，如 freeze / create / cleanup
     * @param string     $actionDesc 动作中文描述，如「冻结用户」
     * @param array      $detail     变更明细（自动脱敏）
     * @param string|null $targetType 目标对象类型，如 user / collectible
     * @param int|null   $targetId   目标对象ID
     */
    public static function record(
        Request $request,
        string $module,
        string $action,
        string $actionDesc,
        array $detail = [],
        ?string $targetType = null,
        ?int $targetId = null
    ): void {
        try {
            $admin = $request->admin ?? [];
            Db::name('admin_operation_logs')->insert([
                'admin_id'    => (int) ($admin['admin_id'] ?? 0) ?: null,
                'admin_name'  => (string) ($admin['username'] ?? '系统'),
                'module'      => $module,
                'action'      => $action,
                'action_desc' => mb_substr($actionDesc, 0, 255),
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'detail'      => $detail ? json_encode(self::sanitize($detail), JSON_UNESCAPED_UNICODE) : null,
                'method'      => mb_substr((string) $request->method(), 0, 10),
                'path'        => mb_substr((string) $request->pathinfo(), 0, 255),
                'ip'          => (string) $request->ip(),
                'user_agent'  => mb_substr((string) $request->header('user-agent', ''), 0, 500),
                'created_at'  => date('Y-m-d H:i:s.v'),
            ]);
        } catch (\Throwable $e) {
            // 审计日志失败不阻断业务
        }
    }

    /**
     * 递归脱敏 detail 中的敏感字段
     */
    private static function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::sanitize($value);
            }
        }
        return $data;
    }
}
