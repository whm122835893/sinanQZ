<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\service\InventoryService;
use think\facade\Db;

/**
 * 管理后台用户管理控制器
 *
 * 功能：用户列表/详情（多维度聚合）、冻结解冻、重置交易密码、
 * 强制登出、黑名单管理、强制回收藏品/盲盒。
 *
 * 敏感数据策略：
 * - 手机号默认脱敏；持有 realname:full 权限可见完整手机号与解密实名信息
 * - 实名信息（real_name/id_card）AES-256-CBC 加密存储，接口侧按需解密
 */
class UserController extends BaseController
{
    /** 是否具备查看完整实名信息权限 */
    private function canViewFull(): bool
    {
        $admin = $this->admin();
        return !empty($admin['is_super']) || in_array('realname:full', $admin['permissions'] ?? [], true);
    }

    /**
     * GET /admin/user/list
     * 筛选：keyword(手机号/UID/用户名)、status、isRealname、isBlacklisted、phoneTail(手机尾号)、注册时间区间
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('users')->alias('u')->whereNull('u.deleted_at');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('u.phone', "%{$keyword}%")
                  ->whereOr('u.uid', $keyword)
                  ->whereLike('u.username', "%{$keyword}%");
            });
        }
        $status = $this->request->param('status');
        if ($status !== null && $status !== '') {
            // 前端语义化状态映射：normal → 1，frozen → 0
            $statusMap = ['normal' => 1, 'frozen' => 0];
            $query->where('u.status', $statusMap[$status] ?? (int) $status);
        }
        // 实名语义化状态（realname_status：0未提交 1待审核 2已通过 3已驳回）
        $realnameStatus = trim((string) $this->request->param('realnameStatus', ''));
        if ($realnameStatus !== '') {
            $realnameMap = ['none' => 0, 'pending' => 1, 'approved' => 2, 'rejected' => 3];
            if (isset($realnameMap[$realnameStatus])) {
                $query->where('u.realname_status', $realnameMap[$realnameStatus]);
            }
        } elseif ($this->request->param('isRealname') !== null && $this->request->param('isRealname') !== '') {
            $query->where('u.is_realname', (int) $this->request->param('isRealname'));
        }
        $isBlacklisted = $this->request->param('isBlacklisted');
        if ($isBlacklisted !== null && $isBlacklisted !== '') {
            $query->where('u.is_blacklisted', (int) $isBlacklisted);
        }
        // 手机尾号（1~11 位数字，逗号分隔多选，如 1 或 1,3,88；命中任一即符合）
        $phoneTail = trim((string) $this->request->param('phoneTail', ''));
        if ($phoneTail !== '') {
            $tails = array_values(array_unique(array_filter(array_map('trim', explode(',', $phoneTail)))));
            if (!$tails) {
                return $this->fail(4220, '手机尾号参数不正确');
            }
            $branches = [];
            foreach ($tails as $t) {
                if (!preg_match('/^\d{1,11}$/', $t)) {
                    return $this->fail(4220, '手机尾号仅允许 1~11 位数字');
                }
                $branches[] = "RIGHT(u.phone, " . strlen($t) . ") = '" . $t . "'";
            }
            $query->whereRaw('(' . implode(' OR ', $branches) . ')');
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('u.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('u.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('u.id, u.uid, u.phone, u.username, u.avatar, u.is_realname, u.status, u.realname_status,
                               u.real_name, u.id_card, u.is_blacklisted, u.blacklist_reason,
                               u.last_login_at, u.login_count, u.created_at,
                               IFNULL(w.balance, 0) AS balance, IFNULL(w.points, 0) AS points,
                               (SELECT COUNT(*) FROM nft_user_collectibles uc
                                 WHERE uc.user_id = u.id AND uc.status = \'held\') AS collectible_count,
                               (SELECT COUNT(*) FROM nft_orders o
                                 WHERE o.user_id = u.id) AS order_count')
            ->leftJoin('wallets w', 'w.user_id = u.id')
            ->order('u.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        $canFull = $this->canViewFull();
        $statusText = [0 => 'none', 1 => 'pending', 2 => 'approved', 3 => 'rejected'];
        foreach ($rows as &$row) {
            $row['phone'] = $canFull ? (string) $row['phone'] : mask_phone((string) $row['phone']);

            // 实名信息（默认脱敏；realname:full 权限可见全量）
            $rnName = $row['real_name'] ? (aes_decrypt((string) $row['real_name']) ?? '') : '';
            $rnId   = $row['id_card'] ? (aes_decrypt((string) $row['id_card']) ?? '') : '';
            if (!$canFull) {
                $rnName = $rnName !== '' ? mb_substr($rnName, 0, 1) . str_repeat('*', max(0, mb_strlen($rnName) - 1)) : '';
                $rnId   = $rnId !== '' ? substr($rnId, 0, 4) . str_repeat('*', 10) . substr($rnId, -4) : '';
            }
            $row['real_name'] = $rnName;
            $row['id_card']   = $rnId;

            // 语义化状态（视图直接渲染）
            $row['realname_status'] = $statusText[(int) $row['realname_status']] ?? 'none';
            $row['status']    = (int) $row['status'] === 1 ? 'normal' : 'frozen';
            $row['is_blacklisted'] = (int) $row['is_blacklisted'];
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/user/detail/:id
     * 聚合：基础信息 + 钱包 + 持仓统计 + 最近订单 + 最近转赠
     */
    public function detail(int $id)
    {
        $user = Db::name('users')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$user) {
            return $this->fail(4040, '用户不存在');
        }

        $canFull = $this->canViewFull();

        // 实名信息：按权限解密或脱敏（含待审核材料）
        $realName = '';
        $idCard   = '';
        if (!empty($user['real_name']) || !empty($user['id_card'])) {
            $realName = (string) ($user['real_name'] ? (aes_decrypt((string) $user['real_name']) ?? '') : '');
            $idCard   = (string) ($user['id_card'] ? (aes_decrypt((string) $user['id_card']) ?? '') : '');
            if (!$canFull) {
                $realName = $realName !== '' ? mb_substr($realName, 0, 1) . str_repeat('*', max(0, mb_strlen($realName) - 1)) : '';
                $idCard   = $idCard !== '' ? substr($idCard, 0, 4) . str_repeat('*', 10) . substr($idCard, -4) : '';
            }
        }

        $wallet = Db::name('wallets')->where('user_id', $id)->find();

        $holdStats = Db::name('user_collectibles')->where('user_id', $id)
            ->where('status', 'held')->count();
        $holdSelling = Db::name('user_collectibles')->where('user_id', $id)
            ->where('status', 'consigned')->count();
        $holdFrozen = Db::name('user_collectibles')->where('user_id', $id)
            ->where('status', 'frozen')->count();

        $orderStats = Db::name('orders')->where('user_id', $id)
            ->field("COUNT(*) AS total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
                     SUM(CASE WHEN status='completed' THEN total_price ELSE 0 END) AS paid")
            ->find();

        $recentOrders = Db::name('orders')->alias('o')
            ->field('o.id, o.order_no, o.total_price, o.status, o.quantity, o.created_at, c.name AS collectible_name, c.image AS collectible_image')
            ->join('collectibles c', 'c.id = o.collectible_id', 'LEFT')
            ->where('o.user_id', $id)
            ->order('o.id', 'desc')->limit(10)->select()->toArray();

        $recentTransfers = Db::name('transfers')->alias('t')
            ->field('t.id, t.status, t.created_at, c.name AS collectible_name,
                     IF(t.from_user_id=' . (int) $id . ', 0, 1) AS is_receive')
            ->join('collectibles c', 'c.id = t.collectible_id', 'LEFT')
            ->whereRaw('(t.from_user_id = ? OR t.to_user_id = ?)', [$id, $id])
            ->order('t.id', 'desc')->limit(10)->select()->toArray();

        // 最近钱包流水（详情抽屉"最近钱包流水"卡片数据源，与钱包页同表）
        $recentWalletLogs = Db::name('wallet_transactions')
            ->field('id, trans_type, title, direction, amount, biz_no, created_at')
            ->where('user_id', $id)
            ->order('id', 'desc')->limit(10)->select()->toArray();

        // 是否在黑名单（含有效记录）
        $blacklisted = Db::name('blacklist')->where('user_id', $id)->where('status', 1)->find();

        $data = [
            'id'          => (int) $user['id'],
            'uid'         => $user['uid'],
            'phone'       => $canFull ? (string) $user['phone'] : mask_phone((string) $user['phone']),
            'username'    => $user['username'],
            'avatar'      => $user['avatar'],
            'status'      => (int) $user['status'] === 1 ? 'normal' : 'frozen',
            'isRealname'  => (int) $user['is_realname'],
            'realnameStatus' => ['0' => 'none', '1' => 'pending', '2' => 'approved', '3' => 'rejected'][(string) (int) $user['realname_status']] ?? 'none',
            'realName'    => $realName,
            'idCard'      => $idCard,
            'rejectReason' => (string) ($user['realname_reject_reason'] ?? ''),
            'isBlacklisted' => (int) $user['is_blacklisted'],
            'blacklistReason' => $blacklisted ? $blacklisted['reason'] : null,
            'hasTransactionPassword' => !empty($user['transaction_password']),
            'lastLoginAt' => $user['last_login_at'],
            'loginCount'  => (int) $user['login_count'],
            'createdAt'   => $user['created_at'],
            'wallet'      => $wallet ? [
                'balance'   => (float) $wallet['balance'],
                'available' => (float) $wallet['available'],
                'frozen'    => (float) $wallet['frozen'],
                'points'    => (float) $wallet['points'],
            ] : ['balance' => 0, 'available' => 0, 'frozen' => 0, 'points' => 0],
            'holdStats'   => [
                'held' => (int) $holdStats, 'consigned' => (int) $holdSelling, 'frozen' => (int) $holdFrozen,
            ],
            'orderStats'  => [
                'total' => (int) ($orderStats['total'] ?? 0),
                'completed' => (int) ($orderStats['completed'] ?? 0),
                'paid' => round((float) ($orderStats['paid'] ?? 0), 2),
            ],
            'recentOrders'   => camelize_keys($recentOrders),
            'recentTransfers' => array_map(function ($t) {
                $t['isReceive'] = (int) $t['is_receive'];
                unset($t['is_receive']);
                return camelize_keys($t);
            }, $recentTransfers),
            'recentWalletLogs' => array_map(function ($l) {
                $l['direction'] = (int) $l['direction'];
                $l['amount']    = (float) $l['amount'];
                return camelize_keys($l);
            }, $recentWalletLogs),
        ];

        // 审计：查看用户详情含实名信息时记录
        $this->audit('user', 'view_detail', '查看用户详情（UID ' . $user['uid'] . '）', [], 'user', (int) $id);

        return $this->success($data);
    }

    /**
     * POST /admin/users/:id/freeze { status(0冻结/1解冻), reason }
     * （兼容 body.user_id；路由变量 id 经 param() 合并可直接读取）
     */
    public function freeze()
    {
        $userId = (int) ($this->request->param('user_id') ?: $this->request->param('id'));
        if ($userId <= 0) {
            return $this->fail(4220, '缺少必填参数：user_id');
        }
        $status = (int) $this->request->param('status', -1);
        $reason = trim((string) $this->request->param('reason', ''));

        if (!in_array($status, [0, 1], true)) {
            return $this->fail(4220, 'status 仅允许 0（冻结）或 1（解冻）');
        }
        if ($status === 0 && $reason === '') {
            return $this->fail(4220, '冻结操作必须填写原因');
        }

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) {
            return $this->fail(4040, '用户不存在');
        }
        if ((int) $user['status'] === $status) {
            return $this->fail(4220, $status === 0 ? '用户已是冻结状态' : '用户已是正常状态');
        }

        $now = date('Y-m-d H:i:s');
        Db::name('users')->where('id', $userId)->update([
            'status'     => $status,
            'updated_at' => $now,
        ]);

        // 冻结同时强制登出（写入 logout_before 使现有令牌失效）
        if ($status === 0) {
            Db::name('users')->where('id', $userId)->update(['logout_before' => $now]);
        }

        $this->audit('user', $status === 0 ? 'freeze' : 'unfreeze',
            ($status === 0 ? '冻结用户' : '解冻用户') . '（UID ' . $user['uid'] . '）',
            ['reason' => $reason], 'user', $userId);

        return $this->success(null, $status === 0 ? '用户已冻结并强制下线' : '用户已解冻');
    }

    /**
     * POST /admin/users/:id/reset-transaction-password
     * 重置交易密码：清空旧密码，用户在 C 端重新设置
     */
    public function resetTransactionPassword()
    {
        $userId = (int) ($this->request->param('user_id') ?: $this->request->param('id'));
        if ($userId <= 0) {
            return $this->fail(4220, 'user_id 参数不正确');
        }
        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) {
            return $this->fail(4040, '用户不存在');
        }

        Db::name('users')->where('id', $userId)->update([
            'transaction_password' => null,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $this->audit('user', 'reset_transaction_password', '重置交易密码（UID ' . $user['uid'] . '）', [], 'user', $userId);
        return $this->success(null, '交易密码已重置，用户需在 APP 重新设置');
    }

    /**
     * POST /admin/user/force-logout { user_id, reason }
     * 强制登出：写入 logout_before，令该用户全部现存令牌失效
     */
    public function forceLogout()
    {
        $userId = (int) ($this->request->param('user_id') ?: $this->request->param('id'));
        if ($userId <= 0) {
            return $this->fail(4220, 'user_id 参数不正确');
        }
        $reason = trim((string) $this->request->param('reason', ''));

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) {
            return $this->fail(4040, '用户不存在');
        }

        Db::name('users')->where('id', $userId)->update([
            'logout_before' => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->audit('user', 'force_logout', '强制登出用户（UID ' . $user['uid'] . '）', ['reason' => $reason], 'user', $userId);
        return $this->success(null, '该用户全部登录态已失效');
    }

    /**
     * POST /admin/users/:id/blacklist { action(add/remove), reason, evidence? }
     * 黑名单：加入后用户即刻被禁止访问（C 端中间件实时校验）
     */
    public function blacklist()
    {
        $userId = (int) ($this->request->param('user_id') ?: $this->request->param('id'));
        if ($userId <= 0) {
            return $this->fail(4220, '缺少必填参数：user_id');
        }
        $action = (string) $this->request->param('action');
        if ($action === '') {
            return $this->fail(4220, '缺少必填参数：action');
        }
        $reason = trim((string) $this->request->param('reason', ''));
        $evidence = trim((string) $this->request->param('evidence', ''));

        if (!in_array($action, ['add', 'remove'], true)) {
            return $this->fail(4220, 'action 仅允许 add / remove');
        }

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) {
            return $this->fail(4040, '用户不存在');
        }

        $now = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            if ($action === 'add') {
                if ($reason === '') {
                    return $this->fail(4220, '加入黑名单必须填写原因');
                }
                if ((int) $user['is_blacklisted'] === 1) {
                    return $this->fail(4220, '用户已在黑名单中');
                }
                Db::name('blacklist')->insert([
                    'user_id'        => $userId,
                    'blacklist_type' => 1,
                    'target_value'   => (string) $user['phone'],
                    'reason'         => $reason,
                    'evidence'       => $evidence !== '' ? $evidence : null,
                    'admin_id'       => $this->adminId(),
                    'admin_name'     => $this->adminName(),
                    'status'         => 1,
                    'created_at'     => $now,
                ]);
                Db::name('users')->where('id', $userId)->update([
                    'is_blacklisted' => 1,
                    'blacklist_reason' => $reason,
                    'blacklist_at'   => $now,
                    'logout_before'  => $now,
                    'updated_at'     => $now,
                ]);
            } else {
                $record = Db::name('blacklist')->where('user_id', $userId)->where('status', 1)->order('id', 'desc')->find();
                if (!$record) {
                    return $this->fail(4040, '该用户没有生效中的黑名单记录');
                }
                Db::name('blacklist')->where('id', $record['id'])->update([
                    'status'       => 0,
                    'lifted_at'    => $now,
                    'lifted_by'    => $this->adminId(),
                    'lifted_reason' => $reason !== '' ? $reason : '管理后台移出黑名单',
                    'updated_at'   => $now,
                ]);
                Db::name('users')->where('id', $userId)->update([
                    'is_blacklisted' => 0,
                    'blacklist_reason' => null,
                    'updated_at'     => $now,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '操作失败：' . $e->getMessage());
        }

        $this->audit('user', 'blacklist_' . $action,
            ($action === 'add' ? '加入黑名单' : '移出黑名单') . '（UID ' . $user['uid'] . '）',
            ['reason' => $reason], 'user', $userId);

        return $this->success(null, $action === 'add' ? '已加入黑名单并强制下线' : '已移出黑名单');
    }

    /**
     * POST /admin/user/recover { user_collectible_id, reason }
     * 强制回收藏品：支持在持有/寄售中/冻结中状态（超卖/错空投/多合等异常处置）
     * 单一事务：取消寄售挂单 → 状态置 recovered → 按资产来源回退计数器（文档 4.3.4）
     */
    public function recover()
    {
        $missing = $this->missingParams(['user_collectible_id', 'reason']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $ucid  = (int) $this->request->param('user_collectible_id');
        $reason = trim((string) $this->request->param('reason', ''));

        $now = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            // 行锁 + 事务内复核，防并发重复回收
            $uc = Db::name('user_collectibles')->alias('uc')
                ->field('uc.*, c.name AS collectible_name')
                ->join('collectibles c', 'c.id = uc.collectible_id')
                ->where('uc.id', $ucid)
                ->lock(true)
                ->find();
            if (!$uc) {
                Db::rollback();
                return $this->fail(4040, '藏品持有记录不存在');
            }
            if (!in_array($uc['status'], ['held', 'consigned', 'frozen'], true)) {
                Db::rollback();
                return $this->fail(4220, '仅持有中/寄售中/冻结中的藏品可强制回收（当前：' . $uc['status'] . '）');
            }

            $revert = $this->recoverRow($uc, $reason, $now);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '回收失败：' . $e->getMessage());
        }

        $this->audit('user', 'recover', '强制回收藏品「' . $uc['collectible_name'] . '」（来源 ' . $revert['source']
            . '，回退 ' . ($revert['reverted'] ? $revert['counter'] : '无（计数器守卫拦截）') . '）',
            ['reason' => $reason, 'serial' => $uc['serial'], 'revert' => $revert], 'user_collectible', $ucid);

        return $this->success([
            'revertSource'    => $revert['source'],
            'counterReverted' => (bool) $revert['reverted'],
            'counter'         => $revert['counter'],
        ], '藏品已强制回收');
    }

    /**
     * POST /admin/user/recover-batch { collectible_id, quantity, reason }
     * 用户维度批量回收：回收该用户在某藏品下的指定份数（1~N，N=有效持仓份数）
     * 按 id 升序回收最早获得的有效持仓，逐份走 recoverRow；份数超限或计数器回退失败即整体回滚。
     */
    public function recoverBatch()
    {
        $userId = $this->positiveInt('id');
        if ($userId === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        $collectibleId = (int) $this->request->param('collectible_id');
        $quantity      = (int) $this->request->param('quantity');
        $reason        = trim((string) $this->request->param('reason', ''));

        if ($collectibleId <= 0) {
            return $this->fail(4220, 'collectible_id 参数不正确');
        }
        if ($quantity <= 0) {
            return $this->fail(4220, '回收份数必须为大于 0 的整数');
        }
        if ($reason === '') {
            return $this->fail(4220, '回收原因不能为空');
        }

        $now = date('Y-m-d H:i:s');
        $available = 0;

        Db::startTrans();
        try {
            $rows = Db::name('user_collectibles')
                ->where('user_id', $userId)
                ->where('collectible_id', $collectibleId)
                ->whereIn('status', ['held', 'consigned', 'frozen'])
                ->order('id')
                ->lock(true)
                ->select()->toArray();
            $available = count($rows);
            if (!$available) {
                Db::rollback();
                return $this->fail(4220, '该用户在该藏品下无有效持仓可回收');
            }
            if ($quantity > $available) {
                Db::rollback();
                return $this->fail(4220, "回收份数超过可回收上限（当前有效持仓 {$available} 份）");
            }

            $revertedCount = 0;
            foreach (array_slice($rows, 0, $quantity) as $uc) {
                $revert = $this->recoverRow($uc, $reason, $now);
                if (!empty($revert['reverted'])) {
                    $revertedCount++;
                }
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '批量回收失败：' . $e->getMessage());
        }

        $this->audit('user', 'recover_batch', "批量回收用户（ID {$userId}）收藏品 x{$quantity} 份",
            ['collectible_id' => $collectibleId, 'quantity' => $quantity, 'available' => $available, 'reason' => $reason],
            'collectible', $collectibleId);

        return $this->success([
            'recovered'       => $quantity,
            'available'       => $available,
            'counterReverted' => $revertedCount,
        ], "已回收 {$quantity} 份（回退计数器 {$revertedCount} 份）");
    }

    /**
     * 事务内回收单条持仓（调用方已锁定并校验 status ∈ held/consigned/frozen）
     * 取消寄售挂单/取消待确认转赠 → 置 recovered → 按来源回退计数器（文档 4.3.4）
     * @return array revertOnRecover 的回退结果
     */
    private function recoverRow(array $uc, string $reason, string $now): array
    {
        // 寄售中：联动下架在售挂单
        if ($uc['status'] === 'consigned') {
            $listing = Db::name('resale_listings')->where('user_collectible_id', $uc['id'])
                ->where('status', 'selling')->find();
            if ($listing) {
                Db::name('resale_listings')->where('id', $listing['id'])->update([
                    'status'             => 'cancelled',
                    'is_system_delisted' => 1,
                    'system_delisted_at' => $now,
                    'delist_reason'      => '管理员强制回收：' . $reason,
                    'updated_at'         => $now,
                ]);
            }
        }

        // 转赠中（冻结）：取消待确认转赠
        if ($uc['status'] === 'frozen') {
            Db::name('transfers')
                ->where('user_collectible_id', $uc['id'])
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'updated_at' => $now]);
        }

        // 资产置已回收
        Db::name('user_collectibles')->where('id', $uc['id'])->update([
            'status'       => 'recovered',
            'is_consigned' => 0,
            'updated_at'   => $now,
        ]);

        return InventoryService::revertOnRecover($uc);
    }

    /**
     * GET /admin/user/holdings/:id
     * 用户有效持仓按藏品聚合（批量回收份数上限数据源）
     * 每个藏品返回 total（有效持仓份数）与 items（每份 serial/status 明细）。
     */
    public function holdings(int $id)
    {
        if (!Db::name('users')->where('id', $id)->whereNull('deleted_at')->count()) {
            return $this->fail(4040, '用户不存在');
        }

        $rows = Db::name('user_collectibles')->alias('uc')
            ->join('collectibles c', 'c.id = uc.collectible_id', 'LEFT')
            ->where('uc.user_id', $id)
            ->whereIn('uc.status', ['held', 'consigned', 'frozen'])
            ->field('uc.id, uc.serial, uc.status, uc.source, uc.acquired_at,
                     c.id AS collectible_id, c.name AS collectible_name, c.image AS collectible_image')
            ->order('uc.collectible_id', 'asc')
            ->order('uc.id', 'asc')
            ->select()->toArray();

        $groups = [];
        foreach ($rows as $r) {
            $cid = (int) $r['collectible_id'];
            if (!isset($groups[$cid])) {
                $groups[$cid] = [
                    'collectible_id'    => $cid,
                    'collectible_name'  => $r['collectible_name'],
                    'collectible_image' => $r['collectible_image'],
                    'total'             => 0,
                    'items'             => [],
                ];
            }
            $groups[$cid]['total']++;
            $groups[$cid]['items'][] = [
                'id'          => (int) $r['id'],
                'serial'      => $r['serial'],
                'status'      => $r['status'],
                'source'      => $r['source'],
                'acquired_at' => $r['acquired_at'],
            ];
        }

        return $this->success(['list' => camelize_keys(array_values($groups))]);
    }

    /**
     * GET /admin/user/assets/:id
     * 用户资产列表（详情抽屉-回收入口数据源）
     * 筛选：status（缺省=有效持仓 held/consigned/frozen；可指定 recovered/consumed/transferred 查历史）
     */
    public function assets(int $id)
    {
        [$page, $pageSize] = $this->pageParams();

        if (!Db::name('users')->where('id', $id)->whereNull('deleted_at')->count()) {
            return $this->fail(4040, '用户不存在');
        }

        $query = Db::name('user_collectibles')->alias('uc')
            ->join('collectibles c', 'c.id = uc.collectible_id', 'LEFT')
            ->where('uc.user_id', $id);

        $status = trim((string) $this->request->param('status', ''));
        if ($status !== '' && in_array($status, ['held', 'consigned', 'frozen', 'transferred', 'consumed', 'recovered'], true)) {
            $query->where('uc.status', $status);
        } else {
            $query->whereIn('uc.status', ['held', 'consigned', 'frozen']);
        }

        $total = (clone $query)->count();
        $rows = $query->field('uc.id, uc.serial, uc.status, uc.source, uc.acquired_price, uc.acquired_at,
                               uc.order_id, uc.airdrop_record_id, uc.token_id, uc.tx_hash, uc.block_number,
                               c.id AS collectible_id, c.name AS collectible_name, c.image AS collectible_image')
            ->order('uc.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }
}
