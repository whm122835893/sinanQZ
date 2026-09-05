<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AdminJwtService;
use app\admin\service\AuditLogService;
use app\admin\service\PermissionMap;
use think\facade\Db;

/**
 * 管理员认证控制器
 * POST /admin/api/v1/auth/login     登录
 * POST /admin/api/v1/auth/logout    登出
 * POST /admin/api/v1/auth/refresh    刷新 Token
 * GET  /admin/api/v1/auth/me        当前管理员信息 + 权限列表
 * PUT  /admin/api/v1/auth/password  修改密码
 */
class Auth extends AdminBase
{
    /** 连续失败锁定阈值 */
    private const MAX_FAIL     = 5;
    private const LOCK_SECONDS = 1800;

    /**
     * 登录
     */
    public function login()
    {
        $username = trim((string) $this->strParam('username'));
        $password = (string) $this->strParam('password', '');

        if ($username === '' || $password === '') {
            return $this->invalid('用户名与密码不能为空');
        }

        $admin = Db::name('admin_users')->where('username', $username)->find();
        $ip    = $this->ip();
        $ua    = substr((string) $this->request->header('user-agent', ''), 0, 500);

        // 账号不存在 / 密码错误：统一提示，避免账号枚举
        if (!$admin || !verify_password($password, (string) $admin['password'])) {
            $this->writeLoginLog($admin['id'] ?? null, $username, $ip, $ua, false, $admin ? '密码错误' : '账号不存在');

            // 存在账号时累计失败次数，达到阈值锁定
            if ($admin) {
                $failCount = (int) $admin['login_fail_count'] + 1;
                $update    = ['login_fail_count' => $failCount];
                if ($failCount >= self::MAX_FAIL) {
                    $update['locked_until'] = date('Y-m-d H:i:s', time() + self::LOCK_SECONDS);
                    $update['login_fail_count'] = 0;
                }
                Db::name('admin_users')->where('id', $admin['id'])->update($update);
            }
            return $this->fail(403, '用户名或密码错误');
        }

        // 状态与锁定校验
        if ((int) $admin['status'] !== 1) {
            $this->writeLoginLog($admin['id'], $username, $ip, $ua, false, '账号已禁用');
            return $this->fail(403, '账号已禁用，请联系超级管理员');
        }
        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
            $this->writeLoginLog($admin['id'], $username, $ip, $ua, false, '账号锁定中');
            return $this->fail(403, '账号已锁定，请' . ceil((strtotime((string) $admin['locked_until']) - time()) / 60) . '分钟后重试');
        }

        // IP 白名单（配置了才校验）
        if (!empty($admin['ip_whitelist'])) {
            $allow = array_map('trim', explode(',', (string) $admin['ip_whitelist']));
            if (!in_array($ip, $allow, true)) {
                $this->writeLoginLog($admin['id'], $username, $ip, $ua, false, 'IP不在白名单');
                return $this->fail(403, '当前IP不在该账号的白名单内');
            }
        }

        Db::name('admin_users')->where('id', $admin['id'])->update([
            'login_fail_count' => 0,
            'locked_until'     => null,
            'last_login_at'   => date('Y-m-d H:i:s'),
        ]);
        $this->writeLoginLog((int) $admin['id'], $username, $ip, $ua, true, null);

        $role = (int) $admin['role'];

        return $this->success([
            'token'         => AdminJwtService::accessToken((int) $admin['id'], $role, $username),
            'refreshToken'  => AdminJwtService::refreshToken((int) $admin['id'], $role, $username),
            'admin'         => $this->adminProfile($admin),
            'permissions'   => PermissionMap::permissionsForRole($role),
            'mustChangePwd' => (int) $admin['must_change_pwd'] === 1,
        ], '登录成功');
    }

    /**
     * 登出（客户端丢弃 token；后端记录审计）
     */
    public function logout()
    {
        AuditLogService::log($this->request, 'auth', 'auth.logout', [
            'target_type' => 'admin',
            'target_id'   => $this->adminId(),
            'target_desc' => $this->adminName(),
        ]);
        return $this->success(null, '已登出');
    }

    /**
     * 刷新 Token
     */
    public function refresh()
    {
        $token = trim((string) $this->strParam('refreshToken'));
        if ($token === '') {
            $token = trim((string) ($this->request->header('x-refresh-token', '')));
        }
        if ($token === '') {
            return $this->invalid('refreshToken不能为空');
        }

        $payload = AdminJwtService::decode($token, 'admin_refresh');
        if (!$payload) {
            return $this->fail(401, 'refreshToken无效或已过期');
        }

        $adminId = (int) $payload->aid;
        $admin   = Db::name('admin_users')
            ->where('id', $adminId)
            ->field('id,username,real_name,role,status')
            ->find();
        if (!$admin || (int) $admin['status'] !== 1) {
            return $this->fail(401, '账号不存在或已禁用');
        }

        $role = (int) $admin['role'];
        return $this->success([
            'token'        => AdminJwtService::accessToken($adminId, $role, (string) $admin['username']),
            'refreshToken' => AdminJwtService::refreshToken($adminId, $role, (string) $admin['username']),
        ]);
    }

    /**
     * 当前管理员信息
     */
    public function me()
    {
        $admin = Db::name('admin_users')
            ->where('id', $this->adminId())
            ->field('id,username,real_name,role,phone,email,avatar,status,last_login_at,last_action_at,created_at')
            ->find();
        if (!$admin) {
            return $this->fail(401, '账号不存在');
        }

        return $this->success([
            'admin'       => $this->adminProfile($admin),
            'permissions' => PermissionMap::permissionsForRole($this->adminRole()),
        ]);
    }

    /**
     * 修改自己的登录密码
     */
    public function password()
    {
        $oldPassword = (string) $this->strParam('oldPassword', '');
        $newPassword = (string) $this->strParam('newPassword', '');

        if (strlen($newPassword) < 8) {
            return $this->invalid('新密码长度至少8位');
        }
        if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).+$/', $newPassword)) {
            return $this->invalid('新密码必须同时包含字母和数字');
        }

        $hash = (string) Db::name('admin_users')->where('id', $this->adminId())->value('password');
        if (!verify_password($oldPassword, $hash)) {
            return $this->fail(403, '原密码错误');
        }
        if (verify_password($newPassword, $hash)) {
            return $this->invalid('新密码不能与原密码相同');
        }

        Db::name('admin_users')->where('id', $this->adminId())->update([
            'password'        => hash_password($newPassword),
            'must_change_pwd' => 0,
        ]);

        AuditLogService::log($this->request, 'auth', 'auth.change_password', [
            'target_type' => 'admin',
            'target_id'   => $this->adminId(),
            'target_desc' => $this->adminName(),
        ]);

        return $this->success(null, '密码已修改，请使用新密码重新登录');
    }

    /**
     * 管理员对外档案（不含敏感字段）
     */
    private function adminProfile(array $admin): array
    {
        return [
            'id'       => (int) $admin['id'],
            'username' => (string) $admin['username'],
            'realName' => (string) ($admin['real_name'] ?? ''),
            'role'     => (int) $admin['role'],
            'roleName' => PermissionMap::roleName((int) $admin['role']),
            'phone'    => $admin['phone'] ?? null,
            'email'    => $admin['email'] ?? null,
            'avatar'   => $admin['avatar'] ?? null,
        ];
    }

    /**
     * 登录日志
     */
    private function writeLoginLog(?int $adminId, string $username, string $ip, string $ua, bool $success, ?string $reason): void
    {
        try {
            Db::name('admin_login_logs')->insert([
                'admin_id'   => $adminId,
                'username'   => $username,
                'ip'         => $ip,
                'user_agent' => $ua ?: null,
                'success'    => $success ? 1 : 0,
                'fail_reason' => $reason,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            trace('admin login log failed: ' . $e->getMessage(), 'error');
        }
    }
}
