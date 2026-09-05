<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AuditLogService;
use think\facade\Db;

/**
 * 实名认证管理控制器（文档 8.5，#29-#32，只读）
 * 列表/详情默认脱敏；完整查看需密码二次验证并写审计（5.6）
 */
class Realnames extends AdminBase
{
    /**
     * #29 GET /realnames 实名列表（name/phone/status/时间范围，全部脱敏）
     */
    public function index()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('users')->whereNull('deleted_at')->where('is_realname', 1);

        // 按姓名脱敏前缀匹配：逐行解密后本地过滤（实名量级有限，可接受）
        $name = trim((string) $this->strParam('name'));
        $phone = trim((string) $this->strParam('phone'));

        $total = 0;
        $list = [];

        if ($name !== '' || $phone !== '') {
            // 先按手机号收敛（手机号明文可比）
            if ($phone !== '') {
                $query->where('phone', 'like', "%{$phone}%");
            }
            $rows = $query
                ->field('id,uid,username,avatar,phone,real_name,id_card,is_realname,created_at,updated_at')
                ->order('id', 'desc')
                ->select()
                ->toArray();
            // 姓名过滤（解密比对，脱敏前缀匹配）
            $filtered = [];
            foreach ($rows as $row) {
                if ($name !== '') {
                    $realName = $row['real_name'] ? aes_decrypt((string) $row['real_name']) : '';
                    if ($realName === null || mb_strpos($realName, $name) === false) {
                        continue;
                    }
                }
                $filtered[] = $row;
            }
            $total = count($filtered);
            $list = array_slice($filtered, $offset, $pageSize);
        } else {
            $total = (clone $query)->count();
            $list = $query
                ->field('id,uid,username,avatar,phone,real_name,id_card,is_realname,created_at,updated_at')
                ->order('id', 'desc')
                ->limit($offset, $pageSize)
                ->select()
                ->toArray();
        }

        $items = array_map(function (array $r) {
            return [
                'id'        => (int) $r['id'],
                'uid'       => (string) $r['uid'],
                'username'  => (string) $r['username'],
                'avatar'    => (string) $r['avatar'],
                'phone'     => mask_phone((string) $r['phone']),
                'realName'  => $this->maskName($r['real_name']),
                'idCard'    => $this->maskIdCard($r['id_card']),
                'status'    => 'verified',
                'createdAt' => (string) $r['created_at'],
            ];
        }, $list);

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * #30 GET /realnames/:id 脱敏实名详情
     */
    public function detail(int $id)
    {
        $user = Db::name('users')
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->field('id,uid,username,avatar,phone,real_name,id_card,is_realname,status,created_at,updated_at')
            ->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }
        if ((int) $user['is_realname'] !== 1) {
            return $this->fail(409, '该用户未完成实名认证');
        }

        return $this->success([
            'id'        => (int) $user['id'],
            'uid'       => (string) $user['uid'],
            'username'  => (string) $user['username'],
            'avatar'    => (string) $user['avatar'],
            'phone'     => mask_phone((string) $user['phone']),
            'realName'  => $this->maskName($user['real_name']),
            'idCard'    => $this->maskIdCard($user['id_card']),
            'accountStatus' => (int) $user['status'] === 1 ? '正常' : '已冻结',
            'realnamedAt' => (string) $user['updated_at'],
            'createdAt' => (string) $user['created_at'],
        ]);
    }

    /**
     * #31 POST /realnames/:id/full 查看完整实名（password 二次验证，写审计，11.1 高风险）
     * 权限：realname:full（仅超管/风控，PermissionMap 控制）
     */
    public function full(int $id)
    {
        $guard = $this->requirePassword();
        if ($guard !== null) {
            return $guard;
        }

        $user = Db::name('users')
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->field('id,uid,username,phone,real_name,id_card,is_realname,updated_at')
            ->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }
        if ((int) $user['is_realname'] !== 1) {
            return $this->fail(409, '该用户未完成实名认证');
        }

        $realName = $user['real_name'] ? aes_decrypt((string) $user['real_name']) : null;
        $idCard = $user['id_card'] ? aes_decrypt((string) $user['id_card']) : null;

        // 审计：谁、何时、查看了谁的实名信息（5.6）
        AuditLogService::log($this->request, 'realname', 'realname.full_view', [
            'target_type' => 'user',
            'target_id'   => $id,
            'target_desc' => $user['username'] . '(' . mask_phone((string) $user['phone']) . ')',
            'after'       => ['viewed' => 'full_realname'],
        ]);

        return $this->success([
            'id'        => (int) $user['id'],
            'uid'       => (string) $user['uid'],
            'username'  => (string) $user['username'],
            'phone'     => (string) $user['phone'],
            'realName'  => $realName ?? '（解密失败，请联系管理员检查 APP_KEY）',
            'idCard'    => $idCard ?? '（解密失败，请联系管理员检查 APP_KEY）',
            'realnamedAt' => (string) $user['updated_at'],
        ]);
    }

    /**
     * #32 GET /realnames/:id/audit-logs 实名查看审计日志
     */
    public function auditLogs(int $id)
    {
        $user = Db::name('users')->whereNull('deleted_at')->where('id', $id)->find();
        if (!$user) {
            return $this->fail(409, '用户不存在');
        }

        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('operation_logs')
            ->where('module', 'realname')
            ->where('action', 'realname.full_view')
            ->where('target_id', $id);

        $total = (clone $query)->count();
        $list = $query
            ->field('id,admin_id,admin_name,action,target_desc,before_value,after_value,reason,ip,created_at')
            ->order('id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        $items = array_map(function (array $r) {
            return [
                'id'        => (int) $r['id'],
                'adminId'   => (int) $r['admin_id'],
                'adminName' => (string) $r['admin_name'],
                'action'    => (string) $r['action'],
                'targetDesc' => (string) ($r['target_desc'] ?? ''),
                'reason'    => (string) ($r['reason'] ?? ''),
                'ip'        => (string) $r['ip'],
                'createdAt' => (string) $r['created_at'],
            ];
        }, $list);

        return $this->paginate($items, $total, $page, $pageSize);
    }

    // =====================================================================
    // 私有辅助（5.6 脱敏规范：姓名「张*」、身份证「110***********1」）
    // =====================================================================

    private function maskName(?string $encrypted): string
    {
        if (!$encrypted) {
            return '—';
        }
        $name = aes_decrypt($encrypted);
        if ($name === null || $name === '') {
            return '—';
        }
        $len = mb_strlen($name);
        if ($len <= 1) {
            return $name;
        }
        return mb_substr($name, 0, 1) . str_repeat('*', $len - 1);
    }

    private function maskIdCard(?string $encrypted): string
    {
        if (!$encrypted) {
            return '—';
        }
        $card = aes_decrypt($encrypted);
        if ($card === null || $card === '') {
            return '—';
        }
        $len = strlen($card);
        return substr($card, 0, 3) . str_repeat('*', max($len - 4, 0)) . substr($card, -1);
    }
}
