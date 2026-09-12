<?php
declare(strict_types=1);

namespace app;

use app\traits\JsonResponse;
use think\App;
use think\Request;

/**
 * 基础控制器
 */
abstract class BaseController
{
    use JsonResponse;

    protected App     $app;
    protected Request $request;

    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $app->request;
    }

    /**
     * 安全解析 int 参数，缺失/非法返回 $default
     *
     * H3-D3 修复：JSON 大数（如 99999999999999999999 → float 1.0E20）经 (int) 隐式
     * 转换会触发 PHP 8.1+ "loses precision" 弃用告警（被错误处理器转为 ErrorException
     * → HTTP 500 调试页）。此处统一拦截：非整数/超 int 范围的浮点按非法参数回退默认值。
     */
    protected function intParam(string $key, int $default = 0): int
    {
        $v = $this->request->param($key);
        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }
        if (!is_int($v)) {
            $f = (float) $v;
            if ($f !== floor($f) || $f > PHP_INT_MAX || $f < PHP_INT_MIN) {
                return $default;
            }
        }
        return (int) $v;
    }

    /**
     * 安全解析 string 参数，null/空串返回 $default
     */
    protected function strParam(string $key, ?string $default = null): ?string
    {
        $v = $this->request->param($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return (string) $v;
    }

    /**
     * 分页参数：page / pageSize
     */
    protected function pagination(): array
    {
        $page     = max(1, $this->intParam('page', 1));
        $pageSize = min(100, max(1, $this->intParam('pageSize', 20)));
        return ['page' => $page, 'pageSize' => $pageSize, 'offset' => ($page - 1) * $pageSize];
    }

    /**
     * 枚举参数：值必须在 $allowed 白名单内，否则回退 $default（null 表示缺省）
     */
    protected function enumParam(string $key, array $allowed, $default = null)
    {
        $value = $this->request->param($key, $default);
        if ($value === null) {
            return $default;
        }
        return in_array((string) $value, array_map('strval', $allowed), true) ? $value : $default;
    }

    /**
     * 当前登录用户ID（JwtAuth 中间件解析后注入）
     */
    protected function userId(): ?int
    {
        return $this->request->userId ?? null;
    }
}
