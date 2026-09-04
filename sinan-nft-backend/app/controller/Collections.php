<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 藏品控制器
 * 分类列表 / 首页发售 / 藏品详情 / 市场列表 / 关注
 */
class Collections extends BaseController
{
    /**
     * GET /api/collections/categories
     * 藏品分类列表
     */
    public function categories()
    {
        $list = Db::name('categories')
            ->whereNull('deleted_at')
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();

        // 前端约定追加一个"全部"选项
        $all = ['id' => 0, 'name' => '全部', 'code' => 'all'];
        return $this->success([$all, ...array_map('camelize_keys', $list)]);
    }

    /**
     * GET /api/collections/featured
     * 首页发售藏品列表
     */
    public function featured()
    {
        $p = $this->pagination();

        $query = Db::name('collectibles')
            ->alias('c')
            ->leftJoin('blind_boxes bb', 'bb.collectible_id = c.id')
            ->whereNull('c.deleted_at')
            ->where('c.is_release', 1)
            ->where('c.status', '<>', 'soldout')
            ->order('c.onsale_at', 'desc');

        $total = $query->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->select()->toArray();

        $items = array_map(function ($c) {
            $sold     = (int) ($c['sold'] ?? 0);
            $locked   = (int) ($c['locked_quantity'] ?? 0);
            $edition  = (int) ($c['edition'] ?? 0);
            $isBlindBox = !empty($c['bb_collectible_id']);
            return [
                'id'        => (int) $c['id'],
                'name'      => $c['name'],
                'subtitle'  => $c['subtitle'],
                'tag'       => $c['tag'],
                'price'     => (float) $c['price'],
                'edition'   => $edition,
                'image'     => $c['image'],
                'gradient'  => $c['gradient'],
                'saleTime'      => $c['onsale_at'],
                'saleEndTime'   => $c['off_sale_at'],
                'status'    => $c['status'],
                'stock'     => max(0, $edition - $sold - $locked),
                'isBlindBox' => $isBlindBox,
            ];
        }, $list);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * GET /api/collections/:id
     * 藏品详情
     */
    public function detail()
    {
        $id = $this->intParam('id');
        $c  = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$c) return $this->fail(1002, '藏品不存在');

        $category = Db::name('categories')->where('id', $c['category_id'])->find();
        $isBlindBox = (bool) Db::name('blind_boxes')->where('collectible_id', $id)->count();

        // 我的已持有数量
        $myOwned = 0;
        $userId  = $this->userId();
        if ($userId) {
            $myOwned = Db::name('user_collectibles')
                ->where('user_id', $userId)
                ->where('collectible_id', $id)
                ->where('status', '<>', 'consumed')
                ->count();
        }

        // 系统配置：限购数
        $saleLimit = (int) Db::name('system_configs')
            ->where('config_key', 'purchase_limit_per_user')
            ->value('config_value');

        return $this->success([
            'id'               => (int) $c['id'],
            'name'             => $c['name'],
            'subtitle'         => $c['subtitle'],
            'tag'              => $c['tag'],
            'price'            => (float) $c['price'],
            'edition'          => (int) $c['edition'],
            'image'            => $c['image'],
            'gradient'         => $c['gradient'],
            'issuer'           => $c['issuer'],
            'creator'          => $c['creator'],
            'brand'            => $c['brand'],
            'album'            => $c['album'],
            'issueCount'       => (int) $c['edition'],
            'circulationCount' => (int) $c['circulate'],
            'todayCount'       => (int) $c['vol'],
            'status'           => $c['status'],
            'saleTime'         => $c['onsale_at'],
            'saleEndTime'      => $c['off_sale_at'],
            'description'      => $c['description'],
            'category'         => [
                'id'   => (int) ($category['id'] ?? 0),
                'name' => $category['name'] ?? '',
                'code' => $category['code'] ?? '',
            ],
            'isBlindBox' => $isBlindBox,
            'saleLimit'  => $saleLimit ?: 5,
            'myOwned'    => $myOwned,
        ]);
    }

    /**
     * GET /api/market/collections
     * 市场藏品列表（聚合挂单最低价）
     */
    public function market()
    {
        $p        = $this->pagination();
        $category = $this->strParam('category', 'all');
        $keyword  = $this->strParam('keyword');
        $sort     = $this->strParam('sort', 'price-asc');

        // 聚合挂单：每个藏品的最低价 + 挂单数
        $minPriceSql = Db::name('resale_listings')
            ->where('status', 'selling')
            ->field('collectible_id, MIN(price) as min_price, COUNT(*) as orders_count')
            ->group('collectible_id')
            ->buildSql();

        $query = Db::name('collectibles')->alias('c')
            ->leftJoin([$minPriceSql => 'mp'], 'mp.collectible_id = c.id')
            ->whereNull('c.deleted_at');

        if ($category && $category !== 'all') {
            $query->where('c.category_id', function ($sub) use ($category) {
                $sub->name('categories')->where('code', $category)->value('id');
            });
        }
        if ($keyword) {
            $query->where('c.name', 'like', "%{$keyword}%");
        }

        // 只展示有在售挂单的藏品
        $query->where('mp.orders_count', '>', 0);

        // 排序
        switch ($sort) {
            case 'price-asc':
                $query->order('mp.min_price', 'asc');
                break;
            case 'price-desc':
                $query->order('mp.min_price', 'desc');
                break;
            case 'time-desc':
                $query->order('c.created_at', 'desc');
                break;
            default:
                $query->order('mp.min_price', 'asc');
        }

        $total = $query->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->select()->toArray();

        $userId  = $this->userId();
        $favs    = $userId ? Db::name('user_favorites')->where('user_id', $userId)->column('collectible_id') : [];

        $items = array_map(function ($c) use ($favs) {
            return [
                'id'               => (int) $c['id'],
                'name'             => $c['name'],
                'image'            => $c['image'],
                'price'            => (float) ($c['min_price'] ?? 0),
                'issueCount'       => (int) $c['edition'],
                'circulationCount' => (int) $c['circulate'],
                'todayCount'       => (int) $c['vol'],
                'ordersCount'      => (int) ($c['orders_count'] ?? 0),
                'isFavorite'       => in_array($c['id'], $favs),
            ];
        }, $list);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * GET /api/user/collections
     * 我的藏品库存
     */
    public function mine()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $list = Db::name('user_collectibles')
            ->alias('uc')
            ->join('collectibles c', 'c.id = uc.collectible_id')
            ->where('uc.user_id', $userId)
            ->where('uc.status', '<>', 'consumed')
            ->order('uc.created_at', 'desc')
            ->field([
                'uc.id as user_collectible_id',
                'c.id as collectible_id',
                'c.name',
                'c.image',
                'uc.serial',
                'uc.source',
                'uc.acquired_price',
                'uc.status',
                'uc.is_consigned',
                'uc.acquired_at',
            ])
            ->select()
            ->toArray();

        // 按 collectible_id 聚合
        $grouped = [];
        foreach ($list as $row) {
            $cid = $row['collectible_id'];
            if (!isset($grouped[$cid])) {
                $grouped[$cid] = [
                    'id'             => (int) $cid,
                    'userCollectibleIds' => [],
                    'name'           => $row['name'],
                    'image'          => $row['image'],
                    'price'          => (float) $row['acquired_price'],
                    'qty'            => 0,
                    'nos'            => [],
                    'type'           => $row['source'],
                    'isConsigned'    => false,
                    'acquiredAt'     => null,
                ];
            }
            $grouped[$cid]['qty']++;
            if (count($grouped[$cid]['nos']) < 5) {
                $grouped[$cid]['nos'][] = $row['serial'];
            }
            if ((int) $row['is_consigned'] === 1) {
                $grouped[$cid]['isConsigned'] = true;
            }
            if ($grouped[$cid]['acquiredAt'] === null || $row['acquired_at'] > $grouped[$cid]['acquiredAt']) {
                $grouped[$cid]['acquiredAt'] = $row['acquired_at'];
            }
        }

        return $this->success(array_values($grouped));
    }

    /**
     * POST /api/collections/:id/favorite
     * 关注/取消关注藏品
     */
    public function favorite()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $collectibleId = $this->intParam('id');
        $favorite      = (bool) $this->request->post('favorite', true);

        if ($collectibleId <= 0) return $this->fail(1001, '参数错误');

        if ($favorite) {
            // 幂等：已收藏直接返回；并发点击撞唯一索引时也视为已收藏
            $exists = Db::name('user_favorites')
                ->where('user_id', $userId)
                ->where('collectible_id', $collectibleId)
                ->find();
            if (!$exists) {
                try {
                    Db::name('user_favorites')->insert([
                        'user_id'        => $userId,
                        'collectible_id' => $collectibleId,
                        'created_at'     => date('Y-m-d H:i:s.v'),
                    ]);
                } catch (\Throwable $e) {
                    // uk_user_collectible 唯一索引兜底，忽略并发重复插入
                }
            }
        } else {
            Db::name('user_favorites')
                ->where('user_id', $userId)
                ->where('collectible_id', $collectibleId)
                ->delete();
        }

        return $this->success(['isFavorite' => $favorite]);
    }

    /**
     * GET /api/user/favorites
     * 我关注的藏品
     */
    public function favorites()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $p = $this->pagination();
        $query = Db::name('user_favorites')->alias('f')
            ->join('collectibles c', 'c.id = f.collectible_id')
            ->where('f.user_id', $userId)
            ->whereNull('c.deleted_at')
            ->order('f.created_at', 'desc');

        $total = $query->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->field([
            'c.id', 'c.name', 'c.image', 'c.price', 'c.edition', 'c.circulate',
        ])->select()->toArray();

        $items = array_map(fn ($c) => [
            'id'               => (int) $c['id'],
            'name'             => $c['name'],
            'image'            => $c['image'],
            'price'            => (float) $c['price'],
            'issueCount'       => (int) $c['edition'],
            'circulationCount' => (int) $c['circulate'],
        ], $list);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }
}
