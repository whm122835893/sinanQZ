<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AuditLogService;
use app\admin\service\InventoryException;
use app\admin\service\InventoryService;
use think\facade\Db;

/**
 * 盲盒管理控制器（文档 8.7，17 接口）
 *
 * 盲盒行即藏品行（D-3）：nft_blind_boxes.id 为盲盒ID，其 collectible_id 指向
 * nft_collectibles 行承载 name/image/price/edition/状态与全部库存计数器。
 *
 * 状态机（文档 6.2）：
 *   draft/off 可配置/修改子藏品与概率（发售中禁止改概率，需先下架）
 *   soldout 可独立空投（扣盲盒库存池）、可销毁
 *   已开启的盲盒资产（consumed）不可回收（5.5）
 */
class BlindBoxes extends AdminBase
{
    /**
     * #51 GET /blindboxes 盲盒列表（展示盲盒发行量与流通量）
     */
    public function index()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('blind_boxes')->alias('bb')
            ->join('nft_collectibles c', 'c.id = bb.collectible_id')
            ->whereNull('c.deleted_at');

        $name = trim((string) $this->strParam('name'));
        if ($name !== '') {
            $query->where('c.name', 'like', "%{$name}%");
        }
        $status = $this->strParam('status');
        if ($status !== null && $status !== '') {
            $query->where('c.status', $status);
        }
        $isOpenable = $this->strParam('isOpenable');
        if ($isOpenable !== null && $isOpenable !== '') {
            $query->where('bb.is_openable', (int) $isOpenable);
        }

        $total = (clone $query)->count();
        $list = $query
            ->field('bb.id,bb.collectible_id,bb.is_openable,bb.is_transferable,bb.is_resaleable,bb.description,c.name,c.image,c.price,c.edition,c.release_quantity,c.sold,c.locked_quantity,c.reserved_count,c.airdropped_count,c.destroyed_count,c.circulate,c.status,c.onsale_at,c.off_sale_at,c.created_at')
            ->order('bb.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(function ($row) {
            return $this->formatRow($row);
        }, $list);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * #52 POST /blindboxes 创建盲盒（name/image/description/edition/price?）
     * 创建藏品行（draft）+ 盲盒扩展行
     */
    public function create()
    {
        $name = trim((string) $this->strParam('name'));
        if ($name === '') {
            return $this->invalid('盲盒名称不能为空');
        }
        $image = trim((string) $this->strParam('image'));
        if ($image === '') {
            $image = 'placeholder.png';
        }
        $edition = $this->intParam('edition');
        if ($edition <= 0) {
            return $this->invalid('盲盒发行总量必须大于 0');
        }
        $price = $this->strParam('price');
        if ($price !== null && $price !== '' && (!is_numeric($price) || (float) $price < 0)) {
            return $this->invalid('盲盒价格必须为非负数字');
        }
        $categoryId = $this->intParam('categoryId');
        if ($categoryId <= 0 || !Db::name('categories')->where('id', $categoryId)->count()) {
            return $this->invalid('请选择有效的盲盒分类');
        }
        $description = trim((string) $this->strParam('description'));

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            $collectibleId = Db::name('collectibles')->insertGetId([
                'category_id' => $categoryId,
                'name'        => $name,
                'subtitle'    => $description !== '' ? $description : null,
                'image'       => $image,
                'price'       => $price !== null && $price !== '' ? number_format((float) $price, 2, '.', '') : 0.00,
                'edition'     => $edition,
                'circulate'   => 0,
                'sold'        => 0,
                'locked_quantity' => 0,
                'reserved_count' => 0,
                'airdropped_count' => 0,
                'destroyed_count' => 0,
                'status'      => 'draft',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $blindBoxId = Db::name('blind_boxes')->insertGetId([
                'collectible_id' => $collectibleId,
                'description'    => $description !== '' ? $description : null,
                'is_openable'   => 1,
                'is_transferable' => 1,
                'is_resaleable'   => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '盲盒创建失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'blindbox', 'blindbox.create', [
            'target_type' => 'blindbox',
            'target_id'   => $blindBoxId,
            'target_desc' => $name,
            'after'       => ['name' => $name, 'edition' => $edition, 'collectibleId' => $collectibleId, 'status' => 'draft'],
        ]);

        return $this->success(['id' => (int) $blindBoxId, 'collectibleId' => (int) $collectibleId], '盲盒创建成功（草稿）');
    }

    /**
     * #53 GET /blindboxes/:id 盲盒详情（含 items 与库存五数）
     */
    public function detail(int $id)
    {
        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }

        $result = $this->formatRow($bb, true);
        $result['items'] = $this->loadItems($id);

        // 概率总和（可视化校验，文档 11.2-18）
        $probSum = 0.0;
        foreach ($result['items'] as $item) {
            $probSum += (float) $item['probability'];
        }
        $result['probabilitySum'] = round($probSum, 4);

        return $this->success($result);
    }

    /**
     * #54 PUT /blindboxes/:id 编辑盲盒（仅 draft/off，文档 8.7）
     */
    public function update(int $id)
    {
        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }
        if (!in_array($bb['status'], ['draft', 'off'], true)) {
            return $this->conflict("仅草稿/已下架可编辑盲盒，当前状态为 {$bb['status']}");
        }

        $update = [];
        $name = $this->strParam('name');
        if ($name !== null && trim($name) !== '') {
            $update['name'] = trim($name);
        }
        if ($this->request->has('image')) {
            $image = trim((string) $this->request->param('image', ''));
            if ($image !== '') {
                $update['image'] = $image;
            }
        }
        $price = $this->strParam('price');
        if ($price !== null && $price !== '') {
            if (!is_numeric($price) || (float) $price < 0) {
                return $this->invalid('盲盒价格必须为非负数字');
            }
            $update['price'] = number_format((float) $price, 2, '.', '');
        }
        if ($this->request->has('description')) {
            $desc = trim((string) $this->request->param('description', ''));
            $update['subtitle'] = $desc !== '' ? $desc : null;
        }
        $edition = $this->strParam('edition');
        if ($edition !== null && $edition !== '') {
            if (!is_numeric($edition) || (int) $edition <= 0) {
                return $this->invalid('发行总量必须为大于 0 的整数');
            }
            if ((int) $edition < (int) $bb['edition']) {
                return $this->conflict("发行总量只能调大不可调小（当前 {$bb['edition']}，已分配 " .
                    ((int) $bb['sold'] + (int) $bb['reserved_count'] + (int) $bb['airdropped_count'] + (int) $bb['destroyed_count']) . '）');
            }
            $update['edition'] = (int) $edition;
        }

        if (!$update) {
            return $this->invalid('未提供任何修改字段');
        }
        $update['updated_at'] = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            Db::name('collectibles')->where('id', $bb['collectible_id'])->update($update);
            if ($this->request->has('description')) {
                Db::name('blind_boxes')->where('id', $id)->update([
                    'description' => trim((string) $this->request->param('description', '')) ?: null,
                    'updated_at'  => $update['updated_at'],
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '盲盒更新失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'blindbox', 'blindbox.edit', [
            'target_type' => 'blindbox',
            'target_id'   => $id,
            'target_desc' => $update['name'] ?? $bb['name'],
            'before'      => ['name' => $bb['name'], 'price' => $bb['price'], 'edition' => (int) $bb['edition']],
            'after'       => $update,
        ]);

        return $this->success(null, '盲盒已更新');
    }

    /**
     * #55 POST /blindboxes/:id/items 配置子藏品（items[] 全量替换）
     * items[]：collectible_id/probability/planned_quantity（→quantity_limit）；概率总和 ≤ 100%
     * 仅 draft/off（文档 6.2：发售中禁止改概率）
     */
    public function items(int $id)
    {
        // 概率修改属高风险操作（文档 11.1：盲盒子藏品概率修改 + 密码 + 概率总和 ≤ 100%）
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }
        if (!in_array($bb['status'], ['draft', 'off'], true)) {
            return $this->conflict("发售中禁止修改子藏品概率（需先下架），当前状态为 {$bb['status']}");
        }

        $items = $this->request->param('items');
        if (!is_array($items) || !$items) {
            return $this->invalid('items[] 不能为空');
        }

        // 参数规范化与校验
        $probSum = 0.0;
        $validated = [];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                return $this->invalid("第 " . ($i + 1) . " 项格式错误");
            }
            $collectibleId = (int) ($item['collectible_id'] ?? 0);
            if ($collectibleId <= 0) {
                return $this->invalid("第 " . ($i + 1) . " 项缺少子藏品ID");
            }
            // 子藏品不能是盲盒自身（防止套娃）
            if ($collectibleId === (int) $bb['collectible_id']) {
                return $this->invalid('子藏品不能是盲盒自身');
            }
            if (Db::name('blind_boxes')->where('collectible_id', $collectibleId)->count() > 0) {
                return $this->invalid("第 " . ($i + 1) . " 项：#{$collectibleId} 是盲盒，不可作为子藏品");
            }
            $target = Db::name('collectibles')->where('id', $collectibleId)->whereNull('deleted_at')->find();
            if (!$target) {
                return $this->invalid("子藏品 #{$collectibleId} 不存在");
            }
            $probability = (float) ($item['probability'] ?? 0);
            if ($probability <= 0 || $probability > 1) {
                return $this->invalid("第 " . ($i + 1) . " 项概率必须在 (0,1] 区间（当前 {$probability}）");
            }
            $probSum += $probability;
            $planned = isset($item['planned_quantity']) && $item['planned_quantity'] !== '' ? (int) $item['planned_quantity'] : null;
            if ($planned !== null && $planned <= 0) {
                return $this->invalid("第 " . ($i + 1) . " 项计划数量必须大于 0");
            }
            if ($planned !== null && $planned > (int) $target['edition']) {
                return $this->conflict("子藏品「{$target['name']}」计划数量超出其发行总量 {$target['edition']}");
            }
            $validated[] = [
                'collectible_id' => $collectibleId,
                'probability'    => $probability,
                'quantity_limit' => $planned,
            ];
        }
        if ($probSum > 1.0001) {
            return $this->conflict('概率总和超过 100%（当前 ' . round($probSum * 100, 2) . '%）');
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            // 全量替换（软删旧行，保留历史痕迹）
            Db::name('blind_box_items')->where('blind_box_id', $id)->whereNull('deleted_at')
                ->update(['deleted_at' => $now]);
            foreach ($validated as $v) {
                Db::name('blind_box_items')->insert([
                    'blind_box_id'        => $id,
                    'prize_collectible_id' => $v['collectible_id'],
                    'probability'         => $v['probability'],
                    'quantity_limit'      => $v['quantity_limit'],
                    'quantity_distributed' => 0,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '子藏品配置失败：' . $e->getMessage());
        }

        $result = [
            'items'           => $this->loadItems($id),
            'probabilitySum'  => round($probSum, 4),
        ];
        AuditLogService::log($this->request, 'blindbox', 'blindbox.items.config', [
            'target_type' => 'blindbox',
            'target_id'   => $id,
            'target_desc' => $bb['name'],
            'after'       => $result,
        ]);

        return $this->success($result, '子藏品配置成功');
    }

    /**
     * #56 PUT /blindboxes/:id/items/:item_id 修改子藏品（仅 draft/off）
     */
    public function updateItem(int $id, int $itemId)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }
        if (!in_array($bb['status'], ['draft', 'off'], true)) {
            return $this->conflict("发售中禁止修改子藏品概率（需先下架），当前状态为 {$bb['status']}");
        }
        $item = Db::name('blind_box_items')
            ->where('id', $itemId)
            ->where('blind_box_id', $id)
            ->whereNull('deleted_at')
            ->find();
        if (!$item) {
            return $this->fail(409, '子藏品配置不存在');
        }

        $update = [];
        if ($this->request->has('probability')) {
            $probability = (float) $this->request->param('probability');
            if ($probability <= 0 || $probability > 1) {
                return $this->invalid('概率必须在 (0,1] 区间');
            }
            $update['probability'] = $probability;
        }
        if ($this->request->has('planned_quantity')) {
            $planned = $this->request->param('planned_quantity');
            if ($planned === '' || $planned === null) {
                $update['quantity_limit'] = null;
            } else {
                $planned = (int) $planned;
                if ($planned <= 0) {
                    return $this->invalid('计划数量必须大于 0');
                }
                $target = Db::name('collectibles')->where('id', $item['prize_collectible_id'])->find();
                if ($target && $planned > (int) $target['edition']) {
                    return $this->conflict("计划数量超出子藏品发行总量 {$target['edition']}");
                }
                $update['quantity_limit'] = $planned;
            }
        }
        if (!$update) {
            return $this->invalid('未提供任何修改字段');
        }

        // 概率总和校验（含其他未修改项）
        if (isset($update['probability'])) {
            $others = Db::name('blind_box_items')
                ->where('blind_box_id', $id)
                ->whereNull('deleted_at')
                ->where('id', '<>', $itemId)
                ->sum('probability');
            if ((float) $others + $update['probability'] > 1.0001) {
                return $this->conflict('概率总和超过 100%（当前 ' .
                    round(((float) $others + $update['probability']) * 100, 2) . '%）');
            }
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        Db::name('blind_box_items')->where('id', $itemId)->update($update);

        AuditLogService::log($this->request, 'blindbox', 'blindbox.item.update', [
            'target_type' => 'blindbox_item',
            'target_id'   => $itemId,
            'target_desc' => $bb['name'] . ' 子藏品 #' . $itemId,
            'before'      => ['probability' => (float) $item['probability'], 'quantity_limit' => $item['quantity_limit']],
            'after'       => $update,
        ]);

        return $this->success(null, '子藏品已更新');
    }

    /**
     * #57 DELETE /blindboxes/:id/items/:item_id 删除子藏品（仅 draft/off）
     */
    public function deleteItem(int $id, int $itemId)
    {
        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }
        if (!in_array($bb['status'], ['draft', 'off'], true)) {
            return $this->conflict("发售中禁止修改子藏品（需先下架），当前状态为 {$bb['status']}");
        }
        $item = Db::name('blind_box_items')
            ->where('id', $itemId)
            ->where('blind_box_id', $id)
            ->whereNull('deleted_at')
            ->find();
        if (!$item) {
            return $this->fail(409, '子藏品配置不存在');
        }
        if ((int) $item['quantity_distributed'] > 0) {
            return $this->conflict("该子藏品已发放 {$item['quantity_distributed']} 份，不可删除");
        }

        Db::name('blind_box_items')->where('id', $itemId)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        AuditLogService::log($this->request, 'blindbox', 'blindbox.item.delete', [
            'target_type' => 'blindbox_item',
            'target_id'   => $itemId,
            'target_desc' => $bb['name'] . ' 子藏品 #' . $itemId,
            'before'      => ['probability' => (float) $item['probability'], 'quantity_limit' => $item['quantity_limit']],
            'after'       => ['deleted' => true],
        ]);

        return $this->success(null, '子藏品已删除');
    }

    /**
     * #58 POST /blindboxes/:id/release 发售配置（作用于盲盒藏品行）
     */
    public function release(int $id)
    {
        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }
        if (!in_array($bb['status'], ['draft', 'upcoming'], true)) {
            return $this->conflict("当前状态 {$bb['status']} 不允许发售配置（仅草稿/待发售）");
        }

        $price = $this->strParam('price');
        if ($price === null || !is_numeric($price) || (float) $price <= 0) {
            return $this->invalid('发售价格必须大于 0');
        }
        $onsaleAt = $this->strParam('onsaleAt');
        if ($onsaleAt === null || !strtotime((string) $onsaleAt)) {
            return $this->invalid('发售开始时间格式不正确');
        }
        $offSaleAt = $this->strParam('offSaleAt');
        if ($offSaleAt !== null && $offSaleAt !== '' && !strtotime($offSaleAt)) {
            return $this->invalid('发售结束时间格式不正确');
        }
        if ($offSaleAt !== null && $offSaleAt !== '' && strtotime($offSaleAt) <= strtotime((string) $onsaleAt)) {
            return $this->invalid('发售结束时间必须晚于开始时间');
        }

        $releaseQuantity = $this->strParam('releaseQuantity');
        if ($releaseQuantity !== null && $releaseQuantity !== '') {
            if (!is_numeric($releaseQuantity) || (int) $releaseQuantity < 0) {
                return $this->invalid('计划发售数量必须为非负整数');
            }
            if ((int) $releaseQuantity > (int) $bb['edition']) {
                return $this->conflict("计划发售数量不能超过盲盒发行总量 {$bb['edition']}");
            }
            $releaseQuantity = (int) $releaseQuantity;
        } else {
            $releaseQuantity = null;
        }

        // 盲盒开盒前提：已配置子藏品
        $itemCount = Db::name('blind_box_items')->where('blind_box_id', $id)->whereNull('deleted_at')->count();
        if (!$itemCount) {
            return $this->conflict('盲盒尚未配置子藏品，不可发售');
        }

        $now = date('Y-m-d H:i:s');
        $newStatus = strtotime((string) $onsaleAt) > time() ? 'upcoming' : 'onsale';

        $update = [
            'price'            => number_format((float) $price, 2, '.', ''),
            'onsale_at'        => $onsaleAt,
            'off_sale_at'      => $offSaleAt ?: null,
            'release_quantity' => $releaseQuantity,
            'status'           => $newStatus,
            'updated_at'       => $now,
        ];
        Db::name('collectibles')->where('id', $bb['collectible_id'])->update($update);

        AuditLogService::log($this->request, 'blindbox', 'blindbox.release', [
            'target_type' => 'blindbox',
            'target_id'   => $id,
            'target_desc' => $bb['name'],
            'before'      => ['status' => $bb['status'], 'price' => $bb['price'], 'onsale_at' => $bb['onsale_at']],
            'after'       => $update,
        ]);

        return $this->success(['status' => $newStatus], '盲盒发售配置已保存');
    }

    /**
     * #59 POST /blindboxes/:id/relist 重新上架（≤ 盲盒库存池 + password）
     */
    public function relist(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }
        if (!in_array($bb['status'], ['soldout', 'off'], true)) {
            return $this->conflict("当前状态 {$bb['status']} 不允许重新上架（仅已售罄/已下架）");
        }

        $releaseQuantity = $this->strParam('releaseQuantity');
        if ($releaseQuantity === null || $releaseQuantity === '' || !is_numeric($releaseQuantity)) {
            return $this->invalid('请提供本次计划发售数量');
        }
        $releaseQuantity = (int) $releaseQuantity;

        $pool = InventoryService::stockPool($bb);
        if ($releaseQuantity <= 0 || $releaseQuantity > $pool) {
            return $this->conflict("上架数量超出盲盒库存池，当前库存池为 {$pool}");
        }

        $onsaleAt = $this->strParam('onsaleAt');
        $now = date('Y-m-d H:i:s');
        if ($onsaleAt !== null && $onsaleAt !== '') {
            if (!strtotime($onsaleAt)) {
                return $this->invalid('发售开始时间格式不正确');
            }
            $newStatus = strtotime($onsaleAt) > time() ? 'upcoming' : 'onsale';
        } else {
            $onsaleAt = $now;
            $newStatus = 'onsale';
        }

        $update = [
            'release_quantity' => $releaseQuantity,
            'onsale_at'        => $onsaleAt,
            'status'           => $newStatus,
            'updated_at'       => $now,
        ];
        Db::name('collectibles')->where('id', $bb['collectible_id'])->update($update);

        AuditLogService::log($this->request, 'blindbox', 'blindbox.relist', [
            'target_type' => 'blindbox',
            'target_id'   => $id,
            'target_desc' => $bb['name'],
            'before'      => ['status' => $bb['status'], 'release_quantity' => $bb['release_quantity']],
            'after'       => $update,
        ]);

        return $this->success(['status' => $newStatus, 'stockPool' => $pool], '盲盒重新上架成功');
    }

    /**
     * #60 POST /blindboxes/:id/force-soldout 强制售罄（reason/password，不清零计数器）
     */
    public function forceSoldout(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('强制售罄原因不能为空');
        }

        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }

        try {
            $result = InventoryService::forceSoldout((int) $bb['collectible_id']);
        } catch (InventoryException $e) {
            return $this->conflict($e->getMessage());
        }

        AuditLogService::log($this->request, 'blindbox', 'blindbox.force_soldout', [
            'target_type' => 'blindbox',
            'target_id'   => $id,
            'target_desc' => $bb['name'],
            'before'      => ['status' => $bb['status']],
            'after'       => ['status' => 'soldout', 'note' => '计数器未清零'],
            'reason'      => $reason,
        ]);

        return $this->success($result, '盲盒已强制售罄（计数器未清零，剩余量保留在库存池）');
    }

    /**
     * #61 POST /blindboxes/:id/destroy 销毁盲盒库存（quantity ≤ 库存池 + password；不可逆）
     */
    public function destroy(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $quantity = $this->intParam('quantity');
        if ($quantity <= 0) {
            return $this->invalid('销毁数量必须大于 0');
        }
        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('销毁原因不能为空');
        }

        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }

        try {
            $result = InventoryService::destroy((int) $bb['collectible_id'], $quantity, $reason, [
                'id'   => $this->adminId(),
                'name' => $this->adminName(),
                'ip'   => $this->ip(),
            ], 2, $id);
        } catch (InventoryException $e) {
            return $this->conflict($e->getMessage());
        }

        AuditLogService::log($this->request, 'blindbox', 'blindbox.destroy', [
            'target_type' => 'blindbox',
            'target_id'   => $id,
            'target_desc' => $bb['name'],
            'after'       => $result,
            'reason'      => $reason,
        ]);

        return $this->success($result, "已销毁盲盒库存 {$quantity} 份（不可逆）");
    }

    /**
     * #62 DELETE /blindboxes/:id 删除盲盒（仅草稿无关联 + password）
     */
    public function delete(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }
        if ($bb['status'] !== 'draft') {
            return $this->conflict("仅草稿状态可删除，当前状态为 {$bb['status']}");
        }

        $checks = [
            ['订单', Db::name('orders')->where('collectible_id', $bb['collectible_id'])->count()],
            ['用户资产', Db::name('user_collectibles')->where('collectible_id', $bb['collectible_id'])->count()],
            ['空投记录', Db::name('airdrop_records')->where('collectible_id', $bb['collectible_id'])->count()],
            ['销毁记录', Db::name('destroy_records')->where('collectible_id', $bb['collectible_id'])->count()],
        ];
        foreach ($checks as [$label, $cnt]) {
            if ((int) $cnt > 0) {
                return $this->conflict("盲盒存在{$label}关联（{$cnt} 条），无法删除");
            }
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            // 子藏品配置软删
            Db::name('blind_box_items')->where('blind_box_id', $id)->whereNull('deleted_at')
                ->update(['deleted_at' => $now]);
            Db::name('blind_boxes')->where('id', $id)->update([
                'is_openable' => 0,
                'updated_at'  => $now,
            ]);
            Db::name('collectibles')->where('id', $bb['collectible_id'])->update([
                'deleted_at' => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '盲盒删除失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'blindbox', 'blindbox.delete', [
            'target_type' => 'blindbox',
            'target_id'   => $id,
            'target_desc' => $bb['name'],
            'before'      => ['status' => 'draft'],
            'after'       => ['deleted' => true],
        ]);

        return $this->success(null, '盲盒已删除');
    }

    /**
     * #63 POST /blindboxes/:id/airdrop 独立空投盲盒（quantity/phones[] + password）
     * 盲盒资产 source=purchase（保持 C 端开盒可用，D-3），空投溯源靠 airdrop_record_id
     */
    public function airdrop(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }
        if ($bb['status'] === 'draft') {
            return $this->conflict('草稿盲盒不可空投，请先完成发售配置');
        }

        $quantity = $this->intParam('quantity');
        if ($quantity <= 0) {
            return $this->invalid('每个用户的空投数量必须大于 0');
        }
        $phones = $this->request->param('phones');
        if (is_string($phones)) {
            $phones = preg_split('/\r\n|\r|\n/', trim($phones)) ?: [];
        }
        if (!is_array($phones) || !$phones) {
            return $this->invalid('空投手机号不能为空');
        }

        try {
            $result = InventoryService::airdrop((int) $bb['collectible_id'], $phones, $quantity, [
                'id'   => $this->adminId(),
                'name' => $this->adminName(),
                'ip'   => $this->ip(),
            ], 'purchase');
        } catch (InventoryException $e) {
            return $this->conflict($e->getMessage());
        }

        AuditLogService::log($this->request, 'blindbox', 'blindbox.airdrop', [
            'target_type' => 'blindbox',
            'target_id'   => $id,
            'target_desc' => $bb['name'],
            'after'       => [
                'users'   => $result['users'],
                'perUser' => $result['perUser'],
                'total'   => $result['total'],
            ],
        ]);

        return $this->success($result, "盲盒空投成功：{$result['users']} 名用户共 {$result['total']} 份");
    }

    /**
     * #64 POST /blindboxes/:id/recover 强制回收盲盒（user_blindbox_id/reason/password，校验未开启）
     * 前置校验（文档 5.5）：资产 status='held'；consumed 即拦截「该盲盒已被开启，无法回收」
     * 回收后按 4.3.4 回退计数器
     */
    public function recover(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('回收原因不能为空');
        }
        $ucId = $this->intParam('userBlindboxId');
        if ($ucId <= 0) {
            return $this->invalid('请提供用户盲盒资产ID');
        }

        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }

        Db::startTrans();
        try {
            // 锁定资产行并校验归属与状态（5.5：已开启 consumed 即拦截）
            $uc = Db::name('user_collectibles')
                ->where('id', $ucId)
                ->where('collectible_id', $bb['collectible_id'])
                ->lock(true)
                ->find();
            if (!$uc) {
                Db::rollback();
                return $this->fail(409, '用户盲盒资产不存在或不属于该盲盒');
            }
            if ($uc['status'] === 'consumed') {
                Db::rollback();
                return $this->conflict('该盲盒已被开启，无法回收');
            }
            if ($uc['status'] !== 'held') {
                $statusText = ['consigned' => '正在寄售', 'frozen' => '转赠冻结中', 'transferred' => '已转赠'][$uc['status']] ?? $uc['status'];
                Db::rollback();
                return $this->conflict("该盲盒{$statusText}，无法回收");
            }

            // 计数器回退（4.3.4，外键优先溯源）
            $revert = InventoryService::revertOnRecover($uc);

            // 资产行物理删除（回收即资产回收入库；审计 before 快照已留痕）
            Db::name('user_collectibles')->where('id', $ucId)->delete();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '回收失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'blindbox', 'blindbox.recover', [
            'target_type' => 'user_collectible',
            'target_id'   => $ucId,
            'target_desc' => $bb['name'] . ' 资产 #' . $ucId . '（用户 ' . $uc['user_id'] . '）',
            'before'      => [
                'userId' => (int) $uc['user_id'],
                'source' => $uc['source'],
                'status' => $uc['status'],
            ],
            'after'       => ['recovered' => true, 'revert' => $revert],
            'reason'      => $reason,
        ]);

        return $this->success(['revert' => $revert], '盲盒已回收，计数器已回退');
    }

    /**
     * #65 GET /blindboxes/:id/open-records 盲盒开启记录
     * 以奖品资产行（blind_box_item_id 归属该盲盒奖池）作为开启产物记录
     */
    public function openRecords(int $id)
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }

        $query = Db::name('user_collectibles')->alias('uc')
            ->join('nft_blind_box_items bi', 'bi.id = uc.blind_box_item_id')
            ->join('nft_users u', 'u.id = uc.user_id', 'LEFT')
            ->join('nft_collectibles c', 'c.id = uc.collectible_id', 'LEFT')
            ->where('bi.blind_box_id', $id);

        $total = (clone $query)->count();
        $list = $query
            ->field('uc.id,uc.user_id,uc.serial,uc.acquired_at,u.username,c.name AS prize_name,c.image AS prize_image')
            ->order('uc.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(function ($row) {
            return [
                'id'         => (int) $row['id'],
                'username'   => $row['username'] ?? '',
                'serial'     => $row['serial'],
                'prizeName'  => $row['prize_name'] ?? '',
                'prizeImage' => $row['prize_image'] ?? '',
                'openedAt'   => $row['acquired_at'],
            ];
        }, $list);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * #66 GET /blindboxes/:id/audit 盲盒库存审计（映射到藏品行，D-3）
     */
    public function audit(int $id)
    {
        $bb = $this->findBlindBox($id);
        if (!$bb) {
            return $this->fail(409, '盲盒不存在');
        }
        try {
            $result = InventoryService::audit((int) $bb['collectible_id']);
        } catch (InventoryException $e) {
            return $this->fail(409, $e->getMessage());
        }
        $result['blindBoxId'] = $id;
        return $this->success($result);
    }

    /**
     * #67 GET /blindboxes/:id/destroy-records 盲盒销毁记录
     */
    public function destroyRecords(int $id)
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('destroy_records')
            ->where('target_type', 2)
            ->where('target_id', $id);

        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(function ($row) {
            return [
                'id'        => (int) $row['id'],
                'quantity'  => (int) $row['quantity'],
                'reason'    => $row['reason'],
                'adminName' => $row['admin_name'],
                'ip'        => $row['ip'],
                'createdAt' => $row['created_at'],
            ];
        }, $list);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    // =====================================================================
    // 私有辅助
    // =====================================================================

    /**
     * 查询盲盒（bb + 藏品行联查）
     */
    private function findBlindBox(int $id): ?array
    {
        $bb = Db::name('blind_boxes')->alias('bb')
            ->join('nft_collectibles c', 'c.id = bb.collectible_id')
            ->where('bb.id', $id)
            ->whereNull('c.deleted_at')
            ->field('bb.id,bb.collectible_id,bb.is_openable,bb.is_transferable,bb.is_resaleable,bb.description,c.category_id,c.name,c.subtitle,c.image,c.price,c.edition,c.release_quantity,c.circulate,c.sold,c.locked_quantity,c.reserved_count,c.airdropped_count,c.destroyed_count,c.status,c.onsale_at,c.off_sale_at,c.per_user_limit,c.created_at,c.updated_at')
            ->find();
        return $bb ?: null;
    }

    /**
     * 子藏品配置列表
     */
    private function loadItems(int $blindBoxId): array
    {
        $items = Db::name('blind_box_items')->alias('bi')
            ->join('nft_collectibles c', 'c.id = bi.prize_collectible_id', 'LEFT')
            ->where('bi.blind_box_id', $blindBoxId)
            ->whereNull('bi.deleted_at')
            ->field('bi.id,bi.prize_collectible_id,bi.probability,bi.quantity_limit,bi.quantity_distributed,c.name,c.image,c.circulate,c.edition')
            ->order('bi.id')
            ->select()
            ->toArray();

        return array_map(function ($item) {
            return [
                'id'              => (int) $item['id'],
                'collectibleId'   => (int) $item['prize_collectible_id'],
                'name'            => $item['name'] ?? '',
                'image'           => $item['image'] ?? '',
                'probability'      => (float) $item['probability'],
                'plannedQuantity' => $item['quantity_limit'] !== null ? (int) $item['quantity_limit'] : null,
                'distributed'    => (int) $item['quantity_distributed'],
                'prizeCirculate'  => (int) ($item['circulate'] ?? 0),
                'prizeEdition'    => (int) ($item['edition'] ?? 0),
            ];
        }, $items);
    }

    /**
     * 行格式化（camelCase + 盲盒库存五数，文档 11.2-14）
     */
    private function formatRow(array $bb, bool $full = false): array
    {
        $row = [
            'id'             => (int) $bb['id'],
            'collectibleId'  => (int) $bb['collectible_id'],
            'name'           => $bb['name'],
            'image'          => $bb['image'],
            'price'          => number_format((float) $bb['price'], 2, '.', ''),
            'status'         => $bb['status'],
            'isOpenable'     => (int) $bb['is_openable'] === 1,
            'isTransferable' => (int) $bb['is_transferable'] === 1,
            'isResaleable'   => (int) $bb['is_resaleable'] === 1,
            'description'    => $bb['description'],
            'onsaleAt'       => $bb['onsale_at'],
            'offSaleAt'      => $bb['off_sale_at'],
            'createdAt'      => $bb['created_at'],
        ];
        $row = array_merge($row, InventoryService::counters($bb));
        if ($full) {
            $row = array_merge($row, [
                'releaseQuantity' => $bb['release_quantity'] !== null ? (int) $bb['release_quantity'] : null,
                'perUserLimit'    => isset($bb['per_user_limit']) ? (int) $bb['per_user_limit'] : 0,
                'saleable'        => InventoryService::saleable($bb),
                'updatedAt'      => $bb['updated_at'] ?? null,
            ]);
        }
        return $row;
    }
}
