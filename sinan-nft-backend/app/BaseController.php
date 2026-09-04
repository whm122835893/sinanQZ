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
     */
    protected function intParam(string $key, int $default = 0): int
    {
        $v = $this->request->param($key);
        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
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
     * 当前登录用户ID（JwtAuth 中间件解析后注入）
     */
    protected function userId(): ?int
    {
        return $this->request->userId ?? null;
    }
}
