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
}
