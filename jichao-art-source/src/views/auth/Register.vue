<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import AppIcon from '@/components/AppIcon.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast } from 'vant'

const router = useRouter()
const { counting, remain, start } = useCountdown(60)

const phone = ref('')
const code = ref('')
const password = ref('')
const confirm = ref('')
const invite = ref('')
const agreed = ref(false)

const canSubmit = computed(
  () => phone.value.length >= 11 && code.value.length >= 4 && password.value.length >= 6 && confirm.value.length >= 6
)

function sendCode() {
  if (phone.value.length < 11) { showToast('请输入手机号'); return }
  if (counting.value) return
  start()
  showToast('验证码已发送')
}

function onSubmit() {
  if (!canSubmit.value) return
  if (password.value !== confirm.value) { showToast('两次密码不一致'); return }
  if (!agreed.value) { showToast('请先阅读并同意协议'); return }
  showToast('注册成功')
  router.replace('/auth/login')
}
</script>

<template>
  <div class="auth page--no-tabbar">
    <AppNavBar title="注册" @click-left="$router.back()" />

    <p class="auth-sub">数/字/藏/珍/品 · 司/南/鉴/匠/心</p>

    <div class="auth-form">
      <AppInput v-model="phone" label="手机号" type="tel" maxlength="11" placeholder="请输入手机号" />
      <AppInput v-model="code" label="验证码" type="tel" maxlength="6" placeholder="请输入验证码">
        <template #suffix>
          <button class="code-btn" :class="{ disabled: counting }" @click="sendCode">
            {{ counting ? remain + 's' : '发送验证码' }}
          </button>
        </template>
      </AppInput>
      <AppInput v-model="password" label="设置登录密码" type="password" password-toggle placeholder="设置6-20位登录密码" />
      <AppInput v-model="confirm" label="确认密码" type="password" password-toggle placeholder="请再次输入密码" />
      <AppInput v-model="invite" label="邀请码(选填)" placeholder="请输入" />

      <div class="auth-agree" @click="agreed = !agreed">
        <span class="auth-checkbox" :class="{ checked: agreed }">
          <AppIcon v-if="agreed" name="back" :size="12" color="#fff" style="transform: rotate(-45deg)" />
        </span>
        <span class="auth-agree-text">我已阅读并同意<em>《用户协议》</em>和<em>《隐私政策》</em></span>
      </div>

      <AppButton :disabled="!canSubmit" @click="onSubmit">立即注册</AppButton>
    </div>
  </div>
</template>

<style scoped lang="scss">
.auth-sub { margin: 4px $page-padding 20px; font-size: 12px; color: $color-text-tertiary; letter-spacing: 2px; }
.auth-form { padding: 0 $page-padding; display: flex; flex-direction: column; gap: 16px; }

.code-btn {
  border: none; cursor: pointer; background: $color-primary; color: #fff; font-size: 13px;
  height: 32px; padding: 0 12px; border-radius: $radius-md; flex-shrink: 0; margin-left: 10px;
  &.disabled { background: #cccccc; cursor: not-allowed; }
}

.auth-agree { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.auth-checkbox {
  width: 16px; height: 16px; border-radius: 4px; border: 1px solid $color-border; background: #fff;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  &.checked { background: $color-primary; border-color: $color-primary; }
}
.auth-agree-text { font-size: 12px; color: $color-text-secondary; em { color: $color-primary; font-style: normal; } }
</style>
