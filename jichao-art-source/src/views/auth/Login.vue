<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import AppIcon from '@/components/AppIcon.vue'
import { showToast } from 'vant'

const router = useRouter()
const user = useUserStore()

const phone = ref('')
const password = ref('')
const agreed = ref(false)

const canSubmit = computed(() => phone.value.length >= 11 && password.value.length >= 6)

function onLogin() {
  if (!canSubmit.value) return
  if (!agreed.value) { showToast('请先阅读并同意协议'); return }
  user.login({ phone: phone.value })
  showToast('登录成功')
  router.replace('/')
}
function onRegister() { router.push('/auth/register') }
function onForgot() { router.push('/auth/forgot') }
</script>

<template>
  <div class="auth page--no-tabbar">
    <div class="auth__close" @click="router.back()">
      <AppIcon name="close" :size="24" color="#333" />
    </div>

    <div class="auth__head">
      <div class="auth__head-left">
        <h1 class="auth__title">登录</h1>
        <p class="auth__sub">数/字/藏/珍/品 · 司/南/鉴/匠/心</p>
      </div>
      <img class="auth__logo" src="/images/platform-logo.png" alt="" />
    </div>

    <div class="auth__form">
      <AppInput v-model="phone" label="手机号" type="tel" maxlength="11" placeholder="请输入手机号" />
      <AppInput v-model="password" label="密码" type="password" password-toggle placeholder="请输入密码" />

      <div class="auth__forgot" @click="onForgot">忘记登录密码？</div>

      <AppButton :disabled="!canSubmit" @click="onLogin">登录</AppButton>
      <AppButton type="outline" @click="onRegister">注册</AppButton>
    </div>

    <div class="auth__agree" @click="agreed = !agreed">
      <span class="auth__checkbox" :class="{ checked: agreed }">
        <AppIcon v-if="agreed" name="check" :size="12" color="#fff" />
      </span>
      <span class="auth__agree-text">我已阅读并同意<em>《用户协议》</em>和<em>《隐私政策》</em></span>
    </div>
  </div>
</template>

<style scoped lang="scss">
.auth { padding: 0 $page-padding 24px; }
.auth__close { padding: 14px 0; cursor: pointer; }
.auth__head { display: flex; align-items: flex-start; justify-content: space-between; margin: 10px 0 24px; }
.auth__title { margin: 0; font-size: 26px; font-weight: 700; color: $color-text-primary; }
.auth__sub { margin: 10px 0 0; font-size: 12px; color: $color-text-tertiary; letter-spacing: 2px; }
.auth__logo { width: 64px; height: 64px; border-radius: 12px; object-fit: cover; }
.auth__form { display: flex; flex-direction: column; gap: 16px; }
.auth__forgot { text-align: right; font-size: 14px; color: $color-text-primary; text-decoration: underline; cursor: pointer; margin-top: -4px; }

.auth__agree { display: flex; align-items: center; gap: 8px; margin-top: 20px; }
.auth__checkbox {
  width: 16px; height: 16px; border-radius: 4px; border: 1px solid $color-border; background: #fff;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  &.checked { background: $color-primary; border-color: $color-primary; }
}
.auth__agree-text { font-size: 12px; color: $color-text-secondary; em { color: $color-primary; font-style: normal; } }
</style>
