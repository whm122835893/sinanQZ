<script setup lang="ts">
/**
 * 登录页：司南红品牌视觉 + 账号密码登录
 */
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { FormInstance, FormRules } from 'element-plus'
import { Lock, User } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const formRef = ref<FormInstance>()
const loading = ref(false)
const form = reactive({ username: '', password: '' })

const rules: FormRules = {
  username: [{ required: true, message: '请输入管理员账号', trigger: 'blur' }],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, message: '密码至少 6 位', trigger: 'blur' },
  ],
}

async function handleLogin() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  loading.value = true
  try {
    await auth.login(form.username, form.password)
    const redirect = (route.query.redirect as string) || '/dashboard'
    router.push(redirect)
  } catch {
    /* 错误已在拦截器全局提示 */
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <!-- 背景装饰 -->
    <div class="bg-deco deco-1" />
    <div class="bg-deco deco-2" />

    <div class="login-card">
      <div class="brand-side">
        <div class="brand-logo">司</div>
        <h1 class="brand-title">司南数字藏品</h1>
        <p class="brand-desc">数字藏品运营管理平台</p>
        <div class="brand-features">
          <span>藏品全生命周期管理</span>
          <span>资金库存双向审计</span>
          <span>五级角色权限隔离</span>
        </div>
      </div>

      <div class="form-side">
        <h2 class="form-title">管理后台登录</h2>
        <p class="form-sub">仅限授权管理人员访问，操作将被审计记录</p>

        <el-form ref="formRef" :model="form" :rules="rules" size="large" @keyup.enter="handleLogin">
          <el-form-item prop="username">
            <el-input v-model="form.username" placeholder="管理员账号" :prefix-icon="User" autocomplete="username" clearable />
          </el-form-item>
          <el-form-item prop="password">
            <el-input
              v-model="form.password"
              type="password"
              placeholder="密码"
              :prefix-icon="Lock"
              show-password
              autocomplete="current-password"
            />
          </el-form-item>
          <el-form-item>
            <el-button class="submit-btn" type="primary" size="large" :loading="loading" @click="handleLogin">
              {{ loading ? '登录中…' : '登 录' }}
            </el-button>
          </el-form-item>
        </el-form>

        <div class="login-tips">连续登录失败 5 次将锁定账号 15 分钟</div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.login-page {
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: radial-gradient(ellipse at top left, #3a1010 0%, #17171a 55%, #101012 100%);
  position: relative;
  overflow: hidden;
}

.bg-deco {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.5;

  &.deco-1 {
    width: 420px;
    height: 420px;
    background: #b00000;
    right: -120px;
    bottom: -140px;
  }
  &.deco-2 {
    width: 300px;
    height: 300px;
    background: #d5342c;
    left: -100px;
    top: -80px;
    opacity: 0.25;
  }
}

.login-card {
  position: relative;
  z-index: 1;
  width: 860px;
  max-width: calc(100vw - 40px);
  min-height: 480px;
  display: flex;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
}

.brand-side {
  flex: 1;
  background: linear-gradient(160deg, #9c0a0a 0%, #6d0505 60%, #4d0303 100%);
  color: #fff;
  padding: 48px 40px;
  display: flex;
  flex-direction: column;
  justify-content: center;

  .brand-logo {
    width: 64px;
    height: 64px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.25);
    font-size: 30px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 24px;
    box-shadow: inset 0 0 20px rgba(255, 255, 255, 0.08);
  }

  .brand-title {
    font-size: 26px;
    margin: 0 0 8px;
    letter-spacing: 2px;
  }

  .brand-desc {
    font-size: 14px;
    opacity: 0.75;
    margin: 0 0 40px;
  }

  .brand-features {
    display: flex;
    flex-direction: column;
    gap: 12px;

    span {
      font-size: 13px;
      opacity: 0.85;
      display: flex;
      align-items: center;
      gap: 8px;

      &::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #ffb3b3;
      }
    }
  }
}

.form-side {
  width: 400px;
  background: #fff;
  padding: 48px 40px;
  display: flex;
  flex-direction: column;
  justify-content: center;

  .form-title {
    font-size: 22px;
    margin: 0 0 6px;
    color: var(--sn-text);
  }

  .form-sub {
    font-size: 12px;
    color: var(--sn-text-secondary);
    margin: 0 0 28px;
  }

  .submit-btn {
    width: 100%;
    letter-spacing: 4px;
  }
}

.login-tips {
  margin-top: 12px;
  text-align: center;
  font-size: 12px;
  color: #9ca3af;
}

@media (max-width: 760px) {
  .brand-side {
    display: none;
  }
  .login-card {
    width: 400px;
  }
}
</style>
