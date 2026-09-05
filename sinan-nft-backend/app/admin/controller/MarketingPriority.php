<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AuditLogService;
use think\facade\Db;

/**
 * 优先购活动管理控制器（文档 8.12，#93-#102，共 10 个）
 *
 * 优先购是"时间优先"通道（公售前提前购买），与资格购完全独立（文档 5.2）：
 * - 白名单记录 user_id/phone、max_quantity、used_quantity、expires_at（精确到时分秒）
 * - 有效资格 = expires_at > now 且 used_quantity < max_quantity 且活动窗口内
 * - 购买成功 used_quantity + 1（C 端下单原子条件 UPDATE 防并发超用，联动点 10.2）
 * - 支持批量清理过期资格（密码二次验证 + 审计）
 */
class MarketingPriority extends AdminBase
{
    /**
     * 活动行（含藏品信息）
     */
    private function findSale(int $id): ?array
    {
        $sale = Db::name('priority_sales')->alias('ps')
            ->join('collectibles c', 'c.id = ps.collectible_id', 'LEFT')
            ->where('ps.id', $id)
            ->field('ps.*,c.name AS collectible_name,c.image,c.onsale_at,c.status AS collectible_status')
            ->find();
        return $sale ?: null;
    }

    /**
     * 活动统计：白名单数 / 发放总量 / 已用 / 剩余
     */
    private function saleStats(int $saleId): array
    {
        $row = Db::name('priority_sale_whitelists')
            ->where('priority_sale_id', $saleId)
            ->field('COUNT(*) AS cnt,COALESCE(SUM(max_quantity),0) AS granted,COALESCE(SUM(used_quantity),0) AS used')
            ->find();
        $granted = (int) ($row['granted'] ?? 0);
        $used    = (int) ($row['used'] ?? 0);
        return [
            'whitelistCount' => (int) ($row['cnt'] ?? 0),
            'grantedTotal'   => $granted,
            'usedTotal'      => $used,
            'remainingTotal' => max($granted - $used, 0),
        ];
    }

    /**
     * 白名单行状态：valid / expired / usedUp / disabled
     */
    private function whitelistState(array $w): array
    {
        $now       = date('Y-m-d H:i:s');
        $isExpired = !empty($w['expires_at']) && strtotime((string) $w['expires_at']) < strtotime($now);
        $isUsedUp  = (int) $w['used_quantity'] >= (int) $w['max_quantity'];
        $state     = 'valid';
        if ((int) $w['status'] !== 1) {
            $state = 'disabled';
        } elseif ($isUsedUp) {
            $state = 'usedUp';
        } elseif ($isExpired) {
            $state = 'expired';
        }
        $stateText = ['valid' => '有效', 'expired' => '已过期', 'usedUp' => '已用完', 'disabled' => '已停用'];
        return [$state, $stateText[$state]];
    }

    /**
     * #93 GET /marketing/priority 优先购活动列表（collectible_id/status）
     */
    public function index()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('priority_sales')->alias('ps')
            ->join('collectibles c', 'c.id = ps.collectible_id', 'LEFT');

        $collectibleId = $this->intParam('collectibleId');
        if ($collectibleId > 0) {
            $query->where('ps.collectible_id', $collectibleId);
        }
        $status = $this->intParam('status', -1);
        if (in_array($status, [0, 1], true)) {
            $query->where('ps.status', $status);
        }

        $total = (clone $query)->count();
        $list = $query
            ->field('ps.*,c.name AS collectible_name,c.image,c.onsale_at')
            ->order('ps.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $now = date('Y-m-d H:i:s');
        $items = array_map(function (array $r) use ($now) {
            $stats = $this->saleStats((int) $r['id']);
            // 活动窗口态
            $phase = 'pending';
            if (strtotime((string) $r['start_time']) <= strtotime($now)) {
                $phase = strtotime((string) $r['end_time']) >= strtotime($now) ? 'ongoing' : 'ended';
            }
            return [
                'id'              => (int) $r['id'],
                'name'            => (string) $r['name'],
                'collectibleId'   => (int) $r['collectible_id'],
                'collectibleName' => (string) ($r['collectible_name'] ?? ''),
                'image'           => (string) ($r['image'] ?? ''),
                'startTime'       => (string) $r['start_time'],
                'endTime'         => (string) $r['end_time'],
                'status'          => (int) $r['status'],
                'phase'           => $phase,
                'createdAt'       => (string) $r['created_at'],
            ] + $stats;
        }, $list);

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * #94 POST /marketing/priority 创建优先购活动
     * 时间窗校验：start < end；start_time 须早于等于公售开始（onsale_at 为空不限制）
     */
    public function create()
    {
        $name          = trim((string) $this->strParam('name'));
        $collectibleId = $this->intParam('collectibleId');
        $startTime     = (string) $this->strParam('startTime');
        $endTime       = (string) $this->strParam('endTime');

        if ($name === '') {
            return $this->invalid('活动名称不能为空');
        }
        if (mb_strlen($name) > 100) {
            return $this->invalid('活动名称不能超过 100 字');
        }
        $collectible = Db::name('collectibles')
            ->where('id', $collectibleId)
            ->whereNull('deleted_at')
            ->field('id,name,status,onsale_at')
            ->find();
        if (!$collectible) {
            return $this->invalid("藏品 #{$collectibleId} 不存在");
        }
        if (!strtotime($startTime) || !strtotime($endTime)) {
            return $this->invalid('开始/结束时间格式不正确');
        }
        if (strtotime($endTime) <= strtotime($startTime)) {
            return $this->invalid('结束时间必须晚于开始时间');
        }
        // 文档 5.2：时间窗必须早于或重叠藏品发售时间（表注释：优先购开始须早于等于公售开始）
        if (!empty($collectible['onsale_at']) && strtotime($startTime) > strtotime((string) $collectible['onsale_at'])) {
            return $this->conflict('优先购开始时间必须早于或等于公售开始时间（' . $collectible['onsale_at'] . '）');
        }

        $now = date('Y-m-d H:i:s');
        $id  = (int) Db::name('priority_sales')->insertGetId([
            'name'           => $name,
            'collectible_id' => $collectibleId,
            'start_time'     => $startTime,
            'end_time'       => $endTime,
            'status'         => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        AuditLogService::log($this->request, 'marketing', 'marketing.priority.create', [
            'target_type' => 'priority_sale',
            'target_id'   => $id,
            'target_desc' => $name,
            'before'      => null,
            'after'        => [
                'name' => $name, 'collectible_id' => $collectibleId,
                'start_time' => $startTime, 'end_time' => $endTime,
            ],
        ]);

        return $this->success(['id' => $id], '优先购活动已创建');
    }

    /**
     * #95 GET /marketing/priority/:id 活动详情（白名单数/发放/已用统计）
     */
    public function detail(int $id)
    {
        $sale = $this->findSale($id);
        if (!$sale) {
            return $this->fail(409, '活动不存在');
        }
        $stats = $this->saleStats($id);

        $now   = date('Y-m-d H:i:s');
        $phase = 'pending';
        if (strtotime((string) $sale['start_time']) <= strtotime($now)) {
            $phase = strtotime((string) $sale['end_time']) >= strtotime($now) ? 'ongoing' : 'ended';
        }

        return $this->success([
            'id'              => (int) $sale['id'],
            'name'            => (string) $sale['name'],
            'collectibleId'   => (int) $sale['collectible_id'],
            'collectibleName' => (string) ($sale['collectible_name'] ?? ''),
            'image'           => (string) ($sale['image'] ?? ''),
            'startTime'       => (string) $sale['start_time'],
            'endTime'         => (string) $sale['end_time'],
            'status'          => (int) $sale['status'],
            'phase'           => $phase,
            'collectibleOnsaleAt' => (string) ($sale['onsale_at'] ?? ''),
            'createdAt'       => (string) $sale['created_at'],
            'updatedAt'       => (string) $sale['updated_at'],
        ] + $stats);
    }

    /**
     * #96 PUT /marketing/priority/:id 编辑活动（时间窗/状态）
     */
    public function update(int $id)
    {
        $sale = Db::name('priority_sales')->where('id', $id)->find();
        if (!$sale) {
            return $this->fail(409, '活动不存在');
        }

        $name      = $this->strParam('name');
        $startTime = $this->strParam('startTime');
        $endTime   = $this->strParam('endTime');
        $status    = $this->intParam('status', -1);

        $payload = ['updated_at' => date('Y-m-d H:i:s')];
        if ($name !== null) {
            $name = trim($name);
            if ($name === '') {
                return $this->invalid('活动名称不能为空');
            }
            if (mb_strlen($name) > 100) {
                return $this->invalid('活动名称不能超过 100 字');
            }
            $payload['name'] = $name;
        }
        if ($startTime !== null || $endTime !== null) {
            $newStart = $startTime ?? (string) $sale['start_time'];
            $newEnd   = $endTime ?? (string) $sale['end_time'];
            if (!strtotime($newStart) || !strtotime($newEnd)) {
                return $this->invalid('开始/结束时间格式不正确');
            }
            if (strtotime($newEnd) <= strtotime($newStart)) {
                return $this->invalid('结束时间必须晚于开始时间');
            }
            $onsaleAt = Db::name('collectibles')->where('id', (int) $sale['collectible_id'])->value('onsale_at');
            if (!empty($onsaleAt) && strtotime($newStart) > strtotime((string) $onsaleAt)) {
                return $this->conflict('优先购开始时间必须早于或等于公售开始时间（' . $onsaleAt . '）');
            }
            $payload['start_time'] = $newStart;
            $payload['end_time']   = $newEnd;
        }
        if (in_array($status, [0, 1], true)) {
            $payload['status'] = $status;
        }

        Db::name('priority_sales')->where('id', $id)->update($payload);

        AuditLogService::log($this->request, 'marketing', 'marketing.priority.edit', [
            'target_type' => 'priority_sale',
            'target_id'   => $id,
            'target_desc' => (string) $sale['name'],
            'before'      => [
                'name' => (string) $sale['name'], 'start_time' => (string) $sale['start_time'],
                'end_time' => (string) $sale['end_time'], 'status' => (int) $sale['status'],
            ],
            'after' => $payload,
        ]);

        return $this->success(null, '活动已更新');
    }

    /**
     * #97 GET /marketing/priority/:id/whitelist 白名单列表
     */
    public function whitelist(int $id)
    {
        $sale = Db::name('priority_sales')->where('id', $id)->find();
        if (!$sale) {
            return $this->fail(409, '活动不存在');
        }

        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('priority_sale_whitelists')->alias('w')
            ->join('users u', 'u.id = w.user_id', 'LEFT')
            ->where('w.priority_sale_id', $id);

        $state = $this->strParam('state');
        $phone = trim((string) $this->strParam('phone'));
        if ($phone !== '') {
            $query->whereLike('w.phone', "%{$phone}%");
        }

        $total = (clone $query)->count();
        $rows = $query
            ->field('w.*,u.username,u.status AS user_status')
            ->order('w.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        // 状态过滤需基于计算态（本地过滤）
        if ($state !== null && $state !== '') {
            $rows = array_values(array_filter($rows, function (array $r) use ($state) {
                return $this->whitelistState($r)[0] === $state;
            }));
        }

        $items = array_map(function (array $r) {
            [$state, $stateText] = $this->whitelistState($r);
            return [
                'id'           => (int) $r['id'],
                'userId'       => (int) $r['user_id'],
                'username'     => (string) ($r['username'] ?? ''),
                'phone'        => mask_phone((string) $r['phone']),
                'maxQuantity'  => (int) $r['max_quantity'],
                'usedQuantity'  => (int) $r['used_quantity'],
                'remaining'    => max((int) $r['max_quantity'] - (int) $r['used_quantity'], 0),
                'expiresAt'    => (string) $r['expires_at'],
                'status'       => (int) $r['status'],
                'state'        => $state,
                'stateText'    => $stateText,
                'createdAt'    => (string) $r['created_at'],
            ];
        }, $rows);

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * 白名单条目字段校验与组装（新增/编辑/批量共用）
     * @param bool $allowExisting 批量导入 upsert 场景允许已存在（由调用方决定更新策略）
     * @return array{id?:int,user_id:int,phone:string,max_quantity:int,expires_at:string}|\think\Response
     */
    private function resolveWhitelistEntry(int $saleId, array $entry, int $line = 0, ?int $excludeWid = null, bool $allowExisting = false)
    {
        $prefix = $line > 0 ? "第 {$line} 行：" : '';
        $userId   = (int) ($entry['userId'] ?? 0);
        $phone    = trim((string) ($entry['phone'] ?? ''));
        $maxQty   = (int) ($entry['maxQuantity'] ?? 0);
        $expires  = trim((string) ($entry['expiresAt'] ?? ''));

        if ($userId <= 0 && $phone === '') {
            return $this->invalid($prefix . 'user_id 与 phone 至少提供一项');
        }

        // 手机号优先定位用户（未注册拦截，文档 5.1-4 同规则）
        if ($phone !== '') {
            if (!preg_match('/^1\d{10}$/', $phone)) {
                return $this->invalid($prefix . "手机号 {$phone} 格式不正确");
            }
            $user = Db::name('users')->where('phone', $phone)->whereNull('deleted_at')->find();
            if (!$user) {
                return $this->invalid($prefix . "手机号 {$phone} 尚未注册");
            }
            $userId = (int) $user['id'];
        } else {
            $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        }
        if (!$user) {
            return $this->invalid($prefix . "用户 #{$userId} 不存在");
        }

        if ($maxQty < 1 || $maxQty > 9999) {
            return $this->invalid($prefix . '最大可购数量必须为 1~9999 的整数');
        }
        // 有效期精确到时分秒（文档 5.2）
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires) || !strtotime($expires)) {
            return $this->invalid($prefix . '有效期必须为「YYYY-MM-DD HH:mm:ss」格式（精确到时分秒）');
        }

        // 同活动内唯一（uk_sale_user）；批量导入 upsert 场景由调用方处理已存在条目
        if (!$allowExisting) {
            $dup = Db::name('priority_sale_whitelists')
                ->where('priority_sale_id', $saleId)
                ->where('user_id', $userId);
            if ($excludeWid !== null) {
                $dup->where('id', '<>', $excludeWid);
            }
            if ($dup->find()) {
                return $this->conflict($prefix . '该用户已在白名单中（手机号 ' . mask_phone((string) $user['phone']) . '）');
            }
        }

        return [
            'user_id'      => $userId,
            'phone'        => (string) $user['phone'],
            'max_quantity' => $maxQty,
            'expires_at'   => $expires,
        ];
    }

    /**
     * #98 POST /marketing/priority/:id/whitelist 添加白名单
     */
    public function addWhitelist(int $id)
    {
        $sale = Db::name('priority_sales')->where('id', $id)->find();
        if (!$sale) {
            return $this->fail(409, '活动不存在');
        }

        $entry = $this->resolveWhitelistEntry($id, $this->request->param());
        if ($entry instanceof \think\Response) {
            return $entry;
        }

        $now = date('Y-m-d H:i:s');
        $wid = (int) Db::name('priority_sale_whitelists')->insertGetId([
            'priority_sale_id' => $id,
            'user_id'          => $entry['user_id'],
            'phone'            => $entry['phone'],
            'max_quantity'     => $entry['max_quantity'],
            'used_quantity'    => 0,
            'expires_at'       => $entry['expires_at'],
            'status'           => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        AuditLogService::log($this->request, 'marketing', 'marketing.priority.whitelist.add', [
            'target_type' => 'priority_whitelist',
            'target_id'   => $wid,
            'target_desc' => $sale['name'] . ' #' . $entry['user_id'],
            'before'      => null,
            'after'       => $entry,
        ]);

        return $this->success(['id' => $wid], '白名单已添加');
    }

    /**
     * #99 POST /marketing/priority/:id/whitelist/batch 批量导入白名单（items[]）
     * 已存在条目按 upsert 处理（保留 used_quantity，更新次数与有效期）
     */
    public function batchWhitelist(int $id)
    {
        $sale = Db::name('priority_sales')->where('id', $id)->find();
        if (!$sale) {
            return $this->fail(409, '活动不存在');
        }

        $items = $this->request->param('items');
        if (!is_array($items) || empty($items)) {
            return $this->invalid('items[] 不能为空');
        }
        if (count($items) > 1000) {
            return $this->invalid('单次批量导入不能超过 1000 条');
        }

        $inserted = 0;
        $updated  = 0;
        $now      = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            foreach (array_values($items) as $i => $raw) {
                if (!is_array($raw)) {
                    throw new \RuntimeException('第 ' . ($i + 1) . ' 行格式不正确');
                }
                $entry = $this->resolveWhitelistEntry($id, $raw, $i + 1, null, true);
                if ($entry instanceof \think\Response) {
                    // resolve 返回 Response 时提取错误信息抛出（统一回滚）
                    $msg = $entry->getData()['message'] ?? '参数错误';
                    throw new \RuntimeException(is_string($msg) ? $msg : '参数错误');
                }

                $exists = Db::name('priority_sale_whitelists')
                    ->where('priority_sale_id', $id)
                    ->where('user_id', $entry['user_id'])
                    ->lock(true)
                    ->find();
                if ($exists) {
                    Db::name('priority_sale_whitelists')->where('id', $exists['id'])->update([
                        'phone'        => $entry['phone'],
                        'max_quantity' => max($entry['max_quantity'], (int) $exists['used_quantity']),
                        'expires_at'   => $entry['expires_at'],
                        'status'       => 1,
                        'updated_at'   => $now,
                    ]);
                    $updated++;
                } else {
                    Db::name('priority_sale_whitelists')->insert([
                        'priority_sale_id' => $id,
                        'user_id'          => $entry['user_id'],
                        'phone'            => $entry['phone'],
                        'max_quantity'     => $entry['max_quantity'],
                        'used_quantity'    => 0,
                        'expires_at'       => $entry['expires_at'],
                        'status'           => 1,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);
                    $inserted++;
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->invalid('批量导入失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'marketing', 'marketing.priority.whitelist.batch', [
            'target_type' => 'priority_sale',
            'target_id'   => $id,
            'target_desc' => $sale['name'],
            'before'      => null,
            'after'       => ['inserted' => $inserted, 'updated' => $updated, 'total' => count($items)],
        ]);

        return $this->success(['inserted' => $inserted, 'updated' => $updated], "批量导入完成：新增 {$inserted} 条，更新 {$updated} 条");
    }

    /**
     * #100 PUT /marketing/priority/:id/whitelist/:wid 编辑白名单（次数/有效期/状态）
     * max_quantity 不可减至小于 used_quantity（已使用不可减）
     */
    public function updateWhitelist(int $id, int $wid)
    {
        $entry = Db::name('priority_sale_whitelists')
            ->where('id', $wid)
            ->where('priority_sale_id', $id)
            ->find();
        if (!$entry) {
            return $this->fail(409, '白名单条目不存在');
        }

        $payload = ['updated_at' => date('Y-m-d H:i:s')];
        $maxQty  = $this->request->param('maxQuantity');
        if ($maxQty !== null) {
            $maxQty = (int) $maxQty;
            if ($maxQty < 1 || $maxQty > 9999) {
                return $this->invalid('最大可购数量必须为 1~9999 的整数');
            }
            if ($maxQty < (int) $entry['used_quantity']) {
                return $this->conflict("已使用数量为 {$entry['used_quantity']}，最大可购数量不可小于已使用数量");
            }
            $payload['max_quantity'] = $maxQty;
        }
        $expiresAt = $this->strParam('expiresAt');
        if ($expiresAt !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiresAt) || !strtotime($expiresAt)) {
                return $this->invalid('有效期必须为「YYYY-MM-DD HH:mm:ss」格式（精确到时分秒）');
            }
            $payload['expires_at'] = $expiresAt;
        }
        $status = $this->intParam('status', -1);
        if (in_array($status, [0, 1], true)) {
            $payload['status'] = $status;
        }

        Db::name('priority_sale_whitelists')->where('id', $wid)->update($payload);

        AuditLogService::log($this->request, 'marketing', 'marketing.priority.whitelist.edit', [
            'target_type' => 'priority_whitelist',
            'target_id'   => $wid,
            'target_desc' => '活动#' . $id . ' 用户#' . $entry['user_id'],
            'before'      => [
                'max_quantity' => (int) $entry['max_quantity'],
                'expires_at'   => (string) $entry['expires_at'],
                'status'       => (int) $entry['status'],
            ],
            'after' => $payload,
        ]);

        return $this->success(null, '白名单已更新');
    }

    /**
     * #101 DELETE /marketing/priority/:id/whitelist/:wid 删除白名单
     */
    public function deleteWhitelist(int $id, int $wid)
    {
        $entry = Db::name('priority_sale_whitelists')
            ->where('id', $wid)
            ->where('priority_sale_id', $id)
            ->find();
        if (!$entry) {
            return $this->fail(409, '白名单条目不存在');
        }

        Db::name('priority_sale_whitelists')->where('id', $wid)->delete();

        AuditLogService::log($this->request, 'marketing', 'marketing.priority.whitelist.delete', [
            'target_type' => 'priority_whitelist',
            'target_id'   => $wid,
            'target_desc' => '活动#' . $id . ' 用户#' . $entry['user_id'],
            'before'      => [
                'user_id'      => (int) $entry['user_id'],
                'max_quantity' => (int) $entry['max_quantity'],
                'used_quantity' => (int) $entry['used_quantity'],
                'expires_at'   => (string) $entry['expires_at'],
            ],
            'after' => null,
        ]);

        return $this->success(null, '白名单条目已删除');
    }

    /**
     * #102 POST /marketing/priority/cleanup 批量清理过期资格（password，二次确认）
     * 将已过期（expires_at < now）且仍为有效状态的资格置为 status=0
     */
    public function cleanup()
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $now = date('Y-m-d H:i:s');
        $affected = Db::name('priority_sale_whitelists')
            ->where('status', 1)
            ->where('expires_at', '<', $now)
            ->update(['status' => 0, 'updated_at' => $now]);

        AuditLogService::log($this->request, 'marketing', 'marketing.priority.cleanup', [
            'target_type' => 'priority_whitelist',
            'target_desc' => '批量清理过期资格',
            'before'      => null,
            'after'       => ['cleaned' => $affected, 'cleaned_at' => $now],
        ]);

        return $this->success(['cleaned' => $affected], "已清理 {$affected} 条过期资格");
    }
}
