<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\Request;

/**
 * 管理端基础控制器
 * 响应码约定（文档 8.1）：0成功 / 401未登录 / 403无权限或密码错误 /
 * 409业务冲突（库存配额不足、状态不允许）/ 422参数错误 / 500服务器错误
 */
abstract class AdminBase extends BaseController
{
    /**
     * 当前管理员ID（AdminAuth 注入）
     */
    protected function adminId(): int
    {
        return (int) ($this->request->adminId ?? 0);
    }

    /**
     * 当前管理员角色
     */
    protected function adminRole(): int
    {
        return (int) ($this->request->adminRole ?? 0);
    }

    /**
     * 当前管理员姓名快照（优先 real_name）
     */
    protected function adminName(): string
    {
        return (string) ($this->request->adminName ?? '');
    }

    /**
     * 参数校验失败响应
     */
    protected function invalid(string $message): \think\Response
    {
        return $this->fail(422, $message);
    }

    /**
     * 业务冲突响应（库存/配额不足、状态不允许等）
     */
    protected function conflict(string $message): \think\Response
    {
        return $this->fail(409, $message);
    }

    /**
     * 高风险操作密码校验（文档 11.1）
     * 请求体必含 password 字段，校验失败返回 403
     */
    protected function verifyOperatorPassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $hash = \think\facade\Db::name('admin_users')
            ->where('id', $this->adminId())
            ->value('password');
        return $hash ? verify_password($password, (string) $hash) : false;
    }

    /**
     * 高风险操作密码守卫（文档 8.1：校验失败返回 403 并写安全事件）
     * 返回 null 表示通过；返回 Response 表示拦截
     */
    protected function requirePassword(): ?\think\Response
    {
        $password = (string) ($this->request->param('password', ''));
        if ($this->verifyOperatorPassword($password)) {
            return null;
        }
        try {
            \think\facade\Db::name('security_events')->insert([
                'event_type'     => 8, // 其他：高风险操作密码校验失败
                'event_level'    => 2,
                'admin_id'       => $this->adminId(),
                'ip'             => $this->ip(),
                'user_agent'     => substr((string) ($this->request->header('user-agent') ?? ''), 0, 500),
                'request_path'   => substr((string) $this->request->pathinfo(), 0, 255),
                'request_method' => $this->request->method(),
                'description'    => '高风险操作管理员密码校验失败',
                'status'         => 1,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            trace('SecurityEvent write failed: ' . $e->getMessage(), 'error');
        }
        return $this->fail(403, '管理员密码错误');
    }

    /**
     * 请求 IP
     */
    protected function ip(): string
    {
        return (string) $this->request->ip();
    }
}
