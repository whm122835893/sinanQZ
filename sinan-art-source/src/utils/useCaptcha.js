import { ref } from 'vue'
import request from '@/utils/request'

/**
 * 图形验证码复用 composable（C 端，支持场景级开关）
 *
 * 场景表（与后端 CaptchaService::SCENES 约定一致）：
 *   auth_login_password  密码登录
 *   auth_login_sms       登录短信验证码发送
 *   auth_register        注册短信验证码发送
 *   auth_forgot          忘记密码短信验证码发送
 *   user_change_pwd      已登录修改登录密码
 *   user_op_pwd          设置/修改支付密码
 *   user_cancel          注销账户
 *   admin_login          管理后台登录（admin 端使用）
 *
 * 使用：
 *   const captcha = useCaptcha('auth_register')
 *   onMounted(() => captcha.refresh())
 *   // 提交时：captcha.inject() 自动拼 captcha_id/captcha_code
 */
export function useCaptcha(scene = '') {
  const enabled = ref(false)
  const image = ref('')
  const captchaId = ref('')
  const code = ref('')
  // 当前场景（切换登录模式时可通过 setScene 更新）
  let currentScene = scene

  async function refresh() {
    try {
      // 先探测该场景是否开启
      const config = currentScene ? { params: { scene: currentScene } } : {}
      const enabledRes = await request.get('/captcha/enabled', config)
      enabled.value = !!enabledRes?.enabled
      if (!enabled.value) {
        image.value = ''
        captchaId.value = ''
        return
      }
      // 再拉图片（同样带场景，后端校验该场景确实开启）
      const res = await request.get('/captcha/image', config)
      if (res?.captcha_id && res?.image) {
        captchaId.value = res.captcha_id
        image.value = res.image
        code.value = ''
      }
    } catch (e) {
      // 后端 4004 表示该场景关闭，其他错误降级为关闭状态
      if (e.message?.includes('已关闭') || e.message?.includes('场景')) {
        enabled.value = false
        image.value = ''
        captchaId.value = ''
      } else {
        console.warn('[captcha] refresh failed:', e.message)
      }
    }
  }

  /**
   * 切换场景（如登录页切换 密码/验证码 模式），并重新探测开关
   */
  async function setScene(nextScene) {
    currentScene = nextScene
    await refresh()
  }

  /**
   * 提交时把图形码参数拼进 payload（该场景关闭时返回原 payload）
   */
  function inject(payload = {}) {
    if (!enabled.value) return payload
    return { ...payload, captcha_id: captchaId.value, captcha_code: code.value }
  }

  return {
    enabled,
    image,
    captchaId,
    code,
    refresh,
    setScene,
    inject
  }
}
