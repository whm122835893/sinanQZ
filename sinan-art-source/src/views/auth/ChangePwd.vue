<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useCaptcha } from '@/utils/useCaptcha'
import request from '@/utils/request'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast } from 'vant'

const router = useRouter()
const user = useUserStore()
const { counting, remain, start } = useCountdown(60)

// 图形验证码（场景 user_change_pwd：已登录改密发码前置）
const captcha = useCaptcha('user_change_pwd')

const code = ref('')
const password = ref('')
const confirm = ref('')
const submitting = ref(false)

const pwdValid = computed(() => password.value.length >= 6)
const confirmError = computed(() => {
  if (!confirm.value) return ''
  return password.value === confirm.value ? '' : '两次输入的密码不一致'
})

const canSubmit = computed(
  () => code.value.length >= 4 && pwdValid.value && confirm.value.length >= 6 && !confirmError.value
)

async function sendCode() {
  if (counting.value) return
  try {
    // 图形码前置（场景 user_change_pwd），captcha_scene 供后端区分场景
    const payload = captcha.inject({ scene: 'reset_password', captcha_scene: 'user_change_pwd' })
    const res = await request.post('/user/send-code', payload)
    start()
    showToast('验证码已发送至 ' + user.userInfo.phone)
    if (res?.debugCode) showToast(`开发验证码：${res.debugCode}`)
    await captcha.refresh()
  } catch (e) {
    if (e.message?.includes('图形验证码')) {
      captcha.code.value = ''
      await captcha.refresh()
    }
    showToast(e.message || '验证码发送失败')
  }
}

onMounted(() => { captcha.refresh() })

async function onSubmit() {
  if (!canSubmit.value) {
    if (code.value.length < 4) showToast('请输入验证码')
    else if (!pwdValid.value) showToast('密码至少 6 位')
    else if (confirmError.value) showToast(confirmError.value)
    return
  }
  if (submitting.value) return
  submitting.value = true
  try {
    await request.post('/user/password/reset', { code: code.value, newPassword: password.value })
    showToast('密码修改成功')
    router.back()
  } catch (e) {
    showToast(e.message || '密码修改失败')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth page--no-tabbar">
    <AppNavBar title="修改密码" @click-left="$router.back()" />

    <p class="auth-sub">已绑定手机号 {{ user.userInfo.phone }}，验证通过后即可重置登录密码</p>

    <div class="auth-form">
      <!-- 图形验证码（发送改密短信前置，后端场景开关关闭时不显示） -->
      <AppInput v-if="captcha.enabled" v-model="captcha.code" label="图形验证码" type="tel" maxlength="4" placeholder="请输入验证码">
        <template #suffix>
          <img :src="captcha.image" class="captcha-img" alt="验证码" @click="captcha.refresh" />
        </template>
      </AppInput>
      <AppInput v-model="code" label="验证码" type="tel" maxlength="6" placeholder="请输入验证码">
        <template #suffix>
          <button class="code-btn" :class="{ disabled: counting }" @click="sendCode">
            {{ counting ? remain + 's' : '发送验证码' }}
          </button>
        </template>
      </AppInput>
      <AppInput
        v-model="password"
        label="新密码"
        type="password"
        password-toggle
        placeholder="设置 6-20 位登录密码"
        style="margin-top:16px"
      />
      <AppInput
        v-model="confirm"
        label="确认新密码"
        type="password"
        password-toggle
        placeholder="请再次输入登录密码"
        style="margin-top:16px"
        :error="confirmError"
      />

      <AppButton :disabled="!canSubmit" style="margin-top:8px" @click="onSubmit">提交</AppButton>
    </div>
  </div>
</template>

<style scoped lang="scss">
.auth-sub { margin: 16px $page-padding; font-size: 13px; color: $color-text-secondary; line-height: 1.6; }
.auth-form { padding: 0 $page-padding; display: flex; flex-direction: column; gap: 4px; }

.code-btn {
  border: none; cursor: pointer; background: $color-primary; color: #fff; font-size: 13px;
  height: 32px; padding: 0 12px; border-radius: $radius-md; flex-shrink: 0; margin-left: 10px;
  &.disabled { background: #cccccc; cursor: not-allowed; }
}

.captcha-img {
  height: 32px; width: auto; cursor: pointer; border-radius: 4px;
  border: 1px solid $color-border; display: block; flex-shrink: 0; margin-left: 10px;
  &:active { opacity: 0.7; }
}
</style>
