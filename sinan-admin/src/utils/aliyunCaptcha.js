/**
 * 阿里云验证码 2.0 SDK 加载与唤起（管理端登录页用）
 *
 * 官方要求：动态引入 JS 且禁止重复引入，故做模块级单例；
 * AliyunCaptchaConfig（region/prefix）必须在脚本加载前设置。
 *
 * 用法：
 *   import { ensureAliyunCaptcha, verifyAliyun } from '@/utils/aliyunCaptcha'
 *   // 页面加载时预初始化（官方建议与验证请求间隔 >2s）
 *   ensureAliyunCaptcha({ sceneId, prefix, region }).catch(() => {})
 *   // 提交前唤起滑块并等待结果
 *   const ok = await verifyAliyun()   // true=通过 false=用户关闭/初始化失败
 */

const ALIYUN_SDK_URL = 'https://o.alicdn.com/captcha-frontend/aliyunCaptcha/AliyunCaptcha.js'
const ELEMENT_ID = 'aliyun-captcha-element'
const BUTTON_ID = 'aliyun-captcha-button'

let sdkPromise = null
let captchaInitPromise = null
let captchaInstance = null
// 当前等待验证结果的回调（弹窗全局唯一，同一时刻只有一个等待方）
let pendingSuccess = null
let pendingDismiss = null
// 进行中的验证（防重复唤起）
let inflightVerify = null

function loadAliyunSdk(cfg) {
  if (typeof window.initAliyunCaptcha === 'function') return Promise.resolve()
  if (sdkPromise) return sdkPromise
  sdkPromise = new Promise((resolve, reject) => {
    // 必须在脚本加载前注入全局身份配置（region/prefix）
    window.AliyunCaptchaConfig = {
      region: cfg?.region || 'cn',
      prefix: cfg?.prefix || ''
    }
    const s = document.createElement('script')
    s.src = ALIYUN_SDK_URL
    s.onload = () => resolve()
    s.onerror = () => {
      sdkPromise = null
      reject(new Error('验证码组件加载失败'))
    }
    document.head.appendChild(s)
  })
  return sdkPromise
}

/**
 * 加载 SDK 并初始化验证码实例（幂等）。
 * popup 模式：隐藏的全局触发按钮点击后唤起弹窗（无痕模式则触发无痕验证）。
 * @param {{sceneId:string, prefix:string, region?:string}} cfg
 */
export function ensureAliyunCaptcha(cfg) {
  if (!cfg?.sceneId || !cfg?.prefix) {
    return Promise.reject(new Error('阿里云验证码配置不完整'))
  }
  if (captchaInitPromise) return captchaInitPromise
  captchaInitPromise = loadAliyunSdk(cfg)
    .then(() => {
      // 隐藏的渲染容器与触发按钮（挂 body 全局）
      if (!document.getElementById(ELEMENT_ID)) {
        const el = document.createElement('div')
        el.id = ELEMENT_ID
        el.style.display = 'none'
        document.body.appendChild(el)
      }
      if (!document.getElementById(BUTTON_ID)) {
        const btn = document.createElement('div')
        btn.id = BUTTON_ID
        btn.style.display = 'none'
        document.body.appendChild(btn)
      }
      window.initAliyunCaptcha({
        SceneId: cfg.sceneId,
        mode: 'popup',
        element: '#' + ELEMENT_ID,
        button: '#' + BUTTON_ID,
        // 验证通过：透传 captchaVerifyParam 给当前等待方
        success: (captchaVerifyParam) => {
          const cb = pendingSuccess
          pendingSuccess = null
          pendingDismiss = null
          cb && cb(captchaVerifyParam)
        },
        // 验证不通过：官方无需处理，弹窗自动刷新供用户重试
        fail: (result) => {
          console.warn('[captcha] aliyun fail:', result)
        },
        error: (err) => {
          console.error('[captcha] aliyun error:', err)
        },
        // 用户主动关闭弹窗：结束本次等待（verifyComplete 已由 success 处理）
        onClose: (reason) => {
          if (reason === 'userDismiss' && pendingDismiss) {
            const cb = pendingDismiss
            pendingSuccess = null
            pendingDismiss = null
            cb()
          }
        },
        getInstance: (instance) => { captchaInstance = instance },
        slideStyle: { width: 360, height: 40 },
        language: 'cn'
      })
    })
    .catch((e) => {
      captchaInitPromise = null // 失败允许重试
      throw e
    })
  return captchaInitPromise
}

/**
 * 唤起滑块并等待结果（全局去重：验证中重复调用复用同一 Promise）
 * @param {{sceneId:string, prefix:string, region?:string}} cfg 阿里云配置（后端 /captcha/enabled 下发）
 * @returns {Promise<string|null>} 通过返回 captchaVerifyParam，关闭/失败返回 null
 */
export function verifyAliyun(cfg) {
  if (inflightVerify) return inflightVerify
  inflightVerify = (async () => {
    let param = ''
    try {
      await ensureAliyunCaptcha(cfg)
    } catch (e) {
      console.warn('[captcha] aliyun init failed:', e?.message)
      return null
    }
    const done = await new Promise((resolve) => {
      let settled = false
      pendingSuccess = (captchaVerifyParam) => {
        if (settled) return
        settled = true
        param = captchaVerifyParam
        resolve(true)
      }
      pendingDismiss = () => {
        if (settled) return
        settled = true
        resolve(false)
      }
      const btn = document.getElementById(BUTTON_ID)
      if (!btn) {
        settled = true
        resolve(false)
        return
      }
      // 触发隐藏按钮：popup 模式唤起弹窗，无痕模式直接触发无痕验证
      btn.click()
    })
    return done ? param : null
  })().finally(() => { inflightVerify = null })
  return inflightVerify
}
