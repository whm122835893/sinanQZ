<template>
  <van-popup
    v-model:show="visible"
    position="center"
    :style="{ padding: 0, borderRadius: '16px', overflow: 'hidden' }"
    :closeable="true"
    close-icon="cross"
    close-icon-position="top-right"
    @closed="handleClosed"
  >
    <div class="captcha-dialog">
      <!-- local graphic: 图片验证码 -->
      <template v-if="provider === 'local'">
        <div class="captcha-title">安全验证</div>
        <div class="captcha-tip">请输入下方图片中的字符</div>
        <div class="captcha-img-wrap" @click="refreshLocal" :title="'点击刷新'">
          <img v-if="localImg" :src="localImg" alt="验证码" class="captcha-img" />
          <div v-else class="captcha-img captcha-img--placeholder">加载中...</div>
          <div class="captcha-refresh" v-if="localImg">↻</div>
        </div>
        <div class="captcha-input-row">
          <input
            v-model="localCode"
            class="captcha-input"
            placeholder="请输入验证码"
            maxlength="6"
            autocomplete="off"
            @keyup.enter="submitLocal"
          />
          <button class="captcha-submit" @click="submitLocal" :disabled="localCode.length < 3">确定</button>
        </div>
        <div class="captcha-footer">
          <span class="captcha-err" v-if="localError">{{ localError }}</span>
          <span class="captcha-countdown" v-if="countdown > 0">{{ countdown }}秒后重试</span>
        </div>
      </template>

      <!-- aliyun: SDK 自己弹窗,我们的 popup 只做 loading 等待 -->
      <template v-else>
        <div class="captcha-title">安全验证</div>
        <div class="captcha-tip">
          {{ mode === 'slider' ? '请完成滑块验证' : '请完成图形验证' }}
        </div>
        <div class="captcha-aliyun-loading">
          <div class="captcha-spinner"></div>
          <div class="captcha-aliyun-hint" v-if="aliyunState === 'loading'">
            正在加载验证码组件...
          </div>
          <div class="captcha-aliyun-hint" v-else-if="aliyunState === 'ready'">
            验证码组件已就绪,请在弹出的窗口中完成验证
          </div>
          <div class="captcha-aliyun-hint" v-else-if="aliyunState === 'error'">
            <span class="captcha-err">{{ aliyunError }}</span>
            <button class="captcha-retry" @click="retryAliyun">重试</button>
          </div>
        </div>
      </template>
    </div>
  </van-popup>
</template>

<script setup>
import { ref, shallowRef, watch, onBeforeUnmount } from 'vue'
import { showToast } from 'vant'
import request from '@/utils/request'

// ============================================================
// Props / Emits
// ============================================================
const props = defineProps({
  /**
   * 业务页可预探测 /captcha/enabled 后传过来,弹窗内就不用再探测。
   * null:弹窗内部自己探测。
   */
  preDetected: {
    type: Object,
    default: null // { enabled, provider, mode, aliyun: { sceneId, prefix, region } }
  }
})
const emit = defineEmits(['success', 'cancel'])

// ============================================================
// 内部状态
// ============================================================
const visible = ref(false)
const scene = ref('')
const provider = ref('local')
const mode = ref('graphic')
const aliyunMeta = ref(null)

// local graphic
const localImg = ref('')
const localId = ref('')
const localCode = ref('')
const localError = ref('')
const countdown = ref(0)
let countdownTimer = null

// aliyun SDK
const aliyunState = ref('idle') // idle | loading | ready | error
const aliyunError = ref('')
let pendingResolver = null
let pendingRejecter = null
let aliyunCaptchaInstance = null

const ALIYUN_SDK_URL = 'https://o.alicdn.com/captcha-frontend/aliyunCaptcha/AliyunCaptcha.js'
let sdkLoadedPromise = null

// ============================================================
// 对外 API
// ============================================================
/**
 * 打开验证码弹窗,返回 Promise:
 *   resolve({ captcha_id, captcha_code })       // local 模式
 *   resolve({ captcha_verify_param })           // aliyun 模式
 *   reject('cancel' | Error)
 */
async function open(opts) {
  scene.value = opts?.scene || ''
  aliyunState.value = 'idle'
  aliyunError.value = ''
  localError.value = ''
  localCode.value = ''
  countdown.value = 0
  clearInterval(countdownTimer)

  // 探测（request 实例 baseURL 已是 /api）
  let detected = props.preDetected
  if (!detected) {
    try {
      const res = await request.get('/captcha/enabled', { params: { scene: scene.value } })
      detected = res || { enabled: false, provider: 'local', mode: 'graphic' }
    } catch (e) {
      detected = { enabled: false, provider: 'local', mode: 'graphic' }
    }
  }

  provider.value = detected.provider === 'aliyun' ? 'aliyun' : 'local'
  mode.value = detected.mode === 'slider' || detected.mode === 'graphic' ? detected.mode : 'graphic'
  aliyunMeta.value = detected.aliyun || null

  return new Promise((resolve, reject) => {
    pendingResolver = resolve
    pendingRejecter = reject
    visible.value = true
  })
}

// ============================================================
// Popup 关闭时清理
// ============================================================
function handleClosed() {
  if (pendingRejecter) {
    pendingRejecter('cancel')
    pendingResolver = null
    pendingRejecter = null
  }
  clearInterval(countdownTimer)
  if (provider.value === 'aliyun' && aliyunCaptchaInstance) {
    try { aliyunCaptchaInstance.close() } catch (e) {}
  }
}

// ============================================================
// local graphic
// ============================================================
async function refreshLocal() {
  localError.value = ''
  localCode.value = ''
  try {
    const res = await request.get('/captcha/image', { params: { scene: scene.value } })
    const data = res || {}
    if (data?.captcha_id && data?.image) {
      localId.value = data.captcha_id
      localImg.value = data.image
    } else {
      showToast('验证码加载失败')
    }
  } catch (e) {
    showToast('网络异常,请重试')
  }
}

async function submitLocal() {
  if (!localId.value || !localCode.value) return
  try {
    // 先用服务端预校验(不消费);校验失败给用户提示
    const res = await request.post('/captcha/verify', {
      captcha_id: localId.value,
      captcha_code: localCode.value
    })
    const ok = res?.ok
    if (!ok) {
      localError.value = '验证码错误,请重试'
      await refreshLocal()
      // 限流:3 秒防快速重试
      countdown.value = 3
      countdownTimer = setInterval(() => {
        countdown.value--
        if (countdown.value <= 0) clearInterval(countdownTimer)
      }, 1000)
      return
    }
    // 成功:业务请求时提交 captcha_id + captcha_code
    resolveSuccess({
      captcha_id: localId.value,
      captcha_code: localCode.value
    })
  } catch (e) {
    localError.value = '网络异常,请重试'
  }
}

// ============================================================
// aliyun: 动态加载 SDK 并唤起 popup
// ============================================================
function loadAliyunSdk() {
  if (typeof window.initAliyunCaptcha === 'function') return Promise.resolve()
  if (sdkLoadedPromise) return sdkLoadedPromise
  sdkLoadedPromise = new Promise((resolve, reject) => {
    const cfg = aliyunMeta.value
    window.AliyunCaptchaConfig = {
      region: cfg?.region || 'cn',
      prefix: cfg?.prefix || ''
    }
    const s = document.createElement('script')
    s.src = ALIYUN_SDK_URL
    s.async = true
    s.onload = () => resolve()
    s.onerror = () => {
      sdkLoadedPromise = null
      reject(new Error('验证码组件加载失败'))
    }
    document.head.appendChild(s)
  })
  return sdkLoadedPromise
}

async function triggerAliyunVerify() {
  aliyunState.value = 'loading'
  try {
    await loadAliyunSdk()
    const cfg = aliyunMeta.value
    if (!cfg?.sceneId || !cfg?.prefix) {
      throw new Error('阿里云验证码配置不完整')
    }

    // SDK 初始化(幂等,可重复 new)
    aliyunCaptchaInstance = new window.AliyunCaptcha({
      SceneId: cfg.sceneId,
      Prefix: cfg.prefix,
      Mode: 'popup',           // 弹窗模式:SDK 自己会弹 popup
      mobile: false,
      slideMode: mode.value === 'slider' ? 'slide' : 'bind', // slider=拖动滑块 bind=图形点选
      success: (data) => {
        // data.CaptchaVerifyParam 是核心
        resolveSuccess({
          captcha_verify_param: data?.CaptchaVerifyParam || ''
        })
      },
      fail: (err) => {
        aliyunState.value = 'error'
        aliyunError.value = '验证失败: ' + (err?.msg || '请重试')
      },
      error: () => {
        aliyunState.value = 'error'
        aliyunError.value = '验证码组件异常,请重试'
      }
    })
    aliyunState.value = 'ready'
    // 让 SDK 弹窗显示
    try { aliyunCaptchaInstance.show() } catch (e) {}
  } catch (e) {
    aliyunState.value = 'error'
    aliyunError.value = e?.message || '验证码加载失败'
  }
}

function retryAliyun() {
  triggerAliyunVerify()
}

// ============================================================
// 内部工具
// ============================================================
function resolveSuccess(payload) {
  emit('success', payload)
  if (pendingResolver) {
    pendingResolver(payload)
    pendingResolver = null
    pendingRejecter = null
  }
  visible.value = false
}

function close() {
  visible.value = false
}
defineExpose({ open, close })

// ============================================================
// watch: visible 变化时执行渲染逻辑
// ============================================================
watch(visible, (v) => {
  if (!v) return
  if (provider.value === 'local') {
    refreshLocal()
  } else {
    triggerAliyunVerify()
  }
})

onBeforeUnmount(() => {
  clearInterval(countdownTimer)
  if (aliyunCaptchaInstance) {
    try { aliyunCaptchaInstance.close() } catch (e) {}
  }
})
</script>

<style scoped>
.captcha-dialog {
  width: 300px;
  padding: 24px 20px 20px;
  box-sizing: border-box;
  background: #fff;
}
.captcha-title {
  font-size: 17px;
  font-weight: 600;
  color: #222;
  text-align: center;
  margin-bottom: 8px;
}
.captcha-tip {
  font-size: 13px;
  color: #888;
  text-align: center;
  margin-bottom: 16px;
}
.captcha-img-wrap {
  position: relative;
  width: 100%;
  height: 72px;
  margin-bottom: 16px;
  cursor: pointer;
  border-radius: 8px;
  overflow: hidden;
  background: #f5f7fa;
}
.captcha-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.captcha-img--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: #aaa;
}
.captcha-refresh {
  position: absolute;
  right: 10px;
  bottom: 8px;
  width: 34px;
  height: 34px;
  background: rgba(255, 255, 255, 0.92);
  border-radius: 50%;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  color: #333;
  font-weight: 600;
}
.captcha-input-row {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 10px;
}
.captcha-input {
  flex: 1;
  height: 40px;
  padding: 0 12px;
  border: 1px solid #e4e7ed;
  border-radius: 8px;
  font-size: 15px;
  outline: none;
  letter-spacing: 2px;
}
.captcha-input:focus {
  border-color: #6366f1;
}
.captcha-submit {
  height: 40px;
  padding: 0 20px;
  background: #6366f1;
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: 14px;
  cursor: pointer;
}
.captcha-submit:disabled {
  background: #c0c4cc;
  cursor: not-allowed;
}
.captcha-footer {
  min-height: 18px;
  display: flex;
  justify-content: space-between;
  font-size: 12px;
}
.captcha-err {
  color: #f56c6c;
}
.captcha-countdown {
  color: #999;
}
.captcha-aliyun-loading {
  height: 120px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
}
.captcha-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid #e4e7ed;
  border-top-color: #6366f1;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
.captcha-aliyun-hint {
  font-size: 13px;
  color: #888;
  text-align: center;
}
.captcha-retry {
  margin-top: 8px;
  padding: 6px 20px;
  background: #6366f1;
  color: #fff;
  border: none;
  border-radius: 20px;
  font-size: 13px;
}
</style>
