<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AuditLogService;
use app\admin\service\PermissionMap;
use think\facade\Db;

/**
 * 管理员账号管理控制器（权限模块 P0 子集）
 * GET  /admin/api/v1/permission/admins       管理员列表
 * POST /admin/api/v1/permission/admins       创建管理员
 * GET  /admin/api/v1/permission/admins/:id   管理员详情
 * 角色管理 / 重置密码 / 删除随 P3 交付（文档 8.16）
 */
class Admins extends AdminBase
{
    /**
     * 管理员列表
     */
    public function index()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('admin_users');

        $username = trim((string) $this->strParam('username'));
        if ($username !== '') {
            $query->where('username', 'like', "%{$username}%");
        }
        $role = $this->strParam('role');
        if ($role !== null && $role !== '') {
            $query->where('role', (int) $role);
        }
        $status = $this->strParam('status');
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        $list  = $query
            ->field('id,username,real_name,role,phone,email,status,last_login_at,last_action_at,created_at')
            ->order('id', 'asc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        foreach ($list as &$row) {
            $row['realName']      = $row['real_name'];
            $row['roleName']      = PermissionMap::roleName((int) $row['role']);
            $row['lastLoginAt']   = $row['last_login_at'];
            $row['lastActionAt']  = $row['last_action_at'];
            $row['createdAt']     = $row['created_at'];
            unset($row['real_name'], $row['last_login_at'], $row['last_action_at'], $row['created_at']);
        }

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * 管理员详情
     */
    public function detail(int $id)
    {
        $admin = Db::name('admin_users')
            ->where('id', $id)
            ->field('id,username,real_name,role,phone,email,avatar,status,last_login_at,last_action_at,created_at')
            ->find();
        if (!$admin) {
            return $this->fail(409, '管理员不存在');
        }

        $admin['realName']     = $admin['real_name'];
        $admin['roleName']     = PermissionMap::roleName((int) $admin['role']);
        $admin['lastLoginAt']  = $admin['last_login_at'];
        $admin['lastActionAt'] = $admin['last_action_at'];
        $admin['createdAt']    = $admin['created_at'];
        $admin['phone']        = $admin['phone'] ? mask_phone((string) $admin['phone']) : null;
        unset($admin['real_name'], $admin['last_login_at'], $admin['last_action_at'], $admin['created_at']);

        return $this->success($admin);
    }

    /**
     * 创建管理员
     */
    public function create()
    {
        $username = trim((string) $this->strParam('username'));
        $password = (string) $this->strParam('password', '');
        $realName = trim((string) $this->strParam('realName'));
        $role     = $this->intParam('role', 0);
        $phone    = trim((string) $this->strParam('phone'));
        $email    = trim((string) $this->strParam('email'));

        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            return $this->invalid('用户名需为3-50位字母数字下划线');
        }
        if (strlen($password) < 8 || !preg_match('/^(?=.*[A-Za-z])(?=.*\d).+$/', $password)) {
            return $this->invalid('密码至少8位且必须同时包含字母和数字');
        }
        if ($realName === '') {
            return $this->invalid('真实姓名不能为空');
        }
        if (!isset(PermissionMap::ROLE_NAMES[$role])) {
            return $this->invalid('角色不合法');
        }
        if ($role === PermissionMap::ROLE_SUPER_ADMIN && $this->adminRole() !== PermissionMap::ROLE_SUPER_ADMIN) {
            return $this->fail(403, '只有超级管理员可以创建超管账号');
        }
        if ($phone !== '' && !preg_match('/^1\d{10}$/', $phone)) {
            return $this->invalid('手机号格式不正确');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->invalid('邮箱格式不正确');
        }

        $exists = Db::name('admin_users')->where('username', $username)->count();
        if ($exists) {
            return $this->conflict('用户名已存在');
        }

        $adminId = Db::name('admin_users')->insertGetId([
            'username'   => $username,
            'password'   => hash_password($password),
            'real_name'  => $realName,
            'role'       => $role,
            'phone'      => $phone ?: null,
            'email'      => $email ?: null,
            'status'     => 1,
            'must_change_pwd' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        AuditLogService::log($this->request, 'permission', 'permission.admin.create', [
            'target_type' => 'admin',
            'target_id'   => $adminId,
            'target_desc' => $username . '（' . PermissionMap::roleName($role) . '）',
            'after'       => ['username' => $username, 'role' => $role, 'realName' => $realName],
        ]);

        return $this->success(['id' => $adminId], '管理员已创建');
    }
}
