<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AdminLogService;
use think\facade\Db;

/**
 * 管理后台藏品管理控制器
 *
 * 覆盖：列表/详情/创建/编辑/发售配置/配额管理/强制售罄下架/
 * 销毁库存/删除/独立空投/寄售管控/资格购配置/库存审计。
 *
 * 库存恒等式（审计核心）：
 *   发行总量 edition = 库存池 + 待支付锁定 + 已配置配额 + 已售出 + 已独立空投 + 已销毁
 *   库存池 = edition - locked_quantity - reserved_count - sold - airdropped_count - destroyed_count ≥ 0
 */
class CollectibleController extends BaseController
{
    /**
     * GET /admin/collectible/list
     * 筛选：keyword(名称)、categoryId、status、isRelease、时间区间
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('collectibles')->alias('c')->whereNull('c.deleted_at');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('c.name', "%{$keyword}%");
        }
        $categoryId = $this->positiveInt('categoryId');
        if ($categoryId !== null) {
            $query->where('c.category_id', $categoryId);
        }
        $status = (string) $this->request->param('status', '');
        if ($status !== '' && in_array($status, ['upcoming', 'onsale', 'soldout', 'off'], true)) {
            $query->where('c.status', $status);
        }
        $isBlindBox = $this->request->param('isBlindBox');
        if ($isBlindBox !== null && $isBlindBox !== '') {
            if ((int) $isBlindBox === 1) {
                $query->join('blind_boxes bb', 'bb.collectible_id = c.id', 'INNER');
            } else {
                $query->whereNotExists(function ($q) {
                    $q->name('blind_boxes')->whereRaw('nft_blind_boxes.collectible_id = c.id');
                });
            }
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('c.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('c.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('c.id, c.name, c.subtitle, c.image, c.category_id, cat.name AS category_name,
                               c.price, c.edition, c.circulate, c.sold, c.locked_quantity,
                               c.airdropped_count, c.destroyed_count, c.reserved_count,
                               c.status, c.is_release, c.featured, c.onsale_at, c.off_sale_at, c.created_at,
                               (bb.id IS NOT NULL) AS is_blind_box')
            ->join('categories cat', 'cat.id = c.category_id', 'LEFT')
            ->join('blind_boxes bb', 'bb.collectible_id = c.id', 'LEFT')
            ->order('c.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        foreach ($rows as &$row) {
            $row['available_pool'] = max(0, (int) $row['edition'] - (int) $row['sold']
                - (int) $row['locked_quantity'] - (int) $row['reserved_count']
                - (int) $row['airdropped_count'] - (int) $row['destroyed_count']);
            $row['is_blind_box'] = $row['is_blind_box'] ? 1 : 0;
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/collectible/detail/:id
     * 全量字段 + 类目 + 盲盒配置 + 配额列表 + 销毁记录 + 库存审计
     */
    public function detail(int $id)
    {
        $c = Db::name('collectibles')->alias('c')
            ->field('c.*, cat.name AS category_name')
            ->join('categories cat', 'cat.id = c.category_id', 'LEFT')
            ->where('c.id', $id)
            ->whereNull('c.deleted_at')
            ->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }

        $blindBox = Db::name('blind_boxes')->where('collectible_id', $id)->find();
        if ($blindBox) {
            $items = Db::name('blind_box_items')->alias('bbi')
                ->field('bbi.*, c2.name AS prize_name, c2.image AS prize_image, c2.price AS prize_price')
                ->join('collectibles c2', 'c2.id = bbi.prize_collectible_id', 'LEFT')
                ->where('bbi.blind_box_id', $blindBox['id'])
                ->whereNull('bbi.deleted_at')
                ->order('bbi.probability', 'desc')
                ->select()->toArray();
            $blindBox['items'] = $items;
        }

        $quotas = Db::name('inventory_quotas')->where('collectible_id', $id)->where('status', 1)->select()->toArray();
        $destroys = Db::name('destroy_records')->where('target_type', 1)->where('target_id', $id)->order('id', 'desc')->limit(20)->select()->toArray();
        $qual = Db::name('qualification_configs')->where('collectible_id', $id)->find();

        $audit = $this->auditCollectible($id);

        $data = camelize_keys($c);
        $data['categoryName'] = $c['category_name'];
        $data['blindBox']     = $blindBox ? camelize_keys($blindBox) : null;
        $data['quotas']      = camelize_keys($quotas);
        $data['destroyRecords'] = camelize_keys($destroys);
        $data['qualification']  = $qual ? camelize_keys($qual) : null;
        $data['inventoryAudit'] = $audit;

        $this->audit('collectible', 'view_detail', '查看藏品详情「' . $c['name'] . '」', [], 'collectible', $id);

        return $this->success($data);
    }

    /**
     * 库存审计（恒等式校验 + 持仓对账）
     */
    private function auditCollectible(int $id): array
    {
        $c = Db::name('collectibles')->where('id', $id)->find();
        if (!$c) {
            return ['ok' => false, 'message' => '藏品不存在'];
        }

        $edition    = (int) $c['edition'];
        $sold       = (int) $c['sold'];
        $locked     = (int) $c['locked_quantity'];
        $reserved   = (int) $c['reserved_count'];
        $airdropped = (int) $c['airdropped_count'];
        $destroyed  = (int) $c['destroyed_count'];
        $pool       = $edition - $sold - $locked - $reserved - $airdropped - $destroyed;

        // 恒等式：edition = pool + locked + reserved + sold + airdropped + destroyed
        $identityOk = ($pool + $locked + $reserved + $sold + $airdropped + $destroyed) === $edition;

        // 持仓对账：circulate 与实际活跃持仓（held/consigned/frozen）对比
        $activeHold = (int) Db::name('user_collectibles')
            ->where('collectible_id', $id)
            ->whereIn('status', ['held', 'consigned', 'frozen'])
            ->count();
        $consumed = (int) Db::name('user_collectibles')
            ->where('collectible_id', $id)
            ->where('status', 'consumed')
            ->count();
        $recovered = (int) Db::name('user_collectibles')
            ->where('collectible_id', $id)
            ->where('status', 'recovered')
            ->count();

        $circulate   = (int) $c['circulate'];
        $expected    = $sold + $airdropped - $recovered;
        $holdingGap  = $circulate - $activeHold - $consumed;

        return [
            'edition'     => $edition,
            'sold'         => $sold,
            'locked'       => $locked,
            'reserved'     => $reserved,
            'airdropped'   => $airdropped,
            'destroyed'    => $destroyed,
            'pool'         => $pool,
            'identityOk'   => $identityOk && $pool >= 0,
            'identityDesc' => $identityOk && $pool >= 0
                ? '库存恒等式成立：发行总量 = 库存池 ' . $pool . ' + 待支付锁定 ' . $locked . ' + 已配置配额 ' . $reserved . ' + 已售出 ' . $sold . ' + 已空投 ' . $airdropped . ' + 已销毁 ' . $destroyed
                : '库存恒等式异常：发行 ' . $edition . ' ≠ 各分项之和（池 ' . $pool . '，锁定 ' . $locked . '，配额 ' . $reserved . '，售出 ' . $sold . '，空投 ' . $airdropped . '，销毁 ' . $destroyed . '）',
            'circulate'    => $circulate,
            'expectedCirculate' => $expected,
            'circulateOk'  => $circulate === $expected,
            'activeHold'   => $activeHold,
            'consumed'     => $consumed,
            'recovered'    => $recovered,
            'holdingGap'   => $holdingGap,
            'holdingOk'    => $holdingGap === 0,
            'holdingDesc'  => $holdingGap === 0
                ? '流通量与实际持仓一致（活跃持仓 ' . $activeHold . ' + 已消耗 ' . $consumed . '）'
                : '流通量与实际持仓存在差异：流通 ' . $circulate . '，实际（活跃 ' . $activeHold . ' + 消耗 ' . $consumed . '），差 ' . $holdingGap,
        ];
    }

    /**
     * POST /admin/collectible/create
     */
    public function create()
    {
        $missing = $this->missingParams(['name', 'category_id', 'image', 'price', 'edition']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $name       = trim((string) $this->request->param('name'));
        $categoryId = (int) $this->request->param('category_id');
        $price      = (float) $this->request->param('price');
        $edition    = (int) $this->request->param('edition');

        if (mb_strlen($name) > 100) {
            return $this->fail(4220, '藏品名称不能超过 100 字');
        }
        if ($price < 0 || $price > 9999999.99) {
            return $this->fail(4220, '价格区间不合法');
        }
        if ($edition < 1 || $edition > 100000) {
            return $this->fail(4220, '发行总量需为 1~100000');
        }
        if (!Db::name('categories')->where('id', $categoryId)->whereNull('deleted_at')->find()) {
            return $this->fail(4040, '类目不存在');
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'category_id'    => $categoryId,
            'name'           => $name,
            'subtitle'       => mb_substr(trim((string) $this->request->param('subtitle', '')), 0, 100) ?: null,
            'image'          => trim((string) $this->request->param('image')),
            'gradient'       => trim((string) $this->request->param('gradient', '')) ?: null,
            'icon'           => trim((string) $this->request->param('icon', '')) ?: null,
            'price'          => $price,
            'edition'        => $edition,
            'per_user_limit' => max(0, (int) $this->request->param('per_user_limit', 0)),
            'is_transferable' => (int) $this->request->param('is_transferable', 1) === 1 ? 1 : 0,
            'is_resaleable'  => (int) $this->request->param('is_resaleable', 1) === 1 ? 1 : 0,
            'resale_price_mode' => (int) $this->request->param('resale_price_mode', 0),
            'resale_price_min' => $this->optionalPrice('resale_price_min'),
            'resale_price_max' => $this->optionalPrice('resale_price_max'),
            'status'         => 'upcoming',
            'issuer'         => mb_substr(trim((string) $this->request->param('issuer', '')), 0, 50) ?: null,
            'creator'        => mb_substr(trim((string) $this->request->param('creator', '')), 0, 50) ?: null,
            'brand'          => mb_substr(trim((string) $this->request->param('brand', '')), 0, 50) ?: null,
            'contract'       => mb_substr(trim((string) $this->request->param('contract', '')), 0, 100) ?: null,
            'chain_type'     => mb_substr(trim((string) $this->request->param('chain_type', '')), 0, 20) ?: null,
            'token_standard' => mb_substr(trim((string) $this->request->param('token_standard', '')), 0, 20) ?: null,
            'release_date'   => $this->optionalDate('release_date'),
            'tag'            => mb_substr(trim((string) $this->request->param('tag', '')), 0, 50) ?: null,
            'featured'       => (int) $this->request->param('featured', 0) === 1 ? 1 : 0,
            'description'    => (string) $this->request->param('description', '') ?: null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        // 寄售价格管控一致性
        $mode = (int) $data['resale_price_mode'];
        if (!in_array($mode, [0, 1, 2], true)) {
            return $this->fail(4220, 'resale_price_mode 仅允许 0（不限价）/1（固定价）/2（区间价）');
        }
        if ($mode === 1 && $data['resale_price_min'] === null) {
            return $this->fail(4220, '固定价模式需提供 resale_price_min');
        }
        if ($mode === 2 && ($data['resale_price_min'] === null || $data['resale_price_max'] === null)) {
            return $this->fail(4220, '区间价模式需提供 resale_price_min 与 resale_price_max');
        }
        if ($mode === 2 && (float) $data['resale_price_min'] > (float) $data['resale_price_max']) {
            return $this->fail(4220, '寄售价格区间下限不能大于上限');
        }

        Db::startTrans();
        try {
            $id = (int) Db::name('collectibles')->insertGetId($data);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '创建失败：' . $e->getMessage());
        }

        $this->audit('collectible', 'create', '创建藏品「' . $name . '」（发行 ' . $edition . ' 份）', $data, 'collectible', $id);
        return $this->success(['id' => $id], '藏品创建成功（当前为待发售状态）');
    }

    /**
     * POST /admin/collectible/update { id, ...可变字段 }
     * 保护字段：edition（发行总量不可变，防止打破库存恒等式）、sold/circulate 等统计字段
     */
    public function update()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $c = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }
        // 已有流通的藏品不允许修改价格（价格管控合规）
        if ((int) $c['sold'] > 0 && $this->request->param('price') !== null
            && (float) $this->request->param('price') !== (float) $c['price']) {
            return $this->fail(4220, '藏品已有售出记录，禁止修改价格（当前 ' . $c['price'] . '）');
        }

        $allowFields = [
            'name' => fn ($v) => mb_substr(trim((string) $v), 0, 100),
            'subtitle' => fn ($v) => mb_substr(trim((string) $v), 0, 100),
            'category_id' => fn ($v) => (int) $v,
            'image' => fn ($v) => trim((string) $v),
            'gradient' => fn ($v) => trim((string) $v),
            'icon' => fn ($v) => trim((string) $v),
            'price' => fn ($v) => (float) $v,
            'per_user_limit' => fn ($v) => max(0, (int) $v),
            'is_transferable' => fn ($v) => (int) $v === 1 ? 1 : 0,
            'is_resaleable' => fn ($v) => (int) $v === 1 ? 1 : 0,
            'resale_price_mode' => fn ($v) => (int) $v,
            'resale_price_min' => fn ($v) => $v === null ? null : (float) $v,
            'resale_price_max' => fn ($v) => $v === null ? null : (float) $v,
            'issuer' => fn ($v) => trim((string) $v),
            'creator' => fn ($v) => trim((string) $v),
            'brand' => fn ($v) => trim((string) $v),
            'album' => fn ($v) => trim((string) $v),
            'contract' => fn ($v) => trim((string) $v),
            'chain_type' => fn ($v) => trim((string) $v),
            'token_standard' => fn ($v) => trim((string) $v),
            'release_date' => fn ($v) => $v,
            'tag' => fn ($v) => trim((string) $v),
            'featured' => fn ($v) => (int) $v === 1 ? 1 : 0,
            'description' => fn ($v) => (string) $v,
        ];

        $update = [];
        $params = $this->request->param();
        foreach ($allowFields as $field => $cast) {
            if (array_key_exists($field, $params)) {
                $value = $cast($params[$field]);
                $update[$field] = ($value === '' && in_array($field, ['subtitle', 'gradient', 'icon', 'issuer', 'creator', 'brand', 'album', 'contract', 'chain_type', 'token_standard', 'release_date', 'tag'], true)) ? null : $value;
            }
        }
        if (!$update) {
            return $this->fail(4220, '没有需要更新的字段');
        }

        // 区间价校验
        $min = $update['resale_price_min'] ?? $c['resale_price_min'];
        $max = $update['resale_price_max'] ?? $c['resale_price_max'];
        $mode = (int) ($update['resale_price_mode'] ?? $c['resale_price_mode']);
        if ($mode === 2 && $min !== null && $max !== null && (float) $min > (float) $max) {
            return $this->fail(4220, '寄售价格区间下限不能大于上限');
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        Db::name('collectibles')->where('id', $id)->update($update);

        $this->audit('collectible', 'update', '编辑藏品「' . $c['name'] . '」', $update, 'collectible', $id);
        return $this->success(null, '藏品信息已更新');
    }

    /**
     * POST /admin/collectible/release { id, status, onsale_at?, off_sale_at? }
     * 发售配置：上架（upcoming→onsale）/重新上架/调整开售时间
     */
    public function release()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $c = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }

        $status = (string) $this->request->param('status', 'onsale');
        if (!in_array($status, ['upcoming', 'onsale'], true)) {
            return $this->fail(4220, '发售状态仅允许 upcoming / onsale');
        }
        if ($status === 'onsale') {
            $pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity']
                  - (int) $c['reserved_count'] - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];
            if ($pool <= 0) {
                return $this->fail(4220, '库存池为空，无法上架发售（需先调整库存）');
            }
        }

        $update = [
            'status'      => $status,
            'is_release'  => 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        foreach (['onsale_at', 'off_sale_at', 'release_date'] as $field) {
            $value = $this->optionalDate($field);
            if ($value !== null) {
                $update[$field] = $value;
            }
        }
        if ($status === 'onsale' && empty($update['onsale_at'])) {
            $update['onsale_at'] = date('Y-m-d H:i:s');
        }

        Db::name('collectibles')->where('id', $id)->update($update);

        $this->audit('collectible', 'release', '发售配置「' . $c['name'] . '」→ ' . $status, $update, 'collectible', $id);
        return $this->success(null, '发售配置已生效');
    }

    /**
     * POST /admin/collectible/quota { collectible_id, quota_type, quota_name, planned_quantity, activity_id?, remark? }
     * 配额管理：预留库存（reserved_count 同步维护恒等式）
     */
    public function quota()
    {
        $missing = $this->missingParams(['collectible_id', 'quota_type', 'quota_name', 'planned_quantity']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $collectibleId = (int) $this->request->param('collectible_id');
        $quotaType     = (int) $this->request->param('quota_type');
        $quotaName     = trim((string) $this->request->param('quota_name'));
        $planned       = (int) $this->request->param('planned_quantity');
        $quotaId       = $this->positiveInt('quota_id');

        $c = Db::name('collectibles')->where('id', $collectibleId)->whereNull('deleted_at')->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }
        if ($planned < 1 || $planned > 100000) {
            return $this->fail(4220, '配额数量需为 1~100000');
        }

        $now = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            if ($quotaId !== null) {
                // 更新配额：调整 reserved_count 差额
                $old = Db::name('inventory_quotas')->where('id', $quotaId)->where('collectible_id', $collectibleId)->find();
                if (!$old) {
                    throw new \Exception('配额不存在');
                }
                if ((int) $old['used_quantity'] > $planned) {
                    throw new \Exception('新配额数量不能小于已使用数量（' . $old['used_quantity'] . '）');
                }
                $delta = $planned - (int) $old['planned_quantity'];
                if ($delta > 0) {
                    $this->assertPoolEnough($c, $delta);
                }
                Db::name('inventory_quotas')->where('id', $quotaId)->update([
                    'quota_type' => $quotaType,
                    'quota_name' => mb_substr($quotaName, 0, 100),
                    'planned_quantity' => $planned,
                    'activity_id' => $this->positiveInt('activity_id'),
                    'activity_type' => trim((string) $this->request->param('activity_type', '')) ?: null,
                    'remark' => mb_substr(trim((string) $this->request->param('remark', '')), 0, 255) ?: null,
                    'status' => (int) $this->request->param('status', 1),
                    'updated_at' => $now,
                ]);
                if ($delta !== 0) {
                    Db::name('collectibles')->where('id', $collectibleId)
                        ->update(['reserved_count' => Db::raw('GREATEST(0, reserved_count + (' . $delta . '))')]);
                }
                $action = 'update';
            } else {
                // 新增配额
                $this->assertPoolEnough($c, $planned);
                Db::name('inventory_quotas')->insert([
                    'collectible_id' => $collectibleId,
                    'quota_type'     => $quotaType,
                    'quota_name'     => mb_substr($quotaName, 0, 100),
                    'planned_quantity' => $planned,
                    'used_quantity' => 0,
                    'status'         => 1,
                    'activity_id'    => $this->positiveInt('activity_id'),
                    'activity_type'  => trim((string) $this->request->param('activity_type', '')) ?: null,
                    'remark'         => mb_substr(trim((string) $this->request->param('remark', '')), 0, 255) ?: null,
                    'created_by'     => $this->adminId(),
                    'created_by_name' => $this->adminName(),
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                Db::name('collectibles')->where('id', $collectibleId)->inc('reserved_count', $planned)->update();
                $action = 'create';
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '配额操作失败：' . $e->getMessage());
        }

        $this->audit('collectible', 'quota_' . $action,
            '配置配额「' . $quotaName . '」' . $planned . ' 份（藏品「' . $c['name'] . '」）',
            ['planned' => $planned], 'collectible', $collectibleId);

        return $this->success(null, '配额已保存');
    }

    /**
     * 校验库存池是否足够分配
     */
    private function assertPoolEnough(array $c, int $need): void
    {
        $pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity']
              - (int) $c['reserved_count'] - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];
        if ($pool < $need) {
            throw new \Exception('库存池不足：当前可分配 ' . $pool . ' 份，需要 ' . $need . ' 份');
        }
    }

    /**
     * POST /admin/collectible/manage { id, action(soldout/off) }
     * 强制售罄 / 强制下架
     */
    public function manage()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $action = (string) $this->request->param('action', '');
        if (!in_array($action, ['soldout', 'off'], true)) {
            return $this->fail(4220, 'action 仅允许 soldout / off');
        }
        $c = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }

        $now = date('Y-m-d H:i:s');
        Db::name('collectibles')->where('id', $id)->update([
            'status'     => $action === 'soldout' ? 'soldout' : 'off',
            'off_sale_at' => $action === 'off' ? $now : $c['off_sale_at'],
            'updated_at' => $now,
        ]);

        $this->audit('collectible', 'manage_' . $action,
            ($action === 'soldout' ? '强制售罄' : '强制下架') . '藏品「' . $c['name'] . '」', [], 'collectible', $id);
        return $this->success(null, $action === 'soldout' ? '已标记为售罄' : '已强制下架');
    }

    /**
     * POST /admin/collectible/destroy { id, quantity, reason }
     * 销毁库存：仅可销毁库存池中的份额（不可触碰已售/锁定/配额）
     */
    public function destroy()
    {
        $missing = $this->missingParams(['id', 'quantity', 'reason']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $id       = (int) $this->request->param('id');
        $quantity = (int) $this->request->param('quantity');
        $reason   = trim((string) $this->request->param('reason'));

        if ($quantity < 1) {
            return $this->fail(4220, '销毁数量至少为 1');
        }
        $c = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }
        $pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity']
              - (int) $c['reserved_count'] - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];
        if ($pool < $quantity) {
            return $this->fail(4220, '可销毁库存池仅剩 ' . $pool . ' 份，无法销毁 ' . $quantity . ' 份');
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            Db::name('collectibles')->where('id', $id)
                ->whereRaw('edition - sold - locked_quantity - reserved_count - airdropped_count - destroyed_count >= ' . $quantity)
                ->update(['destroyed_count' => Db::raw('destroyed_count + ' . $quantity), 'updated_at' => $now]);

            Db::name('destroy_records')->insert([
                'target_type' => 1, // 1=藏品
                'target_id'   => $id,
                'target_name' => $c['name'],
                'quantity'    => $quantity,
                'reason'      => mb_substr($reason, 0, 255),
                'admin_id'    => $this->adminId(),
                'admin_name'  => $this->adminName(),
                'ip'          => (string) $this->request->ip(),
                'created_at'  => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '销毁失败：' . $e->getMessage());
        }

        $this->audit('collectible', 'destroy', '销毁库存「' . $c['name'] . '」' . $quantity . ' 份',
            ['quantity' => $quantity, 'reason' => $reason], 'collectible', $id);
        return $this->success(null, '已销毁 ' . $quantity . ' 份库存（剩余库存池 ' . ($pool - $quantity) . ' 份）');
    }

    /**
     * POST /admin/collectible/delete { id }
     * 软删除：仅允许无售出、无空投、无持仓的藏品
     */
    public function delete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $c = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }
        if ((int) $c['sold'] > 0 || (int) $c['airdropped_count'] > 0 || (int) $c['circulate'] > 0) {
            return $this->fail(4220, '藏品已有流通记录（售出/空投/流通 > 0），禁止删除');
        }

        Db::name('collectibles')->where('id', $id)->update([
            'status'     => 'off',
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('collectible', 'delete', '删除藏品「' . $c['name'] . '」', [], 'collectible', $id);
        return $this->success(null, '藏品已删除（软删除，数据保留可追溯）');
    }

    /**
     * POST /admin/collectible/airdrop { id, users(user_id数组), reason }
     * 独立空投：向指定用户发放藏品（写空投任务 + 记录 + 生成持仓）
     */
    public function airdrop()
    {
        $missing = $this->missingParams(['id', 'reason']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $id     = (int) $this->request->param('id');
        $reason = trim((string) $this->request->param('reason'));
        $users  = $this->request->param('users', []);

        if (!is_array($users) || count($users) < 1) {
            return $this->fail(4220, '请提供至少一位目标用户');
        }
        if (count($users) > 500) {
            return $this->fail(4220, '单次空投最多 500 位用户');
        }
        $userIds = array_values(array_unique(array_map('intval', array_filter($users, 'is_numeric'))));
        if (!$userIds) {
            return $this->fail(4220, '用户ID列表格式不正确');
        }

        $c = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }

        // 用户有效性校验（存在、未删除、非黑名单）
        $validUsers = Db::name('users')->whereIn('id', $userIds)->whereNull('deleted_at')
            ->where('is_blacklisted', 0)->column('id, phone, uid');
        $validIds = array_map('intval', array_keys($validUsers));
        $invalidCount = count($userIds) - count($validIds);
        if (!$validIds) {
            return $this->fail(4220, '目标用户全部无效（不存在/已删除/黑名单）');
        }

        $pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity']
              - (int) $c['reserved_count'] - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];
        $need = count($validIds);
        if ($pool < $need) {
            return $this->fail(4220, '库存池不足：可空投 ' . $pool . ' 份，需要 ' . $need . ' 份');
        }

        $now   = date('Y-m-d H:i:s');
        $taskNo = 'AD' . date('ymdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

        Db::startTrans();
        try {
            $taskId = (int) Db::name('airdrop_tasks')->insertGetId([
                'task_no'        => $taskNo,
                'target_type'    => 1,
                'target_id'      => $id,
                'target_name'    => $c['name'],
                'total_quantity' => $need,
                'user_count'     => $need,
                'admin_id'       => $this->adminId(),
                'admin_name'     => $this->adminName(),
                'ip'             => (string) $this->request->ip(),
                'created_at'     => $now,
            ]);

            $success = 0;
            $failList = [];
            foreach ($validIds as $userId) {
                try {
                    // 占位插入 + 回写编号（与 C 端一致，避免并发唯一索引冲突）
                    $ucid = (int) Db::name('user_collectibles')->insertGetId([
                        'user_id'         => $userId,
                        'collectible_id'  => $id,
                        'airdrop_record_id' => null,
                        'serial'          => gen_serial_placeholder(),
                        'source'          => 'airdrop',
                        'acquired_price'  => 0,
                        'acquired_at'     => $now,
                        'status'          => 'held',
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                    Db::name('user_collectibles')->where('id', $ucid)->update([
                        'serial' => 'SN-' . $id . '-' . str_pad((string) $ucid, 4, '0', STR_PAD_LEFT),
                    ]);

                    Db::name('airdrop_records')->insert([
                        'activity_id' => 0,
                        'task_id'     => $taskId,
                        'user_id'     => $userId,
                        'phone'       => $validUsers[$userId]['phone'],
                        'collectible_id' => $id,
                        'user_collectible_id' => $ucid,
                        'quantity'    => 1,
                        'status'      => 'issued',
                        'issued_at'   => $now,
                        'created_at'  => $now,
                        'updated_at' => $now,
                    ]);
                    $success++;
                } catch (\Throwable $e) {
                    $failList[] = ['user_id' => $userId, 'error' => $e->getMessage()];
                }
            }

            if ($success < $need && $success === 0) {
                throw new \Exception('全部发放失败');
            }

            // 更新藏品统计
            Db::name('collectibles')->where('id', $id)->update([
                'airdropped_count' => Db::raw('airdropped_count + ' . $success),
                'circulate'        => Db::raw('circulate + ' . $success),
                'updated_at'       => $now,
            ]);

            Db::name('airdrop_tasks')->where('id', $taskId)->update([
                'success_count' => $success,
                'fail_count'    => count($failList),
                'fail_list'     => $failList ? json_encode($failList, JSON_UNESCAPED_UNICODE) : null,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '空投失败：' . $e->getMessage());
        }

        $this->audit('collectible', 'airdrop', '独立空投「' . $c['name'] . '」' . $success . ' 份',
            ['task_no' => $taskNo, 'reason' => $reason, 'users' => $validIds], 'collectible', $id);

        return $this->success([
            'task_no' => $taskNo,
            'success' => $success,
            'fail'    => count($failList),
            'invalidUsers' => $invalidCount,
        ], '空投完成：成功 ' . $success . ' 份' . ($invalidCount > 0 ? '（' . $invalidCount . ' 个无效用户已跳过）' : ''));
    }

    /**
     * POST /admin/collectible/market-config { id, is_resaleable, resale_price_mode, resale_price_min?, resale_price_max? }
     * 寄售开关与价格管控
     */
    public function marketConfig()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $c = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }

        $update = [];
        if ($this->request->param('is_resaleable') !== null) {
            $update['is_resaleable'] = (int) $this->request->param('is_resaleable') === 1 ? 1 : 0;
        }
        if ($this->request->param('resale_price_mode') !== null) {
            $mode = (int) $this->request->param('resale_price_mode');
            if (!in_array($mode, [0, 1, 2], true)) {
                return $this->fail(4220, 'resale_price_mode 仅允许 0/1/2');
            }
            $update['resale_price_mode'] = $mode;
        }
        if ($this->request->param('resale_price_min') !== null) {
            $update['resale_price_min'] = $this->optionalPrice('resale_price_min');
        }
        if ($this->request->param('resale_price_max') !== null) {
            $update['resale_price_max'] = $this->optionalPrice('resale_price_max');
        }
        if (!$update) {
            return $this->fail(4220, '没有需要更新的配置');
        }

        $mode = (int) ($update['resale_price_mode'] ?? $c['resale_price_mode']);
        $min  = $update['resale_price_min'] ?? $c['resale_price_min'];
        $max  = $update['resale_price_max'] ?? $c['resale_price_max'];
        if ($mode === 1 && $min === null) {
            return $this->fail(4220, '固定价模式需配置价格');
        }
        if ($mode === 2 && ($min === null || $max === null)) {
            return $this->fail(4220, '区间价模式需配置价格上下限');
        }
        if ($mode === 2 && (float) $min > (float) $max) {
            return $this->fail(4220, '价格区间下限不能大于上限');
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        Db::name('collectibles')->where('id', $id)->update($update);

        $this->audit('collectible', 'market_config', '寄售管控「' . $c['name'] . '」', $update, 'collectible', $id);
        return $this->success(null, '寄售配置已更新');
    }

    /**
     * POST /admin/collectible/qualification { id, is_enabled, condition_type?, required_collectible_ids?, required_checkin_days?, required_invite_count?, valid_start_at?, valid_end_at? }
     * 资格购配置（upsert）
     */
    public function qualification()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $c = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$c) {
            return $this->fail(4040, '藏品不存在');
        }
        $isEnabled = (int) $this->request->param('is_enabled', 0) === 1 ? 1 : 0;

        $conditionType = (int) ($this->request->param('condition_type', 1));
        if (!in_array($conditionType, [1, 2, 3], true)) {
            return $this->fail(4220, 'condition_type 仅允许 1（持有）/2（签到）/3（邀请）');
        }

        $requiredIds = $this->request->param('required_collectible_ids', []);
        if (!is_array($requiredIds)) {
            $requiredIds = $requiredIds ? array_map('intval', explode(',', (string) $requiredIds)) : [];
        }
        $requiredIds = array_values(array_filter(array_map('intval', $requiredIds)));
        if ($conditionType === 1 && !$requiredIds) {
            return $this->fail(4220, '持有条件需配置至少一个藏品');
        }
        if ($requiredIds) {
            $existCount = Db::name('collectibles')->whereIn('id', $requiredIds)->whereNull('deleted_at')->count();
            if ($existCount !== count($requiredIds)) {
                return $this->fail(4220, '前置藏品ID列表包含无效藏品');
            }
        }

        $now = date('Y-m-d H:i:s');
        $data = [
            'collectible_id'   => $id,
            'is_enabled'       => $isEnabled,
            'required_collectible_ids' => $requiredIds ? json_encode($requiredIds) : null,
            'required_checkin_days' => max(0, (int) $this->request->param('required_checkin_days', 0)),
            'required_invite_count' => max(0, (int) $this->request->param('required_invite_count', 0)),
            'condition_type'   => $conditionType,
            'valid_start_at'   => $this->optionalDate('valid_start_at'),
            'valid_end_at'     => $this->optionalDate('valid_end_at'),
            'updated_at'       => $now,
        ];

        $exists = Db::name('qualification_configs')->where('collectible_id', $id)->find();
        if ($exists) {
            Db::name('qualification_configs')->where('collectible_id', $id)->update($data);
        } else {
            $data['created_at'] = $now;
            Db::name('qualification_configs')->insert($data);
        }

        // 藏品主表同步标记
        Db::name('collectibles')->where('id', $id)->update([
            'is_qualification_enabled' => $isEnabled,
            'updated_at' => $now,
        ]);

        $this->audit('collectible', 'qualification', '资格购配置「' . $c['name'] . '」' . ($isEnabled ? '启用' : '停用'), $data, 'collectible', $id);
        return $this->success(null, '资格购配置已保存');
    }

    /**
     * GET /admin/collectibles/audit
     * 全局库存审计：批量恒等式校验，输出异常藏品清单
     * （命名避免与 BaseController::audit() 审计日志方法签名冲突）
     */
    public function auditList()
    {
        $rows = Db::name('collectibles')->whereNull('deleted_at')
            ->field('id, name, edition, sold, locked_quantity, reserved_count, airdropped_count, destroyed_count, circulate')
            ->select()->toArray();

        $abnormal = [];
        $checked = count($rows);
        foreach ($rows as $row) {
            $pool = (int) $row['edition'] - (int) $row['sold'] - (int) $row['locked_quantity']
                  - (int) $row['reserved_count'] - (int) $row['airdropped_count'] - (int) $row['destroyed_count'];
            $expected = (int) $row['sold'] + (int) $row['airdropped_count'];
            $issues = [];
            if ($pool < 0) {
                $issues[] = '库存池为负（' . $pool . '），库存恒等式被打破';
            }
            if ((int) $row['circulate'] < $expected) {
                $issues[] = '流通量小于 已售+已空投（流通 ' . $row['circulate'] . ' < ' . $expected . '）';
            }
            if ($issues) {
                $abnormal[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'issues' => $issues];
            }
        }

        return $this->success([
            'checked'  => $checked,
            'abnormalCount' => count($abnormal),
            'abnormal' => $abnormal,
            'allOk'    => count($abnormal) === 0,
        ], count($abnormal) === 0 ? '全部藏品库存恒等式校验通过' : '发现 ' . count($abnormal) . ' 个异常藏品');
    }

    private function optionalPrice(string $key): ?float
    {
        $value = $this->request->param($key);
        if ($value === null || $value === '') {
            return null;
        }
        $price = (float) $value;
        return $price >= 0 ? $price : null;
    }

    private function optionalDate(string $key): ?string
    {
        $value = trim((string) $this->request->param($key, ''));
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
