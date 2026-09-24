<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AdminLogService;
use app\admin\traits\AdminResponse;
use think\exception\HttpResponseException;
use think\Request;

/**
 * 管理后台控制器基类
 *
 * 统一提供：
 * - 响应格式（success/fail/paginate，与前端 ProTable 约定一致）
 * - 认证上下文读取（AdminAuth 中间件注入 request->admin）
 * - 分页参数解析与越界保护
 * - 参数校验辅助
 * - 操作审计日志快捷入口
 */
abstract class BaseController
{
    use AdminResponse;

    protected Request $request;

    /**
     * 公开端点白名单（归一化控制器名 => 方法）：仅这些方法允许在无 AdminAuth 上下文时执行。
     * 与 route/app.php 公开路由一一对应，新增公开路由必须同步登记。
     */
    private const PUBLIC_ALLOWLIST = [
        'auth'    => ['login', 'refresh'],
        'captcha' => ['image', 'verify', 'enabled'],
        'cms'     => ['sitebrand'],
    ];

    /**
     * 控制器名归一化：TP 的 request->controller() 在不同分发路径下可能返回
     * 'AuthController' / 'auth_controller' / 'auth' 等形态，统一去掉分隔符与
     * controller 后缀后再与白名单比对，避免因大小写/蛇形差异误拦公开端点。
     */
    private static function normalizeController(string $name): string
    {
        $s = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
        return str_ends_with($s, 'controller') ? substr($s, 0, -10) : $s;
    }

    public function __construct(Request $request)
    {
        $this->request = $request;

        // fail-closed 兜底：管理端认证授权全部挂在路由级中间件上，一旦框架在路由
        // 未匹配（HTTP 动词不匹配、大小写/路径变体）时回退到 控制器/方法 默认分发，
        // 中间件将被整体旁路。此处强制要求非公开端点必须携带 AdminAuth 注入的
        // 认证上下文，旁路请求一律拒绝。
        $controller = self::normalizeController($request->controller());
        $action     = strtolower($request->action());
        $isPublic   = in_array($action, self::PUBLIC_ALLOWLIST[$controller] ?? [], true);
        if (!$isPublic && empty($request->admin)) {
            throw new HttpResponseException(json([
                'code'    => 4001,
                'message' => '未登录或令牌缺失',
                'data'    => null,
            ]));
        }
    }

    /**
     * 当前管理员认证上下文
     */
    protected function admin(): array
    {
        return $this->request->admin ?? [];
    }

    protected function adminId(): int
    {
        return (int) ($this->request->admin['admin_id'] ?? 0);
    }

    protected function adminName(): string
    {
        return (string) ($this->request->admin['real_name'] ?: ($this->request->admin['username'] ?? '系统'));
    }

    /**
     * 分页参数（page ≥ 1；pageSize 1~100，默认 20）
     *
     * @return array{0:int,1:int} [page, pageSize]
     */
    protected function pageParams(int $defaultSize = 20): array
    {
        $page     = max(1, (int) $this->request->param('page', 1));
        $pageSize = (int) $this->request->param('pageSize', $this->request->param('page_size', $defaultSize));
        $pageSize = min(100, max(1, $pageSize));
        return [$page, $pageSize];
    }

    /**
     * 必填参数校验：返回缺失字段名列表（空数组表示全部存在且非空）
     *
     * @param array $keys 需要校验的字段名
     * @param bool  $allowEmptyString 空串是否算缺失
     */
    protected function missingParams(array $keys, bool $allowEmptyString = false): array
    {
        $missing = [];
        $params  = $this->request->param();
        foreach ($keys as $key) {
            $value = $params[$key] ?? null;
            if ($value === null || $value === '' || (!$allowEmptyString && is_string($value) && trim($value) === '')) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /**
     * 校验失败快捷响应
     */
    protected function failMissing(array $missing): \think\Response
    {
        return $this->fail(4220, '缺少必填参数：' . implode('、', $missing));
    }

    /**
     * 正整数参数校验（id 类）
     */
    protected function positiveInt(string $key): ?int
    {
        $value = $this->request->param($key);
        if ($value === null || $value === '' || !ctype_digit((string) $value) || (int) $value <= 0) {
            return null;
        }
        return (int) $value;
    }

    /**
     * 时间区间参数 [start, end]（Y-m-d H:i:s 或 Y-m-d）
     *
     * @return array{0:string,1:string}|null 未传任何区间参数时返回 null
     */
    protected function dateRange(): ?array
    {
        // 兼容多种参数名：startDate/start_time/start_date（报表前端使用 snake_case）
        $start = trim((string) $this->request->param('startDate',
            $this->request->param('start_time', $this->request->param('start_date', ''))));
        $end   = trim((string) $this->request->param('endDate',
            $this->request->param('end_time', $this->request->param('end_date', ''))));
        if ($start === '' && $end === '') {
            return null;
        }
        if ($start !== '' && !strtotime($start)) {
            return null;
        }
        if ($end !== '' && !strtotime($end)) {
            return null;
        }
        // 日期粒度自动补全为当天结束
        if ($end !== '' && strlen($end) === 10) {
            $end .= ' 23:59:59';
        }
        if ($start !== '' && strlen($start) === 10) {
            $start .= ' 00:00:00';
        }
        return [$start, $end];
    }

    /**
     * 排序白名单解析（默认 created_at DESC）
     *
     * @param array $allowed 允许的排序字段映射：请求字段名 => 数据库字段名
     */
    protected function orderParams(array $allowed): array
    {
        $sortField = (string) $this->request->param('sortField', 'created_at');
        $sortOrder = strtolower((string) $this->request->param('sortOrder', 'desc'));
        $direction = $sortOrder === 'asc' ? 'ASC' : 'DESC';
        $dbField   = $allowed[$sortField] ?? 'created_at';
        return [$dbField, $direction];
    }

    /**
     * 审计日志快捷入口
     */
    protected function audit(string $module, string $action, string $desc, array $detail = [], ?string $targetType = null, ?int $targetId = null): void
    {
        AdminLogService::record($this->request, $module, $action, $desc, $detail, $targetType, $targetId);
    }

    /**
     * 枚举校验：值是否在白名单内
     */
    protected function enumParam(string $key, array $allowed, $default = null)
    {
        $value = $this->request->param($key, $default);
        if ($value === null) {
            return $default;
        }
        return in_array((string) $value, array_map('strval', $allowed), true) ? $value : $default;
    }
}
