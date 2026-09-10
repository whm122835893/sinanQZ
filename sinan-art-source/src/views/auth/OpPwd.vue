<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import AppCard from '@/components/AppCard.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast } from 'vant'

const router = useRouter()
const { counting, remain, start } = useCountdown(60)

const phone = ref('175****1293')
const code = ref('')
const opPwd = ref('')
const confirm = ref('')

const canSubmit = computed(() => code.value.length >= 4 && opPwd.value.length === 6 && confirm.value.length === 6)

function sendCode() {
  if (counting.value) return
  start()
  showToast('验证码已发送')
}
function onSubmit() {
  if (!canSubmit.value) return
  if (opPwd.value !== confirm.value) { showToast('两次密码不一致'); return }
  showToast('操作密码设置成功')
  router.back()
}
</script>

<template>
  <div class="auth page--no-tabbar">
    <AppNavBar title="操作密码" @click-left="$router.back()" />

    <div class="auth-form">
      <AppCard :padding="16">
        <AppInput v-model="phone" label="手机号" readonly />
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
</style>
