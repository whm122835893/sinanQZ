<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 回收站管理（admin/trash:*）
 *
 * 覆盖：用户、藏品、订单、轮播图、公告 五大核心表
 * 所有列表查询 where deleted_at IS NOT NULL；recover 置 NULL；purge 物理删除
 */
class TrashController extends BaseController
{
    /** 表名映射（白名单，防止任意表注入） */
    private const TABLE_MAP = [
        'collectibles' => 'collectibles',
        'orders'       => 'orders',
        'users'        => 'users',
        'banners'      => 'banners',
        'announcements' => 'announcements',
        'transfer'     => 'transfers',
        'resale_listings' => 'resale_listings',
        'blind_boxes'  => 'blind_boxes',
    ];

    private const DEFAULT_PAGE_SIZE = 30;

    /**
     * GET /admin/trash/collectibles
     */
    public function collectibles()
    {
        [$page, $pageSize] = $this->pageParams(self::DEFAULT_PAGE_SIZE);
        $query = Db::name('collectibles')->whereNotNull('deleted_at')
            ->field('id, name, subtitle, category_id, price, edition, sold, status, deleted_at, created_at')
            ->order('deleted_at', 'desc');
        $total = (clone $query)->count();
        $rows = $query->page($page, $pageSize)->select()->toArray();
        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    public function orders()
    {
        [$page, $pageSize] = $this->pageParams(self::DEFAULT_PAGE_SIZE);
        $query = Db::name('orders')->whereNotNull('deleted_at')
            ->field('id, order_no, user_id, collectible_id, total_price, quantity, status, deleted_at, created_at')
            ->order('deleted_at', 'desc');
        $total = (clone $query)->count();
        $rows = $query->page($page, $pageSize)->select()->toArray();
        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    public function users()
    {
        [$page, $pageSize] = $this->pageParams(self::DEFAULT_PAGE_SIZE);
        $query = Db::name('users')->whereNotNull('deleted_at')
            ->field('id, uid, username, phone, status, is_realname, deleted_at, created_at')
            ->order('deleted_at', 'desc');
        $total = (clone $query)->count();
        $rows = $query->page($page, $pageSize)->select()->toArray();
        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    public function banners()
    {
        [$page, $pageSize] = $this->pageParams(self::DEFAULT_PAGE_SIZE);
        $query = Db::name('banners')->whereNotNull('deleted_at')
            ->field('id, image, description, sort_order, is_active, deleted_at, created_at')
            ->order('deleted_at', 'desc');
        $total = (clone $query)->count();
        $rows = $query->page($page, $pageSize)->select()->toArray();
        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    public function announcements()
    {
        [$page, $pageSize] = $this->pageParams(self::DEFAULT_PAGE_SIZE);
        $query = Db::name('announcements')->whereNotNull('deleted_at')
            ->field('id, title, type, status, is_top, deleted_at, created_at')
            ->order('deleted_at', 'desc');
        $total = (clone $query)->count();
        $rows = $query->page($page, $pageSize)->select()->toArray();
        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * POST /admin/trash/:type/:id/recover  恢复（置 deleted_at = NULL）
     */
    public function recover()
    {
        $type = (string) $this->request->param('type');
        $id   = $this->positiveInt('id');
        if (!isset(self::TABLE_MAP[$type])) return $this->fail(4220, '不支持的表类型');
        if ($id === null) return $this->failMissing(['id']);

        Db::name(self::TABLE_MAP[$type])->where('id', $id)->update(['deleted_at' => null]);
        $this->audit('回收站', 'recover', "恢复 [{$type}] id={$id}");
        return $this->success(['type' => $type, 'id' => $id]);
    }

    /**
     * DELETE /admin/trash/:type/:id/purge  物理删除（不可恢复）
     */
    public function purge()
    {
        $type = (string) $this->request->param('type');
        $id   = $this->positiveInt('id');
        if (!isset(self::TABLE_MAP[$type])) return $this->fail(4220, '不支持的表类型');
        if ($id === null) return $this->failMissing(['id']);

        Db::name(self::TABLE_MAP[$type])->where('id', $id)->whereNotNull('deleted_at')->delete();
        $this->audit('回收站', 'purge', "物理删除 [{$type}] id={$id}");
        return $this->success(['type' => $type, 'id' => $id]);
    }

    /**
     * POST /admin/trash/:type/purge-all  清空某表回收站（物理删除全部已软删行）
     */
    public function purgeAll()
    {
        $type = (string) $this->request->param('type');
        if (!isset(self::TABLE_MAP[$type])) return $this->fail(4220, '不支持的表类型');

        $count = (int) Db::name(self::TABLE_MAP[$type])->whereNotNull('deleted_at')->count();
        Db::name(self::TABLE_MAP[$type])->whereNotNull('deleted_at')->delete();
        $this->audit('回收站', 'purge-all', "清空 [{$type}] 回收站，共 {$count} 条");
        return $this->success(['type' => $type, 'purged' => $count]);
    }
}
