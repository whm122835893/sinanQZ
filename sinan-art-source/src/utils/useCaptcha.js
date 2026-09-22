import { ref, shallowRef } from 'vue'
import request from '@/utils/request'

/**
 * C 端验证码 composable（与 CaptchaDialog.vue 配合使用）
 *
 * 设计理念：业务页面只需要在模板里放一次 <CaptchaDialog>，
 * 提交前调 `await captcha.require(scene)` —— 该方法自动：
 *   1. 探测 /api/captcha/enabled 判断该场景是否需要验证
 *   2. 若需要 → 调用 dialog.open(scene) 返回 Promise(用户完成验证)
 *   3. 若不需要 → 直接 resolve(null)，业务跳过验证码字段
 *
 * 使用示例：
 *   <template>
 *     ...业务表单...
 *     <CaptchaDialog ref="captchaRef" />
 *   </template>
 *
 *   <script setup>
 *   import CaptchaDialog from '@/components/CaptchaDialog.vue'
 *   const captchaRef = ref(null)
 *   const captcha = useCaptcha(captchaRef)
 *
 *   async function onSubmit() {
 *     const payload = await captcha.require('auth_login_sms')
 *     // payload = null  → 该场景未启用验证码，跳过
 *     // payload = { captcha_id, captcha_code }   → local 图形码
 *     // payload = { captcha_verify_param }       → aliyun
 *     await api.sendCode({ ...form, ...(payload || {}) })
 *   }
 *   </script>
 */

/**
 * 缓存各场景的探测结果（同页面多个业务动作共用探测）
 * key: scene
 * value: Promise<detected>
 */
const detectedCache = {}

function detectScene(scene) {
  if (!scene) return Promise.resolve(null)
  if (detectedCache[scene]) return detectedCache[scene]
  // 注意：request 实例 baseURL 已是 /api，这里只写相对路径
  detectedCache[scene] = request.get('/captcha/enabled', { params: { scene } })
    .then((res) => res || { enabled: false, provider: 'local', mode: 'graphic' })
    .catch(() => {
      delete detectedCache[scene] // 探测失败不缓存，下次点击自动重试
      return { enabled: false, provider: 'local', mode: 'graphic' }
    })
  return detectedCache[scene]
}

export function useCaptcha(dialogRef) {
  /**
   * 探测 + 弹窗，返回 Promise。
   * @param {string} scene 场景 key（见 CaptchaService::SCENES）
   * @returns {Promise<object|null>} null=未启用验证码/用户取消；object=payload
   */
  async function require(scene) {
    if (!scene) return null

    const detected = await detectScene(scene)
    if (!detected?.enabled) return null

    // 需要验证 → 打开弹窗
    if (!dialogRef?.value || typeof dialogRef.value.open !== 'function') {
      console.warn('[useCaptcha] CaptchaDialog ref 未就绪,请确保模板中已挂载')
      return null
    }
    try {
      const payload = await dialogRef.value.open({ scene, preDetected: detected })
      return payload
    } catch (e) {
      // 用户取消或错误
      return null
    }
  }

  /** 强制刷新某个场景的探测缓存（切后台或网络恢复时调用） */
  function invalidate(scene) {
    if (scene) delete detectedCache[scene]
    else Object.keys(detectedCache).forEach((k) => delete detectedCache[k])
  }

  return { require, invalidate }
}

export default useCaptcha
