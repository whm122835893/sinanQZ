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
            'qqGroup'     => $g['qq_group'] ?? '',
        ], $list));
    }

    /**
     * GET /api/config
     * 客户端公开的系统配置
     */
    public function siteConfig()
    {
        $keys = ['purchase_limit_per_user', 'order_pay_timeout_seconds', 'resale_cooldown_seconds', 'resale_fee_rate', 'service_hotline', 'service_hours', 'service_online_url'];
        $list = Db::name('system_configs')->whereIn('config_key', $keys)->column('config_value', 'config_key');

        // 站点装修（B 端配置的全局风格：名称/头像/主题色/图标主题等）
        $siteKeys = ['site_name', 'site_logo', 'site_avatar', 'theme_color', 'bg_color', 'button_color', 'button_radius', 'seo_title', 'seo_description', 'seo_keywords', 'feature_icon_theme', 'tab_icon_theme', 'custom_icon_calendar', 'custom_icon_activity', 'custom_icon_lottery', 'custom_icon_inventory', 'custom_icon_wallet', 'custom_icon_invite', 'splash_enabled', 'splash_image', 'splash_duration', 'splash_mode', 'agreement_user', 'agreement_privacy'];
        $site = Db::name('site_settings')->whereIn('setting_key', $siteKeys)->column('setting_value', 'setting_key');

        return $this->success([
            'purchaseLimitPerUser'    => (int) ($list['purchase_limit_per_user'] ?? 5),
            'orderPayTimeoutSeconds'  => (int) ($list['order_pay_timeout_seconds'] ?? 300),
            'resaleCooldownSeconds'   => (int) ($list['resale_cooldown_seconds'] ?? 180),
            'resaleFeeRate'           => (float) ($list['resale_fee_rate'] ?? 1.0),
            'service' => [
                'hotline'   => '',
                'hours'     => $list['service_hours'] ?? '9:00 - 22:00',
                'onlineUrl' => $list['service_online_url'] ?? '',
            ],
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
                // 图标主题：功能入口与底部导航可独立切换；非法值回退 classic
                'featureIconTheme' => $this->validFeatureIconTheme($site['feature_icon_theme'] ?? ''),
                'tabIconTheme'     => $this->validTabIconTheme($site['tab_icon_theme'] ?? ''),
                // 自定义图标：管理员上传的功能入口位图（日历/活动/抽奖/库存/钱包/邀请好友）
                'customIcons'    => [
                    'calendar'  => $site['custom_icon_calendar'] ?? '',
                    'activity'  => $site['custom_icon_activity'] ?? '',
                    'lottery'   => $site['custom_icon_lottery'] ?? '',
                    'inventory' => $site['custom_icon_inventory'] ?? '',
                    'wallet'    => $site['custom_icon_wallet'] ?? '',
                    'invite'    => $site['custom_icon_invite'] ?? '',
                ],
                // 开屏配置
                'splashEnabled'  => (int) ($site['splash_enabled'] ?? 0) === 1,
                'splashImage'    => $site['splash_image'] ?? '',
                'splashDuration' => max(1, min(10, (int) ($site['splash_duration'] ?? 3))),
                'splashMode'     => in_array($site['splash_mode'] ?? '', ['every', 'daily', 'image'], true) ? $site['splash_mode'] : 'image',
                // 登录页协议（B 端「内容 → 协议管理」维护，纯文本）
                'agreement'      => $site['agreement_user'] ?? '',
                'privacy'        => $site['agreement_privacy'] ?? '',
            ],
        ]);
    }

    /** 颜色值兜底：非法/为空时返回默认 */
    private function hexOrDefault(string $value, string $default): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? $value : $default;
    }

    /** 功能图标主题白名单兜底：非法/为空回退 classic（允许自定义图标） */
    private function validFeatureIconTheme(string $value): string
    {
        $allowed = ['classic', 'gem', 'ink', 'shanse', 'glass', 'custom'];
        return in_array($value, $allowed, true) ? $value : 'classic';
    }

    /** 底部导航图标主题白名单兜底：非法/为空回退 classic（导航无自定义上传，排除 custom） */
    private function validTabIconTheme(string $value): string
    {
        $allowed = ['classic', 'gem', 'ink', 'shanse', 'glass'];
        return in_array($value, $allowed, true) ? $value : 'classic';
    }
}
