<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { User, Lock } from '@element-plus/icons-vue'
import { login } from '@/api'
import { useAdminStore } from '@/stores/admin'

const router = useRouter()
const route = useRoute()
const adminStore = useAdminStore()

const formRef = ref(null)
const form = ref({ username: 'admin', password: 'admin123' })
const submitting = ref(false)
const year = new Date().getFullYear()

const rules = {
  username: [{ required: true, message: '请输入管理员账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }]
}

onMounted(() => {
  if (adminStore.isLogged) router.replace('/dashboard')
})

async function onSubmit() {
  await formRef.value.validate()
  submitting.value = true
  try {
    const res = await login({ username: form.value.username, password: form.value.password })
    if (res.code !== 0) throw new Error(res.message)
    adminStore.setSession(res.data)
    ElMessage.success('登录成功')
    router.replace(route.query.redirect || '/dashboard')
  } catch (e) {
    ElMessage.error(e.message || '登录失败')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="login">
    <div class="login__deco login__deco--1" />
    <div class="login__deco login__deco--2" />

    <div class="login__card">
      <img class="login__logo" src="/images/platform-logo.png" alt="logo" />
      <div class="login__brand">
        <div class="login__title"><span class="calligraphy">司南</span>珍藏 · 管理后台</div>
        <div class="login__subtitle">SINAN ADMIN CONSOLE</div>
      </div>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        size="large"
        class="login__form"
        @submit.prevent="onSubmit"
      >
        <el-form-item prop="username">
          <el-input
            v-model="form.username"
            placeholder="请输入管理员账号"
            :prefix-icon="User"
            clearable
          />
        </el-form-item>
        <el-form-item prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="请输入密码"
            :prefix-icon="Lock"
            show-password
            @keyup.enter="onSubmit"
          />
        </el-form-item>
        <el-button
          type="primary"
          class="login__btn"
          :loading="submitting"
          native-type="submit"
        >
          {{ submitting ? '登录中...' : '登 录' }}
        </el-button>
      </el-form>

      <div class="login__hint">
        演示账号：admin / admin123（当前为纯前端 Mock，未联调后端）
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
  padding: 36px 32px 26px;
  box-shadow: 0 8px 40px rgba(26, 26, 26, 0.08);
  backdrop-filter: blur(6px);
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  z-index: 1;
}

.login__logo {
  width: 54px;
  height: 54px;
  border-radius: 14px;
  object-fit: cover;
  border: 1px solid rgba(212, 165, 116, 0.35);
  box-shadow: 0 4px 14px rgba(192, 0, 0, 0.12);
}

.login__brand {
  text-align: center;
  margin: 14px 0 22px;
}

.login__title {
  font-size: 20px;
  font-weight: 700;
  color: $color-text-primary;
  letter-spacing: 1px;
}

.login__subtitle {
  margin-top: 6px;
  font-size: 11px;
  letter-spacing: 3px;
  color: $color-text-tertiary;
}

.login__form { width: 100%; }

.login__btn {
  width: 100%;
  margin-top: 6px;
  height: 44px;
  font-size: 15px;
  letter-spacing: 6px;
  border-radius: 8px;
}

.login__hint {
  margin-top: 18px;
  font-size: 11px;
  color: $color-text-tertiary;
  background: $color-surface;
  border-radius: 6px;
  padding: 6px 12px;
}

.login__footer {
  position: absolute;
  bottom: 18px;
  font-size: 11px;
  color: $color-text-tertiary;
  letter-spacing: 1px;
}
</style>
