<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import request from '@/utils/request'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast } from 'vant'

const router = useRouter()
const user = useUserStore()
const { counting, remain, start } = useCountdown(60)

const phone = ref('')
const code = ref('')
const password = ref('')
const submitting = ref(false)

const canSubmit = computed(() => phone.value.length >= 11 && code.value.length >= 4 && password.value.length >= 6)

async function sendCode() {
  if (phone.value.length < 11) { showToast('请输入手机号'); return }
  if (counting.value) return
  try {
    const res = await user.sendCode(phone.value, 'reset_password')
    start()
    showToast('验证码已发送')
    if (res?.debugCode) showToast(`开发验证码：${res.debugCode}`)
  } catch (e) {
    showToast(e.message || '验证码发送失败')
  }
}

async function onSubmit() {
  if (!canSubmit.value) return
  if (submitting.value) return
  submitting.value = true
  try {
    await request.post('/auth/reset-password', {
      phone: phone.value,
      code: code.value,
      newPassword: password.value
    })
    showToast('密码已重置')
    router.replace('/auth/login')
  } catch (e) {
    showToast(e.message || '密码重置失败')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth page--no-tabbar">
    <AppNavBar title="找回密码" @click-left="$router.back()" />

    <div class="auth-form">
      <AppInput v-model="phone" label="手机号" type="tel" maxlength="11" placeholder="请输入手机号" />
      <AppInput v-model="code" label="验证码" type="tel" maxlength="6" placeholder="请输入验证码">
        <template #suffix>
          <button class="code-btn" :class="{ disabled: counting }" @click="sendCode">
            {{ counting ? remain + 's' : '发送验证码' }}
          </button>
        </template>
      </AppInput>
      <AppInput v-model="password" label="新密码" type="password" password-toggle placeholder="设置6-20位登录密码" />

      <AppButton :disabled="!canSubmit" @click="onSubmit">提交</AppButton>
    </div>
  </div>
</template>

<style scoped lang="scss">
.auth-form { padding: 16px $page-padding; display: flex; flex-direction: column; gap: 16px; }
.code-btn {
  border: none; cursor: pointer; background: $color-primary; color: #fff; font-size: 13px;
  height: 32px; padding: 0 12px; border-radius: $radius-md; flex-shrink: 0; margin-left: 10px;
  &.disabled { background: #cccccc; cursor: not-allowed; }
}
</style>
