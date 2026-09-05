<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showSuccessToast, showFailToast } from 'vant'
import { login } from '@/api'
import { useAdminStore } from '@/stores/admin'

const router = useRouter()
const route = useRoute()
const adminStore = useAdminStore()

const username = ref('admin')
const password = ref('admin123')
const submitting = ref(false)
const year = new Date().getFullYear()

onMounted(() => {
  if (adminStore.isLogged) router.replace('/dashboard')
})

async function onSubmit() {
  if (!username.value || !password.value) {
    showFailToast('请输入账号与密码')
    return
  }
  submitting.value = true
  try {
    const res = await login({ username: username.value, password: password.value })
    if (res.code !== 0) throw new Error(res.message)
    adminStore.setSession(res.data)
    showSuccessToast('登录成功')
    router.replace(route.query.redirect || '/dashboard')
  } catch (e) {
    showFailToast(e.message || '登录失败')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="login safe-top">
    <div class="login__deco login__deco--1" />
    <div class="login__deco login__deco--2" />

    <div class="login__card">
      <img class="login__logo" src="/images/platform-logo.png" alt="logo" />
      <div class="login__brand">
        <div class="login__title"><span class="calligraphy">司南</span>珍藏 · 管理后台</div>
        <div class="login__subtitle">SINAN ADMIN CONSOLE</div>
      </div>

      <van-form class="login__form" @submit="onSubmit">
        <van-cell-group inset>
          <van-field
            v-model="username"
            name="账号"
            label="账号"
            placeholder="请输入管理员账号"
            left-icon="manager-o"
            :rules="[{ required: true, message: '请输入账号' }]"
          />
          <van-field
            v-model="password"
            type="password"
            name="密码"
            label="密码"
            placeholder="请输入密码"
            left-icon="lock"
            :rules="[{ required: true, message: '请输入密码' }]"
          />
        </van-cell-group>

        <van-button
          round
          block
          type="primary"
          native-type="submit"
          class="login__btn"
          :loading="submitting"
          loading-text="登录中..."
        >
          登 录
        </van-button>
      </van-form>

      <div class="login__hint">
        <van-icon name="info-o" /> 演示账号：admin / admin123（当前为纯前端 Mock，未联调后端）
      </div>
    </div>

    <div class="login__footer">© {{ year }} 司南珍藏 · SINAN DIGITAL COLLECTION</div>
  </div>
</template>

<style scoped lang="scss">
.login {
  min-height: 100vh;
  background:
    radial-gradient(600px 300px at 85% -5%, rgba(212, 165, 116, 0.16), transparent 60%),
    radial-gradient(500px 260px at 0% 100%, rgba(192, 0, 0, 0.06), transparent 55%),
    $color-bg;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 30px 20px;
  position: relative;
  overflow: hidden;
}

.login__deco {
  position: absolute;
  border-radius: 50%;
  &--1 {
    width: 260px;
    height: 260px;
    border: 40px solid rgba(192, 0, 0, 0.04);
    top: -90px;
    right: -70px;
  }
  &--2 {
    width: 180px;
    height: 180px;
    border: 30px solid rgba(212, 165, 116, 0.1);
    bottom: -60px;
    left: -50px;
  }
}

.login__card {
  width: 100%;
  max-width: 400px;
  background: $color-card;
  border-radius: 18px;
  padding: 34px 18px 24px;
  box-shadow: 0 18px 50px rgba(26, 26, 26, 0.08);
  position: relative;
  z-index: 1;
}

.login__logo {
  width: 64px;
  height: 64px;
  border-radius: 16px;
  margin: 0 auto;
  box-shadow: 0 8px 20px rgba(192, 0, 0, 0.16);
}

.login__brand {
  text-align: center;
  margin: 14px 0 24px;
}

.login__title {
  font-size: 20px;
  font-weight: 700;
  color: $color-text-primary;

  .calligraphy { color: $color-primary; }
}

.login__subtitle {
  font-size: 10px;
  letter-spacing: 3px;
  color: $color-gold;
  margin-top: 5px;
}

.login__btn {
  margin-top: 20px;
  height: 44px;
  font-size: 16px;
  letter-spacing: 6px;
}

.login__hint {
  margin-top: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  font-size: 11px;
  color: $color-text-tertiary;
}

.login__footer {
  margin-top: 26px;
  font-size: 10px;
  color: $color-text-tertiary;
  letter-spacing: 1px;
  position: relative;
  z-index: 1;
}
</style>
