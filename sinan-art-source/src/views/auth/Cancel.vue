<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import request from '@/utils/request'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import AppCard from '@/components/AppCard.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast, showConfirmDialog } from 'vant'

const router = useRouter()
const user = useUserStore()
const { counting, remain, start } = useCountdown(60)

const code = ref('')
const realName = ref('')
const idCard = ref('')
const submitting = ref(false)

const canSubmit = computed(() => code.value.length >= 4 && realName.value.length >= 2 && idCard.value.length >= 15)

async function sendCode() {
  if (counting.value) return
  try {
    const res = await request.post('/user/send-code', { scene: 'cancel' })
    start()
    showToast('验证码已发送')
    if (res?.debugCode) showToast(`开发验证码：${res.debugCode}`)
  } catch (e) {
    showToast(e.message || '验证码发送失败')
  }
}

function onSubmit() {
  if (!canSubmit.value) return
  showConfirmDialog({ title: '注销账号', message: '注销后数据将无法恢复，确认注销？' })
    .then(async () => {
      if (submitting.value) return
      submitting.value = true
      try {
        await request.post('/user/cancel', { code: code.value, realName: realName.value, idCard: idCard.value })
        showToast('账号已注销')
        user.logout()
        router.replace('/auth/login')
      } catch (e) {
        showToast(e.message || '注销失败')
      } finally {
        submitting.value = false
      }
    })
    .catch(() => {})
}
</script>

<template>
  <div class="auth page--no-tabbar">
    <AppNavBar title="注销账号" @click-left="$router.back()" />

    <p class="cancel-tip">您正在注销您的账号：{{ user.userInfo.phone }}</p>

    <AppCard :padding="16" style="margin:0 16px">
      <AppInput v-model="code" label="验证码" type="tel" maxlength="6" placeholder="请输入验证码">
        <template #suffix>
          <button class="code-btn" :class="{ disabled: counting }" @click="sendCode">
            {{ counting ? remain + 's' : '发送验证码' }}
          </button>
        </template>
      </AppInput>
      <AppInput v-model="realName" label="真实姓名" placeholder="请输入真实姓名" style="margin-top:16px" />
      <AppInput v-model="idCard" label="身份证号" placeholder="请输入身份证号" style="margin-top:16px" />
    </AppCard>

    <div class="cancel-notice">
      <h3 class="cancel-notice__title">注销须知</h3>
      <p>1. 账号注销后，您的所有数字藏品、司南币及订单数据将被清空且无法恢复。</p>
      <p>2. 注销前请确认已完成所有进行中的订单与资产提现。</p>
      <p>3. 注销操作需通过手机验证码与实名信息校验，请谨慎操作。</p>
    </div>

    <div class="cancel-submit">
      <AppButton :disabled="!canSubmit" @click="onSubmit">确认</AppButton>
    </div>
  </div>
</template>

<style scoped lang="scss">
.cancel-tip { margin: 16px; font-size: 14px; color: $color-text-primary; }
.cancel-notice { padding: 16px; margin: 16px; background: $color-card; border-radius: $radius-lg; }
.cancel-notice__title { margin: 0 0 12px; font-size: 16px; font-weight: 700; color: $color-text-primary; }
.cancel-notice p { margin: 0 0 8px; font-size: 14px; color: $color-text-secondary; line-height: 1.6; }
.cancel-submit { padding: 0 16px; }
.code-btn {
  border: none; cursor: pointer; background: $color-primary; color: #fff; font-size: 13px;
  height: 32px; padding: 0 12px; border-radius: $radius-md; flex-shrink: 0; margin-left: 10px;
  &.disabled { background: #cccccc; cursor: not-allowed; }
}
</style>
