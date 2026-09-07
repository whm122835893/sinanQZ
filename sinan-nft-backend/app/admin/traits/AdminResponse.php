<?php
declare(strict_types=1);

namespace app\admin\traits;

use think\Response;

/**
 * 管理后台统一响应（Admin 约定：code === 200 为成功）
 * 与 C 端（code 0）完全隔离，互不干扰
 */
trait AdminResponse
{
    protected function success(mixed $data = null, string $message = 'ok'): Response
    {
        return json(['code' => 200, 'message' => $message, 'data' => $data]);
    }

    protected function fail(int $code = 5000, string $message = '系统内部错误', mixed $data = null): Response
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data]);
    }

    /**
     * 统一分页返回（与前端 ProTable 约定：list / total / page / pageSize / lastPage）
     * @param array $extra 附加统计字段（如 stats）
     */
    protected function paginate(array $items, int $total, int $page, int $pageSize, array $extra = []): Response
    {
        return $this->success([
            'list'     => $items,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'lastPage' => (int) ceil($total / max($pageSize, 1)),
        ] + $extra);
    }
}
