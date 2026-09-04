<?php
declare(strict_types=1);

namespace app\traits;

use think\Response;

/**
 * 统一 JSON 响应 Trait
 * 所有 Controller 继承 BaseController 即自动拥有此能力
 */
trait JsonResponse
{
    protected function success(mixed $data = null, string $message = 'ok'): Response
    {
        return json([
            'code'    => 0,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    protected function fail(int $code = 5001, string $message = '系统内部错误', mixed $data = null): Response
    {
        return json([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * 分页响应
     */
    protected function paginate(array $items, int $total, int $page, int $pageSize): Response
    {
        return $this->success([
            'list'     => $items,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'lastPage' => (int) ceil($total / max($pageSize, 1)),
        ]);
    }
}
