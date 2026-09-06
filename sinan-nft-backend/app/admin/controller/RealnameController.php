<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台实名认证控制器
 *
 * 列表仅展示脱敏摘要；完整明文（姓名/证件号）仅 realname:full 权限可见，
 * 且每次查看完整信息均写审计日志（合规要求）。
 */
class RealnameController extends BaseController
{
    /**
     * GET /admin/realname/list
     * 实名用户列表（is_realname=1），支持 keyword（手机号/UID）与时间筛选
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('users')->alias('u')
            ->whereNull('u.deleted_at')
            ->where('u.is_realname', 1);

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('u.phone', "%{$keyword}%")
                  ->whereOr('u.uid', $keyword);
            });
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('u.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('u.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('u.id, u.uid, u.phone, u.username, u.is_realname, u.created_at AS realname_time, u.updated_at')
            ->order('u.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        // 摘要仅展示解密后的姓氏
        foreach ($rows as &$row) {
            $row['phone'] = mask_phone((string) $row['phone']);
            $row['real_name_masked'] = '已实名';
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/realname/detail/:userId
     * 完整实名信息（需 realname:full 权限，路由层已校验；此处二次防御 + 强制审计）
     */
    public function detail(int $userId)
    {
        $admin = $this->admin();
        if (empty($admin['is_super']) && !in_array('realname:full', $admin['permissions'] ?? [], true)) {
            return $this->fail(4003, '无完整实名信息查看权限');
        }

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) {
            return $this->fail(4040, '用户不存在');
        }

        $realName = $user['real_name'] ? (aes_decrypt((string) $user['real_name']) ?? '') : '';
        $idCard   = $user['id_card'] ? (aes_decrypt((string) $user['id_card']) ?? '') : '';

        // 敏感操作：每次查看完整实名信息写审计（需求明确要求）
        $this->audit('realname', 'view_full', '查看完整实名信息（UID ' . $user['uid'] . '）', [], 'user', $userId);

        return $this->success([
            'id'          => (int) $user['id'],
            'uid'         => $user['uid'],
            'phone'       => (string) $user['phone'],
            'username'    => $user['username'],
            'isRealname'  => (int) $user['is_realname'],
            'realName'    => $realName,
            'idCard'      => $idCard,
            'realnameTime' => $user['updated_at'],
        ]);
    }

    /**
     * GET /admin/realname/stats
     * 实名统计（列表页顶部卡片）
     */
    public function stats()
    {
        $total = Db::name('users')->whereNull('deleted_at')->count();
        $done  = Db::name('users')->whereNull('deleted_at')->where('is_realname', 1)->count();
        $today = Db::name('users')->whereNull('deleted_at')->where('is_realname', 1)
            ->whereBetweenTime('updated_at', date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'))->count();

        return $this->success([
            'total' => $total,
            'realnamed' => $done,
            'rate' => $total > 0 ? round($done / $total * 100, 1) : 0,
            'today' => $today,
        ]);
    }
}
