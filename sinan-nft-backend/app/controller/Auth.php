<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use app\service\JwtService;
use think\facade\Db;

/**
 * 认证控制器
 * 短信验证码发送 / 注册 / 登录 / 忘记密码
 */
class Auth extends BaseController
{
    /**
     * POST /api/auth/send-code
     * 发送短信验证码
     */
    public function sendCode()
    {
        $phone = $this->request->post('phone', '');
        $scene = $this->request->post('scene', 'register');

        if (!preg_match('/^1\d{10}$/', $phone)) {
            return $this->fail(1001, '手机号格式错误');
        }
        if (!in_array($scene, ['register', 'login', 'reset_password'])) {
            return $this->fail(1001, '场景参数错误');
        }

        // 60秒内同手机号+场景禁止重发
        $recent = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', $scene)
            ->where('sent_at', '>', date('Y-m-d H:i:s.v', time() - 60))
            ->find();
        if ($recent) {
            return $this->fail(1001, '验证码发送过于频繁，请稍后再试');
        }

        // Mock：生成6位明文验证码，不真发短信，直接存库（带bcrypt哈希）
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now  = date('Y-m-d H:i:s.v');

        Db::name('verification_codes')->insert([
            'phone'      => $phone,
            'scene'      => $scene,
            'code'       => hash_password($code),
            'expires_at' => date('Y-m-d H:i:s.v', time() + 300),
            'sent_at'    => $now,
            'ip'         => $this->request->ip(),
            'created_at' => $now,
        ]);

        // Mock 环境下直接把明文验证码返回（生产环境删除）
        return $this->success(['debugCode' => env('APP_DEBUG') ? $code : null]);
    }

    /**
     * POST /api/auth/register
     * 注册
     */
    public function register()
    {
        $phone      = $this->request->post('phone', '');
        $code       = $this->request->post('code', '');
        $nickname   = $this->request->post('nickname', '');
        $inviteCode = $this->request->post('inviteCode', '');

        if (!preg_match('/^1\d{10}$/', $phone)) {
            return $this->fail(1001, '手机号格式错误');
        }
        if (strlen($code) !== 6) {
            return $this->fail(1001, '验证码格式错误');
        }
        if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 20) {
            return $this->fail(1001, '用户名长度需在 2-20 字之间');
        }

        // 校验手机号是否已注册
        $exists = Db::name('users')->where('phone', $phone)->find();
        if ($exists) {
            return $this->fail(1001, '该手机号已注册');
        }

        // 校验验证码
        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'register')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time()) {
            return $this->fail(1001, '验证码已过期');
        }
        if (!verify_password($code, $vc['code'])) {
            return $this->fail(1001, '验证码错误');
        }

        $now = date('Y-m-d H:i:s.v');

        Db::startTrans();
        try {
            // 生成唯一 invite_code
            do {
                $newInviteCode = gen_invite_code();
            } while (Db::name('users')->where('invite_code', $newInviteCode)->find());

            Db::name('users')->insert([
                'phone'       => $phone,
                'username'    => $nickname,
                'uid'         => '',  // 先占位，下面更新
                'invite_code' => $newInviteCode,
                'status'      => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            $userId = Db::name('users')->where('phone', $phone)->value('id');
            Db::name('users')->where('id', $userId)->update(['uid' => gen_uid($userId)]);

            // 创建钱包
            Db::name('wallets')->insert([
                'user_id'    => $userId,
                'balance'    => 0,
                'available'  => 0,
                'frozen'     => 0,
                'points'     => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 核销验证码
            Db::name('verification_codes')->where('id', $vc['id'])->update(['used_at' => $now]);

            // 处理邀请码
            if ($inviteCode) {
                $inviter = Db::name('users')->where('invite_code', $inviteCode)->find();
                if ($inviter) {
                    $dup = Db::name('invite_records')->where('invitee_id', $userId)->find();
                    if (!$dup) {
                        Db::name('invite_records')->insert([
                            'inviter_id'  => $inviter['id'],
                            'invitee_id'  => $userId,
                            'invite_code' => $inviteCode,
                            'status'      => 'registered',
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ]);
                    }
                }
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '注册失败：' . $e->getMessage());
        }

        $user = Db::name('users')->where('id', $userId)->find();
        $token = JwtService::encode($userId, $phone);

        return $this->success([
            'token'    => $token,
            'userInfo' => camelize_keys([
                'uid'        => $user['uid'],
                'username'   => $user['username'],
                'phone'      => mask_phone($user['phone']),
                'avatar'     => $user['avatar'],
                'is_realname' => (bool) $user['is_realname'],
                'invite_code' => $user['invite_code'],
            ]),
        ]);
    }

    /**
     * POST /api/auth/login
     * 登录
     */
    public function login()
    {
        $phone = $this->request->post('phone', '');
        $code  = $this->request->post('code', '');

        if (!preg_match('/^1\d{10}$/', $phone)) {
            return $this->fail(1001, '手机号格式错误');
        }

        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'login')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time() || !verify_password($code, $vc['code'])) {
            return $this->fail(1001, '验证码错误或已过期');
        }

        $user = Db::name('users')->where('phone', $phone)->find();
        if (!$user) {
            return $this->fail(1002, '该手机号未注册');
        }
        if ((int) $user['status'] !== 1) {
            return $this->fail(2002, '账户已被禁用');
        }

        Db::startTrans();
        Db::name('verification_codes')->where('id', $vc['id'])->update(['used_at' => date('Y-m-d H:i:s.v')]);
        Db::name('users')->where('id', $user['id'])->update([
            'last_login_at' => date('Y-m-d H:i:s.v'),
            'login_count'   => $user['login_count'] + 1,
        ]);
        Db::commit();

        // 重新登录即开启新会话：清除管理端强制登出标记（踢出标记仅作用于旧 token，否则等效封禁到 TTL）
        try {
            cache('force_logout_' . $user['id'], null);
        } catch (\Throwable $e) {
            // 缓存异常不阻断登录
        }

        $token = JwtService::encode($user['id'], $phone);
        return $this->success([
            'token'    => $token,
            'userInfo' => camelize_keys([
                'uid'        => $user['uid'],
                'username'   => $user['username'],
                'phone'      => mask_phone($user['phone']),
                'avatar'     => $user['avatar'],
                'is_realname' => (bool) $user['is_realname'],
                'invite_code' => $user['invite_code'],
            ]),
        ]);
    }

    /**
     * POST /api/auth/reset-password
     * 忘记密码（设置/重置交易密码）
     */
    public function resetPassword()
    {
        $phone        = $this->request->post('phone', '');
        $code         = $this->request->post('code', '');
        $newPassword  = $this->request->post('newPassword', '');

        if (!preg_match('/^1\d{10}$/', $phone)) {
            return $this->fail(1001, '手机号格式错误');
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 20) {
            return $this->fail(1001, '密码长度需在 6-20 位之间');
        }

        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'reset_password')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time() || !verify_password($code, $vc['code'])) {
            return $this->fail(1001, '验证码错误或已过期');
        }

        $user = Db::name('users')->where('phone', $phone)->find();
        if (!$user) {
            return $this->fail(1002, '该手机号未注册');
        }

        Db::startTrans();
        Db::name('users')->where('id', $user['id'])->update([
            'transaction_password' => hash_password($newPassword),
            'updated_at'            => date('Y-m-d H:i:s.v'),
        ]);
        Db::name('verification_codes')->where('id', $vc['id'])->update(['used_at' => date('Y-m-d H:i:s.v')]);
        Db::commit();

        return $this->success();
    }

    /**
     * POST /api/auth/logout
     * 登出（无状态 JWT，客户端清 token 即可）
     */
    public function logout()
    {
        return $this->success();
    }
}
