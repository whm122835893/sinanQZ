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
     * 公告/新闻列表（仅已发布且到生效时间的公告：status=published 且 publish_time 为空或已到）
     */
    public function announcements()
    {
        $p    = $this->pagination();
        $type = $this->strParam('type');

        $query = Db::name('announcements')->whereNull('deleted_at')
            ->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('publish_time')->whereOr('publish_time', '<=', date('Y-m-d H:i:s'));
            });
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
     * 公告/新闻详情（草稿/未到定时时间的公告不可见）
     */
    public function announcementDetail()
    {
        $id = $this->intParam('id');
        $a  = Db::name('announcements')->where('id', $id)->whereNull('deleted_at')
            ->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('publish_time')->whereOr('publish_time', '<=', date('Y-m-d H:i:s'));
            })
            ->find();
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

        // 站点装修（B 端配置的全局风格：名称/头像/主题色等）
        $siteKeys = ['site_name', 'site_logo', 'site_avatar', 'theme_color', 'bg_color', 'button_color', 'button_radius', 'seo_title', 'seo_description', 'seo_keywords'];
        $site = Db::name('site_settings')->whereIn('setting_key', $siteKeys)->column('setting_value', 'setting_key');

        return $this->success([
            'purchaseLimitPerUser'    => (int) ($list['purchase_limit_per_user'] ?? 5),
            'orderPayTimeoutSeconds'  => (int) ($list['order_pay_timeout_seconds'] ?? 300),
            'resaleCooldownSeconds'   => (int) ($list['resale_cooldown_seconds'] ?? 180),
            'resaleFeeRate'           => (float) ($list['resale_fee_rate'] ?? 1.0),
            'site' => [
                'siteName'       => $site['site_name'] ?? '司南艺术',
                'siteLogo'       => $site['site_logo'] ?? '',
                'siteAvatar'     => $site['site_avatar'] ?? '',
                'themeColor'     => $this->hexOrDefault($site['theme_color'] ?? '', '#C00000'),
                'bgColor'        => $this->hexOrDefault($site['bg_color'] ?? '', '#F7F8FA'),
                'buttonColor'    => $this->hexOrDefault($site['button_color'] ?? '', ''),
                'buttonRadius'   => (int) ($site['button_radius'] ?? 8),
                'seoTitle'       => $site['seo_title'] ?? '',
                'seoDescription' => $site['seo_description'] ?? '',
                'seoKeywords'    => $site['seo_keywords'] ?? '',
            ],
        ]);
    }

    /** 颜色值兜底：非法/为空时返回默认 */
    private function hexOrDefault(string $value, string $default): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? $value : $default;
    }
}
