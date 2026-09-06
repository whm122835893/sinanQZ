<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import AppIcon from '@/components/AppIcon.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast, showDialog } from 'vant'

const route = useRoute()
const router = useRouter()
const user = useUserStore()
const { counting, remain, start } = useCountdown(60)

const phone = ref('')
const nickname = ref('')
const code = ref('')
// 邀请码：从注册链接 ?code= 自动回填（邀请页复制注册链接的闭环）
const invite = ref(String(route.query.code || ''))
const agreed = ref(false)
const submitting = ref(false)

// 后端注册接口必填昵称（2-20 字），验证码登录模式无密码字段
const canSubmit = computed(
  () => phone.value.length >= 11 && code.value.length >= 4 && nickname.value.trim().length >= 2
)

// MOCK_REPLACED: 原为本地直接弹"验证码已发送"，现走后端 POST /api/auth/send-code
async function sendCode() {
  if (phone.value.length < 11) { showToast('请输入手机号'); return }
  if (counting.value) return
  try {
    const res = await user.sendCode(phone.value, 'register')
    start()
    showToast('验证码已发送')
    // 开发环境后端直接回传验证码，便于联调
    if (res?.debugCode) showToast(`开发验证码：${res.debugCode}`)
  } catch (e) {
    showToast(e.message || '验证码发送失败')
  }
}

// MOCK_REPLACED: 原为本地直接弹"注册成功"，现走后端 POST /api/auth/register
// （注册即登录返回 token；携带邀请码时后端写入 invite_records 绑定邀请关系）
async function onSubmit() {
  if (!canSubmit.value) return
  if (!agreed.value) { showToast('请先阅读并同意协议'); return }
  if (submitting.value) return
  submitting.value = true
  try {
    await user.register({
      phone: phone.value,
      code: code.value,
      nickname: nickname.value.trim(),
      inviteCode: invite.value.trim()
    })
    showToast('注册成功')
    const redirect = route.query.redirect
    router.replace(redirect ? String(redirect) : '/')
  } catch (e) {
    showToast(e.message || '注册失败')
  } finally {
    submitting.value = false
  }
}

function goAgreement(name) {
  showDialog({ title: name, message: '此处为' + name + '的正文内容，实际接入后替换为真实条款。', confirmButtonText: '我知道了' })
}
</script>

<template>
  <div class="auth page--no-tabbar">
    <AppNavBar title="注册" @click-left="$router.back()" />

    <p class="auth-sub">司/南/载/道·文/脉/传/心</p>

    <div class="auth-form">
      <AppInput v-model="phone" label="手机号" type="tel" maxlength="11" placeholder="请输入手机号" />
      <AppInput v-model="code" label="验证码" type="tel" maxlength="6" placeholder="请输入验证码">
        <template #suffix>
          <button class="code-btn" :class="{ disabled: counting }" @click="sendCode">
            {{ counting ? remain + 's' : '发送验证码' }}
          </button>
        </template>
      </AppInput>
      <AppInput v-model="nickname" label="昵称" maxlength="20" placeholder="请输入2-20位昵称" />
      <AppInput v-model="invite" label="邀请码(选填)" placeholder="请输入" />

      <div class="auth-agree" @click="agreed = !agreed">
        <span class="auth-checkbox" :class="{ checked: agreed }">
          <AppIcon v-if="agreed" name="check" :size="12" color="#fff" />
        </span>
        <span class="auth-agree-text">我已阅读并同意<em @click.stop="goAgreement('用户协议')">《用户协议》</em>和<em @click.stop="goAgreement('隐私政策')">《隐私政策》</em></span>
      </div>

      <AppButton :disabled="!canSubmit" @click="onSubmit">立即注册</AppButton>
      <p class="auth-login">已有账号？<em @click="router.push('/auth/login')">去登录</em></p>
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
.auth-agree-text { font-size: 12px; color: $color-text-secondary; em { color: $color-primary; font-style: normal; cursor: pointer; } }

.auth-login {
  margin: 0; text-align: center; font-size: 13px; color: $color-text-secondary;
  em { color: $color-primary; font-style: normal; cursor: pointer; }
}
</style>
