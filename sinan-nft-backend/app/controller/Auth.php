<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use app\service\CaptchaService;
use app\service\DrawCodeService;
use app\service\JwtService;
use app\service\SmsDispatchService;
use think\facade\Db;

/**
 * 认证控制器
 * 短信验证码发送 / 注册 / 登录 / 忘记密码
 */
class Auth extends BaseController
{
    /**
     * SEC-R1 修复（安全专项 9.2）：验证码失败尝试限制
     * 6 位码 5 分钟有效期内可被无限次爆破，此处按 手机号+场景 维度计数，
     * 窗口内累计失败达上限后即使验证码正确也拒绝，需等待窗口过期或重新发送。
     * 实现复用 BaseController::codeFailLimited/codeFailIncr/codeFailClear。
     */

    /**
     * 图形码前置校验（支持场景级开关 + 服务商分流）。开关关闭时直接通过。
     *
     * local ：captcha_id + captcha_code，校验成功后立即消费（防重放）
     * aliyun：captcha_verify_param，调阿里云 VerifyIntelligentCaptcha 二次校验
     *
     * @param string|null $scene 图形码场景 key（null 时只看总开关）
     * @return \think\Response|void 校验失败返回响应，成功继续执行
     */
    private function requireCaptcha(?string $scene = null)
    {
        if (!CaptchaService::isEnabled($scene)) {
            return;
        }
        if (CaptchaService::provider() === CaptchaService::PROVIDER_ALIYUN) {
            $hasParam = trim((string) $this->request->post('captcha_verify_param', '')) !== '';
            if (!$hasParam) {
                return $this->fail(1001, '请先完成滑块验证');
            }
            if (!CaptchaService::verifyRequest($this->request)) {
                return $this->fail(1001, '滑块验证未通过，请重试');
            }
            return;
        }
        $captchaId   = trim((string) $this->request->post('captcha_id', ''));
        $captchaCode = trim((string) $this->request->post('captcha_code', ''));
        if ($captchaId === '' || $captchaCode === '') {
            return $this->fail(1001, '请先完成图形验证码');
        }
        if (!CaptchaService::verifyRequest($this->request)) {
            return $this->fail(1001, '图形验证码错误或已过期，请刷新重试');
        }
    }

    /**
     * 短信场景 → 图形码场景映射（/auth/send-code 三个场景）
     */
    private const SMS_SCENE_TO_CAPTCHA = [
        'register'       => 'auth_register',
        'login'          => 'auth_login_sms',
        'reset_password' => 'auth_forgot',
    ];

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

        // 图形码前置（按场景）：挡机器人刷短信
        $captchaFail = $this->requireCaptcha(self::SMS_SCENE_TO_CAPTCHA[$scene]);
        if ($captchaFail !== null) {
            return $captchaFail;
        }

        // 统一派发：60s 频控 + 每日限 SmsDispatchService::DAILY_LIMIT 条 + 发送落库
        $r = SmsDispatchService::dispatch($phone, $scene, $this->request->ip());
        if (!$r['ok']) {
            return $this->fail($r['code'], $r['message']);
        }

        return $this->success(['debugCode' => $r['debugCode']]);
    }

    /**
     * POST /api/auth/register
     * 注册
     */
    public function register()
    {
        $phone      = $this->request->post('phone', '');
        $code       = $this->request->post('code', '');
        $password   = $this->request->post('password', '');
        // SEC-X1 修复（安全专项 5.1）：昵称剥离 HTML 标签，防止存储型 XSS 原样入库回显
        $rawNickname = strip_tags(trim((string) $this->request->post('nickname', '')));
        $inviteCode = $this->request->post('inviteCode', '');

        if (!preg_match('/^1\d{10}$/', $phone)) {
            return $this->fail(1001, '手机号格式错误');
        }
        if (strlen($code) !== 6) {
            return $this->fail(1001, '验证码格式错误');
        }
        if (strlen($password) < 6 || strlen($password) > 20) {
            return $this->fail(1001, '登录密码长度需在 6-20 位之间');
        }

        // ---- 昵称处理 ----
        // 1) 未填写 → 自动生成："司南-" + 手机号后 4 位
        // 2) 填写了 → 敏感词检测 + 打码；过滤后过短 / 全是 * 则拒绝
        if ($rawNickname === '') {
            $nickname = gen_default_nickname($phone);
        } else {
            $filter = filter_nickname($rawNickname);
            if (!$filter['ok']) {
                return $this->fail(1001, $filter['reason'] === '昵称过短（过滤后不足 2 字）'
                    ? '昵称过短，请重新输入'
                    : $filter['reason']);
            }
            $nickname = $filter['nickname'];
        }
        // 兜底长度（自动生成不会触发，但防御性保留）
        if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 20) {
            $nickname = gen_default_nickname($phone);
        }

        // 校验手机号是否已注册
        $exists = Db::name('users')->where('phone', $phone)->find();
        if ($exists) {
            return $this->fail(1001, '该手机号已注册');
        }

        // SEC-R1：失败次数超限拒绝（防爆破）
        if ($this->codeFailLimited($phone, 'register')) {
            return $this->fail(1003, '验证码错误次数过多，请 15 分钟后重试');
        }

        // 校验验证码
        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'register')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time() || !verify_password($code, $vc['code'])) {
            $this->codeFailIncr($phone, 'register');
            return $this->fail(1001, '验证码错误或已过期');
        }
        $this->codeFailClear($phone, 'register');

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
                'password'    => hash_password($password),
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
                        // 邀请注册奖励：邀请人 +1 抽签码（事务内）
                        DrawCodeService::grant((int) $inviter['id'], DrawCodeService::SOURCE_INVITE);
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
                'has_password' => !empty($user['password']),
            ]),
        ]);
    }

    /**
     * POST /api/auth/login
     * 登录
     */
    public function login()
    {
        $phone    = $this->request->post('phone', '');
        $code     = $this->request->post('code', '');
        $password = $this->request->post('password', '');

        if (!preg_match('/^1\d{10}$/', $phone)) {
            return $this->fail(1001, '手机号格式错误');
        }

        // 密码登录分支（携带 password 时走密码校验，否则短信验证码）
        if ($password !== '') {
            // 密码登录需图形码前置（挡撞库爆破，场景级开关）
            $captchaFail = $this->requireCaptcha('auth_login_password');
            if ($captchaFail !== null) {
                return $captchaFail;
            }
            return $this->loginByPassword($phone, $password);
        }

        // SEC-R1：失败次数超限拒绝（防爆破）
        if ($this->codeFailLimited($phone, 'login')) {
            return $this->fail(1003, '验证码错误次数过多，请 15 分钟后重试');
        }

        $vc = Db::name('verification_codes')
            ->where('phone', $phone)
            ->where('scene', 'login')
            ->where('used_at', null)
            ->order('id', 'desc')
            ->find();
        if (!$vc || strtotime($vc['expires_at']) < time() || !verify_password($code, $vc['code'])) {
            $this->codeFailIncr($phone, 'login');
            return $this->fail(1001, '验证码错误或已过期');
        }
        $this->codeFailClear($phone, 'login');

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
                'has_password' => !empty($user['password']),
            ]),
        ]);
    }

    /**
     * 密码登录内部实现（登录成功后的登录态刷新与返回）
     */
    private function loginByPassword(string $phone, string $password)
    {
        $user = Db::name('users')->where('phone', $phone)->find();
        if (!$user) {
            return $this->fail(1002, '该手机号未注册');
        }
        if ((int) $user['status'] !== 1) {
            return $this->fail(2002, '账户已被禁用');
        }
        if (empty($user['password'])) {
            return $this->fail(2004, '该账号未设置登录密码，请使用验证码登录');
        }
        if (!verify_password($password, $user['password'])) {
            return $this->fail(2003, '登录密码错误');
        }

        Db::name('users')->where('id', $user['id'])->update([
            'last_login_at' => date('Y-m-d H:i:s.v'),
            'login_count'   => $user['login_count'] + 1,
        ]);

        try {
            cache('force_logout_' . $user['id'], null);
        } catch (\Throwable $e) {
            // 缓存异常不阻断登录
        }

        $token = JwtService::encode($user['id'], $phone);
        return $this->success([
            'token'    => $token,
            'userInfo' => camelize_keys([
                'uid'         => $user['uid'],
                'username'    => $user['username'],
                'phone'       => mask_phone($user['phone']),
                'avatar'      => $user['avatar'],
                'is_realname' => (bool) $user['is_realname'],
                'invite_code' => $user['invite_code'],
                'has_password' => !empty($user['password']),
            ]),
        ]);
    }

    /**
     * POST /api/auth/reset-password
     * 忘记登录密码（短信验证码重置登录密码）
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

        // SEC-R1：失败次数超限拒绝（防爆破，重置交易密码场景同样敏感）
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

        $user = Db::name('users')->where('phone', $phone)->find();
        if (!$user) {
            return $this->fail(1002, '该手机号未注册');
        }

        Db::startTrans();
        Db::name('users')->where('id', $user['id'])->update([
            'password'   => hash_password($newPassword),
            'updated_at' => date('Y-m-d H:i:s.v'),
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
