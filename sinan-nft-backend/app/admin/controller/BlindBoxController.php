<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台盲盒管理控制器
 *
 * 盲盒模型：盲盒本身是一条 collectibles 记录（可购买资产），
 * 通过 nft_blind_boxes 关联 nft_blind_box_items 奖池（含概率与限量）。
 *
 * 覆盖：列表/详情/创建/编辑/奖池概率配置/发售/强制售罄下架/
 * 销毁库存/独立空投/库存审计。
 *
 * 严谨性：
 * - 概率合计必须 ≈ 1（±0.0001 容差）才允许启用
 * - 奖品限量修改不得低于已发放量
 * - 开过的盲盒禁止减少限量
 */
class BlindBoxController extends BaseController
{
    /**
     * GET /admin/blindbox/list
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('blind_boxes')->alias('bb')
            ->join('collectibles c', 'c.id = bb.collectible_id')
            ->whereNull('c.deleted_at');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('c.name', "%{$keyword}%");
        }
        $status = (string) $this->request->param('status', '');
        if ($status !== '' && in_array($status, ['upcoming', 'onsale', 'soldout', 'off'], true)) {
            $query->where('c.status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->field('bb.id, bb.collectible_id, c.name, c.image, c.price, c.edition, c.sold,
                               c.locked_quantity, c.airdropped_count, c.destroyed_count, c.status,
                               c.onsale_at, bb.is_openable, bb.opened_count, c.created_at,
                               c.is_transferable, c.is_resaleable,
                               c.resale_price_mode, c.resale_price_min, c.resale_price_max,
                               (SELECT COUNT(*) FROM nft_blind_box_items x WHERE x.blind_box_id = bb.id AND x.deleted_at IS NULL) AS item_count,
                               (SELECT SUM(x.probability) FROM nft_blind_box_items x WHERE x.blind_box_id = bb.id AND x.deleted_at IS NULL) AS probability_sum')
            ->order('bb.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        foreach ($rows as &$row) {
            $row['item_count'] = (int) $row['item_count'];
            $row['probability_sum'] = round((float) $row['probability_sum'], 4);
            $row['probability_ok'] = abs($row['probability_sum'] - 1) <= 0.0001;
            $row['available_pool'] = max(0, (int) $row['edition'] - (int) $row['sold']
                - (int) $row['locked_quantity'] - (int) $row['airdropped_count'] - (int) $row['destroyed_count']);
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/blindbox/detail/:id（id 为 blind_boxes.id）
     */
    public function detail(int $id)
    {
        $bb = Db::name('blind_boxes')->alias('bb')
            ->field('bb.*, c.name, c.image, c.price, c.edition, c.sold, c.locked_quantity,
                     c.airdropped_count, c.destroyed_count, c.status, c.onsale_at, c.off_sale_at,
                     c.is_transferable, c.is_resaleable,
                     c.resale_price_mode, c.resale_price_min, c.resale_price_max,
                     cat.name AS category_name')
            ->join('collectibles c', 'c.id = bb.collectible_id')
            ->join('categories cat', 'cat.id = c.category_id', 'LEFT')
            ->where('bb.id', $id)
            ->find();
        if (!$bb) {
            return $this->fail(4040, '盲盒不存在');
        }

        $items = Db::name('blind_box_items')->alias('bbi')
            ->field('bbi.*, c2.name AS prize_name, c2.image AS prize_image, c2.price AS prize_price, c2.edition AS prize_edition')
            ->join('collectibles c2', 'c2.id = bbi.prize_collectible_id', 'LEFT')
            ->where('bbi.blind_box_id', $id)
            ->whereNull('bbi.deleted_at')
            ->order('bbi.probability', 'desc')
            ->select()->toArray();

        $probabilitySum = array_sum(array_map(fn ($i) => (float) $i['probability'], $items));

        $destroys = Db::name('destroy_records')->where('target_type', 2)->where('target_id', $id)
            ->order('id', 'desc')->limit(20)->select()->toArray();

        $data = camelize_keys($bb);
        $data['items'] = camelize_keys($items);
        $data['probabilitySum'] = round($probabilitySum, 4);
        $data['probabilityOk'] = abs($probabilitySum - 1) <= 0.0001;

        $data['audit'] = [
            'pool' => max(0, (int) $bb['edition'] - (int) $bb['sold'] - (int) $bb['locked_quantity']
                - (int) $bb['airdropped_count'] - (int) $bb['destroyed_count']),
            'issuedTotal' => array_sum(array_map(fn ($i) => (int) $i['quantity_distributed'], $items)),
            'openedCount' => (int) $bb['opened_count'],
            'issuedMatchesOpened' => array_sum(array_map(fn ($i) => (int) $i['quantity_distributed'], $items)) === (int) $bb['opened_count'],
        ];
        $data['destroyRecords'] = camelize_keys($destroys);

        $this->audit('blindbox', 'view_detail', '查看盲盒详情「' . $bb['name'] . '」', [], 'blind_box', $id);
        return $this->success($data);
    }

    /**
     * POST /admin/blindbox/create { name, category_id, image, price, edition, description?, items:[{prize_collectible_id, probability, quantity_limit?}] }
     */
    public function create()
    {
        $missing = $this->missingParams(['name', 'category_id', 'image', 'price', 'edition', 'items']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $items = $this->request->param('items', []);
        if (!is_array($items) || count($items) < 2) {
            return $this->fail(4220, '盲盒奖池至少需要 2 个奖品');
        }
        if (count($items) > 50) {
            return $this->fail(4220, '盲盒奖池最多 50 个奖品');
        }

        // 奖品校验
        $probabilitySum = 0.0;
        $prizeIds = [];
        foreach ($items as $item) {
            $prizeId = (int) ($item['prize_collectible_id'] ?? 0);
            $prob    = (float) ($item['probability'] ?? 0);
            $limit   = isset($item['quantity_limit']) && $item['quantity_limit'] !== '' ? (int) $item['quantity_limit'] : null;
            if ($prizeId <= 0 || !Db::name('collectibles')->where('id', $prizeId)->whereNull('deleted_at')->find()) {
                return $this->fail(4220, '奖品ID ' . $prizeId . ' 不存在');
            }
            if ($prob <= 0 || $prob > 1) {
                return $this->fail(4220, '奖品「ID ' . $prizeId . '」概率需在 (0,1] 区间');
            }
            if ($limit !== null && $limit < 1) {
                return $this->fail(4220, '奖品限量需 ≥ 1');
            }
            $probabilitySum += $prob;
            $prizeIds[] = $prizeId;
        }
        if (abs($probabilitySum - 1) > 0.0001) {
            return $this->fail(4220, '概率合计必须为 1（当前 ' . round($probabilitySum, 4) . '）');
        }
        if (count(array_unique($prizeIds)) !== count($prizeIds)) {
            return $this->fail(4220, '奖池中存在重复奖品');
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            // 1. 盲盒资产（collectibles 记录；转赠/寄售默认关闭，创建后由管理员在列表/详情中按需开启，与藏品一致）
            $collectibleId = (int) Db::name('collectibles')->insertGetId([
                'category_id'    => (int) $this->request->param('category_id'),
                'name'          => trim((string) $this->request->param('name')),
                'subtitle'      => mb_substr(trim((string) $this->request->param('subtitle', '')), 0, 100) ?: null,
                'image'         => trim((string) $this->request->param('image')),
                'price'         => (float) $this->request->param('price'),
                'edition'       => (int) $this->request->param('edition'),
                'per_user_limit' => max(0, (int) $this->request->param('per_user_limit', 0)),
                'status'        => 'upcoming',
                'tag'           => 'blindbox',
                'description'   => (string) $this->request->param('description', '') ?: null,
                'is_transferable' => 0,
                'is_resaleable'  => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

            // 2. 盲盒配置
            $bbId = (int) Db::name('blind_boxes')->insertGetId([
                'collectible_id' => $collectibleId,
                'description'    => mb_substr(trim((string) $this->request->param('description', '')), 0, 500) ?: null,
                'is_openable'   => (int) $this->request->param('is_openable', 1) === 1 ? 1 : 0,
                'created_at'    => $now,
                'updated_at'   => $now,
            ]);

            // 3. 奖池
            foreach ($items as $item) {
                Db::name('blind_box_items')->insert([
                    'blind_box_id' => $bbId,
                    'prize_collectible_id' => (int) $item['prize_collectible_id'],
                    'probability' => (float) $item['probability'],
                    'quantity_limit' => isset($item['quantity_limit']) && $item['quantity_limit'] !== '' ? (int) $item['quantity_limit'] : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '创建失败：' . $e->getMessage());
        }

        $this->audit('blindbox', 'create', '创建盲盒「' . $this->request->param('name') . '」（' . count($items) . ' 个奖品）',
            ['items' => count($items)], 'blind_box', $bbId);
        return $this->success(['id' => $bbId, 'collectible_id' => $collectibleId], '盲盒创建成功（待发售状态）');
    }

    /**
     * POST /admin/blindbox/update { id, ...盲盒基础字段 }
     */
    public function update()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $bb = Db::name('blind_boxes')->where('id', $id)->find();
        if (!$bb) {
            return $this->fail(4040, '盲盒不存在');
        }

        $update = [];
        if ($this->request->param('description') !== null) {
            $update['description'] = mb_substr(trim((string) $this->request->param('description')), 0, 500) ?: null;
        }
        if ($this->request->param('is_openable') !== null) {
            // 已开过盒的盲盒禁止关闭可开盒（存量资产需要可开启）
            if ((int) $bb['opened_count'] > 0 && (int) $this->request->param('is_openable') !== 1) {
                return $this->fail(4220, '该盲盒已有开启记录，禁止关闭开盒功能');
            }
            $update['is_openable'] = (int) $this->request->param('is_openable') === 1 ? 1 : 0;
        }

        // 同步盲盒资产（collectibles）可编辑字段
        $cUpdate = [];
        foreach (['name', 'subtitle', 'image', 'per_user_limit'] as $field) {
            if ($this->request->param($field) !== null) {
                $cUpdate[$field] = trim((string) $this->request->param($field));
            }
        }
        if ($this->request->param('price') !== null) {
            $c = Db::name('collectibles')->where('id', $bb['collectible_id'])->find();
            if ($c && (int) $c['sold'] > 0 && (float) $this->request->param('price') !== (float) $c['price']) {
                return $this->fail(4220, '盲盒已有售出记录，禁止修改价格');
            }
            $cUpdate['price'] = (float) $this->request->param('price');
        }

        if (!$update && !$cUpdate) {
            return $this->fail(4220, '没有需要更新的字段');
        }

        $now = date('Y-m-d H:i:s');
        if ($update) {
            $update['updated_at'] = $now;
            Db::name('blind_boxes')->where('id', $id)->update($update);
        }
        if ($cUpdate) {
            $cUpdate['updated_at'] = $now;
            Db::name('collectibles')->where('id', $bb['collectible_id'])->update($cUpdate);
        }

        $this->audit('blindbox', 'update', '编辑盲盒（ID ' . $id . '）', array_merge($update, $cUpdate), 'blind_box', $id);
        return $this->success(null, '盲盒信息已更新');
    }

    /**
     * POST /admin/blindbox/config { id, items:[{id?, prize_collectible_id, probability, quantity_limit?}] }
     * 奖池与概率配置（整体覆盖式更新；已发放量保护）
     */
    public function config()
    {
        $missing = $this->missingParams(['id', 'items']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $id    = (int) $this->request->param('id');
        $items = $this->request->param('items', []);
        $bb    = Db::name('blind_boxes')->where('id', $id)->find();
        if (!$bb) {
            return $this->fail(4040, '盲盒不存在');
        }
        if (!is_array($items) || count($items) < 2) {
            return $this->fail(4220, '奖池至少需要 2 个奖品');
        }

        $existing = Db::name('blind_box_items')->where('blind_box_id', $id)->whereNull('deleted_at')
            ->column('quantity_distributed', 'id');

        $probabilitySum = 0.0;
        $prizeIds = [];
        foreach ($items as $item) {
            $prizeId = (int) ($item['prize_collectible_id'] ?? 0);
            $prob    = (float) ($item['probability'] ?? 0);
            $limit   = isset($item['quantity_limit']) && $item['quantity_limit'] !== '' ? (int) $item['quantity_limit'] : null;
            if ($prizeId <= 0 || !Db::name('collectibles')->where('id', $prizeId)->whereNull('deleted_at')->find()) {
                return $this->fail(4220, '奖品ID ' . $prizeId . ' 不存在');
            }
            if ($prob <= 0 || $prob > 1) {
                return $this->fail(4220, '奖品「ID ' . $prizeId . '」概率需在 (0,1] 区间');
            }
            // 既有条目限量保护
            $oldItemId = (int) ($item['id'] ?? 0);
            if ($oldItemId > 0 && isset($existing[$oldItemId])) {
                $distributed = (int) $existing[$oldItemId];
                if ($limit !== null && $limit < $distributed) {
                    return $this->fail(4220, '奖品「ID ' . $prizeId . '」限量不能低于已发放量 ' . $distributed);
                }
            }
            $probabilitySum += $prob;
            $prizeIds[] = $prizeId;
        }
        if (abs($probabilitySum - 1) > 0.0001) {
            return $this->fail(4220, '概率合计必须为 1（当前 ' . round($probabilitySum, 4) . '）');
        }
        if (count(array_unique($prizeIds)) !== count($prizeIds)) {
            return $this->fail(4220, '奖池中存在重复奖品');
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            // 保留 id 命中的条目（更新），其余软删除
            $keepIds = [];
            foreach ($items as $item) {
                $oldItemId = (int) ($item['id'] ?? 0);
                if ($oldItemId > 0 && isset($existing[$oldItemId])) {
                    $keepIds[] = $oldItemId;
                    Db::name('blind_box_items')->where('id', $oldItemId)->update([
                        'prize_collectible_id' => (int) $item['prize_collectible_id'],
                        'probability' => (float) $item['probability'],
                        'quantity_limit' => isset($item['quantity_limit']) && $item['quantity_limit'] !== '' ? (int) $item['quantity_limit'] : null,
                        'updated_at' => $now,
                    ]);
                } else {
                    Db::name('blind_box_items')->insert([
                        'blind_box_id' => $id,
                        'prize_collectible_id' => (int) $item['prize_collectible_id'],
                        'probability' => (float) $item['probability'],
                        'quantity_limit' => isset($item['quantity_limit']) && $item['quantity_limit'] !== '' ? (int) $item['quantity_limit'] : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
            // 未保留的旧条目软删除
            $removeIds = array_diff(array_keys($existing), $keepIds);
            if ($removeIds) {
                foreach ($removeIds as $removeId) {
                    // 已发放量 > 0 的条目禁止删除（历史可追溯）
                    if ((int) $existing[$removeId] > 0) {
                        throw new \Exception('奖品条目（ID ' . $removeId . '）已发放 ' . $existing[$removeId] . ' 份，禁止删除，仅可调整概率/限量');
                    }
                }
                Db::name('blind_box_items')->whereIn('id', $removeIds)->update(['deleted_at' => $now]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '奖池配置失败：' . $e->getMessage());
        }

        $this->audit('blindbox', 'config', '修改盲盒奖池/概率配置（ID ' . $id . '，' . count($items) . ' 个奖品）',
            ['items' => $items], 'blind_box', $id);
        return $this->success(null, '奖池配置已保存');
    }

    /**
     * POST /admin/blindbox/release { id, status(upcoming/onsale), onsale_at? }
     */
    public function release()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $bb = Db::name('blind_boxes')->where('id', $id)->find();
        if (!$bb) {
            return $this->fail(4040, '盲盒不存在');
        }
        $c = Db::name('collectibles')->where('id', $bb['collectible_id'])->find();
        if (!$c) {
            return $this->fail(4040, '盲盒资产不存在');
        }

        // 概率校验：上架前必须配置完整
        $probSum = (float) Db::name('blind_box_items')->where('blind_box_id', $id)->whereNull('deleted_at')->sum('probability');
        if (abs($probSum - 1) > 0.0001) {
            return $this->fail(4220, '奖池概率合计为 ' . round($probSum, 4) . '，必须为 1 才能上架');
        }

        $status = (string) $this->request->param('status', 'onsale');
        if (!in_array($status, ['upcoming', 'onsale'], true)) {
            return $this->fail(4220, '发售状态仅允许 upcoming / onsale');
        }

        $now = date('Y-m-d H:i:s');
        $update = ['status' => $status, 'is_release' => 1, 'updated_at' => $now];
        if ($status === 'onsale') {
            $update['onsale_at'] = date('Y-m-d H:i:s', strtotime((string) ($this->request->param('onsale_at') ?: 'now')));
        }
        Db::name('collectibles')->where('id', $bb['collectible_id'])->update($update);

        $this->audit('blindbox', 'release', '盲盒发售配置「' . $c['name'] . '」→ ' . $status, $update, 'blind_box', $id);
        return $this->success(null, '发售配置已生效');
    }

    /**
     * POST /admin/blindbox/manage { id, action(soldout/off) }
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
        $bb = Db::name('blind_boxes')->where('id', $id)->find();
        if (!$bb) {
            return $this->fail(4040, '盲盒不存在');
        }

        $now = date('Y-m-d H:i:s');
        Db::name('collectibles')->where('id', $bb['collectible_id'])->update([
            'status'     => $action === 'soldout' ? 'soldout' : 'off',
            'off_sale_at' => $action === 'off' ? $now : null,
            'updated_at' => $now,
        ]);

        $this->audit('blindbox', 'manage_' . $action,
            ($action === 'soldout' ? '强制售罄' : '强制下架') . '盲盒（ID ' . $id . '）', [], 'blind_box', $id);
        return $this->success(null, $action === 'soldout' ? '盲盒已标记售罄' : '盲盒已强制下架');
    }

    /**
     * POST /admin/blindbox/destroy { id, quantity, reason }
     * 销毁盲盒库存（库存池份额）
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
        $bb = Db::name('blind_boxes')->where('id', $id)->find();
        if (!$bb) {
            return $this->fail(4040, '盲盒不存在');
        }
        $c = Db::name('collectibles')->where('id', $bb['collectible_id'])->find();
        if (!$c) {
            return $this->fail(4040, '盲盒资产不存在');
        }
        $pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity']
              - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];
        if ($pool < $quantity) {
            return $this->fail(4220, '可销毁库存池仅剩 ' . $pool . ' 份');
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            Db::name('collectibles')->where('id', $c['id'])
                ->whereRaw('edition - sold - locked_quantity - airdropped_count - destroyed_count >= ' . $quantity)
                ->update(['destroyed_count' => Db::raw('destroyed_count + ' . $quantity), 'updated_at' => $now]);

            Db::name('destroy_records')->insert([
                'target_type' => 2, // 2=盲盒
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

        $this->audit('blindbox', 'destroy', '销毁盲盒库存「' . $c['name'] . '」' . $quantity . ' 份',
            ['quantity' => $quantity, 'reason' => $reason], 'blind_box', $id);
        return $this->success(null, '已销毁 ' . $quantity . ' 份盲盒库存');
    }

    /**
     * POST /admin/blindbox/airdrop { id, users, reason }
     * 独立空投盲盒给指定用户（生成盲盒持仓，可开盒）
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
        $bb = Db::name('blind_boxes')->where('id', $id)->find();
        if (!$bb) {
            return $this->fail(4040, '盲盒不存在');
        }
        $c = Db::name('collectibles')->where('id', $bb['collectible_id'])->find();
        if (!$c) {
            return $this->fail(4040, '盲盒资产不存在');
        }

        $userIds = array_values(array_unique(array_map('intval', array_filter($users, 'is_numeric'))));
        if (!$userIds) {
            return $this->fail(4220, '用户ID列表格式不正确');
        }
        $validUsers = Db::name('users')->whereIn('id', $userIds)->whereNull('deleted_at')
            ->where('is_blacklisted', 0)->column('id, phone');
        $validIds = array_map('intval', array_keys($validUsers));
        if (!$validIds) {
            return $this->fail(4220, '目标用户全部无效');
        }

        $pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity']
              - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];
        $need = count($validIds);
        if ($pool < $need) {
            return $this->fail(4220, '库存池不足：可空投 ' . $pool . ' 份');
        }

        $now    = date('Y-m-d H:i:s');
        $taskNo = 'ADB' . date('ymdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

        Db::startTrans();
        try {
            $taskId = (int) Db::name('airdrop_tasks')->insertGetId([
                'task_no'        => $taskNo,
                'target_type'    => 2,
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
            foreach ($validIds as $userId) {
                $ucid = (int) Db::name('user_collectibles')->insertGetId([
                    'user_id'        => $userId,
                    'collectible_id' => $c['id'],
                    'serial'         => gen_serial_placeholder(),
                    'source'         => 'airdrop',
                    'acquired_price' => 0,
                    'acquired_at'    => $now,
                    'status'         => 'held',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                Db::name('user_collectibles')->where('id', $ucid)->update([
                    'serial' => 'SN-' . $c['id'] . '-' . str_pad((string) $ucid, 4, '0', STR_PAD_LEFT),
                ]);
                Db::name('airdrop_records')->insert([
                    'activity_id' => 0,
                    'task_id'     => $taskId,
                    'user_id'     => $userId,
                    'phone'       => $validUsers[$userId]['phone'],
                    'collectible_id' => $c['id'],
                    'user_collectible_id' => $ucid,
                    'quantity'    => 1,
                    'status'      => 'issued',
                    'issued_at'   => $now,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
                $success++;
            }

            Db::name('collectibles')->where('id', $c['id'])->update([
                'airdropped_count' => Db::raw('airdropped_count + ' . $success),
                'circulate'        => Db::raw('circulate + ' . $success),
                'updated_at'       => $now,
            ]);
            Db::name('airdrop_tasks')->where('id', $taskId)->update(['success_count' => $success]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '空投失败：' . $e->getMessage());
        }

        $this->audit('blindbox', 'airdrop', '独立空投盲盒「' . $c['name'] . '」' . $success . ' 份',
            ['task_no' => $taskNo, 'reason' => $reason], 'blind_box', $id);
        return $this->success(['task_no' => $taskNo, 'success' => $success], '盲盒空投完成：成功 ' . $success . ' 份');
    }

    /**
     * GET /admin/blind-boxes/audit
     * 全量盲盒审计：概率合规 + 奖品限量 + 开盒对账
     * （命名避免与 BaseController::audit() 审计日志方法签名冲突）
     */
    public function auditList()
    {
        $boxes = Db::name('blind_boxes')->alias('bb')
            ->field('bb.id, c.name, c.sold, c.airdropped_count, bb.opened_count')
            ->join('collectibles c', 'c.id = bb.collectible_id')
            ->whereNull('c.deleted_at')
            ->select()->toArray();

        $checked = count($boxes);
        $abnormal = [];
        foreach ($boxes as $box) {
            $issues = [];
            $items = Db::name('blind_box_items')->where('blind_box_id', $box['id'])->whereNull('deleted_at')->select()->toArray();
            $probSum = array_sum(array_map(fn ($i) => (float) $i['probability'], $items));
            if (count($items) < 2) {
                $issues[] = '奖池奖品少于 2 个';
            }
            if (abs($probSum - 1) > 0.0001) {
                $issues[] = '概率合计 ' . round($probSum, 4) . ' ≠ 1';
            }
            foreach ($items as $item) {
                if ($item['quantity_limit'] !== null && (int) $item['quantity_distributed'] > (int) $item['quantity_limit']) {
                    $issues[] = '奖品（ID ' . $item['prize_collectible_id'] . '）发放 ' . $item['quantity_distributed'] . ' 超出限量 ' . $item['quantity_limit'];
                }
            }
            // 开盒对账：opened_count 与奖池发放总数
            $issuedTotal = array_sum(array_map(fn ($i) => (int) $i['quantity_distributed'], $items));
            if ($issuedTotal !== (int) $box['opened_count']) {
                $issues[] = '开盒数 ' . $box['opened_count'] . ' 与奖池发放总数 ' . $issuedTotal . ' 不一致';
            }
            if ($issues) {
                $abnormal[] = ['id' => (int) $box['id'], 'name' => $box['name'], 'issues' => $issues];
            }
        }

        return $this->success([
            'checked' => $checked,
            'abnormalCount' => count($abnormal),
            'abnormal' => $abnormal,
            'allOk' => count($abnormal) === 0,
        ], count($abnormal) === 0 ? '全部盲盒审计通过' : '发现 ' . count($abnormal) . ' 个异常盲盒');
    }
}
