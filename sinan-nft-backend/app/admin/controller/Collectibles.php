<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AuditLogService;
use app\admin\service\InventoryException;
use app\admin\service\InventoryService;
use think\facade\Db;

/**
 * 藏品管理控制器（文档 8.6，18 接口）
 *
 * 状态机（文档 6.1）：
 *   draft（草稿）→ upcoming（待发售）→ onsale（发售中）→ soldout / off
 *   soldout / off 可重新上架（relist）；draft 无关联可删除
 *
 * 高风险操作（销毁/空投/强制售罄/重新上架/寄售开关/价格管控/配额修改/删除）
 * 均需请求体 password 二次验证（文档 11.1）。
 */
class Collectibles extends AdminBase
{
    /**
     * #33 GET /collectibles 藏品列表
     * 筛选：name/categoryId/status/isResaleable/isTransferable/qualification
     */
    public function index()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('collectibles')->alias('c')
            ->whereNull('c.deleted_at');

        $name = trim((string) $this->strParam('name'));
        if ($name !== '') {
            $query->where('c.name', 'like', "%{$name}%");
        }
        $categoryId = $this->intParam('categoryId');
        if ($categoryId > 0) {
            $query->where('c.category_id', $categoryId);
        }
        $status = $this->strParam('status');
        if ($status !== null && $status !== '') {
            $query->where('c.status', $status);
        }
        $isResaleable = $this->strParam('isResaleable');
        if ($isResaleable !== null && $isResaleable !== '') {
            $query->where('c.is_resaleable', (int) $isResaleable);
        }
        $isTransferable = $this->strParam('isTransferable');
        if ($isTransferable !== null && $isTransferable !== '') {
            $query->where('c.is_transferable', (int) $isTransferable);
        }
        $qualification = $this->strParam('qualification');
        if ($qualification !== null && $qualification !== '') {
            $query->join('nft_qualification_configs qc', 'qc.collectible_id = c.id', 'LEFT');
            if ((int) $qualification === 1) {
                $query->where('qc.is_enabled', 1);
            } else {
                $query->where(function ($q) {
                    $q->where('qc.id', null)->whereOr('qc.is_enabled', 0);
                });
            }
        }

        $total = (clone $query)->count();
        $list  = $query
            ->field('c.id,c.name,c.subtitle,c.image,c.price,c.edition,c.release_quantity,c.sold,c.locked_quantity,c.reserved_count,c.airdropped_count,c.destroyed_count,c.circulate,c.status,c.category_id,c.is_resaleable,c.is_transferable,c.onsale_at,c.off_sale_at,c.created_at')
            ->order('c.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $categoryNames = Db::name('categories')->column('name', 'id');
        $list = array_map(function ($row) use ($categoryNames) {
            return $this->formatRow($row, $categoryNames);
        }, $list);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * #34 POST /collectibles 创建藏品（创建后为 draft 草稿）
     * 参数：name/category_id 必填；edition 必填；story(创作故事)/description(简介)/images[]/issuer/creator/tag 可选
     * 字段映射：story→description、description→subtitle、images[0]→image（现有表结构，文档 4.1 权威 DDL）
     */
    public function create()
    {
        $v = $this->validateBase();
        if ($v !== null) {
            return $v;
        }

        $data = $this->collectBaseFields();
        $now  = date('Y-m-d H:i:s');

        $id = Db::name('collectibles')->insertGetId(array_merge($data, [
            'circulate'   => 0,
            'sold'        => 0,
            'locked_quantity' => 0,
            'reserved_count' => 0,
            'airdropped_count' => 0,
            'destroyed_count' => 0,
            'status'      => 'draft',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]));

        AuditLogService::log($this->request, 'collectible', 'collectible.create', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $data['name'],
            'after'       => ['name' => $data['name'], 'edition' => $data['edition'], 'status' => 'draft'],
        ]);

        return $this->success(['id' => (int) $id], '藏品创建成功（草稿）');
    }

    /**
     * #35 GET /collectibles/:id 藏品详情（含库存五数与全部开关）
     */
    public function detail(int $id)
    {
        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }

        $categoryNames = Db::name('categories')->column('name', 'id');
        $result = $this->formatRow($c, $categoryNames, true);

        // 配额列表
        $quotas = Db::name('inventory_quotas')->where('collectible_id', $id)->order('id')->select()->toArray();
        $result['quotas'] = array_map(function ($q) {
            return [
                'id'              => (int) $q['id'],
                'quotaType'       => (int) $q['quota_type'],
                'quotaName'       => $q['quota_name'],
                'plannedQuantity' => (int) $q['planned_quantity'],
                'usedQuantity'    => (int) $q['used_quantity'],
                'status'          => (int) $q['status'],
                'activityId'      => $q['activity_id'] !== null ? (int) $q['activity_id'] : null,
                'activityType'    => $q['activity_type'],
                'remark'          => $q['remark'],
                'createdAt'       => $q['created_at'],
            ];
        }, $quotas);

        // 资格购配置 + 白名单（手机号脱敏）
        $qc = Db::name('qualification_configs')->where('collectible_id', $id)->find();
        if ($qc) {
            $whitelist = Db::name('qualification_whitelists')
                ->where('config_id', $qc['id'])
                ->where('status', 1)
                ->select()->toArray();
            $result['qualification'] = [
                'isEnabled'   => (int) $qc['is_enabled'] === 1,
                'requiredCollectibleIds' => json_decode((string) $qc['required_collectible_ids'], true) ?: [],
                'requiredCheckinDays'    => (int) $qc['required_checkin_days'],
                'requiredInviteCount'    => (int) $qc['required_invite_count'],
                'conditionType' => (int) $qc['condition_type'],
                'validStartAt'  => $qc['valid_start_at'],
                'validEndAt'    => $qc['valid_end_at'],
                'whitelist'     => array_map(function ($w) {
                    return [
                        'userId' => (int) $w['user_id'],
                        'phone'  => mask_phone((string) $w['phone']),
                        'expiresAt' => $w['expires_at'],
                    ];
                }, $whitelist),
            ];
        } else {
            $result['qualification'] = null;
        }

        // 是否盲盒行（D-3：盲盒行即藏品行）
        $result['isBlindbox'] = Db::name('blind_boxes')->where('collectible_id', $id)->count() > 0;

        return $this->success($result);
    }

    /**
     * #36 PUT /collectibles/:id 编辑藏品（仅 draft，文档 6.1）
     */
    public function update(int $id)
    {
        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }
        if ($c['status'] !== 'draft') {
            return $this->conflict("仅草稿状态可编辑基础信息，当前状态为 {$c['status']}");
        }

        $v = $this->validateBase(false);
        if ($v !== null) {
            return $v;
        }

        $data = $this->collectBaseFields(false);
        if (!$data) {
            return $this->invalid('未提供任何修改字段');
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        Db::name('collectibles')->where('id', $id)->update($data);

        AuditLogService::log($this->request, 'collectible', 'collectible.edit', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $data['name'] ?? $c['name'],
            'before'      => $this->baseSnapshot($c),
            'after'       => $data,
        ]);

        return $this->success(null, '藏品已更新');
    }

    /**
     * #37 POST /collectibles/:id/release 发售配置
     * draft/upcoming 可配置（文档 6.1）；配置后按 onsale_at 判定 upcoming/onsale
     */
    public function release(int $id)
    {
        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }
        if (!in_array($c['status'], ['draft', 'upcoming'], true)) {
            return $this->conflict("当前状态 {$c['status']} 不允许发售配置（仅草稿/待发售）");
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

        $perUserLimit = $this->intParam('perUserLimit', 0);
        if ($perUserLimit < 0) {
            return $this->invalid('每人限购数量不能为负');
        }

        $releaseQuantity = $this->strParam('releaseQuantity');
        if ($releaseQuantity !== null && $releaseQuantity !== '') {
            if (!is_numeric($releaseQuantity) || (int) $releaseQuantity < 0) {
                return $this->invalid('计划发售数量必须为非负整数');
            }
            if ((int) $releaseQuantity > (int) $c['edition']) {
                return $this->conflict("计划发售数量不能超过发行总量 {$c['edition']}");
            }
            $releaseQuantity = (int) $releaseQuantity;
        } else {
            $releaseQuantity = null;
        }

        $now = date('Y-m-d H:i:s');
        // 到点切换以 onsale_at 判定为主（文档 6.1：不依赖定时任务硬刷）
        $newStatus = strtotime((string) $onsaleAt) > time() ? 'upcoming' : 'onsale';

        $update = [
            'price'            => number_format((float) $price, 2, '.', ''),
            'onsale_at'        => $onsaleAt,
            'off_sale_at'      => $offSaleAt ?: null,
            'per_user_limit'   => $perUserLimit,
            'release_quantity' => $releaseQuantity,
            'status'           => $newStatus,
            'updated_at'       => $now,
        ];
        Db::name('collectibles')->where('id', $id)->update($update);

        AuditLogService::log($this->request, 'collectible', 'collectible.release', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'],
            'before'      => ['status' => $c['status'], 'price' => $c['price'], 'onsale_at' => $c['onsale_at']],
            'after'       => $update,
        ]);

        return $this->success(['status' => $newStatus], '发售配置已保存');
    }

    /**
     * #38 POST /collectibles/:id/quotas 配额配置（quotas[]，校验库存池）
     */
    public function quotas(int $id)
    {
        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }

        $quotas = $this->request->param('quotas');
        if (!is_array($quotas) || !$quotas) {
            return $this->invalid('quotas[] 不能为空');
        }

        try {
            $result = InventoryService::addQuotas($id, $quotas, $this->adminId());
        } catch (InventoryException $e) {
            return $this->conflict($e->getMessage());
        }

        AuditLogService::log($this->request, 'collectible', 'collectible.quota.config', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'],
            'after'       => $result,
        ]);

        return $this->success($result, '配额配置成功');
    }

    /**
     * #39 PUT /collectibles/:id/quotas/:quota_id 修改配额
     * planned ≥ used（已使用不可减）；差额回库存池；status=0 停用释放未用部分
     */
    public function updateQuota(int $id, int $quotaId)
    {
        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }

        // 配额修改属高风险操作（文档 11.1）
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $params = [
            'quota_name' => $this->strParam('quotaName'),
        ];
        $planned = $this->strParam('plannedQuantity');
        if ($planned !== null && $planned !== '') {
            if (!is_numeric($planned)) {
                return $this->invalid('计划数量必须为整数');
            }
            $params['planned_quantity'] = (int) $planned;
        }
        $status = $this->strParam('status');
        if ($status !== null && $status !== '') {
            $params['status'] = (int) $status;
        }
        if ($this->request->has('remark')) {
            $params['remark'] = (string) $this->request->param('remark', '');
        }

        try {
            $result = InventoryService::updateQuota($id, $quotaId, $params);
        } catch (InventoryException $e) {
            return $this->conflict($e->getMessage());
        }

        AuditLogService::log($this->request, 'collectible', 'collectible.quota.update', [
            'target_type' => 'quota',
            'target_id'   => $quotaId,
            'target_desc' => $c['name'] . ' 配额 #' . $quotaId,
            'after'       => $result,
        ]);

        return $this->success($result, '配额已更新');
    }

    /**
     * #40 POST /collectibles/:id/relist 重新上架（soldout/off；release_quantity ≤ 库存池；密码）
     */
    public function relist(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }
        if (!in_array($c['status'], ['soldout', 'off'], true)) {
            return $this->conflict("当前状态 {$c['status']} 不允许重新上架（仅已售罄/已下架）");
        }

        $releaseQuantity = $this->strParam('releaseQuantity');
        if ($releaseQuantity === null || $releaseQuantity === '' || !is_numeric($releaseQuantity)) {
            return $this->invalid('请提供本次计划发售数量');
        }
        $releaseQuantity = (int) $releaseQuantity;

        $pool = InventoryService::stockPool($c);
        if ($releaseQuantity <= 0 || $releaseQuantity > $pool) {
            return $this->conflict("上架数量超出库存池，当前库存池为 {$pool}");
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
        Db::name('collectibles')->where('id', $id)->update($update);

        AuditLogService::log($this->request, 'collectible', 'collectible.relist', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'],
            'before'      => ['status' => $c['status'], 'release_quantity' => $c['release_quantity']],
            'after'       => $update,
        ]);

        return $this->success(['status' => $newStatus, 'stockPool' => $pool], '重新上架成功');
    }

    /**
     * #41 POST /collectibles/:id/force-soldout 强制售罄（reason/password，不清零计数器）
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

        try {
            $result = InventoryService::forceSoldout($id);
        } catch (InventoryException $e) {
            return $this->conflict($e->getMessage());
        }

        $c = $this->findCollectible($id);
        AuditLogService::log($this->request, 'collectible', 'collectible.force_soldout', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'] ?? '',
            'before'      => ['status' => $c['status'] ?? ''],
            'after'       => ['status' => 'soldout', 'note' => '计数器未清零'],
            'reason'      => $reason,
        ]);

        return $this->success($result, '已强制售罄（计数器未清零，剩余量保留在库存池）');
    }

    /**
     * #42 POST /collectibles/:id/destroy 销毁库存（quantity ≤ 库存池 + reason + password；不可逆）
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

        try {
            $result = InventoryService::destroy($id, $quantity, $reason, [
                'id'   => $this->adminId(),
                'name' => $this->adminName(),
                'ip'   => $this->ip(),
            ], 1, $id);
        } catch (InventoryException $e) {
            return $this->conflict($e->getMessage());
        }

        $c = $this->findCollectible($id);
        AuditLogService::log($this->request, 'collectible', 'collectible.destroy', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'] ?? '',
            'after'       => $result,
            'reason'      => $reason,
        ]);

        return $this->success($result, "已销毁 {$quantity} 份（不可逆）");
    }

    /**
     * #43 DELETE /collectibles/:id 删除藏品（仅草稿无关联 + password；软删）
     */
    public function delete(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }
        if ($c['status'] !== 'draft') {
            return $this->conflict("仅草稿状态可删除，当前状态为 {$c['status']}");
        }

        // 关联校验（存在任一关联即拦截，明确提示）
        $checks = [
            ['订单', Db::name('orders')->where('collectible_id', $id)->count()],
            ['用户资产', Db::name('user_collectibles')->where('collectible_id', $id)->count()],
            ['寄售挂单', Db::name('resale_listings')->where('collectible_id', $id)->count()],
            ['空投记录', Db::name('airdrop_records')->where('collectible_id', $id)->count()],
            ['销毁记录', Db::name('destroy_records')->where('collectible_id', $id)->count()],
            ['盲盒行', Db::name('blind_boxes')->where('collectible_id', $id)->count()],
            ['盲盒奖池引用', Db::name('blind_box_items')->where('prize_collectible_id', $id)->count()],
        ];
        foreach ($checks as [$label, $cnt]) {
            if ((int) $cnt > 0) {
                return $this->conflict("藏品存在{$label}关联（{$cnt} 条），无法删除");
            }
        }

        Db::name('collectibles')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        AuditLogService::log($this->request, 'collectible', 'collectible.delete', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'],
            'before'      => ['status' => 'draft'],
            'after'       => ['deleted' => true],
        ]);

        return $this->success(null, '藏品已删除');
    }

    /**
     * #44 POST /collectibles/:id/airdrop 独立空投（quantity/phones[] 换行批量 + password）
     */
    public function airdrop(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }
        if ($c['status'] === 'draft') {
            return $this->conflict('草稿藏品不可空投，请先完成发售配置');
        }

        $quantity = $this->intParam('quantity');
        if ($quantity <= 0) {
            return $this->invalid('每个用户的空投数量必须大于 0');
        }
        // phones[] 支持数组或换行分隔文本（文档 11.1：换行批量）
        $phones = $this->request->param('phones');
        if (is_string($phones)) {
            $phones = preg_split('/\r\n|\r|\n/', trim($phones)) ?: [];
        }
        if (!is_array($phones) || !$phones) {
            return $this->invalid('空投手机号不能为空');
        }

        try {
            $result = InventoryService::airdrop($id, $phones, $quantity, [
                'id'   => $this->adminId(),
                'name' => $this->adminName(),
                'ip'   => $this->ip(),
            ]);
        } catch (InventoryException $e) {
            return $this->conflict($e->getMessage());
        }

        AuditLogService::log($this->request, 'collectible', 'collectible.airdrop', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'],
            'after'       => [
                'users' => $result['users'],
                'perUser' => $result['perUser'],
                'total' => $result['total'],
            ],
        ]);

        return $this->success($result, "空投成功：{$result['users']} 名用户共 {$result['total']} 份");
    }

    /**
     * #45 POST /collectibles/:id/resale-toggle 寄售开关（is_resaleable/reason/password）
     * 关闭时联动强制下架该藏品全部在售挂单（文档 11.1 / 5.3）
     */
    public function resaleToggle(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }

        $isResaleable = $this->intParam('isResaleable', -1);
        if (!in_array($isResaleable, [0, 1], true)) {
            return $this->invalid('isResaleable 必须为 0 或 1');
        }
        $reason = trim((string) $this->strParam('reason'));
        if ($isResaleable === 0 && $reason === '') {
            return $this->invalid('关闭寄售必须提供原因');
        }

        $now = date('Y-m-d H:i:s');
        Db::name('collectibles')->where('id', $id)->update([
            'is_resaleable' => $isResaleable,
            'updated_at'    => $now,
        ]);

        // 关闭寄售：强制下架全部在售挂单（联动，文档 5.3）
        $delisted = 0;
        if ($isResaleable === 0) {
            $delisted = Db::name('resale_listings')
                ->where('collectible_id', $id)
                ->where('status', 'selling')
                ->update([
                    'status'             => 'system_off',
                    'is_system_delisted' => 1,
                    'system_delisted_at' => $now,
                    'delist_reason'      => $reason,
                    'updated_at'         => $now,
                ]);
        }

        AuditLogService::log($this->request, 'collectible', 'collectible.resale_toggle', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'],
            'before'      => ['is_resaleable' => (int) $c['is_resaleable']],
            'after'       => ['is_resaleable' => $isResaleable, 'delistedListings' => (int) $delisted],
            'reason'      => $reason ?: null,
        ]);

        return $this->success([
            'isResaleable'    => $isResaleable === 1,
            'delistedListings' => (int) $delisted,
        ], $isResaleable === 1 ? '寄售已开启' : "寄售已关闭，强制下架 {$delisted} 个挂单");
    }

    /**
     * #46 POST /collectibles/:id/price-control 价格管控（resale_price_mode/min/max + password）
     */
    public function priceControl(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }

        $mode = $this->intParam('resalePriceMode', -1);
        if (!in_array($mode, [0, 1], true)) {
            return $this->invalid('resalePriceMode 必须为 0（不限价）或 1（限价）');
        }

        $min = $this->strParam('resalePriceMin');
        $max = $this->strParam('resalePriceMax');
        if ($mode === 1) {
            if (($min === null || $min === '' || !is_numeric($min)) && ($max === null || $max === '' || !is_numeric($max))) {
                return $this->invalid('限价模式必须至少提供价格下限或上限');
            }
            if ($min !== null && $min !== '' && !is_numeric($min)) {
                return $this->invalid('价格下限必须为数字');
            }
            if ($max !== null && $max !== '' && !is_numeric($max)) {
                return $this->invalid('价格上限必须为数字');
            }
            if ($min !== null && $min !== '' && $max !== null && $max !== '' && (float) $min > (float) $max) {
                return $this->invalid('价格下限不能大于上限');
            }
        }

        $update = [
            'resale_price_mode' => $mode,
            'resale_price_min'  => ($mode === 1 && $min !== null && $min !== '') ? number_format((float) $min, 2, '.', '') : null,
            'resale_price_max'  => ($mode === 1 && $max !== null && $max !== '') ? number_format((float) $max, 2, '.', '') : null,
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        Db::name('collectibles')->where('id', $id)->update($update);

        AuditLogService::log($this->request, 'collectible', 'collectible.price_control', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'],
            'before'      => [
                'mode' => (int) $c['resale_price_mode'],
                'min'  => $c['resale_price_min'],
                'max'  => $c['resale_price_max'],
            ],
            'after' => $update,
        ]);

        return $this->success($update, $mode === 1 ? '价格管控已启用' : '价格管控已关闭（不限价）');
    }

    /**
     * #47 POST /collectibles/:id/qualification 资格购配置（条件组合/有效期/白名单手机号）
     * 规则（文档 5.1）：资格藏品必须流通量>0；白名单手机号必须已注册（提示行号与号码）
     */
    public function qualification(int $id)
    {
        $c = $this->findCollectible($id);
        if (!$c) {
            return $this->fail(409, '藏品不存在');
        }

        $isEnabled = $this->intParam('isEnabled', -1);
        if (!in_array($isEnabled, [0, 1], true)) {
            return $this->invalid('isEnabled 必须为 0 或 1');
        }

        $requiredCollectibleIds = $this->request->param('requiredCollectibleIds');
        if (is_string($requiredCollectibleIds)) {
            $requiredCollectibleIds = array_filter(array_map('trim', explode(',', $requiredCollectibleIds)));
        }
        $requiredCollectibleIds = is_array($requiredCollectibleIds)
            ? array_values(array_filter(array_map('intval', $requiredCollectibleIds)))
            : [];
        $requiredCheckinDays = max(0, $this->intParam('requiredCheckinDays', 0));
        $requiredInviteCount = max(0, $this->intParam('requiredInviteCount', 0));
        $conditionType = $this->intParam('conditionType', 1);
        if (!in_array($conditionType, [1, 2], true)) {
            return $this->invalid('conditionType 必须为 1（满足任一）或 2（满足全部）');
        }
        $validStartAt = $this->strParam('validStartAt');
        $validEndAt   = $this->strParam('validEndAt');
        if ($validStartAt !== null && $validStartAt !== '' && !strtotime($validStartAt)) {
            return $this->invalid('有效期开始时间格式不正确');
        }
        if ($validEndAt !== null && $validEndAt !== '' && !strtotime($validEndAt)) {
            return $this->invalid('有效期结束时间格式不正确');
        }
        if ($validStartAt && $validEndAt && strtotime((string) $validEndAt) <= strtotime((string) $validStartAt)) {
            return $this->invalid('有效期结束时间必须晚于开始时间');
        }

        // 白名单手机号（数组或换行分隔文本；逐行校验必须已注册，文档 5.1-4）
        $whitelistPhones = $this->request->param('whitelistPhones');
        if (is_string($whitelistPhones)) {
            $whitelistPhones = preg_split('/\r\n|\r|\n|,/', trim((string) $whitelistPhones)) ?: [];
        }
        $whitelistPhones = is_array($whitelistPhones)
            ? array_values(array_filter(array_map('trim', $whitelistPhones)))
            : [];
        $whitelistUsers = [];
        foreach ($whitelistPhones as $line => $phone) {
            if (!preg_match('/^1\d{10}$/', $phone)) {
                return $this->invalid('白名单第 ' . ($line + 1) . ' 行手机号 ' . $phone . ' 格式不正确');
            }
            $user = Db::name('users')->where('phone', $phone)->whereNull('deleted_at')->find();
            if (!$user) {
                return $this->invalid('白名单第 ' . ($line + 1) . ' 行手机号 ' . $phone . ' 尚未注册');
            }
            $whitelistUsers[] = ['user_id' => (int) $user['id'], 'phone' => $phone];
        }

        // 资格藏品校验：流通量 > 0（文档 5.1-1）
        foreach ($requiredCollectibleIds as $rid) {
            $target = Db::name('collectibles')
                ->where('id', $rid)
                ->whereNull('deleted_at')
                ->field('id,name,circulate')
                ->find();
            if (!$target) {
                return $this->invalid("资格藏品 #{$rid} 不存在");
            }
            if ((int) $target['circulate'] <= 0) {
                return $this->conflict("资格藏品「{$target['name']}」流通量为 0，不可作为资格条件");
            }
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            $config = Db::name('qualification_configs')
                ->where('collectible_id', $id)
                ->lock(true)
                ->find();

            $payload = [
                'collectible_id' => $id,
                'is_enabled'     => $isEnabled,
                'required_collectible_ids' => $requiredCollectibleIds ? json_encode($requiredCollectibleIds) : null,
                'required_checkin_days'    => $requiredCheckinDays,
                'required_invite_count'    => $requiredInviteCount,
                'condition_type' => $conditionType,
                'valid_start_at'  => $validStartAt ?: null,
                'valid_end_at'    => $validEndAt ?: null,
                'updated_at'      => $now,
            ];

            if ($config) {
                Db::name('qualification_configs')->where('id', $config['id'])->update($payload);
                $configId = (int) $config['id'];
            } else {
                $payload['created_at'] = $now;
                $configId = (int) Db::name('qualification_configs')->insertGetId($payload);
            }

            // 白名单全量重建（差异以删除+插入保证一致）
            Db::name('qualification_whitelists')->where('config_id', $configId)->delete();
            foreach ($whitelistUsers as $wu) {
                Db::name('qualification_whitelists')->insert([
                    'config_id'  => $configId,
                    'user_id'    => $wu['user_id'],
                    'phone'      => $wu['phone'],
                    'status'     => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '资格购配置失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'collectible', 'collectible.qualification', [
            'target_type' => 'collectible',
            'target_id'   => $id,
            'target_desc' => $c['name'],
            'before'      => $config ? [
                'is_enabled' => (int) $config['is_enabled'],
                'required_collectible_ids' => $config['required_collectible_ids'],
            ] : null,
            'after' => [
                'is_enabled' => $isEnabled,
                'required_collectible_ids' => $requiredCollectibleIds,
                'required_checkin_days' => $requiredCheckinDays,
                'required_invite_count' => $requiredInviteCount,
                'condition_type' => $conditionType,
                'whitelist_count' => count($whitelistUsers),
            ],
        ]);

        return $this->success(null, '资格购配置已保存');
    }

    /**
     * #48 GET /collectibles/:id/audit 库存审计（守恒校验，文档 4.3.1）
     */
    public function audit(int $id)
    {
        try {
            $result = InventoryService::audit($id);
        } catch (InventoryException $e) {
            return $this->fail(409, $e->getMessage());
        }
        return $this->success($result);
    }

    /**
     * #49 GET /collectibles/:id/airdrop-records 空投发放记录
     */
    public function airdropRecords(int $id)
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('airdrop_records')->alias('ar')
            ->where('ar.collectible_id', $id);

        $status = $this->strParam('status');
        if ($status !== null && $status !== '') {
            $query->where('ar.status', $status);
        }
        $source = $this->strParam('source'); // independent=独立空投 / activity=活动空投
        if ($source === 'independent') {
            $query->whereNull('ar.activity_id');
        } elseif ($source === 'activity') {
            $query->whereNotNull('ar.activity_id');
        }

        $total = (clone $query)->count();
        $list = $query
            ->field('ar.id,ar.activity_id,ar.user_id,ar.phone,ar.quantity,ar.status,ar.issued_at,ar.created_at,u.username')
            ->join('nft_users u', 'u.id = ar.user_id', 'LEFT')
            ->order('ar.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $activityNames = Db::name('airdrop_activities')->column('name', 'id');
        $list = array_map(function ($row) use ($activityNames) {
            return [
                'id'         => (int) $row['id'],
                'username'   => $row['username'] ?? '',
                'phone'      => mask_phone((string) $row['phone']),
                'quantity'   => (int) $row['quantity'],
                'status'     => $row['status'],
                'source'     => $row['activity_id'] === null ? '独立空投' : ($activityNames[$row['activity_id']] ?? '活动空投 #' . $row['activity_id']),
                'issuedAt'   => $row['issued_at'],
                'createdAt'  => $row['created_at'],
            ];
        }, $list);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * #50 GET /collectibles/:id/destroy-records 销毁记录
     */
    public function destroyRecords(int $id)
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('destroy_records')
            ->where('collectible_id', $id)
            ->where('target_type', 1);

        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(function ($row) {
            return [
                'id'         => (int) $row['id'],
                'quantity'   => (int) $row['quantity'],
                'reason'     => $row['reason'],
                'adminName'  => $row['admin_name'],
                'ip'         => $row['ip'],
                'createdAt' => $row['created_at'],
            ];
        }, $list);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    // =====================================================================
    // 私有辅助
    // =====================================================================

    private function findCollectible(int $id): ?array
    {
        $c = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        return $c ?: null;
    }

    /**
     * 创建/编辑基础信息校验
     */
    private function validateBase(bool $isCreate = true): ?\think\Response
    {
        if ($isCreate) {
            if (trim((string) $this->strParam('name')) === '') {
                return $this->invalid('藏品名称不能为空');
            }
            $categoryId = $this->intParam('categoryId');
            if ($categoryId <= 0) {
                return $this->invalid('请选择藏品分类');
            }
            if (!Db::name('categories')->where('id', $categoryId)->count()) {
                return $this->invalid('藏品分类不存在');
            }
            $edition = $this->intParam('edition');
            if ($edition <= 0) {
                return $this->invalid('发行总量必须大于 0');
            }
        } else {
            $categoryId = $this->strParam('categoryId');
            if ($categoryId !== null && $categoryId !== '') {
                if (!Db::name('categories')->where('id', (int) $categoryId)->count()) {
                    return $this->invalid('藏品分类不存在');
                }
            }
            $edition = $this->strParam('edition');
            if ($edition !== null && $edition !== '' && ((int) $edition <= 0 || !is_numeric($edition))) {
                return $this->invalid('发行总量必须为大于 0 的整数');
            }
        }
        return null;
    }

    /**
     * 收集基础信息字段（story→description、description→subtitle、images[0]→image）
     */
    private function collectBaseFields(bool $isCreate = true): array
    {
        $data = [];
        if ($isCreate) {
            $data['name']        = trim((string) $this->strParam('name'));
            $data['category_id'] = $this->intParam('categoryId');
            $data['edition']     = $this->intParam('edition');
            $data['price']       = 0.00; // 发售配置时设置
        } else {
            $name = $this->strParam('name');
            if ($name !== null && trim($name) !== '') {
                $data['name'] = trim($name);
            }
            $categoryId = $this->strParam('categoryId');
            if ($categoryId !== null && $categoryId !== '') {
                $data['category_id'] = (int) $categoryId;
            }
            $edition = $this->strParam('edition');
            if ($edition !== null && $edition !== '') {
                $data['edition'] = (int) $edition;
            }
        }

        // story（创作故事）→ description
        if ($this->request->has('story')) {
            $data['description'] = (string) $this->request->param('story', '');
        }
        // description（简介）→ subtitle
        if ($this->request->has('description')) {
            $data['subtitle'] = (string) $this->request->param('description', '');
        }
        // images[] → image（首图；现有表单主图列）
        $images = $this->request->param('images');
        if (is_string($images) && $images !== '') {
            $images = [$images];
        }
        if (is_array($images) && $images) {
            $first = trim((string) $images[0]);
            if ($isCreate && $first === '') {
                $first = 'placeholder.png';
            }
            if ($first !== '') {
                $data['image'] = $first;
            }
        } elseif ($isCreate) {
            $data['image'] = 'placeholder.png';
        }

        foreach (['issuer', 'creator', 'tag'] as $field) {
            if ($this->request->has($field)) {
                $v = trim((string) $this->request->param($field, ''));
                if ($v !== '' || !$isCreate) {
                    $data[$field] = $v !== '' ? $v : null;
                }
            }
        }

        return $data;
    }

    /**
     * 基础信息快照（审计 before 用）
     */
    private function baseSnapshot(array $c): array
    {
        return [
            'name'        => $c['name'],
            'subtitle'   => $c['subtitle'],
            'image'      => $c['image'],
            'edition'    => (int) $c['edition'],
            'issuer'     => $c['issuer'],
            'creator'    => $c['creator'],
            'tag'        => $c['tag'],
            'description' => $c['description'],
        ];
    }

    /**
     * 行格式化（camelCase + 库存五数，文档 11.2-13）
     */
    private function formatRow(array $c, array $categoryNames, bool $full = false): array
    {
        $row = [
            'id'          => (int) $c['id'],
            'name'        => $c['name'],
            'subtitle'    => $c['subtitle'],
            'image'       => $c['image'],
            'price'       => number_format((float) $c['price'], 2, '.', ''),
            'category'    => $categoryNames[$c['category_id']] ?? ('#' . $c['category_id']),
            'categoryId'  => (int) $c['category_id'],
            'status'      => $c['status'],
            'isResaleable'  => (int) $c['is_resaleable'] === 1,
            'isTransferable' => (int) $c['is_transferable'] === 1,
            'onsaleAt'    => $c['onsale_at'],
            'offSaleAt'   => $c['off_sale_at'],
            'createdAt'   => $c['created_at'],
        ];
        $row = array_merge($row, InventoryService::counters($c));
        if ($full) {
            $row = array_merge($row, [
                'releaseQuantity' => $c['release_quantity'] !== null ? (int) $c['release_quantity'] : null,
                'perUserLimit'    => (int) $c['per_user_limit'],
                'resalePriceMode' => (int) $c['resale_price_mode'],
                'resalePriceMin'  => $c['resale_price_min'] !== null ? number_format((float) $c['resale_price_min'], 2, '.', '') : null,
                'resalePriceMax'  => $c['resale_price_max'] !== null ? number_format((float) $c['resale_price_max'], 2, '.', '') : null,
                'issuer'          => $c['issuer'],
                'creator'         => $c['creator'],
                'tag'             => $c['tag'],
                'description'     => $c['description'],
                'saleable'        => InventoryService::saleable($c),
                'updatedAt'       => $c['updated_at'],
            ]);
        }
        return $row;
    }
}
