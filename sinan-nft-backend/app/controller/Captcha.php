<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\CaptchaService;

/**
 * 图形验证码（C 端，公开接口，无需登录）
 *
 * GET  /api/captcha/image?scene=xxx     生成并返回 data URI 图片 + captcha_id（按场景判断是否需要）
 * POST /api/captcha/verify              校验（consume=false，仅前端预校验）
 * GET  /api/captcha/enabled?scene=xxx   探测指定场景是否开启图形码
 *
 * 场景表见 CaptchaService::SCENES（与后台「安全策略」场景级开关联动）
 */
class Captcha extends BaseController
{
    /**
     * GET /api/captcha/image
     */
    public function image()
    {
        $scene = trim((string) $this->request->param('scene', ''));
        if ($scene !== '' && !isset(CaptchaService::SCENES[$scene])) {
            return $this->fail(4004, '未知验证码场景：' . $scene);
        }
        if (!CaptchaService::isEnabled($scene !== '' ? $scene : null)) {
            return $this->fail(4004, '该场景图形验证码已关闭');
        }
        $result = (new CaptchaService())->build();
        return $this->success($result);
    }

    /**
     * POST /api/captcha/verify
     * 业务入口校验会直接调用 CaptchaService::verify()，
     * 此路由仅用于前端预校验（可选），不消费验证码（consume=false）。
     */
    public function verify()
    {
        $id   = (string) $this->request->post('captcha_id', '');
        $code = (string) $this->request->post('captcha_code', '');

        if (!CaptchaService::isEnabled()) {
            return $this->success(['ok' => true, 'skipped' => true]);
        }
        $svc = new CaptchaService();
        $ok  = $svc->verify($id, $code, false);
        return $this->success(['ok' => $ok, 'expired' => !$ok && !$svc->exists($id)]);
    }

    /**
     * GET /api/captcha/enabled?scene=xxx
     * 不传 scene 时只反映总开关；传 scene 时反映「总开关 && 场景开关」。
     *
     * provider 字段告知前端验证方式：
     *   local  → 拉取 /captcha/image 渲染图形码，提交 captcha_id/captcha_code
     *   aliyun → 加载阿里云验证码 JS SDK（SceneId/prefix 见 aliyun 字段），提交 captcha_verify_param
     */
    public function enabled()
    {
        $scene = trim((string) $this->request->param('scene', ''));
        if ($scene !== '' && !isset(CaptchaService::SCENES[$scene])) {
            return $this->fail(4004, '未知验证码场景：' . $scene);
        }

        $provider = CaptchaService::provider();
        $aliyun = null;
        if ($provider === CaptchaService::PROVIDER_ALIYUN) {
            $cfg = CaptchaService::aliyunConfig();
            // scene_id/prefix 本身是前端 SDK 初始化所需的无敏感信息，直接下发
            $aliyun = [
                'sceneId' => $cfg['scene_id'],
                'prefix'  => $cfg['prefix'],
                'region'  => 'cn',
            ];
        }

        return $this->success([
            'enabled'  => CaptchaService::isEnabled($scene !== '' ? $scene : null),
            'scene'    => $scene,
            'provider' => $provider,
            'mode'     => CaptchaService::mode(),
            'aliyun'   => $aliyun,
        ]);
    }
}
