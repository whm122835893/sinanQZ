<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useCaptcha } from '@/utils/useCaptcha'
import request from '@/utils/request'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import AppCard from '@/components/AppCard.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast } from 'vant'

const router = useRouter()
const user = useUserStore()
const { counting, remain, start } = useCountdown(60)

// 图形验证码（场景 user_op_pwd：设置/修改支付密码发码前置）
const captcha = useCaptcha('user_op_pwd')

const phone = ref(user.userInfo.phone || '')
const code = ref('')
const opPwd = ref('')
const confirm = ref('')
const submitting = ref(false)

const canSubmit = computed(() => code.value.length >= 4 && opPwd.value.length === 6 && confirm.value.length === 6)

async function sendCode() {
  if (counting.value) return
  try {
    // 图形码前置（场景 user_op_pwd），captcha_scene 供后端区分场景
    const payload = captcha.inject({ scene: 'reset_password', captcha_scene: 'user_op_pwd' })
    const res = await request.post('/user/send-code', payload)
    start()
    showToast('验证码已发送')
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
  if (!canSubmit.value) return
  if (opPwd.value !== confirm.value) { showToast('两次密码不一致'); return }
  if (submitting.value) return
  submitting.value = true
  try {
    await request.post('/user/password/trade/reset', { code: code.value, newPassword: opPwd.value })
    showToast('操作密码设置成功')
    router.back()
  } catch (e) {
    showToast(e.message || '操作密码设置失败')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth page--no-tabbar">
    <AppNavBar title="操作密码" @click-left="$router.back()" />

    <div class="auth-form">
      <AppCard :padding="16">
        <AppInput v-model="phone" label="手机号" readonly />
        <!-- 图形验证码（发送支付密码短信前置，后端场景开关关闭时不显示） -->
        <AppInput
          v-if="captcha.enabled"
          v-model="captcha.code"
          label="图形验证码"
          type="tel"
          maxlength="4"
          placeholder="请输入验证码"
          style="margin-top:16px"
        >
          <template #suffix>
            <img :src="captcha.image" class="captcha-img" alt="验证码" @click="captcha.refresh" />
          </template>
        </AppInput>
        <AppInput v-model="code" label="验证码" type="tel" maxlength="6" placeholder="请输入验证码" style="margin-top:16px">
          <template #suffix>
            <button class="code-btn" :class="{ disabled: counting }" @click="sendCode">
              {{ counting ? remain + 's' : '发送验证码' }}
            </button>
          </template>
        </AppInput>
        <AppInput v-model="opPwd" label="操作密码" type="tel" maxlength="6" placeholder="设置6位数字操作密码" style="margin-top:16px" />
        <AppInput v-model="confirm" label="确认新密码" type="tel" maxlength="6" placeholder="请再次输入操作密码" style="margin-top:16px" />
      </AppCard>

      <AppButton :disabled="!canSubmit" @click="onSubmit">提交</AppButton>
    </div>
  </div>
</template>

<style scoped lang="scss">
.auth-form { padding: 16px $page-padding; display: flex; flex-direction: column; gap: 20px; }
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
