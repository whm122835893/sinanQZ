<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 用户控制器
 * 获取信息 / 修改资料 / 实名认证 / 交易密码
 */
class User extends BaseController
{
    /**
     * GET /api/user/profile
     * 获取当前用户信息
     */
    public function profile()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) return $this->fail(1002, '用户不存在');

        $wallet = Db::name('wallets')->where('user_id', $userId)->find();

        return $this->success([
            'uid'      => $user['uid'],
            'nickname' => $user['username'],
            'phone'    => mask_phone($user['phone']),
            'avatar'   => $user['avatar'],
            'isRealName'   => (bool) $user['is_realname'],
            // 实名审核状态：0未提交 1待审核 2已通过 3已驳回（供 C 端展示「审核中/被驳回」）
            'realnameStatus' => (int) ($user['realname_status'] ?? 0),
            'realnameRejectReason' => (string) ($user['realname_reject_reason'] ?? ''),
            'inviteCode'   => $user['invite_code'],
            // 是否已设置登录密码（供 C 端账户安全页展示「登录密码 已设置/未设置」）
            'hasPassword' => !empty($user['password']),
            // 是否已设置交易密码（供 C 端账户安全页展示「操作密码 已设置/未设置」）
            'hasTransactionPassword' => !empty($user['transaction_password']),
            'wallet' => [
                'balance'   => (float) ($wallet['balance'] ?? 0),
                'available' => (float) ($wallet['available'] ?? 0),
                'frozen'    => (float) ($wallet['frozen'] ?? 0),
                'points'    => (float) ($wallet['points'] ?? 0),
            ],
        ]);
    }

    /**
     * PUT /api/user/profile
     * 修改资料（昵称/头像）
     */
    public function updateProfile()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $nickname = $this->request->put('nickname');
        $avatar   = $this->request->put('avatar');

        $update = [];
        if ($nickname !== null) {
            // SEC-X1 修复（安全专项 5.1）：昵称剥离 HTML 标签，防止存储型 XSS 原样入库回显
            $nickname = strip_tags(trim((string) $nickname));
            if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 20) {
                return $this->fail(1001, '昵称长度需在 2-20 字之间');
            }
            $update['username'] = $nickname;
        }
        if ($avatar !== null) {
            $update['avatar'] = $avatar;
        }

        if ($update) {
            $update['updated_at'] = date('Y-m-d H:i:s.v');
            Db::name('users')->where('id', $userId)->update($update);
        }

        return $this->success([
            'nickname' => $update['username'] ?? Db::name('users')->where('id', $userId)->value('username'),
            'avatar'   => $update['avatar']   ?? Db::name('users')->where('id', $userId)->value('avatar'),
        ]);
    }

    /**
     * POST /api/user/realname
     * 实名认证
     */
    public function realname()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $realName = $this->request->post('realName', '');
        $idCard   = $this->request->post('idCard', '');

        if (strlen($realName) < 2) return $this->fail(1001, '真实姓名不能为空');
        if (!preg_match('/^[1-9]\d{5}(19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[\dXx]$/', $idCard)) {
            return $this->fail(1001, '身份证号格式错误');
        }

        $user = Db::name('users')->where('id', $userId)->find();
        if ((int) $user['is_realname'] === 1 && (int) $user['realname_status'] === 2) {
            return $this->fail(1001, '已完成实名认证');
        }
        if ((int) ($user['realname_status'] ?? 0) === 1) {
            return $this->fail(1001, '实名认证审核中，请耐心等待');
        }

        // 提交后进入待审核（管理员后台审核通过后 is_realname 置 1）
        Db::name('users')->where('id', $userId)->update([
            'real_name'              => aes_encrypt($realName),
            'id_card'                => aes_encrypt($idCard),
            'realname_status'        => 1,
            'realname_submitted_at'  => date('Y-m-d H:i:s'),
            'realname_reject_reason' => null,
            'updated_at'             => date('Y-m-d H:i:s.v'),
        ]);

        return $this->success(['status' => 'pending'], '已提交实名认证，等待审核');
    }

    /**
     * POST /api/user/password/trade
     * 设置/修改交易密码
     */
    public function setTradePassword()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $oldPassword = $this->request->post('oldPassword');
        $newPassword = $this->request->post('newPassword', '');

        if (strlen($newPassword) < 6 || strlen($newPassword) > 20) {
            return $this->fail(1001, '新密码长度需在 6-20 位之间');
        }

        $user = Db::name('users')->where('id', $userId)->find();

        // 修改模式：旧密码必须正确
        if (!empty($user['transaction_password'])) {
            if (!$oldPassword) {
                return $this->fail(1001, '请输入原交易密码');
            }
            if (!verify_password($oldPassword, $user['transaction_password'])) {
                return $this->fail(2003, '原交易密码错误');
            }
        }

        Db::name('users')->where('id', $userId)->update([
            'transaction_password' => hash_password($newPassword),
            'updated_at'            => date('Y-m-d H:i:s.v'),
        ]);

        return $this->success();
    }

    /**
     * POST /api/user/verify-trade-password
     * 校验交易密码（供前端预校验）
     */
    public function verifyTradePassword()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $password = $this->request->post('password', '');
        $hash = Db::name('users')->where('id', $userId)->value('transaction_password');

        if (!$hash) return $this->fail(2003, '未设置交易密码');
        if (!verify_password($password, $hash)) return $this->fail(2003, '交易密码错误');

        return $this->success();
    }

    /**
     * POST /api/user/send-code
     * 已登录用户发送验证码（发送至本人手机号，场景：resert_password 改密/cancel 注销）
     */
    public function sendCode()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) return $this->fail(1002, '用户不存在');

        $scene = $this->request->post('scene', 'reset_password');
        if (!in_array($scene, ['reset_password', 'cancel'], true)) {
            return $this->fail(1001, '场景参数错误');
        }
        $phone = $user['phone'];

        // 60 秒内同手机号+场景禁止重发
        $recent = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', $scene)
            ->where('sent_at', '>', date('Y-m-d H:i:s.v', time() - 60))
            ->find();
        if ($recent) {
            return $this->fail(1001, '验证码发送过于频繁，请稍后再试');
        }

        // Mock：生成 6 位明文验证码，不真发短信（与 Auth::sendCode 同策略）
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

        return $this->success(['debugCode' => env('APP_DEBUG') ? $code : null]);
    }

    /**
     * POST /api/user/password/reset
     * 已登录用户重置登录密码（SMS 验证码）——账户安全页「登录密码」入口
     */
    public function resetPassword()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $code        = $this->request->post('code', '');
        $newPassword = $this->request->post('newPassword', '');

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) return $this->fail(1002, '用户不存在');

        if (strlen($code) !== 6) {
            return $this->fail(1001, '验证码格式错误');
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 20) {
            return $this->fail(1001, '新密码长度需在 6-20 位之间');
        }

        $phone = $user['phone'];
        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'reset_password')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time() || !verify_password($code, $vc['code'])) {
            return $this->fail(1001, '验证码错误或已过期');
        }

        Db::startTrans();
        Db::name('users')->where('id', $userId)->update([
            'password'   => hash_password($newPassword),
            'updated_at' => date('Y-m-d H:i:s.v'),
        ]);
        Db::name('verification_codes')->where('id', $vc['id'])->update(['used_at' => date('Y-m-d H:i:s.v')]);
        Db::commit();

        return $this->success();
    }

    /**
     * POST /api/user/password/trade/reset
     * 已登录用户重置交易密码（SMS 验证码）——账户安全页「操作密码」入口
     */
    public function resetTradePassword()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $code        = $this->request->post('code', '');
        $newPassword = $this->request->post('newPassword', '');

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) return $this->fail(1002, '用户不存在');

        if (strlen($code) !== 6) {
            return $this->fail(1001, '验证码格式错误');
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 20) {
            return $this->fail(1001, '新密码长度需在 6-20 位之间');
        }

        $phone = $user['phone'];
        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'reset_password')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time() || !verify_password($code, $vc['code'])) {
            return $this->fail(1001, '验证码错误或已过期');
        }

        Db::startTrans();
        Db::name('users')->where('id', $userId)->update([
            'transaction_password' => hash_password($newPassword),
            'updated_at'           => date('Y-m-d H:i:s.v'),
        ]);
        Db::name('verification_codes')->where('id', $vc['id'])->update(['used_at' => date('Y-m-d H:i:s.v')]);
        Db::commit();

        return $this->success();
    }

    /**
     * POST /api/user/cancel
     * 注销账号（软删除：deleted_at 置当前时间，JWT 中间件随即拒绝访问）
     */
    public function cancelAccount()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $code     = $this->request->post('code', '');
        $realName = trim((string) $this->request->post('realName', ''));
        $idCard   = trim((string) $this->request->post('idCard', ''));

        $user = Db::name('users')->where('id', $userId)->whereNull('deleted_at')->find();
        if (!$user) return $this->fail(1002, '用户不存在');

        if (strlen($code) !== 6) {
            return $this->fail(1001, '验证码格式错误');
        }

        $phone = $user['phone'];
        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'cancel')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time() || !verify_password($code, $vc['code'])) {
            return $this->fail(1001, '验证码错误或已过期');
        }

        // 实名信息校验（已实名时比对，防止盗销；未实名则跳过）
        $storedName = $user['real_name'] ? (aes_decrypt((string) $user['real_name']) ?? '') : '';
        $storedId   = $user['id_card'] ? (aes_decrypt((string) $user['id_card']) ?? '') : '';
        if ($storedName !== '' && $storedName !== $realName) {
            return $this->fail(1001, '真实姓名与实名信息不一致');
        }
        if ($storedId !== '' && $storedId !== $idCard) {
            return $this->fail(1001, '身份证号与实名信息不一致');
        }

        Db::startTrans();
        Db::name('users')->where('id', $userId)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s.v'),
        ]);
        Db::name('verification_codes')->where('id', $vc['id'])->update(['used_at' => date('Y-m-d H:i:s.v')]);
        Db::commit();

        return $this->success();
    }
}
