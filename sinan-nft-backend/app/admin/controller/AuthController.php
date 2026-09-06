<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AdminAuthService;
use think\facade\Db;

/**
 * 管理后台认证控制器
 *
 * 登录（失败锁定/禁用校验/登录日志）→ 双令牌（access 12h / refresh 7d）
 * → 刷新 → 个人信息（权限码+菜单树）→ 修改密码 → 登出
 */
class AuthController extends BaseController
{
    /**
     * POST /admin/auth/login { username, password }
     */
    public function login()
    {
        $missing = $this->missingParams(['username', 'password']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $username = trim((string) $this->request->param('username'));
        $password = (string) $this->request->param('password');

        if (mb_strlen($username) > 50 || mb_strlen($password) > 100) {
            return $this->fail(4220, '账号或密码格式不正确');
        }

        return AdminAuthService::login(
            $username,
            $password,
            (string) $this->request->ip(),
            (string) $this->request->header('user-agent', '')
        );
    }

    /**
     * POST /admin/auth/refresh { refresh_token }
     * 刷新令牌：校验 refresh scope 后签发新令牌对（滚动刷新）
     */
    public function refresh()
    {
        $refreshToken = trim((string) $this->request->param('refresh_token', ''));
        if ($refreshToken === '') {
            return $this->fail(4220, '缺少 refresh_token 参数');
        }

        $payload = AdminAuthService::verifyToken($refreshToken, 'admin_refresh');
        if ($payload === null) {
            return $this->fail(4002, '刷新令牌无效或已过期，请重新登录');
        }

        $adminId = (int) $payload['sub'];
        $context = AdminAuthService::authContext($adminId);
        if ($context === null) {
            return $this->fail(4003, '账号状态异常，请重新登录');
        }

        $admin = Db::name('admin_users')->where('id', $adminId)->whereNull('deleted_at')->find();
        if (!$admin) {
            return $this->fail(4003, '账号不存在');
        }

        $tokens = AdminAuthService::issueTokens($admin, (string) $this->request->ip());

        return $this->success([
            'token'         => $tokens['token'],
            'refresh_token' => $tokens['refresh_token'],
            'expire_at'     => $tokens['expire_at'],
            'admin'         => AdminAuthService::adminInfo($adminId),
        ]);
    }

    /**
     * POST /admin/auth/logout
     * JWT 无状态：前端清除令牌；服务端记录审计日志
     */
    public function logout()
    {
        $this->audit('auth', 'logout', '管理员登出');
        return $this->success(null, '已安全退出');
    }

    /**
     * GET /admin/auth/profile
     * 当前管理员信息（含权限码与菜单树，前端动态路由数据源）
     */
    public function profile()
    {
        $info = AdminAuthService::adminInfo($this->adminId());
        if (!$info) {
            return $this->fail(4003, '账号信息读取失败');
        }
        return $this->success($info);
    }

    /**
     * POST /admin/auth/change-password { old_password, new_password, confirm_password }
     */
    public function changePassword()
    {
        $missing = $this->missingParams(['old_password', 'new_password', 'confirm_password']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $oldPassword = (string) $this->request->param('old_password');
        $newPassword = (string) $this->request->param('new_password');
        $confirm     = (string) $this->request->param('confirm_password');

        if ($newPassword !== $confirm) {
            return $this->fail(4220, '两次输入的新密码不一致');
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > 64) {
            return $this->fail(4220, '新密码长度需为 8~64 位');
        }
        if (!preg_match('/^(?=.*[a-zA-Z])(?=.*\d).+$/', $newPassword)) {
            return $this->fail(4220, '新密码需同时包含字母和数字');
        }

        $adminId = $this->adminId();
        $admin   = Db::name('admin_users')->where('id', $adminId)->whereNull('deleted_at')->find();
        if (!$admin || !password_verify($oldPassword, (string) $admin['password_hash'])) {
            return $this->fail(4004, '原密码错误');
        }
        if (password_verify($newPassword, (string) $admin['password_hash'])) {
            return $this->fail(4220, '新密码不能与原密码相同');
        }

        Db::name('admin_users')->where('id', $adminId)->update([
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->audit('auth', 'change_password', '修改登录密码');

        return $this->success(null, '密码修改成功，下次登录请使用新密码');
    }
}
