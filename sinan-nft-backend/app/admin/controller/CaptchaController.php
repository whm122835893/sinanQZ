<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\AdminAuthService;
use app\service\CaptchaService;

/**
 * 管理后台图形验证码（公开接口，无需登录）
 *
 * GET  /captcha/image        生成并返回 data URI 图片 + captcha_id
 * POST /auth/login           已在 AuthController 中接入图形码校验（captcha_id + captcha_code）
 * GET  /captcha/enabled      探测后端是否开启图形码
 */
class CaptchaController extends BaseController
{
    public function image()
    {
        $scene = trim((string) $this->request->param('scene', 'admin_login'));
        if (!isset(CaptchaService::SCENES[$scene])) {
            return $this->fail(4004, '未知验证码场景：' . $scene);
        }
        if (!CaptchaService::isEnabled($scene)) {
            return $this->fail(4004, '该场景图形验证码已关闭');
        }
        $result = (new CaptchaService())->build();
        return $this->success($result);
    }

    public function enabled()
    {
        $scene = trim((string) $this->request->param('scene', 'admin_login'));
        if (!isset(CaptchaService::SCENES[$scene])) {
            return $this->fail(4004, '未知验证码场景：' . $scene);
        }

        $provider = CaptchaService::provider();
        $aliyun = null;
        if ($provider === CaptchaService::PROVIDER_ALIYUN) {
            $cfg = CaptchaService::aliyunConfig();
            // scene_id/prefix 是前端 SDK 初始化所需的无敏感信息，直接下发
            $aliyun = [
                'sceneId' => $cfg['scene_id'],
                'prefix'  => $cfg['prefix'],
                'region'  => 'cn',
            ];
        }

        return $this->success([
            'enabled'  => CaptchaService::isEnabled($scene),
            'scene'    => $scene,
            'provider' => $provider,
            'aliyun'   => $aliyun,
        ]);
    }
}
