<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use app\service\RealnameService;
use app\service\CaptchaService;
use app\service\SmsDispatchService;
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
            // 修改昵称不允许为空；敏感词检测 + 打码
            if ($nickname === '') {
                return $this->fail(1001, '昵称不能为空');
            }
            $filter = filter_nickname($nickname);
            if (!$filter['ok']) {
                return $this->fail(1001, $filter['reason']);
            }
            $update['username'] = $filter['nickname'];
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

        // 审核模式开关（后台「全局参数」realname_audit_mode，实时生效）：
        // manual=人工审核（进入待审核队列） auto=自动通过（与管理端审核通过同口径）
        $auditMode = (string) (Db::name('system_configs')->where('config_key', 'realname_audit_mode')->value('config_value') ?: 'manual');

        if ($auditMode === 'auto') {
            // 提交即通过：提交数据合并进同一条 update，状态/奖励口径由 RealnameService 统一
            RealnameService::approve($userId, [
                'real_name'             => aes_encrypt($realName),
                'id_card'               => aes_encrypt($idCard),
                'realname_submitted_at' => date('Y-m-d H:i:s'),
            ]);
            return $this->success(['status' => 'approved'], '实名认证已自动通过');
        }

        // 人工审核：进入待审核队列
        Db::name('users')->where('id', $userId)->update([
            'real_name'              => aes_encrypt($realName),
            'id_card'                => aes_encrypt($idCard),
            'realname_status'        => 1,
            'is_realname'            => 0,
            'realname_submitted_at'  => date('Y-m-d H:i:s'),
            'realname_reject_reason' => '',
            'updated_at'             => date('Y-m-d H:i:s.v'),
        ]);

        return $this->success(['status' => 'pending'], '已提交实名认证，等待审核');
    }

    /**
     * POST /api/user/verify-trade-password
     * 校验交易密码（供前端预校验）
     */
    public function verifyTradePassword()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        // M3 修复：交易密码失败次数限制，5 次失败锁定 15 分钟（防在线爆破）
        $lockKey   = 'trade_pwd_lock:' . $userId;
        $failKey   = 'trade_pwd_fail:' . $userId;
        $locked    = cache($lockKey);
        if ($locked) {
            return $this->fail(2003, '交易密码错误次数过多，请 15 分钟后再试');
        }

        $password = $this->request->post('password', '');
        $hash = Db::name('users')->where('id', $userId)->value('transaction_password');

        if (!$hash) return $this->fail(2003, '未设置交易密码');
        if (!verify_password($password, $hash)) {
            $fails = (int) cache($failKey) + 1;
            cache($failKey, $fails, 900);
            if ($fails >= 5) {
                cache($lockKey, 1, 900);
                cache($failKey, null);
                return $this->fail(2003, '交易密码错误次数过多，已锁定 15 分钟');
            }
            return $this->fail(2003, '交易密码错误（还可尝试 ' . (5 - $fails) . ' 次）');
        }

        cache($failKey, null);
        return $this->success();
    }

    /**
     * POST /api/user/send-code
     * 已登录用户发送验证码（发送至本人手机号，场景：reset_password 改密/cancel 注销）
     *
     * 图形码场景：通过 captcha_scene 参数细分（user_change_pwd 改密 / user_op_pwd 支付密码 / user_cancel 注销），
     * 与后台「安全策略」场景级开关联动；未传时按短信 scene 兜底映射。
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

        // 图形码场景：前端显式传 captcha_scene（user_op_pwd/user_change_pwd/user_cancel），
        // 未传时按短信 scene 兜底（reset_password→user_change_pwd，cancel→user_cancel）
        $captchaScene = trim((string) $this->request->post('captcha_scene', ''));
        if ($captchaScene === '') {
            $captchaScene = $scene === 'cancel' ? 'user_cancel' : 'user_change_pwd';
        }
        if (!in_array($captchaScene, ['user_change_pwd', 'user_op_pwd', 'user_cancel'], true)) {
            return $this->fail(1001, '图形码场景参数错误');
        }

        // 图形码前置（按场景，开关关闭直接通过；local/aliyun 服务商分流）
        if (CaptchaService::isEnabled($captchaScene)) {
            $aliyun = CaptchaService::provider() === CaptchaService::PROVIDER_ALIYUN;
            $missing = $aliyun
                ? trim((string) $this->request->post('captcha_verify_param', '')) === ''
                : (trim((string) $this->request->post('captcha_id', '')) === ''
                    || trim((string) $this->request->post('captcha_code', '')) === '');
            if ($missing) {
                return $this->fail(1001, $aliyun ? '请先完成滑块验证' : '请先完成图形验证码');
            }
            if (!CaptchaService::verifyRequest($this->request)) {
                return $this->fail(1001, $aliyun ? '滑块验证未通过，请重试' : '图形验证码错误或已过期，请刷新重试');
            }
        }

        // 统一派发：60s 频控 + 每日限 SmsDispatchService::DAILY_LIMIT 条 + 发送落库
        $r = SmsDispatchService::dispatch($phone, $scene, $this->request->ip());
        if (!$r['ok']) {
            return $this->fail($r['code'], $r['message']);
        }

        return $this->success(['debugCode' => $r['debugCode']]);
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
        // M3 修复：短信验证码失败次数限制
        if ($this->codeFailLimited($phone, 'reset_password')) {
            return $this->fail(1003, '验证码错误次数过多，请 15 分钟后重试');
        }
        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'reset_password')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time() || !verify_password($code, $vc['code'])) {
            $this->codeFailIncr($phone, 'reset_password');
            return $this->fail(1001, '验证码错误或已过期');
        }
        $this->codeFailClear($phone, 'reset_password');

        $now = date('Y-m-d H:i:s.v');
        Db::startTrans();
        Db::name('users')->where('id', $userId)->update([
            'password'     => hash_password($newPassword),
            'logout_before'=> $now,  // 改密后使所有旧 token 失效（iat <= logout_before 拒绝）
            'updated_at'   => $now,
        ]);
        Db::name('verification_codes')->where('id', $vc['id'])->update(['used_at' => $now]);
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
        // M3 修复：短信验证码失败次数限制（重置交易密码复用 reset_password 场景）
        if ($this->codeFailLimited($phone, 'reset_password')) {
            return $this->fail(1003, '验证码错误次数过多，请 15 分钟后重试');
        }
        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'reset_password')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time() || !verify_password($code, $vc['code'])) {
            $this->codeFailIncr($phone, 'reset_password');
            return $this->fail(1001, '验证码错误或已过期');
        }
        $this->codeFailClear($phone, 'reset_password');

        $now = date('Y-m-d H:i:s.v');
        Db::startTrans();
        Db::name('users')->where('id', $userId)->update([
            'transaction_password' => hash_password($newPassword),
            'logout_before'        => $now,  // 重置交易密码后使所有旧 token 失效
            'updated_at'           => $now,
        ]);
        Db::name('verification_codes')->where('id', $vc['id'])->update(['used_at' => $now]);
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
