<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import AppIcon from '@/components/AppIcon.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast, showDialog } from 'vant'

const route = useRoute()
const router = useRouter()
const user = useUserStore()
const { counting, remain, start } = useCountdown(60)

const loginMode = ref('password') // 'password' | 'code'
const phone = ref('')
const password = ref('')
const code = ref('')
const agreed = ref(false)

const canSubmit = computed(() => {
  if (phone.value.length < 11) return false
  if (loginMode.value === 'password') return password.value.length >= 6
  return code.value.length >= 4
})

function sendCode() {
  if (phone.value.length < 11) { showToast('请输入手机号'); return }
  if (counting.value) return
  start()
  showToast('验证码已发送')
}

function onLogin() {
  if (!canSubmit.value) return
  if (!agreed.value) { showToast('请先阅读并同意协议'); return }
  user.login({ phone: phone.value })
  showToast('登录成功')
  // 登录后回跳来源页面（从全局登录弹窗进入时携带 redirect 参数）
  const redirect = route.query.redirect
  if (redirect) {
    router.replace(String(redirect))
  } else {
    router.replace('/')
  }
}
function onRegister() { router.push('/auth/register') }
function onForgot() { router.push('/auth/forgot') }

function goAgreement(name) {
  showDialog({ title: name, message: '此处为' + name + '的正文内容，实际接入后替换为真实条款。', confirmButtonText: '我知道了' })
}
</script>

<template>
  <div class="auth page--no-tabbar">
    <div class="auth__close" @click="router.back()">
      <AppIcon name="close" :size="24" color="#333" />
    </div>

    <div class="auth__head">
      <div class="auth__head-left">
        <h1 class="auth__title">登录</h1>
        <p class="auth__sub">司/南/载/道·文/脉/传/心</p>
      </div>
      <img class="auth__logo" src="/images/platform-logo.png" alt="" />
    </div>

    <!-- 登录方式切换 -->
    <div class="auth__tabs">
      <span class="auth__tab" :class="{ active: loginMode === 'password' }" @click="loginMode = 'password'">密码登录</span>
      <span class="auth__tab" :class="{ active: loginMode === 'code' }" @click="loginMode = 'code'">验证码登录</span>
    </div>

    <div class="auth__form">
      <AppInput v-model="phone" label="手机号" type="tel" maxlength="11" placeholder="请输入手机号" />

      <template v-if="loginMode === 'password'">
        <AppInput v-model="password" label="密码" type="password" password-toggle placeholder="请输入密码" />
        <div class="auth__forgot" @click="onForgot">忘记登录密码？</div>
      </template>
      <template v-else>
        <AppInput v-model="code" label="验证码" type="tel" maxlength="6" placeholder="请输入验证码">
          <template #suffix>
            <button class="auth__code-btn" :class="{ disabled: counting }" @click="sendCode">
              {{ counting ? remain + 's' : '发送验证码' }}
            </button>
          </template>
        </AppInput>
      </template>

      <AppButton :disabled="!canSubmit" @click="onLogin">登录</AppButton>
      <AppButton type="outline" @click="onRegister">注册</AppButton>
    </div>

    <div class="auth__agree" @click="agreed = !agreed">
      <span class="auth__checkbox" :class="{ checked: agreed }">
        <AppIcon v-if="agreed" name="check" :size="12" color="#fff" />
      </span>
      <span class="auth__agree-text">我已阅读并同意<em @click.stop="goAgreement('用户协议')">《用户协议》</em>和<em @click.stop="goAgreement('隐私政策')">《隐私政策》</em></span>
    </div>
  </div>
</template>

<style scoped lang="scss">
.auth { padding: 0 $page-padding 24px; }
.auth__close { padding: 14px 0; cursor: pointer; }
.auth__head { display: flex; align-items: flex-start; justify-content: space-between; margin: 10px 0 24px; }
.auth__title { margin: 0; font-size: 26px; font-weight: 700; color: $color-text-primary; }
.auth__sub { margin: 10px 0 0; font-size: 12px; color: $color-text-tertiary; letter-spacing: 2px; }
.auth__logo { width: 64px; height: 64px; border-radius: 12px; object-fit: cover; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
.auth__form { display: flex; flex-direction: column; gap: 16px; }
.auth__forgot { text-align: right; font-size: 14px; color: $color-text-primary; text-decoration: underline; cursor: pointer; margin-top: -4px; }

.auth__tabs { display: flex; gap: 24px; margin-bottom: 16px; }
.auth__tab {
  font-size: 16px; color: $color-text-tertiary; cursor: pointer; padding-bottom: 6px;
  border-bottom: 2px solid transparent; transition: all 0.2s;
  &.active { color: $color-text-primary; font-weight: 700; border-bottom-color: $color-primary; }
}
.auth__code-btn {
  border: none; cursor: pointer; background: $color-primary; color: #fff; font-size: 13px;
  height: 32px; padding: 0 12px; border-radius: $radius-md; flex-shrink: 0; margin-left: 10px;
  &.disabled { background: #cccccc; cursor: not-allowed; }
}

.auth__agree { display: flex; align-items: center; gap: 8px; margin-top: 20px; }
.auth__checkbox {
  width: 16px; height: 16px; border-radius: 4px; border: 1px solid $color-border; background: #fff;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  &.checked { background: $color-primary; border-color: $color-primary; }
}
.auth__agree-text { font-size: 12px; color: $color-text-secondary; em { color: $color-primary; font-style: normal; cursor: pointer; } }
</style>
