<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\UploadCleanupService;

/**
 * 上传图片清理（system:image-cleanup）
 *
 * - unreferenced：扫描 uploads 中零引用图（分页，可按 biz 过滤）
 * - trash：勾选式批量移入回收站（被引用/不存在的跳过并回报）
 * - trash 列表 / restore 恢复 / purge 物理删除
 */
class UploadCleanupController extends BaseController
{
    private UploadCleanupService $service;

    public function __construct(\think\Request $request)
    {
        parent::__construct($request);
        $this->service = new UploadCleanupService();
    }

    /**
     * GET /admin/upload-cleanup/unreferenced?page&pageSize&biz
     */
    public function unreferenced()
    {
        [$page, $pageSize] = $this->pageParams(30);
        $biz = $this->enumParam('biz', $this->service->bizDirs(), null);
        $items = $this->service->scanUnreferenced($biz);

        $totalSize = 0;
        $byBiz = [];
        foreach ($items as $it) {
            $totalSize += $it['size'];
            $byBiz[$it['biz']] = ($byBiz[$it['biz']] ?? 0) + 1;
        }
        $slice = array_slice($items, ($page - 1) * $pageSize, $pageSize);

        return $this->success([
            'items'   => $slice,
            'total'   => count($items),
            'summary' => [
                'totalSize' => $totalSize,
                'byBiz'     => $byBiz,
                'scannedAt' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * POST /admin/upload-cleanup/trash { urls: [] }
     */
    public function trash()
    {
        $urls = $this->request->param('urls');
        if (!is_array($urls) || !$urls) {
            return $this->fail(4220, '请勾选要清理的图片（urls 数组不能为空）');
        }
        if (count($urls) > 100) {
            return $this->fail(4220, '单次最多清理 100 张图片');
        }
        $result = $this->service->trashFiles($urls);
        $this->audit('upload', 'cleanup-trash', '图片清理：移入回收站 ' . count($result['moved']) . ' 张', $result);
        return $this->success($result, sprintf(
            '已移入回收站 %d 张（跳过：仍被引用 %d、不存在 %d、非法 %d）',
            count($result['moved']), count($result['referenced']),
            count($result['missing']), count($result['invalid'])
        ));
    }

    /**
     * GET /admin/upload-cleanup/trash
     */
    public function trashList()
    {
        [$page, $pageSize] = $this->pageParams(30);
        $items = $this->service->listTrash();
        $totalSize = array_sum(array_column($items, 'size'));
        return $this->success([
            'items'   => array_slice($items, ($page - 1) * $pageSize, $pageSize),
            'total'   => count($items),
            'summary' => ['totalSize' => $totalSize],
        ]);
    }

    /**
     * POST /admin/upload-cleanup/trash/restore { urls: [] }
     */
    public function trashRestore()
    {
        $urls = $this->request->param('urls');
        if (!is_array($urls) || !$urls) {
            return $this->fail(4220, '请勾选要恢复的图片');
        }
        if (count($urls) > 100) {
            return $this->fail(4220, '单次最多恢复 100 张图片');
        }
        $result = $this->service->restoreFiles($urls);
        $this->audit('upload', 'cleanup-restore', '图片清理：恢复 ' . count($result['restored']) . ' 张', $result);
        return $this->success($result, sprintf(
            '已恢复 %d 张（跳过：不存在 %d、原位冲突 %d、非法 %d）',
            count($result['restored']), count($result['missing']),
            count($result['conflict']), count($result['invalid'])
        ));
    }

    /**
     * POST /admin/upload-cleanup/trash/purge { urls?: [], all?: 0|1 }
     * 物理删除，不可恢复；all=1 时清空整个回收站
     */
    public function trashPurge()
    {
        $all = (int) $this->request->param('all', 0) === 1;
        if ($all) {
            $urls = array_column($this->service->listTrash(), 'url');
        } else {
            $urls = $this->request->param('urls');
            if (!is_array($urls) || !$urls) {
                return $this->fail(4220, '请勾选要彻底删除的图片，或传 all=1 清空回收站');
            }
            if (count($urls) > 200) {
                return $this->fail(4220, '单次最多彻底删除 200 张图片');
            }
        }
        $result = $this->service->purgeTrashFiles($urls);
        $this->audit('upload', 'cleanup-purge', '图片清理：物理删除 ' . count($result['purged']) . ' 张', $result);
        return $this->success($result, sprintf(
            '已物理删除 %d 张（跳过：不存在 %d、非法 %d）',
            count($result['purged']), count($result['missing']), count($result['invalid'])
        ));
    }
}
