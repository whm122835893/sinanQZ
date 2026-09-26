<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { User, Lock, Key } from '@element-plus/icons-vue'
import { login, getCaptchaEnabled, getCaptchaImage } from '@/api'
import { useAdminStore } from '@/stores/admin'
import { useSiteStore } from '@/stores/site'
import { ensureAliyunCaptcha, verifyAliyun } from '@/utils/aliyunCaptcha'

const router = useRouter()
const route = useRoute()
const adminStore = useAdminStore()
const site = useSiteStore()

const formRef = ref(null)
// C9 修复：生产环境不预填任何账号密码；仅开发模式（DEV）下为方便联调预填
const form = ref({
  username: import.meta.env.DEV ? 'admin' : '',
  password: import.meta.env.DEV ? 'admin123' : '',
})
const submitting = ref(false)
const year = new Date().getFullYear()

// ===== 弹窗式验证码 =====
const captchaEnabled = ref(false)
const captchaProvider = ref('local')
const captchaMode = ref('graphic')
const aliyunCfg = ref(null)

// local graphic 弹窗状态
const localDialogVisible = ref(false)
const localImg = ref('')
const localId = ref('')
const localCode = ref('')
const localError = ref('')
let localResolver = null // Promise 等待 confirm

const rules = {
  username: [{ required: true, message: '请输入管理员账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }]
}

onMounted(async () => {
  if (adminStore.isLogged) router.replace('/dashboard')
  await detectCaptcha()
})

async function detectCaptcha() {
  try {
    const res = await getCaptchaEnabled()
    const data = res?.code === 0 ? res.data : null
    captchaEnabled.value = !!data?.enabled
    captchaProvider.value = data?.provider === 'aliyun' ? 'aliyun' : 'local'
    captchaMode.value = data?.mode === 'slider' ? 'slider' : 'graphic'
    aliyunCfg.value = data?.aliyun || null
    if (captchaEnabled.value && captchaProvider.value === 'aliyun') {
      // 预加载 SDK（官方建议提前 2s）
      ensureAliyunCaptcha(aliyunCfg.value).catch((e) => {
        console.warn('[captcha] aliyun preload failed:', e?.message)
      })
    }
  } catch (e) {
    captchaEnabled.value = false
  }
}

// ===== local graphic 弹窗 =====
async function openLocalDialog() {
  // 拉新图片
  try {
    const res = await getCaptchaImage()
    if (res?.code === 0) {
      localId.value = res.data?.captcha_id || ''
      localImg.value = res.data?.image || ''
      localCode.value = ''
      localError.value = ''
    }
  } catch (e) {
    ElMessage.error('验证码加载失败')
  }
  localDialogVisible.value = true
  return new Promise((resolve) => {
    localResolver = resolve
  })
}

function closeLocalDialog() {
  localDialogVisible.value = false
  if (localResolver) {
    localResolver(null) // 用户关闭
    localResolver = null
  }
}

async function submitLocalCode() {
  if (!localId.value || !localCode.value) return
  try {
    // 预校验（不消费）
    const res = await fetch('/api/admin/captcha/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ captcha_id: localId.value, captcha_code: localCode.value })
    }).then((r) => r.json())
    const ok = res?.data?.ok
    if (!ok) {
      localError.value = '验证码错误，请重试'
      // 刷新
      const imgRes = await getCaptchaImage()
      if (imgRes?.code === 0) {
        localId.value = imgRes.data?.captcha_id || ''
        localImg.value = imgRes.data?.image || ''
        localCode.value = ''
      }
      return
    }
    // 通过：resolve payload, 关闭弹窗
    const payload = { captcha_id: localId.value, captcha_code: localCode.value }
    localDialogVisible.value = false
    if (localResolver) { localResolver(payload); localResolver = null }
  } catch (e) {
    localError.value = '网络异常，请重试'
  }
}

async function refreshLocalImg() {
  const res = await getCaptchaImage()
  if (res?.code === 0) {
    localId.value = res.data?.captcha_id || ''
    localImg.value = res.data?.image || ''
    localCode.value = ''
    localError.value = ''
  }
}

// ===== 提交 =====
async function onSubmit() {
  await formRef.value.validate()
  submitting.value = true
  try {
    const payload = {}
    if (captchaEnabled.value) {
      if (captchaProvider.value === 'aliyun') {
        const param = await verifyAliyun(aliyunCfg.value)
        if (!param) { submitting.value = false; return }
        payload.captcha_verify_param = param
      } else {
        const p = await openLocalDialog()
        if (!p) { submitting.value = false; return }
        Object.assign(payload, p)
      }
    }
    const res = await login({
      username: form.value.username,
      password: form.value.password,
      ...payload
    })
    if (res.code !== 0) {
      ElMessage.error(res.message || '登录失败')
      return
    }
    adminStore.setSession(res.data)
    ElMessage.success('登录成功')
    router.replace(route.query.redirect || '/dashboard')
  } catch (e) {
    ElMessage.error(e.message || '登录失败')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="login">
    <div class="login__deco login__deco--1" />
    <div class="login__deco login__deco--2" />

    <div class="login__card">
      <img class="login__logo" :src="site.brandLogo" alt="logo" />
      <div class="login__brand">
        <div class="login__title">{{ site.siteName }} · 管理后台</div>
        <div class="login__subtitle">SINAN ADMIN CONSOLE</div>
      </div>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        size="large"
        class="login__form"
        @submit.prevent="onSubmit"
      >
        <el-form-item prop="username">
          <el-input
            v-model="form.username"
            placeholder="请输入管理员账号"
            :prefix-icon="User"
            clearable
          />
        </el-form-item>
        <el-form-item prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="请输入密码"
            :prefix-icon="Lock"
            show-password
            @keyup.enter="onSubmit"
          />
        </el-form-item>
        <!-- 验证码改为弹窗式，提交时自动唤起（local 弹图形输入弹窗，aliyun 弹滑块/图形 SDK 弹窗） -->
        <el-button
          type="primary"
          class="login__btn"
          :loading="submitting"
          native-type="submit"
        >
          {{ submitting ? '登录中...' : '登 录' }}
        </el-button>
      </el-form>

      <div class="login__hint">
        <template v-if="captchaEnabled">
          验证码：{{ captchaProvider === 'aliyun' ? '阿里云' : '本地图形' }}
          （{{ captchaMode === 'slider' ? '滑块' : '图形' }}）模式
        </template>
        <template v-else>
          请使用管理员账号登录
        </template>
      </div>
    </div>

    <!-- local graphic 弹窗 -->
    <el-dialog
      v-model="localDialogVisible"
      title="安全验证"
      width="360px"
      :close-on-click-modal="false"
      @close="closeLocalDialog"
    >
      <div class="local-captcha">
        <div class="local-captcha__tip">请输入下方图片中的字符</div>
        <div class="local-captcha__img-wrap" @click="refreshLocalImg" title="点击刷新">
          <img v-if="localImg" :src="localImg" alt="验证码" class="local-captcha__img" />
          <div v-else class="local-captcha__img local-captcha__img--ph">加载中...</div>
        </div>
        <div class="local-captcha__input-row">
          <el-input
            v-model="localCode"
            maxlength="6"
            placeholder="请输入验证码"
            :prefix-icon="Key"
            @keyup.enter="submitLocalCode"
          />
        </div>
        <div class="local-captcha__footer">
          <span v-if="localError" class="local-captcha__err">{{ localError }}</span>
        </div>
      </div>
      <template #footer>
        <el-button @click="closeLocalDialog">取消</el-button>
        <el-button type="primary" :disabled="localCode.length < 3" @click="submitLocalCode">确定</el-button>
      </template>
    </el-dialog>

    <div class="login__footer">© {{ year }} {{ site.siteName }}</div>
  </div>
</template>

<style scoped lang="scss">
.login {
  min-height: 100vh;
  background:
    radial-gradient(600px 300px at 85% -5%, rgba(212, 165, 116, 0.16), transparent 60%),
    radial-gradient(500px 260px at 0% 100%, rgba(192, 0, 0, 0.06), transparent 55%),
    $color-bg;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 30px 20px;
  position: relative;
  overflow: hidden;
}

.login__deco {
  position: absolute;
  border-radius: 50%;
  &--1 { width: 260px; height: 260px; border: 40px solid rgba(192, 0, 0, 0.04); top: -90px; right: -70px; }
  &--2 { width: 180px; height: 180px; border: 30px solid rgba(212, 165, 116, 0.1); bottom: -60px; left: -50px; }
}

.login__card {
  width: 100%; max-width: 400px; background: $color-card; border-radius: 18px;
  padding: 36px 32px 26px; box-shadow: 0 8px 40px rgba(26, 26, 26, 0.08);
  backdrop-filter: blur(6px); display: flex; flex-direction: column;
  align-items: center; position: relative; z-index: 1;
}

.login__logo {
  width: 54px; height: 54px; border-radius: 14px; object-fit: cover;
  border: 1px solid rgba(212, 165, 116, 0.35); box-shadow: 0 4px 14px rgba(192, 0, 0, 0.12);
}

.login__brand { text-align: center; margin: 14px 0 22px; }
.login__title { font-size: 20px; font-weight: 700; color: $color-text-primary; letter-spacing: 1px; }
.login__subtitle { margin-top: 6px; font-size: 11px; letter-spacing: 3px; color: $color-text-tertiary; }
.login__form { width: 100%; }
.login__btn { width: 100%; margin-top: 6px; height: 44px; font-size: 15px; letter-spacing: 6px; border-radius: 8px; }
.login__hint {
  margin-top: 18px; font-size: 11px; color: $color-text-tertiary;
  background: $color-surface; border-radius: 6px; padding: 6px 12px;
}
.login__footer { position: absolute; bottom: 18px; font-size: 11px; color: $color-text-tertiary; letter-spacing: 1px; }

// local graphic 弹窗样式
.local-captcha { text-align: center; }
.local-captcha__tip { font-size: 13px; color: #888; margin-bottom: 12px; }
.local-captcha__img-wrap {
  width: 180px; height: 60px; margin: 0 auto 16px; cursor: pointer;
  border-radius: 6px; overflow: hidden; border: 1px solid #ebeef5;
}
.local-captcha__img { width: 100%; height: 100%; display: block; object-fit: cover; }
.local-captcha__img--ph {
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; color: #aaa; background: #f5f7fa;
}
.local-captcha__input-row { margin-bottom: 12px; }
.local-captcha__footer { min-height: 18px; }
.local-captcha__err { color: #f56c6c; font-size: 12px; }
</style>
