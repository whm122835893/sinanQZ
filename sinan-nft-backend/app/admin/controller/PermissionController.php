<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 权限管理控制器（RBAC）
 *
 * - 管理员管理（permission:admin）：增删改查、启用/禁用、解锁、重置密码
 * - 角色管理（permission:role）：增删改查、权限分配（内置角色保护）
 * - 日志查询（permission:log）：操作日志 + 登录日志
 *
 * 严谨性设计：
 * - 内置角色（is_builtin=1）不可删除、不可修改标识；超管角色权限不可裁剪
 * - 最后一个启用状态的 super_admin 不可禁用（防锁死）
 * - 删除角色前校验是否存在在职管理员引用
 * - 密码 bcrypt 强度要求：8~64 位、含字母与数字
 */
class PermissionController extends BaseController
{
    // ============================================================
    // 一、管理员管理（permission:admin）
    // ============================================================

    /**
     * GET /admin/permission/admins
     */
    public function adminList()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('admin_users')->alias('au')
            ->field('au.id, au.username, au.real_name, au.phone, au.email, au.status, au.locked_until,
                     au.last_login_at, au.last_login_ip, au.last_action_at, au.created_at,
                     r.id AS role_id, r.name AS role_name, r.code AS role_code, r.is_builtin AS role_builtin')
            ->join('admin_roles r', 'r.id = au.role_id')
            ->whereNull('au.deleted_at');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('au.username', '%' . $keyword . '%')
                    ->whereOr('au.real_name', 'like', '%' . $keyword . '%');
            });
        }
        if ($this->request->param('role_id') !== null && $this->request->param('role_id') !== '') {
            $query->where('au.role_id', (int) $this->request->param('role_id'));
        }
        if ($this->request->param('status') !== null && $this->request->param('status') !== '') {
            $query->where('au.status', (int) $this->request->param('status') === 1 ? 1 : 0);
        }

        $total = (clone $query)->count();
        $items = $query->order('au.id', 'asc')->page($page, $pageSize)->select()->toArray();

        // 实时锁定状态判定
        $now = date('Y-m-d H:i:s');
        foreach ($items as &$item) {
            $item['is_locked'] = $item['locked_until'] !== null && $item['locked_until'] > $now ? 1 : 0;
        }

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * POST /admin/permission/admins { username, password, real_name, role_id, phone?, email? }
     */
    public function adminCreate()
    {
        $missing = $this->missingParams(['username', 'password', 'real_name', 'role_id']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $username = trim((string) $this->request->param('username'));
        $password = (string) $this->request->param('password');
        $realName = trim((string) $this->request->param('real_name'));
        $roleId   = (int) $this->request->param('role_id');

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,49}$/', $username)) {
            return $this->fail(4220, '账号需为字母开头的 3~50 位字母/数字/下划线');
        }
        $pwdCheck = $this->validatePassword($password);
        if ($pwdCheck !== '') {
            return $this->fail(4220, $pwdCheck);
        }
        if (mb_strlen($realName) < 2 || mb_strlen($realName) > 50) {
            return $this->fail(4220, '真实姓名需为 2~50 字');
        }
        $role = Db::name('admin_roles')->where('id', $roleId)->find();
        if (!$role) {
            return $this->fail(4040, '角色不存在');
        }
        if (Db::name('admin_users')->where('username', $username)->whereNull('deleted_at')->count() > 0) {
            return $this->fail(4220, '账号已存在');
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('admin_users')->insertGetId([
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
            'real_name'     => $realName,
            'role_id'       => $roleId,
            'phone'         => $this->optPhone(),
            'email'         => $this->optEmail(),
            'status'        => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $this->audit('permission', 'admin_create', '新增管理员「' . $username . '」（' . $realName . '，角色：' . $role['name'] . '）',
            ['role_id' => $roleId], 'admin_user', $id);
        return $this->success(['id' => $id], '管理员已创建');
    }

    /**
     * PUT /admin/permission/admins/:id
     * { real_name?, role_id?, phone?, email?, status? }
     */
    public function adminUpdate()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $admin = Db::name('admin_users')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$admin) {
            return $this->fail(4040, '管理员不存在');
        }

        $update = ['updated_at' => date('Y-m-d H:i:s')];

        if ($this->request->param('real_name') !== null && $this->request->param('real_name') !== '') {
            $realName = trim((string) $this->request->param('real_name'));
            if (mb_strlen($realName) < 2 || mb_strlen($realName) > 50) {
                return $this->fail(4220, '真实姓名需为 2~50 字');
            }
            $update['real_name'] = $realName;
        }
        if ($this->request->param('phone') !== null) {
            $update['phone'] = $this->optPhone();
        }
        if ($this->request->param('email') !== null) {
            $update['email'] = $this->optEmail();
        }
        if ($this->request->param('role_id') !== null && $this->request->param('role_id') !== '') {
            $newRoleId = (int) $this->request->param('role_id');
            $role = Db::name('admin_roles')->where('id', $newRoleId)->find();
            if (!$role) {
                return $this->fail(4040, '角色不存在');
            }
            $update['role_id'] = $newRoleId;
        }

        // 状态变更（含防锁死保护）
        if ($this->request->param('status') !== null && $this->request->param('status') !== '') {
            $newStatus = (int) $this->request->param('status') === 1 ? 1 : 0;
            if ((int) $admin['status'] === 1 && $newStatus === 0) {
                $err = $this->checkLastSuperAdmin($id, '禁用');
                if ($err !== '') {
                    return $this->fail(4220, $err);
                }
            }
            $update['status'] = $newStatus;
        }

        Db::name('admin_users')->where('id', $id)->update($update);

        $this->audit('permission', 'admin_update', '编辑管理员「' . $admin['username'] . '」', $update, 'admin_user', $id);
        return $this->success(null, '管理员信息已更新');
    }

    /**
     * POST /admin/permission/admins/:id/reset-password { new_password }
     */
    public function adminResetPassword()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $admin = Db::name('admin_users')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$admin) {
            return $this->fail(4040, '管理员不存在');
        }

        $newPassword = (string) $this->request->param('new_password', '');
        $pwdCheck = $this->validatePassword($newPassword);
        if ($pwdCheck !== '') {
            return $this->fail(4220, $pwdCheck);
        }

        Db::name('admin_users')->where('id', $id)->update([
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->audit('permission', 'admin_reset_password', '重置管理员「' . $admin['username'] . '」登录密码', [], 'admin_user', $id);
        return $this->success(null, '密码已重置');
    }

    /**
     * POST /admin/permission/admins/:id/unlock（解除登录锁定）
     */
    public function adminUnlock()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $admin = Db::name('admin_users')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$admin) {
            return $this->fail(4040, '管理员不存在');
        }
        if (!$admin['locked_until'] || $admin['locked_until'] < date('Y-m-d H:i:s')) {
            return $this->fail(4220, '该账号未处于锁定状态');
        }

        Db::name('admin_users')->where('id', $id)->update([
            'locked_until'     => null,
            'login_fail_count' => 0,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->audit('permission', 'admin_unlock', '解除管理员「' . $admin['username'] . '」登录锁定', [], 'admin_user', $id);
        return $this->success(null, '账号已解锁');
    }

    /**
     * DELETE /admin/permission/admins/:id（软删除；不可删除自己与最后超管）
     */
    public function adminDelete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }
        if ($id === $this->adminId()) {
            return $this->fail(4220, '不能删除当前登录账号');
        }

        $admin = Db::name('admin_users')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$admin) {
            return $this->fail(4040, '管理员不存在');
        }

        $roleCode = Db::name('admin_roles')->where('id', $admin['role_id'])->value('code');
        if ($roleCode === 'super_admin') {
            $err = $this->checkLastSuperAdmin($id, '删除');
            if ($err !== '') {
                return $this->fail(4220, $err);
            }
        }

        Db::name('admin_users')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'status'     => 0,
        ]);

        $this->audit('permission', 'admin_delete', '删除管理员「' . $admin['username'] . '」', [], 'admin_user', $id);
        return $this->success(null, '管理员已删除');
    }

    // ============================================================
    // 二、角色管理（permission:role）
    // ============================================================

    /**
     * GET /admin/permission/roles（含权限码集合与使用计数）
     */
    public function roleList()
    {
        $roles = Db::name('admin_roles')->order('id', 'asc')->select()->toArray();

        $list = [];
        foreach ($roles as $role) {
            $permCount = Db::name('admin_role_permissions')->where('role_id', $role['id'])->count();
            $adminCount = Db::name('admin_users')->where('role_id', $role['id'])->whereNull('deleted_at')->count();
            $role['permission_count'] = $permCount;
            $role['admin_count'] = $adminCount;
            $list[] = $role;
        }
        return $this->success($list);
    }

    /**
     * GET /admin/permission/roles/:id（角色详情 + 权限ID集合）
     */
    public function roleDetail()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $role = Db::name('admin_roles')->where('id', $id)->find();
        if (!$role) {
            return $this->fail(4040, '角色不存在');
        }

        $role['permission_ids'] = Db::name('admin_role_permissions')
            ->where('role_id', $id)->column('permission_id');

        return $this->success($role);
    }

    /**
     * GET /admin/permission/tree（全量权限树，角色分配数据源）
     */
    public function permissionTree()
    {
        $all = Db::name('admin_permissions')->where('status', 1)->order('sort_order', 'asc')->select()->toArray();

        $nodes = [];
        foreach ($all as $p) {
            $nodes[] = [
                'id'       => (int) $p['id'],
                'name'     => $p['name'],
                'code'     => $p['code'],
                'module'   => $p['module'],
                'type'     => (int) $p['type'],
                'parentId' => (int) $p['parent_id'],
                'sort'     => (int) $p['sort_order'],
                'children' => [],
            ];
        }

        $byId = array_column($nodes, null, 'id');
        $tree = [];
        foreach ($nodes as &$node) {
            if ($node['parentId'] > 0 && isset($byId[$node['parentId']])) {
                $byId[$node['parentId']]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }

        return $this->success($tree);
    }

    /**
     * POST /admin/permission/roles { name, code, description, permission_ids[] }
     */
    public function roleCreate()
    {
        $missing = $this->missingParams(['name', 'code']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $name = trim((string) $this->request->param('name'));
        $code = trim((string) $this->request->param('code'));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
            return $this->fail(4220, '角色名称需为 2~50 字');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $code)) {
            return $this->fail(4220, '角色标识需为小写字母开头的 2~50 位字母/数字/下划线');
        }
        if (in_array($code, ['super_admin', 'operator', 'finance', 'risk', 'support'], true)) {
            return $this->fail(4220, '该角色标识为内置保留，请更换');
        }
        if (Db::name('admin_roles')->where('code', $code)->count() > 0) {
            return $this->fail(4220, '角色标识已存在');
        }

        $now = date('Y-m-d H:i:s');
        Db::startTrans();
        try {
            $roleId = (int) Db::name('admin_roles')->insertGetId([
                'name'        => $name,
                'code'        => $code,
                'description' => $this->request->param('description') !== null
                    ? mb_substr(trim((string) $this->request->param('description')), 0, 255) : null,
                'status'      => 1,
                'is_builtin'  => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            $this->saveRolePermissions($roleId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '角色创建失败：' . $e->getMessage());
        }

        $this->audit('permission', 'role_create', '新增角色「' . $name . '」（' . $code . '）', [], 'admin_role', $roleId);
        return $this->success(['id' => $roleId], '角色已创建');
    }

    /**
     * PUT /admin/permission/roles/:id
     * { name?, description?, status?, permission_ids[] }
     */
    public function roleUpdate()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $role = Db::name('admin_roles')->where('id', $id)->find();
        if (!$role) {
            return $this->fail(4040, '角色不存在');
        }

        $update = ['updated_at' => date('Y-m-d H:i:s')];

        if ($this->request->param('name') !== null && $this->request->param('name') !== '') {
            $name = trim((string) $this->request->param('name'));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
                return $this->fail(4220, '角色名称需为 2~50 字');
            }
            $update['name'] = $name;
        }
        if ($this->request->param('description') !== null) {
            $update['description'] = mb_substr(trim((string) $this->request->param('description')), 0, 255);
        }

        // 超管角色状态与标识保护
        if ($role['code'] === 'super_admin') {
            if ($this->request->param('status') !== null && (int) $this->request->param('status') !== 1) {
                return $this->fail(4220, '超级管理员角色不可停用');
            }
            if ($this->request->has('permission_ids')) {
                return $this->fail(4220, '超级管理员角色拥有全部权限，不可调整');
            }
        } elseif ($this->request->param('status') !== null && $this->request->param('status') !== '') {
            $newStatus = (int) $this->request->param('status') === 1 ? 1 : 0;
            if ((int) $role['status'] === 1 && $newStatus === 0) {
                $using = Db::name('admin_users')->where('role_id', $id)->whereNull('deleted_at')->where('status', 1)->count();
                if ($using > 0) {
                    return $this->fail(4220, '该角色下仍有 ' . $using . ' 名在职管理员，不可停用');
                }
            }
            $update['status'] = $newStatus;
        }

        Db::startTrans();
        try {
            Db::name('admin_roles')->where('id', $id)->update($update);

            if ($role['code'] !== 'super_admin' && $this->request->has('permission_ids')) {
                $this->saveRolePermissions($id);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '角色更新失败：' . $e->getMessage());
        }

        $this->audit('permission', 'role_update', '编辑角色「' . ($update['name'] ?? $role['name']) . '」'
            . ($this->request->has('permission_ids') ? '（含权限调整）' : ''), [], 'admin_role', $id);
        return $this->success(null, '角色已更新');
    }

    /**
     * DELETE /admin/permission/roles/:id
     * 内置角色不可删；有管理员引用不可删
     */
    public function roleDelete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) {
            return $this->fail(4220, 'id 参数不正确');
        }

        $role = Db::name('admin_roles')->where('id', $id)->find();
        if (!$role) {
            return $this->fail(4040, '角色不存在');
        }
        if ((int) $role['is_builtin'] === 1) {
            return $this->fail(4220, '内置角色不可删除');
        }
        $using = Db::name('admin_users')->where('role_id', $id)->whereNull('deleted_at')->count();
        if ($using > 0) {
            return $this->fail(4220, '该角色下仍有 ' . $using . ' 名管理员，请先转移后再删除');
        }

        Db::startTrans();
        try {
            Db::name('admin_role_permissions')->where('role_id', $id)->delete();
            Db::name('admin_roles')->where('id', $id)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '角色删除失败：' . $e->getMessage());
        }

        $this->audit('permission', 'role_delete', '删除角色「' . $role['name'] . '」', [], 'admin_role', $id);
        return $this->success(null, '角色已删除');
    }

    /**
     * 保存角色权限映射（全删全插，事务内调用）
     */
    private function saveRolePermissions(int $roleId): void
    {
        $permissionIds = $this->request->param('permission_ids', []);
        if (is_string($permissionIds)) {
            $permissionIds = array_filter(explode(',', $permissionIds));
        }
        if (!is_array($permissionIds)) {
            $permissionIds = [];
        }

        // 白名单过滤：仅允许有效权限ID
        $validIds = Db::name('admin_permissions')->where('status', 1)->column('id');
        $permissionIds = array_values(array_unique(array_map('intval', array_intersect(
            array_map(fn ($v) => (int) $v, $permissionIds), $validIds
        ))));

        Db::name('admin_role_permissions')->where('role_id', $roleId)->delete();
        if ($permissionIds !== []) {
            $now = date('Y-m-d H:i:s');
            $rows = array_map(fn ($pid) => [
                'role_id'       => $roleId,
                'permission_id' => $pid,
                'created_at'    => $now,
            ], $permissionIds);
            Db::name('admin_role_permissions')->insertAll($rows);
        }
    }

    // ============================================================
    // 三、日志查询（permission:log）
    // ============================================================

    /**
     * GET /admin/permission/operation-logs
     */
    public function operationLogs()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('admin_operation_logs');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('action_desc', '%' . $keyword . '%')
                    ->whereOr('admin_name', 'like', '%' . $keyword . '%');
            });
        }
        if ($this->request->param('module') !== null && $this->request->param('module') !== '') {
            $query->where('module', (string) $this->request->param('module'));
        }
        if ($this->request->param('admin_id') !== null && $this->request->param('admin_id') !== '') {
            $query->where('admin_id', (int) $this->request->param('admin_id'));
        }
        if ($range = $this->dateRange()) {
            $query->whereBetween('created_at', $range);
        }

        $total = (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * GET /admin/permission/log-modules（模块筛选项，前端下拉数据源）
     */
    public function logModules()
    {
        $modules = Db::name('admin_operation_logs')->distinct(true)->order('module')->column('module');
        return $this->success(array_values(array_filter($modules)));
    }

    /**
     * GET /admin/permission/login-logs
     */
    public function loginLogs()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('admin_login_logs');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('username', '%' . $keyword . '%');
        }
        if ($this->request->param('status') !== null && $this->request->param('status') !== '') {
            $query->where('status', (int) $this->request->param('status'));
        }
        if ($this->request->param('ip') !== null && $this->request->param('ip') !== '') {
            $query->where('ip', 'like', '%' . trim((string) $this->request->param('ip')) . '%');
        }
        if ($range = $this->dateRange()) {
            $query->whereBetween('created_at', $range);
        }

        $total = (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->paginate($items, $total, $page, $pageSize);
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 密码强度校验（返回错误消息，空串表示通过）
     */
    private function validatePassword(string $password): string
    {
        if (strlen($password) < 8 || strlen($password) > 64) {
            return '密码长度需为 8~64 位';
        }
        if (!preg_match('/^(?=.*[a-zA-Z])(?=.*\d).+$/', $password)) {
            return '密码需同时包含字母和数字';
        }
        return '';
    }

    /**
     * 可选手机号校验
     */
    private function optPhone(): ?string
    {
        $phone = trim((string) $this->request->param('phone', ''));
        if ($phone === '') {
            return null;
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return null; // 格式非法按空处理，避免脏数据入库
        }
        return $phone;
    }

    /**
     * 可选邮箱校验
     */
    private function optEmail(): ?string
    {
        $email = trim((string) $this->request->param('email', ''));
        if ($email === '') {
            return null;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 100) : null;
    }

    /**
     * 最后超管保护校验（返回错误消息，空串表示通过）
     */
    private function checkLastSuperAdmin(int $adminId, string $action): string
    {
        $superRoleId = Db::name('admin_roles')->where('code', 'super_admin')->value('id');
        if (!$superRoleId) {
            return '';
        }
        $activeSupers = Db::name('admin_users')
            ->where('role_id', $superRoleId)
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->where('id', '<>', $adminId)
            ->count();
        if ($activeSupers === 0) {
            return '系统必须保留至少一名启用状态的超级管理员，无法' . $action;
        }
        return '';
    }
}
