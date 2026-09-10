<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast } from 'vant'

const router = useRouter()
const { counting, remain, start } = useCountdown(60)

const phone = ref('')
const code = ref('')
const password = ref('')

const canSubmit = computed(() => phone.value.length >= 11 && code.value.length >= 4 && password.value.length >= 6)

function sendCode() {
  if (phone.value.length < 11) { showToast('请输入手机号'); return }
  if (counting.value) return
  start()
  showToast('验证码已发送')
}
function onSubmit() {
  if (!canSubmit.value) return
  showToast('密码已重置')
  router.replace('/auth/login')
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
