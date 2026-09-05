<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AuditLogService;
use app\admin\service\InventoryService;
use think\facade\Db;

/**
 * 用户管理控制器（文档 8.4，#14-#28，15 接口）
 * GET /admin/api/v1/users                                用户列表
 * GET /admin/api/v1/users/:id                             用户详情
 * PUT /admin/api/v1/users/:id/freeze|unfreeze             冻结/解冻
 * PUT /admin/api/v1/users/:id/reset-tx-password           重置交易密码
 * PUT /admin/api/v1/users/:id/force-logout                强制登出
 * POST|DELETE /admin/api/v1/users/:id/blacklist           黑名单
 * GET /admin/api/v1/users/:id/wallet|collectibles|blindboxes|priority-qualifications|invites
 * POST /admin/api/v1/users/:id/recover-collectible|recover-blindbox 强制回收
 */
class Users extends AdminBase
{
    /**
     * 用户列表（支持 phone/username/uid/status/isRealname/注册时间范围）
     */
    public function index()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('users')->whereNull('deleted_at');

        $phone = trim((string) $this->strParam('phone'));
        if ($phone !== '') {
            $query->where('phone', 'like', "%{$phone}%");
        }
        $username = trim((string) $this->strParam('username'));
        if ($username !== '') {
            $query->where('username', 'like', "%{$username}%");
        }
        $uid = trim((string) $this->strParam('uid'));
        if ($uid !== '') {
            $query->where('uid', $uid);
        }
        $status = $this->strParam('status');
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }
        $isRealname = $this->strParam('isRealname');
        if ($isRealname !== null && $isRealname !== '') {
            $query->where('is_realname', (int) $isRealname);
        }
        $createdAtStart = $this->strParam('createdAtStart');
        if ($createdAtStart) {
            $query->where('created_at', '>=', $createdAtStart . ' 00:00:00');
        }
        $createdAtEnd = $this->strParam('createdAtEnd');
        if ($createdAtEnd) {
            $query->where('created_at', '<=', $createdAtEnd . ' 23:59:59');
        }

        $total = (clone $query)->count();
        $list  = $query
            ->field('id,uid,username,avatar,phone,is_realname,status,last_login_at,login_count,created_at')
            ->order('id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        // 脱敏 + camelCase 字段映射（与前端类型对齐）
        foreach ($list as &$row) {
            $row['phone']       = mask_phone((string) $row['phone']);
            $row['isRealname']  = (int) $row['is_realname'] === 1;
            $row['lastLoginAt'] = $row['last_login_at'];
            $row['loginCount']  = (int) $row['login_count'];
            $row['createdAt']   = $row['created_at'];
            unset($row['is_realname'], $row['last_login_at'], $row['login_count'], $row['created_at']);
        }

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * 用户详情（基础信息 + 钱包/持有概览；实名信息脱敏，完整查看属 P1 realname 模块）
     */
    public function detail(int $id)
    {
        $user = Db::name('users')
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->field('id,uid,username,avatar,phone,is_realname,status,invite_code,last_login_at,login_count,created_at,updated_at')
            ->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        $wallet = Db::name('wallets')->where('user_id', $id)
            ->field('balance')->find();

        $heldCollectibles = Db::name('user_collectibles')
            ->where('user_id', $id)
            ->whereIn('status', ['held', 'consigned', 'frozen'])
            ->count();
        $heldBlindboxes = Db::name('user_collectibles')
            ->alias('uc')
            ->join('nft_blind_boxes bb', 'uc.collectible_id = bb.collectible_id')
            ->where('uc.user_id', $id)
            ->where('uc.status', 'held')
            ->count();

        $orderCount = Db::name('orders')->where('user_id', $id)->count();

        $user['phone']          = mask_phone((string) $user['phone']);
        $user['isRealname']     = (int) $user['is_realname'] === 1;
        $user['inviteCode']     = $user['invite_code'];
        $user['lastLoginAt']    = $user['last_login_at'];
        $user['loginCount']     = (int) $user['login_count'];
        $user['createdAt']      = $user['created_at'];
        $user['updatedAt']      = $user['updated_at'];
        $user['balance']        = number_format((float) ($wallet['balance'] ?? 0), 2, '.', '');
        $user['heldCollectibles'] = $heldCollectibles;
        $user['heldBlindboxes'] = $heldBlindboxes;
        $user['orderCount']     = $orderCount;
        unset(
            $user['is_realname'],
            $user['invite_code'],
            $user['last_login_at'],
            $user['login_count'],
            $user['created_at'],
            $user['updated_at']
        );

        return $this->success($user);
    }

    /**
     * 冻结账号
     */
    public function freeze(int $id)
    {
        return $this->toggleStatus($id, 0);
    }

    /**
     * 解冻账号
     */
    public function unfreeze(int $id)
    {
        return $this->toggleStatus($id, 1);
    }

    private function toggleStatus(int $id, int $status)
    {
        $action = $status === 0 ? 'user.freeze' : 'user.unfreeze';

        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }
        if ((int) $user['status'] === $status) {
            return $this->conflict($status === 0 ? '该账号已是冻结状态' : '该账号已是正常状态');
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($status === 0 && $reason === '') {
            return $this->invalid('冻结原因不能为空');
        }

        Db::name('users')->where('id', $id)->update(['status' => $status]);

        AuditLogService::log($this->request, 'user', $action, [
            'target_type' => 'user',
            'target_id'   => $id,
            'target_desc' => $user['username'] . '(' . mask_phone((string) $user['phone']) . ')',
            'before'      => ['status' => (int) $user['status']],
            'after'       => ['status' => $status],
            'reason'      => $reason ?: null,
        ]);

        return $this->success(null, $status === 0 ? '账号已冻结' : '账号已解冻');
    }

    /**
     * #18 PUT /users/:id/reset-tx-password 重置交易密码（reason）
     * 置空 transaction_password，用户下次交易前需重新设置（对齐 C 端忘记密码流程语义）
     */
    public function resetTxPassword(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }
        if (empty($user['transaction_password'])) {
            return $this->conflict('该用户尚未设置交易密码，无需重置');
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('重置原因不能为空');
        }

        $now = date('Y-m-d H:i:s.v');
        Db::name('users')->where('id', $id)->update([
            'transaction_password' => null,
            'updated_at'           => $now,
        ]);

        AuditLogService::log($this->request, 'user', 'user.reset_tx_password', [
            'target_type' => 'user',
            'target_id'   => $id,
            'target_desc' => $user['username'] . '(' . mask_phone((string) $user['phone']) . ')',
            'before'      => ['hasTxPassword' => true],
            'after'       => ['hasTxPassword' => false],
            'reason'      => $reason,
        ]);

        return $this->success(null, '交易密码已重置，用户需重新设置后方可交易');
    }

    /**
     * #19 PUT /users/:id/force-logout 强制登出（reason）
     * 写缓存踢出标记（TTL = JWT 有效期，过期后 token 自然失效无需再查），
     * C 端 JwtAuth 解析后校验该标记；reason 必填
     */
    public function forceLogout(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('强制登出原因不能为空');
        }

        $ttl = (int) env('jwt.EXPIRE', 86400);
        cache('force_logout_' . $id, time(), $ttl);

        // 同步刷新最后登录时间基准，避免登录空投等依赖 last_login_at 的活动被反复触发
        AuditLogService::log($this->request, 'user', 'user.force_logout', [
            'target_type' => 'user',
            'target_id'   => $id,
            'target_desc' => $user['username'] . '(' . mask_phone((string) $user['phone']) . ')',
            'after'       => ['kickedAt' => date('Y-m-d H:i:s'), 'ttl' => $ttl],
            'reason'      => $reason,
        ]);

        return $this->success(null, '用户已被强制登出，现有登录态即刻失效');
    }

    /**
     * #20 POST /users/:id/blacklist 加入黑名单（reason/expires_at?）
     * 用户级黑名单（blacklist_type=1），同时更新 users 冗余标记
     */
    public function blacklist(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('拉黑原因不能为空');
        }
        $expiresAt = $this->strParam('expiresAt');
        if ($expiresAt !== null && strtotime((string) $expiresAt) === false) {
            return $this->invalid('expires_at 格式错误（应为合法时间）');
        }

        $now = date('Y-m-d H:i:s.v');

        Db::startTrans();
        try {
            // uk_user_type 冲突即已在黑名单（含已解除的历史行 → 复活）
            $exists = Db::name('blacklist')
                ->where('user_id', $id)
                ->where('blacklist_type', 1)
                ->find();
            if ($exists && (int) $exists['status'] === 1) {
                Db::rollback();
                return $this->conflict('该用户已在黑名单中');
            }

            if ($exists) {
                Db::name('blacklist')->where('id', (int) $exists['id'])->update([
                    'reason'      => $reason,
                    'evidence'    => null,
                    'admin_id'    => $this->adminId(),
                    'admin_name'  => $this->adminName() ?: 'admin',
                    'status'      => 1,
                    'lifted_at'   => null,
                    'lifted_by'   => null,
                    'expires_at'  => $expiresAt ?: null,
                    'updated_at'  => $now,
                ]);
            } else {
                Db::name('blacklist')->insert([
                    'user_id'        => $id,
                    'blacklist_type' => 1,
                    'target_value'   => (string) $id,
                    'reason'         => $reason,
                    'admin_id'       => $this->adminId(),
                    'admin_name'     => $this->adminName() ?: 'admin',
                    'status'         => 1,
                    'expires_at'     => $expiresAt ?: null,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }

            Db::name('users')->where('id', $id)->update([
                'is_blacklisted'  => 1,
                'blacklist_reason' => $reason,
                'blacklist_at'     => $now,
                'blacklist_by'     => $this->adminId(),
                'updated_at'       => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '加入黑名单失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'user', 'user.blacklist.add', [
            'target_type' => 'user',
            'target_id'   => $id,
            'target_desc' => $user['username'] . '(' . mask_phone((string) $user['phone']) . ')',
            'before'      => ['isBlacklisted' => false],
            'after'       => ['isBlacklisted' => true, 'expiresAt' => $expiresAt],
            'reason'      => $reason,
        ]);

        return $this->success(null, $expiresAt ? '已加入黑名单（至 ' . $expiresAt . ' 自动过期）' : '已加入黑名单（永久）');
    }

    /**
     * #21 DELETE /users/:id/blacklist 移出黑名单（reason）
     */
    public function blacklistRemove(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('移出原因不能为空');
        }

        $now = date('Y-m-d H:i:s.v');

        Db::startTrans();
        try {
            $record = Db::name('blacklist')
                ->where('user_id', $id)
                ->where('blacklist_type', 1)
                ->where('status', 1)
                ->find();
            if (!$record) {
                Db::rollback();
                return $this->conflict('该用户不在黑名单中');
            }

            Db::name('blacklist')->where('id', (int) $record['id'])->update([
                'status'     => 0,
                'lifted_at'  => $now,
                'lifted_by'  => $this->adminId(),
                'updated_at' => $now,
            ]);

            Db::name('users')->where('id', $id)->update([
                'is_blacklisted'  => 0,
                'blacklist_reason' => null,
                'updated_at'       => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '移出黑名单失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'user', 'user.blacklist.remove', [
            'target_type' => 'user',
            'target_id'   => $id,
            'target_desc' => $user['username'] . '(' . mask_phone((string) $user['phone']) . ')',
            'before'      => ['isBlacklisted' => true],
            'after'       => ['isBlacklisted' => false],
            'reason'      => $reason,
        ]);

        return $this->success(null, '已移出黑名单');
    }

    /**
     * #22 GET /users/:id/wallet 用户钱包资产与流水
     */
    public function wallet(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $wallet = Db::name('wallets')->where('user_id', $id)->find();

        $query = Db::name('wallet_transactions')->where('user_id', $id);
        $type = $this->strParam('type');
        if ($type !== null && $type !== '') {
            $query->where('trans_type', $type);
        }

        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->limit($offset, $pageSize)->select()->toArray();

        // 收支统计
        $stats = Db::name('wallet_transactions')->where('user_id', $id)
            ->field('direction, COUNT(*) AS cnt, SUM(amount) AS total')
            ->group('direction')
            ->select()
            ->toArray();
        $inflow = $outflow = 0;
        foreach ($stats as $s) {
            if ((int) $s['direction'] === 1) {
                $inflow = (float) $s['total'];
            } elseif ((int) $s['direction'] === 2) {
                $outflow = (float) $s['total'];
            }
        }

        $items = array_map(function (array $t) {
            return [
                'id'           => (int) $t['id'],
                'transType'    => $t['trans_type'],
                'title'        => (string) $t['title'],
                'direction'    => (int) $t['direction'],
                'amount'       => number_format((float) $t['amount'], 2, '.', ''),
                'balanceAfter' => number_format((float) $t['balance_after'], 2, '.', ''),
                'bizNo'        => (string) ($t['biz_no'] ?? ''),
                'createdAt'    => (string) $t['created_at'],
            ];
        }, $list);

        return $this->success([
            'wallet' => [
                'balance'   => number_format((float) ($wallet['balance'] ?? 0), 2, '.', ''),
                'available' => number_format((float) ($wallet['available'] ?? 0), 2, '.', ''),
                'frozen'    => number_format((float) ($wallet['frozen'] ?? 0), 2, '.', ''),
                'points'    => (int) ($wallet['points'] ?? 0),
            ],
            'stats' => [
                'totalInflow'  => number_format($inflow, 2, '.', ''),
                'totalOutflow' => number_format($outflow, 2, '.', ''),
            ],
            'transactions' => ['list' => $items, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize],
        ]);
    }

    /**
     * #23 GET /users/:id/collectibles 用户仓库藏品（分页，排除盲盒资产）
     */
    public function userCollectibles(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('user_collectibles')->alias('uc')
            ->join('nft_collectibles c', 'c.id = uc.collectible_id', 'LEFT')
            ->where('uc.user_id', $id)
            ->whereNotExists(function ($q) {
                $q->name('blind_boxes')->whereRaw('nft_blind_boxes.collectible_id = uc.collectible_id');
            });

        $status = $this->strParam('status');
        if ($status !== null && $status !== '') {
            $query->where('uc.status', $status);
        }

        $total = (clone $query)->count();
        $list = $query
            ->field('uc.id,uc.collectible_id,uc.serial,uc.source,uc.status,uc.acquired_price,uc.acquired_at,uc.created_at,
                     c.name,c.image,c.category_id')
            ->order('uc.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $statusMap = ['held' => '持有中', 'consigned' => '寄售中', 'frozen' => '转赠冻结中', 'transferred' => '已转出', 'consumed' => '已消耗'];
        $sourceMap = ['purchase' => '购买', 'airdrop' => '空投', 'blindbox' => '盲盒开启', 'lucky_draw' => '抽奖', 'synthesis' => '合成', 'transfer' => '转赠'];

        $items = array_map(function (array $r) use ($statusMap, $sourceMap) {
            return [
                'id'            => (int) $r['id'],
                'collectibleId' => (int) $r['collectible_id'],
                'name'          => (string) ($r['name'] ?? ''),
                'image'         => (string) ($r['image'] ?? ''),
                'serial'        => (string) $r['serial'],
                'source'        => (string) $r['source'],
                'sourceText'    => $sourceMap[$r['source']] ?? $r['source'],
                'status'        => (string) $r['status'],
                'statusText'    => $statusMap[$r['status']] ?? $r['status'],
                'acquiredPrice' => number_format((float) $r['acquired_price'], 2, '.', ''),
                'acquiredAt'    => (string) $r['acquired_at'],
            ];
        }, $list);

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * #24 GET /users/:id/blindboxes 用户仓库盲盒（分页，未开启）
     */
    public function userBlindboxes(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('user_collectibles')->alias('uc')
            ->join('nft_blind_boxes bb', 'bb.collectible_id = uc.collectible_id', 'INNER')
            ->join('nft_collectibles c', 'c.id = uc.collectible_id', 'LEFT')
            ->where('uc.user_id', $id);

        $total = (clone $query)->count();
        $list = $query
            ->field('uc.id,uc.collectible_id,uc.serial,uc.source,uc.status,uc.acquired_price,uc.acquired_at,uc.created_at,
                     c.name,c.image')
            ->order('uc.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $statusMap = ['held' => '未开启', 'consigned' => '寄售中', 'frozen' => '转赠冻结中', 'transferred' => '已转出', 'consumed' => '已开启'];

        $items = array_map(function (array $r) use ($statusMap) {
            return [
                'id'            => (int) $r['id'],
                'collectibleId' => (int) $r['collectible_id'],
                'name'          => (string) ($r['name'] ?? ''),
                'image'         => (string) ($r['image'] ?? ''),
                'serial'        => (string) $r['serial'],
                'source'        => (string) $r['source'],
                'status'        => (string) $r['status'],
                'statusText'    => $statusMap[$r['status']] ?? $r['status'],
                'opened'        => $r['status'] === 'consumed',
                'acquiredPrice' => number_format((float) $r['acquired_price'], 2, '.', ''),
                'acquiredAt'    => (string) $r['acquired_at'],
            ];
        }, $list);

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * #25 GET /users/:id/priority-qualifications 优先购资格台账（有效/过期/已用完）
     */
    public function priorityQualifications(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('priority_sale_whitelists')->alias('w')
            ->join('nft_priority_sales ps', 'ps.id = w.priority_sale_id', 'LEFT')
            ->join('nft_collectibles c', 'c.id = ps.collectible_id', 'LEFT')
            ->where('w.user_id', $id);

        $total = (clone $query)->count();
        $list = $query
            ->field('w.*,ps.name AS activity_name,ps.start_time,ps.end_time,ps.status AS activity_status,
                     c.name AS collectible_name,c.image')
            ->order('w.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $now = date('Y-m-d H:i:s');
        $summary = ['valid' => 0, 'expired' => 0, 'usedUp' => 0, 'disabled' => 0];
        $items = array_map(function (array $r) use ($now, &$summary) {
            $isExpired = $r['expires_at'] !== null && strtotime((string) $r['expires_at']) < strtotime($now);
            $isUsedUp = (int) $r['used_quantity'] >= (int) $r['max_quantity'];
            $state = 'valid';
            if ((int) $r['status'] !== 1) {
                $state = 'disabled';
            } elseif ($isUsedUp) {
                $state = 'usedUp';
            } elseif ($isExpired) {
                $state = 'expired';
            }
            $summary[$state]++;

            $stateText = ['valid' => '有效', 'expired' => '已过期', 'usedUp' => '已用完', 'disabled' => '已停用'];
            return [
                'id'             => (int) $r['id'],
                'activityId'     => (int) $r['priority_sale_id'],
                'activityName'   => (string) ($r['activity_name'] ?? ''),
                'collectibleId'  => 0,
                'collectibleName' => (string) ($r['collectible_name'] ?? ''),
                'image'          => (string) ($r['image'] ?? ''),
                'phone'          => mask_phone((string) $r['phone']),
                'maxQuantity'    => (int) $r['max_quantity'],
                'usedQuantity'   => (int) $r['used_quantity'],
                'remaining'      => max((int) $r['max_quantity'] - (int) $r['used_quantity'], 0),
                'state'          => $state,
                'stateText'      => $stateText[$state],
                'expiresAt'      => (string) $r['expires_at'],
                'activityWindow' => ['start' => (string) $r['start_time'], 'end' => (string) $r['end_time']],
                'createdAt'      => (string) $r['created_at'],
            ];
        }, $list);

        return $this->success([
            'summary' => $summary,
            'list'    => $items,
            'total'   => $total,
            'page'    => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * #26 GET /users/:id/invites 邀请关系链与奖励统计
     */
    public function invites(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        // 作为邀请人的记录
        $query = Db::name('invite_records')->alias('ir')
            ->join('nft_users u', 'u.id = ir.invitee_id', 'LEFT')
            ->where('ir.inviter_id', $id);

        $total = (clone $query)->count();
        $list = $query
            ->field('ir.*,u.username,u.phone,u.status AS invitee_status,u.created_at AS invitee_created_at')
            ->order('ir.id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $statusMap = ['pending' => '已邀请未注册', 'registered' => '已注册'];
        $items = array_map(function (array $r) use ($statusMap) {
            return [
                'id'             => (int) $r['id'],
                'inviteeId'      => (int) $r['invitee_id'],
                'inviteeName'    => (string) ($r['username'] ?? ''),
                'inviteePhone'   => mask_phone((string) ($r['phone'] ?? '')),
                'inviteCode'     => (string) $r['invite_code'],
                'status'         => (string) $r['status'],
                'statusText'     => $statusMap[$r['status']] ?? $r['status'],
                'inviterRewarded' => !empty($r['inviter_airdrop_record_id']),
                'inviteeRewarded' => !empty($r['invitee_airdrop_record_id']),
                'createdAt'      => (string) $r['created_at'],
            ];
        }, $list);

        // 统计：邀请总数 / 已注册 / 双方奖励发放数
        $stats = Db::name('invite_records')->where('inviter_id', $id)
            ->field('status, COUNT(*) AS cnt, SUM(inviter_airdrop_record_id IS NOT NULL) AS rewarded')
            ->group('status')
            ->select()
            ->toArray();
        $totalInvites = 0;
        $registered = 0;
        $rewarded = 0;
        foreach ($stats as $s) {
            $totalInvites += (int) $s['cnt'];
            if ($s['status'] === 'registered') {
                $registered = (int) $s['cnt'];
                $rewarded = (int) ($s['rewarded'] ?? 0);
            }
        }

        // 该用户被谁邀请（如：invitee_id = id）
        $invitedBy = Db::name('invite_records')->alias('ir')
            ->join('nft_users u', 'u.id = ir.inviter_id', 'LEFT')
            ->where('ir.invitee_id', $id)
            ->field('ir.id,ir.invite_code,ir.status,ir.created_at,u.username,u.phone')
            ->find();

        return $this->success([
            'stats' => [
                'totalInvites' => $totalInvites,
                'registered'   => $registered,
                'rewarded'     => $rewarded,
                'inviteCode'   => (string) $user['invite_code'],
            ],
            'invitedBy' => $invitedBy ? [
                'inviterId'   => null,
                'inviterName' => (string) ($invitedBy['username'] ?? ''),
                'inviterPhone' => mask_phone((string) ($invitedBy['phone'] ?? '')),
                'inviteCode'  => (string) $invitedBy['invite_code'],
                'status'      => (string) $invitedBy['status'],
                'createdAt'   => (string) $invitedBy['created_at'],
            ] : null,
            'list'    => $items,
            'total'   => $total,
            'page'    => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * #27 POST /users/:id/recover-collectible 强制回收藏品（user_collectible_id/reason/password）
     * 5.5 校验：status='held' 且未二次流转；回退计数器（4.3.4）
     */
    public function recoverCollectible(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $reason = trim((string) $this->strParam('reason'));
        if ($reason === '') {
            return $this->invalid('回收原因不能为空');
        }
        $ucId = $this->intParam('userCollectibleId');
        if ($ucId <= 0) {
            return $this->invalid('请提供用户藏品资产ID');
        }

        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        Db::startTrans();
        try {
            $uc = Db::name('user_collectibles')->where('id', $ucId)->lock(true)->find();
            if (!$uc || (int) $uc['user_id'] !== $id) {
                Db::rollback();
                return $this->fail(409, '资产不存在或不属于该用户');
            }
            // 盲盒资产应走 recover-blindbox 接口
            if (Db::name('blind_boxes')->where('collectible_id', (int) $uc['collectible_id'])->count() > 0) {
                Db::rollback();
                return $this->conflict('该资产为盲盒，请使用「强制回收盲盒」操作');
            }
            if ($uc['status'] !== 'held') {
                $statusText = ['consigned' => '该藏品正在寄售', 'frozen' => '该藏品转赠冻结中', 'transferred' => '该藏品已发生二次流转（已转赠）', 'consumed' => '该藏品已被消耗'][$uc['status']] ?? $uc['status'];
                Db::rollback();
                return $this->conflict($statusText . '，无法回收');
            }

            $collectible = Db::name('collectibles')->where('id', (int) $uc['collectible_id'])->find();

            // 计数器回退（4.3.4）
            $revert = InventoryService::revertOnRecover($uc);

            // 资产行物理删除（审计 before 快照留痕）
            Db::name('user_collectibles')->where('id', $ucId)->delete();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '回收失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'user', 'user.recover_collectible', [
            'target_type' => 'user_collectible',
            'target_id'   => $ucId,
            'target_desc' => ($collectible['name'] ?? '藏品') . ' 资产 #' . $ucId . '（用户 ' . $user['username'] . '）',
            'before'      => [
                'userId'   => $id,
                'serial'   => $uc['serial'],
                'source'   => $uc['source'],
                'status'   => $uc['status'],
            ],
            'after'       => ['recovered' => true, 'revert' => $revert],
            'reason'      => $reason,
        ]);

        return $this->success(['revert' => $revert], '藏品已回收，计数器已回退');
    }

    /**
     * #28 POST /users/:id/recover-blindbox 强制回收盲盒（user_blindbox_id/reason/password）
     * 5.5 校验：未开启（consumed 即拦截）
     */
    public function recoverBlindbox(int $id)
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

        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        Db::startTrans();
        try {
            $uc = Db::name('user_collectibles')->where('id', $ucId)->lock(true)->find();
            if (!$uc || (int) $uc['user_id'] !== $id) {
                Db::rollback();
                return $this->fail(409, '资产不存在或不属于该用户');
            }
            // 校验为盲盒资产
            if (Db::name('blind_boxes')->where('collectible_id', (int) $uc['collectible_id'])->count() === 0) {
                Db::rollback();
                return $this->conflict('该资产不是盲盒，请使用「强制回收藏品」操作');
            }
            if ($uc['status'] === 'consumed') {
                Db::rollback();
                return $this->conflict('该盲盒已被开启，无法回收');
            }
            if ($uc['status'] !== 'held') {
                $statusText = ['consigned' => '该盲盒正在寄售', 'frozen' => '该盲盒转赠冻结中', 'transferred' => '该盲盒已转赠'][$uc['status']] ?? $uc['status'];
                Db::rollback();
                return $this->conflict($statusText . '，无法回收');
            }

            $collectible = Db::name('collectibles')->where('id', (int) $uc['collectible_id'])->find();

            $revert = InventoryService::revertOnRecover($uc);
            Db::name('user_collectibles')->where('id', $ucId)->delete();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(500, '回收失败：' . $e->getMessage());
        }

        AuditLogService::log($this->request, 'user', 'user.recover_blindbox', [
            'target_type' => 'user_collectible',
            'target_id'   => $ucId,
            'target_desc' => ($collectible['name'] ?? '盲盒') . ' 资产 #' . $ucId . '（用户 ' . $user['username'] . '）',
            'before'      => [
                'userId'   => $id,
                'serial'   => $uc['serial'],
                'source'   => $uc['source'],
                'status'   => $uc['status'],
            ],
            'after'       => ['recovered' => true, 'revert' => $revert],
            'reason'      => $reason,
        ]);

        return $this->success(['revert' => $revert], '盲盒已回收，计数器已回退');
    }
}
