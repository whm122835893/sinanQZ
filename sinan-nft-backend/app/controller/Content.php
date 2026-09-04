<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 内容控制器：公告/新闻/轮播/社区/系统配置
 */
class Content extends BaseController
{
    /**
     * GET /api/announcements
     * 公告/新闻列表
     */
    public function announcements()
    {
        $p    = $this->pagination();
        $type = $this->strParam('type');

        $query = Db::name('announcements')->whereNull('deleted_at');
        if ($type) $query->where('type', $type);

        $total = $query->count();
        $list  = $query->order('is_top', 'desc')->order('created_at', 'desc')
            ->limit($p['offset'], $p['pageSize'])->select()->toArray();

        return $this->paginate(array_map(fn ($a) => [
            'id'         => (int) $a['id'],
            'title'      => $a['title'],
            'summary'    => $a['summary'],
            'type'       => $a['type'],
            'subtype'    => $a['subtype'],
            'tagColor'   => $a['tag_color'],
            'isTop'      => (bool) $a['is_top'],
            'createdAt'  => $a['created_at'],
        ], $list), $total, $p['page'], $p['pageSize']);
    }

    /**
     * GET /api/announcements/:id
     * 公告/新闻详情
     */
    public function announcementDetail()
    {
        $id = $this->intParam('id');
        $a  = Db::name('announcements')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$a) return $this->fail(1002, '公告不存在');

        return $this->success([
            'id'         => (int) $a['id'],
            'title'      => $a['title'],
            'content'    => $a['content'],
            'coverImage' => $a['cover_image'],
            'type'       => $a['type'],
            'subtype'    => $a['subtype'],
            'tagColor'   => $a['tag_color'],
            'createdAt'  => $a['created_at'],
        ]);
    }

    /**
     * GET /api/banners
     * 首页轮播
     */
    public function banners()
    {
        $list = Db::name('banners')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();

        return $this->success(array_map(fn ($b) => [
            'id'         => (int) $b['id'],
            'image'      => $b['image'],
            'description'=> $b['description'],
            'sortOrder'  => (int) $b['sort_order'],
        ], $list));
    }

    /**
     * GET /api/community/groups
     * 社区群列表
     */
    public function community()
    {
        $list = Db::name('community_groups')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();

        return $this->success(array_map(fn ($g) => [
            'id'          => (int) $g['id'],
            'icon'        => $g['icon'],
            'name'        => $g['name'],
            'description' => $g['description'],
            'qrCode'      => $g['qr_code'],
        ], $list));
    }

    /**
     * GET /api/config
     * 客户端公开的系统配置
     */
    public function siteConfig()
    {
        $keys = ['purchase_limit_per_user', 'order_pay_timeout_seconds', 'resale_cooldown_seconds', 'resale_fee_rate'];
        $list = Db::name('system_configs')->whereIn('config_key', $keys)->column('config_value', 'config_key');

        return $this->success([
            'purchaseLimitPerUser'    => (int) ($list['purchase_limit_per_user'] ?? 5),
            'orderPayTimeoutSeconds'  => (int) ($list['order_pay_timeout_seconds'] ?? 300),
            'resaleCooldownSeconds'   => (int) ($list['resale_cooldown_seconds'] ?? 180),
            'resaleFeeRate'           => (float) ($list['resale_fee_rate'] ?? 1.0),
        ]);
    }
}
