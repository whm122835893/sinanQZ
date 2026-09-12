<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 内容管理控制器（CMS）
 *
 * - 轮播图管理：nft_banners 增删改查 + 启停
 * - 分类管理：nft_categories（scene=market 市场二级分类 / artifact 文物展览分类）增删改
 * - 公告管理：nft_announcements（notice公告/news新闻）增删改查 + 置顶
 * - 协议管理：nft_site_settings 键值存储（用户协议/隐私政策富文本）
 * - 文物展馆：nft_artifacts 增删改查
 * - 站点装修：nft_site_settings 分组 KV（basic/theme/button/seo）
 *
 * 严谨性设计：
 * - 全部软删除（deleted_at），物理删除仅软删入口
 * - 富文本内容入库前去除危险标签（script/iframe/on* 事件属性）
 * - 排序字段整数化、枚举白名单校验、路径/URL 长度截断
 */
class CmsController extends BaseController
{
    // ============================================================
    // 一、轮播图管理（cms:banner）
    // ============================================================

    /**
     * GET /admin/cms/banners
     */
    public function bannerList()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('banners')->whereNull('deleted_at');
        $status = $this->request->param('status');
        if ($status !== null && $status !== '') {
            $query->where('is_active', (int) $status === 1 ? 1 : 0);
        }

        $total = (clone $query)->count();
        $items = $query->order('sort_order', 'asc')->order('id', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * POST /admin/cms/banners { image, description?, sort_order, is_active }
     */
    public function bannerCreate()
    {
        $missing = $this->missingParams(['image']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $image = trim((string) $this->request->param('image'));
        if (!preg_match('#^https?://#i', $image) && !str_starts_with($image, '/')) {
            return $this->fail(4220, '图片地址需为 http(s):// 或以 / 开头的相对路径');
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('banners')->insertGetId([
            'image'       => mb_substr($image, 0, 255),
            'description' => $this->request->param('description') !== null
                ? mb_substr(trim((string) $this->request->param('description')), 0, 100) : null,
            'sort_order'  => (int) $this->request->param('sort_order', 0),
            'is_active'   => (int) $this->request->param('is_active', 1) === 1 ? 1 : 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->audit('cms', 'banner_create', '新增轮播图（ID ' . $id . '）', ['image' => $image], 'banner', $id);
        return $this->success(['id' => $id], '轮播图已创建');
    }

    /**
     * PUT /admin/cms/banners/:id
     */
    public function bannerUpdate()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $banner = Db::name('banners')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$banner) {
            return $this->fail(4040, '轮播图不存在');
        }

        $update = ['updated_at' => date('Y-m-d H:i:s')];

        if ($this->request->param('image') !== null && $this->request->param('image') !== '') {
            $image = trim((string) $this->request->param('image'));
            if (!preg_match('#^https?://#i', $image) && !str_starts_with($image, '/')) {
                return $this->fail(4220, '图片地址需为 http(s):// 或以 / 开头的相对路径');
            }
            $update['image'] = mb_substr($image, 0, 255);
        }
        if ($this->request->param('description') !== null) {
            $update['description'] = mb_substr(trim((string) $this->request->param('description')), 0, 100);
        }
        if ($this->request->param('sort_order') !== null) {
            $update['sort_order'] = (int) $this->request->param('sort_order');
        }
        if ($this->request->param('is_active') !== null) {
            $update['is_active'] = (int) $this->request->param('is_active') === 1 ? 1 : 0;
        }

        Db::name('banners')->where('id', $id)->update($update);

        $this->audit('cms', 'banner_update', '编辑轮播图（ID ' . $id . '）', $update, 'banner', $id);
        return $this->success(null, '轮播图已更新');
    }

    /**
     * DELETE /admin/cms/banners/:id（软删除）
     */
    public function bannerDelete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $banner = Db::name('banners')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$banner) {
            return $this->fail(4040, '轮播图不存在');
        }

        Db::name('banners')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('cms', 'banner_delete', '删除轮播图（ID ' . $id . '）', [], 'banner', $id);
        return $this->success(null, '轮播图已删除');
    }

    /**
     * POST /admin/cms/banners/:id/toggle（启/停切换）
     */
    public function bannerToggle()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $banner = Db::name('banners')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$banner) {
            return $this->fail(4040, '轮播图不存在');
        }

        $newStatus = (int) $banner['is_active'] === 1 ? 0 : 1;
        Db::name('banners')->where('id', $id)->update([
            'is_active'  => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('cms', 'banner_toggle', ($newStatus === 1 ? '启用' : '停用') . '轮播图（ID ' . $id . '）', [], 'banner', $id);
        return $this->success(['is_active' => $newStatus], $newStatus === 1 ? '已启用' : '已停用');
    }

    // ============================================================
    // 一b、分类管理（cms:category）
    // 场景：market 市场二级分类（水墨/国潮…）/ artifact 文物展览分类（青铜/陶瓷…）
    // ============================================================

    /**
     * GET /admin/cms/categories?scene=market|artifact
     */
    public function categoryList()
    {
        $scene = $this->enumParam('scene', ['market', 'artifact']);

        $query = Db::name('categories')->whereNull('deleted_at');
        if ($scene !== null) {
            $query->where('scene', $scene);
        }

        $items = $query->order('sort_order', 'asc')->order('id', 'asc')->select()->toArray();

        // 分类下藏品数（market 场景关联 nft_collectibles.category_id）
        $countRows = Db::name('collectibles')
            ->whereNull('deleted_at')
            ->field('category_id, COUNT(*) as cnt')
            ->group('category_id')
            ->select()->toArray();
        $countMap = array_column($countRows, 'cnt', 'category_id');

        $list = array_map(function ($c) use ($countMap) {
            return [
                'id'         => (int) $c['id'],
                'name'       => $c['name'],
                'code'       => $c['code'],
                'scene'      => $c['scene'],
                'sortOrder'  => (int) $c['sort_order'],
                'icon'       => $c['icon'] ?? '',
                'collectibleCount' => (int) ($countMap[$c['id']] ?? 0),
            ];
        }, $items);

        return $this->success($list);
    }

    /**
     * POST /admin/cms/categories { name, code, scene, sort_order?, icon? }
     */
    public function categoryCreate()
    {
        $missing = $this->missingParams(['name', 'code', 'scene']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $scene = $this->enumParam('scene', ['market', 'artifact']);
        if ($scene === null) {
            return $this->fail(4220, 'scene 仅支持 market / artifact');
        }

        $name = trim((string) $this->request->param('name'));
        if ($name === '' || mb_strlen($name) > 20) {
            return $this->fail(4220, '分类名需为 1~20 字符');
        }

        $code = strtolower(trim((string) $this->request->param('code')));
        if (!preg_match('/^[a-z0-9_-]{1,20}$/', $code)) {
            return $this->fail(4220, '分类编码仅支持小写字母/数字/中划线/下划线（1~20位）');
        }

        if (Db::name('categories')->where('code', $code)->whereNull('deleted_at')->count()) {
            return $this->fail(4220, '分类编码已存在：' . $code);
        }

        $now = date('Y-m-d H:i:s');
        $id = Db::name('categories')->insertGetId([
            'name'       => $name,
            'code'       => $code,
            'scene'      => $scene,
            'sort_order' => max(0, (int) $this->request->param('sort_order', 0)),
            'icon'       => $this->optStr('icon', 50),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit('cms', 'category_create', '新增分类「' . $name . '」（' . $scene . '）', compact('name', 'code', 'scene'), 'category', $id);
        return $this->success(['id' => $id], '分类已创建');
    }

    /**
     * PUT /admin/cms/categories/:id
     */
    public function categoryUpdate()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $cat = Db::name('categories')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$cat) {
            return $this->fail(4040, '分类不存在');
        }

        $update = ['updated_at' => date('Y-m-d H:i:s')];

        if ($this->request->has('name')) {
            $name = trim((string) $this->request->param('name'));
            if ($name === '' || mb_strlen($name) > 20) {
                return $this->fail(4220, '分类名需为 1~20 字符');
            }
            $update['name'] = $name;
        }
        if ($this->request->has('code')) {
            $code = strtolower(trim((string) $this->request->param('code')));
            if (!preg_match('/^[a-z0-9_-]{1,20}$/', $code)) {
                return $this->fail(4220, '分类编码仅支持小写字母/数字/中划线/下划线（1~20位）');
            }
            if ($code !== $cat['code']
                && Db::name('categories')->where('code', $code)->whereNull('deleted_at')->count()) {
                return $this->fail(4220, '分类编码已存在：' . $code);
            }
            $update['code'] = $code;
        }
        if ($this->request->has('scene')) {
            $scene = $this->enumParam('scene', ['market', 'artifact']);
            if ($scene === null) {
                return $this->fail(4220, 'scene 仅支持 market / artifact');
            }
            $update['scene'] = $scene;
        }
        if ($this->request->has('sort_order')) {
            $update['sort_order'] = max(0, (int) $this->request->param('sort_order'));
        }
        if ($this->request->has('icon')) {
            $update['icon'] = $this->optStr('icon', 50);
        }

        Db::name('categories')->where('id', $id)->update($update);

        // artifact 分类改名 → 同步文物 tags 首标签（C 端文物展览区按 tags[0] 与分类名匹配）
        if (($update['name'] ?? null) !== null
            && ($cat['scene'] === 'artifact' || ($update['scene'] ?? '') === 'artifact')
            && $update['name'] !== $cat['name']) {
            $oldName = $cat['name'];
            $newName = $update['name'];
            $rows = Db::name('artifacts')
                ->whereNull('deleted_at')
                ->whereLike('tags', '%' . $oldName . '%')
                ->field('id, tags')
                ->select()->toArray();
            foreach ($rows as $row) {
                $tags = json_decode((string) $row['tags'], true);
                if (!is_array($tags)) continue;
                $changed = false;
                foreach ($tags as $i => $t) {
                    if ($t === $oldName) { $tags[$i] = $newName; $changed = true; }
                }
                if ($changed) {
                    Db::name('artifacts')->where('id', $row['id'])->update([
                        'tags'       => json_encode($tags, JSON_UNESCAPED_UNICODE),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        $this->audit('cms', 'category_update', '编辑分类「' . ($update['name'] ?? $cat['name']) . '」',
            array_intersect_key($update, array_flip(['name', 'code', 'scene', 'sort_order'])), 'category', $id);
        return $this->success(null, '分类已更新');
    }

    /**
     * DELETE /admin/cms/categories/:id（软删除；被藏品引用的分类不可删）
     */
    public function categoryDelete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $cat = Db::name('categories')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$cat) {
            return $this->fail(4040, '分类不存在');
        }

        $used = Db::name('collectibles')->where('category_id', $id)->whereNull('deleted_at')->count();
        if ($used > 0) {
            return $this->fail(4220, '该分类下还有 ' . $used . ' 个藏品，请先调整藏品分类后再删除');
        }

        Db::name('categories')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('cms', 'category_delete', '删除分类「' . $cat['name'] . '」', [], 'category', $id);
        return $this->success(null, '分类已删除');
    }

    // ============================================================
    // 二、公告管理（cms:announcement）
    // ============================================================

    /**
     * GET /admin/cms/announcements
     */
    public function announcementList()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('announcements')->whereNull('deleted_at');

        $type = $this->enumParam('type', ['notice', 'news']);
        if ($type !== null) {
            $query->where('type', $type);
        }
        // 子分类筛选（管理端类型 = C 端 subtype：activity/compose/operation）
        $subtype = $this->enumParam('subtype', ['activity', 'compose', 'operation']);
        if ($subtype !== null) {
            $query->where('subtype', $subtype);
        }
        // 状态筛选：draft 草稿 / published 已发布（含定时待生效）
        $status = $this->enumParam('status', ['draft', 'published']);
        if ($status !== null) {
            $query->where('status', $status);
        }
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('title', '%' . $keyword . '%');
        }
        if ($this->request->param('is_top') !== null && $this->request->param('is_top') !== '') {
            $query->where('is_top', (int) $this->request->param('is_top') === 1 ? 1 : 0);
        }
        if ($range = $this->dateRange()) {
            $query->whereBetween('created_at', $range);
        }

        $total = (clone $query)->count();
        $items = $query->order('is_top', 'desc')->order('created_at', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * POST /admin/cms/announcements { title, type, content, ... }
     */
    public function announcementCreate()
    {
        $missing = $this->missingParams(['title', 'type', 'content']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $type = (string) $this->request->param('type');
        if (!in_array($type, ['notice', 'news'], true)) {
            return $this->fail(4220, 'type 仅允许 notice/news');
        }
        $title = trim((string) $this->request->param('title'));
        if (mb_strlen($title) < 2 || mb_strlen($title) > 200) {
            return $this->fail(4220, '标题长度需为 2~200 字');
        }

        $now = date('Y-m-d H:i:s');
        $publishTime = $this->publishTimeParam();
        if ($publishTime === false) {
            return $this->fail(4220, '定时发布时间格式须为 Y-m-d H:i:s');
        }
        $id = (int) Db::name('announcements')->insertGetId([
            'title'       => $title,
            'summary'     => $this->request->param('summary') !== null
                ? mb_substr(trim((string) $this->request->param('summary')), 0, 500) : null,
            'content'     => $this->sanitizeRichText((string) $this->request->param('content')),
            'cover_image' => $this->request->param('cover_image') !== null
                ? mb_substr(trim((string) $this->request->param('cover_image')), 0, 255) : null,
            'type'        => $type,
            'subtype'     => $this->enumParam('subtype', ['activity', 'compose', 'operation']),
            'tag_color'   => $this->request->param('tag_color') !== null
                ? mb_substr(trim((string) $this->request->param('tag_color')), 0, 20) : null,
            'status'      => $this->enumParam('status', ['draft', 'published']) ?? 'draft',
            'publish_time' => $publishTime,
            'is_top'      => (int) $this->request->param('is_top', 0) === 1 ? 1 : 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->audit('cms', 'announcement_create', '发布公告「' . $title . '」', ['type' => $type], 'announcement', $id);
        return $this->success(['id' => $id], '公告已保存');
    }

    /**
     * PUT /admin/cms/announcements/:id
     */
    public function announcementUpdate()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $item = Db::name('announcements')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$item) {
            return $this->fail(4040, '公告不存在');
        }

        $update = ['updated_at' => date('Y-m-d H:i:s')];

        if ($this->request->param('title') !== null && $this->request->param('title') !== '') {
            $title = trim((string) $this->request->param('title'));
            if (mb_strlen($title) < 2 || mb_strlen($title) > 200) {
                return $this->fail(4220, '标题长度需为 2~200 字');
            }
            $update['title'] = $title;
        }
        if ($this->request->param('type') !== null) {
            $type = (string) $this->request->param('type');
            if (!in_array($type, ['notice', 'news'], true)) {
                return $this->fail(4220, 'type 仅允许 notice/news');
            }
            $update['type'] = $type;
        }
        if ($this->request->param('content') !== null && $this->request->param('content') !== '') {
            $update['content'] = $this->sanitizeRichText((string) $this->request->param('content'));
        }
        if ($this->request->param('summary') !== null) {
            $update['summary'] = mb_substr(trim((string) $this->request->param('summary')), 0, 500);
        }
        if ($this->request->param('cover_image') !== null) {
            $update['cover_image'] = mb_substr(trim((string) $this->request->param('cover_image')), 0, 255);
        }
        if ($this->request->param('subtype') !== null) {
            $update['subtype'] = $this->enumParam('subtype', ['activity', 'compose', 'operation']);
        }
        if ($this->request->param('tag_color') !== null) {
            $update['tag_color'] = mb_substr(trim((string) $this->request->param('tag_color')), 0, 20);
        }
        if ($this->request->param('status') !== null) {
            $update['status'] = $this->enumParam('status', ['draft', 'published']) ?? 'draft';
        }
        if ($this->request->param('publish_time') !== null) {
            $publishTime = $this->publishTimeParam();
            if ($publishTime === false) {
                return $this->fail(4220, '定时发布时间格式须为 Y-m-d H:i:s');
            }
            $update['publish_time'] = $publishTime;
        }
        if ($this->request->param('is_top') !== null) {
            $update['is_top'] = (int) $this->request->param('is_top') === 1 ? 1 : 0;
        }

        Db::name('announcements')->where('id', $id)->update($update);

        $this->audit('cms', 'announcement_update', '编辑公告「' . ($update['title'] ?? $item['title']) . '」', [], 'announcement', $id);
        return $this->success(null, '公告已更新');
    }

    /**
     * DELETE /admin/cms/announcements/:id（软删除）
     */
    public function announcementDelete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $item = Db::name('announcements')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$item) {
            return $this->fail(4040, '公告不存在');
        }

        Db::name('announcements')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('cms', 'announcement_delete', '删除公告「' . $item['title'] . '」', [], 'announcement', $id);
        return $this->success(null, '公告已删除');
    }

    /**
     * POST /admin/cms/announcements/:id/toggle-top
     */
    public function announcementToggleTop()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $item = Db::name('announcements')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$item) {
            return $this->fail(4040, '公告不存在');
        }

        $newTop = (int) $item['is_top'] === 1 ? 0 : 1;
        Db::name('announcements')->where('id', $id)->update([
            'is_top'     => $newTop,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('cms', 'announcement_toggle_top', ($newTop === 1 ? '置顶' : '取消置顶') . '公告「' . $item['title'] . '」', [], 'announcement', $id);
        return $this->success(['is_top' => $newTop], $newTop === 1 ? '已置顶' : '已取消置顶');
    }

    // ============================================================
    // 三、协议管理（cms:agreement）—— 键值存储于 nft_site_settings
    // ============================================================

    /** 协议键白名单（键名 => 中文名称） */
    private const AGREEMENT_KEYS = [
        'agreement_user'    => '用户服务协议',
        'agreement_privacy' => '隐私政策',
        'agreement_digital' => '数字藏品购买及持有须知',
    ];

    /**
     * GET /admin/cms/agreements
     */
    public function agreementList()
    {
        $rows = Db::name('site_settings')
            ->whereIn('setting_key', array_keys(self::AGREEMENT_KEYS))
            ->select()->toArray();
        $map = array_column($rows, 'setting_value', 'setting_key');

        $list = [];
        foreach (self::AGREEMENT_KEYS as $key => $name) {
            $list[] = [
                'key'         => $key,
                'name'        => $name,
                'content'     => $map[$key] ?? '',
                'updated_at'  => null,
                'exists'      => isset($map[$key]),
            ];
        }

        // 附带更新时间
        $times = array_column($rows, 'updated_at', 'setting_key');
        foreach ($list as &$item) {
            $item['updated_at'] = $times[$item['key']] ?? null;
        }

        return $this->success($list);
    }

    /**
     * PUT /admin/cms/agreements/:key { content }
     */
    public function agreementSave()
    {
        $key = (string) $this->request->param('key');
        if (!isset(self::AGREEMENT_KEYS[$key])) {
            return $this->fail(4220, '不支持的协议键：' . $key . '（允许：' . implode('、', array_keys(self::AGREEMENT_KEYS)) . '）');
        }

        // F7-D3 修复：协议富文本与公告同样消毒（前端以富文本渲染，防止存储型 XSS）
        $content = $this->sanitizeRichText(trim((string) $this->request->param('content', '')));
        if ($content === '') {
            return $this->fail(4220, '协议内容不能为空');
        }
        if (mb_strlen($content) > 100000) {
            return $this->fail(4220, '协议内容过长（上限 10 万字符）');
        }

        $now = date('Y-m-d H:i:s');
        $exists = Db::name('site_settings')->where('setting_key', $key)->find();
        if ($exists) {
            Db::name('site_settings')->where('setting_key', $key)->update([
                'setting_value' => $content,
                'updated_at'    => $now,
            ]);
        } else {
            Db::name('site_settings')->insert([
                'setting_key'   => $key,
                'setting_value' => $content,
                'setting_group' => 'basic',
                'description'   => self::AGREEMENT_KEYS[$key],
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        $this->audit('cms', 'agreement_save', '更新「' . self::AGREEMENT_KEYS[$key] . '」', ['key' => $key]);
        return $this->success(null, '「' . self::AGREEMENT_KEYS[$key] . '」已保存');
    }

    // ============================================================
    // 四、文物展馆（cms:artifact）
    // ============================================================

    /**
     * GET /admin/cms/artifacts
     */
    public function artifactList()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('artifacts')->whereNull('deleted_at');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('dynasty', 'like', '%' . $keyword . '%');
            });
        }
        $dynasty = trim((string) $this->request->param('dynasty', ''));
        if ($dynasty !== '') {
            $query->where('dynasty', $dynasty);
        }
        // 展示状态筛选：1展示中 0已隐藏
        $status = $this->request->param('status');
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status === 1 ? 1 : 0);
        }

        $total = (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * POST /admin/cms/artifacts
     */
    public function artifactCreate()
    {
        // period/story 可选：管理端简表未填时，period 缺省取朝代、story 缺省空串
        $missing = $this->missingParams(['name', 'dynasty', 'image', 'material']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $name    = mb_substr(trim((string) $this->request->param('name')), 0, 100);
        $dynasty = mb_substr(trim((string) $this->request->param('dynasty')), 0, 50);

        $data = [
            'name'       => $name,
            'dynasty'    => $dynasty,
            'image'      => mb_substr(trim((string) $this->request->param('image')), 0, 255),
            'img_height' => max(50, min(2000, (int) $this->request->param('img_height', 150))),
            'material'   => mb_substr(trim((string) $this->request->param('material')), 0, 50),
            'period'     => $this->optStr('period', 100) ?? $dynasty,
            'size'       => $this->optStr('size', 100),
            'origin'     => $this->optStr('origin', 100),
            'museum'     => $this->optStr('museum', 100),
            'level'      => $this->optStr('level', 20),
            'story'      => (string) ($this->request->param('story') ?? ''),
            'status'     => (int) $this->request->param('status', 1) === 0 ? 0 : 1,
        ];

        foreach (['specs', 'tags'] as $jsonField) {
            $raw = $this->request->param($jsonField);
            if ($raw !== null && $raw !== '') {
                $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
                if (!is_array($decoded)) {
                    return $this->fail(4220, $jsonField . ' 需为合法 JSON 数组/对象');
                }
                $data[$jsonField] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        }

        $now = date('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $id = (int) Db::name('artifacts')->insertGetId($data);

        $this->audit('cms', 'artifact_create', '新增文物「' . $data['name'] . '」', [], 'artifact', $id);
        return $this->success(['id' => $id], '文物已创建');
    }

    /**
     * PUT /admin/cms/artifacts/:id
     */
    public function artifactUpdate()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $item = Db::name('artifacts')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$item) {
            return $this->fail(4040, '文物不存在');
        }

        $update = ['updated_at' => date('Y-m-d H:i:s')];
        $strFields = ['name' => 100, 'dynasty' => 50, 'image' => 255, 'material' => 50, 'period' => 100];
        foreach ($strFields as $field => $limit) {
            if ($this->request->param($field) !== null && $this->request->param($field) !== '') {
                $update[$field] = mb_substr(trim((string) $this->request->param($field)), 0, $limit);
            }
        }
        foreach (['size', 'origin', 'museum', 'level'] as $field) {
            if ($this->request->param($field) !== null) {
                $update[$field] = $this->optStr($field, $field === 'level' ? 20 : 100);
            }
        }
        if ($this->request->param('story') !== null && $this->request->param('story') !== '') {
            $update['story'] = (string) $this->request->param('story');
        }
        if ($this->request->param('status') !== null) {
            $update['status'] = (int) $this->request->param('status') === 1 ? 1 : 0;
        }
        if ($this->request->param('img_height') !== null) {
            $update['img_height'] = max(50, min(2000, (int) $this->request->param('img_height')));
        }
        foreach (['specs', 'tags'] as $jsonField) {
            $raw = $this->request->param($jsonField);
            if ($raw !== null && $raw !== '') {
                $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
                if (!is_array($decoded)) {
                    return $this->fail(4220, $jsonField . ' 需为合法 JSON 数组/对象');
                }
                $update[$jsonField] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        }

        Db::name('artifacts')->where('id', $id)->update($update);

        $this->audit('cms', 'artifact_update', '编辑文物「' . ($update['name'] ?? $item['name']) . '」', [], 'artifact', $id);
        return $this->success(null, '文物已更新');
    }

    /**
     * DELETE /admin/cms/artifacts/:id（软删除）
     */
    public function artifactDelete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $item = Db::name('artifacts')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$item) {
            return $this->fail(4040, '文物不存在');
        }

        Db::name('artifacts')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('cms', 'artifact_delete', '删除文物「' . $item['name'] . '」', [], 'artifact', $id);
        return $this->success(null, '文物已删除');
    }

    // ============================================================
    // 五、站点装修（cms:decoration）—— nft_site_settings 分组 KV
    // ============================================================

    /** 站点装修配置键白名单（键 => [分组, 中文名]） */
    private const DECORATION_KEYS = [
        'site_name'       => ['basic', '站点名称'],
        'site_logo'       => ['basic', '站点 Logo'],
        'site_avatar'     => ['basic', '平台头像'],
        'theme_color'     => ['theme', '主题色'],
        'bg_color'        => ['theme', '背景色'],
        'button_color'    => ['button', '按钮色'],
        'button_radius'   => ['button', '按钮圆角(px)'],
        'seo_title'       => ['seo', 'SEO 标题'],
        'seo_description' => ['seo', 'SEO 描述'],
        'seo_keywords'    => ['seo', 'SEO 关键词'],
    ];

    /** 颜色类配置键（HEX 校验） */
    private const COLOR_KEYS = ['theme_color', 'bg_color', 'button_color'];

    /**
     * GET /admin/site-brand（公开）
     * 管理后台登录页/侧边栏品牌展示：站点名 + 头像（C 端装修配置同步）
     */
    public function siteBrand()
    {
        $rows = Db::name('site_settings')
            ->whereIn('setting_key', ['site_name', 'site_logo', 'site_avatar', 'theme_color'])
            ->column('setting_value', 'setting_key');

        return $this->success([
            'siteName'   => $rows['site_name'] ?? '司南珍藏',
            'siteLogo'   => $rows['site_logo'] ?? '',
            'siteAvatar' => $rows['site_avatar'] ?? '',
            'themeColor' => $rows['theme_color'] ?? '',
        ]);
    }

    /**
     * GET /admin/cms/decoration
     */
    public function decorationList()
    {
        $rows = Db::name('site_settings')
            ->whereIn('setting_key', array_keys(self::DECORATION_KEYS))
            ->select()->toArray();
        $map = array_column($rows, 'setting_value', 'setting_key');

        $result = [];
        foreach (self::DECORATION_KEYS as $key => [$group, $name]) {
            $result[] = [
                'key'    => $key,
                'group'  => $group,
                'name'   => $name,
                'value'  => $map[$key] ?? '',
            ];
        }
        return $this->success($result);
    }

    /**
     * POST /admin/cms/decoration { settings: { key: value, ... } }
     * 批量保存（仅白名单键）
     */
    public function decorationSave()
    {
        $settings = $this->request->param('settings');
        if (!is_array($settings) || $settings === []) {
            return $this->fail(4220, '缺少 settings 配置对象');
        }

        $now = date('Y-m-d H:i:s');
        $saved = [];
        foreach ($settings as $key => $value) {
            if (!isset(self::DECORATION_KEYS[$key])) {
                continue; // 静默跳过非白名单键（严谨：不报错也不落库未知键）
            }
            $value = mb_substr(trim((string) $value), 0, 5000);

            // 颜色键校验：空值放行（沿用默认），非空必须为合法 HEX 颜色
            if (in_array($key, self::COLOR_KEYS, true)) {
                if ($value !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                    return $this->fail(4220, self::DECORATION_KEYS[$key][1] . ' 需为 #RRGGBB 格式的颜色值');
                }
            }
            // 按钮圆角：0~24px
            if ($key === 'button_radius' && $value !== '' && (!ctype_digit($value) || (int) $value > 24)) {
                return $this->fail(4220, '按钮圆角需为 0~24 的整数');
            }

            $exists = Db::name('site_settings')->where('setting_key', $key)->find();
            if ($exists) {
                Db::name('site_settings')->where('setting_key', $key)->update([
                    'setting_value' => $value,
                    'updated_at'    => $now,
                ]);
            } else {
                [$group, $name] = self::DECORATION_KEYS[$key];
                Db::name('site_settings')->insert([
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'setting_group' => $group,
                    'description'   => $name,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
            $saved[$key] = $value;
        }

        if ($saved === []) {
            return $this->fail(4220, '无可保存的白名单配置项');
        }

        $this->audit('cms', 'decoration_save', '更新站点装修配置（' . count($saved) . ' 项）', $saved);
        return $this->success(['saved' => array_keys($saved)], '站点配置已保存');
    }

    // ============================================================
    // 官方社群（C 端「社区」页入口）
    // ============================================================

    /**
     * GET /admin/cms/community
     */
    public function communityList()
    {
        $rows = Db::name('community_groups')->whereNull('deleted_at')
            ->order('sort_order', 'asc')->order('id', 'asc')
            ->select()->toArray();

        $result = array_map(function ($row) {
            return [
                'id'          => (int) $row['id'],
                'name'        => $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'icon'        => (string) $row['icon'],
                'qrCode'      => (string) ($row['qr_code'] ?? ''),
                'members'     => (int) $row['members'],
                'sort'        => (int) $row['sort_order'],
                'isActive'    => (int) $row['is_active'],
                'createdAt'   => $row['created_at'],
            ];
        }, $rows);

        return $this->success($result);
    }

    /**
     * POST /admin/cms/community { name, description?, icon, qr_code?, members?, sort?, is_active? }
     */
    public function communityCreate()
    {
        $missing = $this->missingParams(['name', 'icon']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $name = trim((string) $this->request->param('name'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 20) {
            return $this->fail(4220, '社群名称长度需为 2~20 字符');
        }

        $now = date('Y-m-d H:i:s');
        $id = Db::name('community_groups')->insertGetId([
            'name'        => $name,
            'description' => $this->optStr('description', 60),
            'icon'        => mb_substr(trim((string) $this->request->param('icon')), 0, 255),
            'qr_code'     => $this->optStr('qr_code', 255),
            'members'     => max(0, (int) $this->request->param('members', 0)),
            'sort_order'  => max(0, (int) $this->request->param('sort', 1)),
            'is_active'   => (int) $this->request->param('is_active', 1) === 1 ? 1 : 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->audit('cms', 'community_create', '新增官方社群「' . $name . '」', [], 'community', $id);
        return $this->success(['id' => $id], '社群已创建');
    }

    /**
     * PUT /admin/cms/community/:id（软删除数据不可更新）
     */
    public function communityUpdate(int $id)
    {
        $group = Db::name('community_groups')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$group) {
            return $this->fail(4040, '社群不存在');
        }

        $update = ['updated_at' => date('Y-m-d H:i:s')];
        if ($this->request->has('name')) {
            $name = trim((string) $this->request->param('name'));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 20) {
                return $this->fail(4220, '社群名称长度需为 2~20 字符');
            }
            $update['name'] = $name;
        }
        foreach (['description' => 60, 'qr_code' => 255] as $key => $limit) {
            if ($this->request->has($key)) {
                $update[$key] = $this->optStr($key, $limit);
            }
        }
        if ($this->request->has('icon')) {
            $icon = trim((string) $this->request->param('icon'));
            if ($icon === '') {
                return $this->fail(4220, '社群图标不能为空');
            }
            $update['icon'] = mb_substr($icon, 0, 255);
        }
        if ($this->request->has('members')) {
            $update['members'] = max(0, (int) $this->request->param('members'));
        }
        if ($this->request->has('sort')) {
            $update['sort_order'] = max(0, (int) $this->request->param('sort'));
        }
        if ($this->request->has('is_active')) {
            $update['is_active'] = (int) $this->request->param('is_active') === 1 ? 1 : 0;
        }

        Db::name('community_groups')->where('id', $id)->update($update);
        $this->audit('cms', 'community_update', '更新官方社群「' . $group['name'] . '」',
            array_intersect_key($update, array_flip(['name', 'is_active', 'sort_order', 'members'])), 'community', $id);
        return $this->success(null, '社群已更新');
    }

    /**
     * DELETE /admin/cms/community/:id（软删除）
     */
    public function communityDelete(int $id)
    {
        $group = Db::name('community_groups')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$group) {
            return $this->fail(4040, '社群不存在');
        }
        Db::name('community_groups')->where('id', $id)->update([
            'is_active'  => 0,
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('cms', 'community_delete', '删除官方社群「' . $group['name'] . '」', [], 'community', $id);
        return $this->success(null, '社群已删除');
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 可选字符串字段（null 安全 + 长度截断）
     */
    private function optStr(string $key, int $limit): ?string
    {
        $value = $this->request->param($key);
        if ($value === null || $value === '') {
            return null;
        }
        return mb_substr(trim((string) $value), 0, $limit);
    }

    /**
     * 富文本消毒：移除 script/iframe/object/embed 标签与 on* 事件属性、javascript: 协议
     */
    private function sanitizeRichText(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|iframe|object|embed)[^>]*/?>#is', '', $html) ?? $html;
        $html = preg_replace('#\son\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2#i', '$1=$2$2', $html) ?? $html;
        return $html;
    }

    /**
     * 定时发布时间参数：空字符串 → NULL（立即生效）；格式错误返回 false（由调用方 fail）
     */
    private function publishTimeParam()
    {
        $raw = trim((string) $this->request->param('publish_time', ''));
        if ($raw === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $raw);
        if (!$d || $d->format('Y-m-d H:i:s') !== $raw) {
            return false;
        }
        return $raw;
    }
}
