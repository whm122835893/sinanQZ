<script setup lang="ts">
// 登录页：左侧品牌区（司南红渐变）+ 右侧登录表单
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useAdminStore } from '@/stores/admin'
import { login, changePassword } from '@/api/auth'

const route = useRoute()
const router = useRouter()
const adminStore = useAdminStore()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive({
  username: '',
  password: ''
})

const rules: FormRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }]
}

async function handleLogin(): Promise<void> {
  const formEl = formRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    const result = await login({ username: form.username, password: form.password })
    adminStore.setLogin(result)

    if (result.mustChangePwd) {
      // 首次登录/被重置密码：强制修改后再进入
      resetPwd.value = true
      return
    }
    gotoRedirect()
  } catch {
    // 错误提示已由拦截器统一处理
  } finally {
    submitting.value = false
  }
}

function gotoRedirect(): void {
  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
  router.push(redirect)
}

// ---------------- 强制改密（mustChangePwd） ----------------
const resetPwd = ref(false)
const resetSubmitting = ref(false)
const resetFormRef = ref<FormInstance>()
const resetForm = reactive({
  oldPassword: '',
  newPassword: '',
  confirmPassword: ''
})

const resetRules: FormRules = {
  oldPassword: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  newPassword: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '密码长度至少8位', trigger: 'blur' },
    { pattern: /^(?=.*[A-Za-z])(?=.*\d).+$/, message: '密码必须同时包含字母和数字', trigger: 'blur' }
  ],
  confirmPassword: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_rule, value: string, callback) => {
        if (value !== resetForm.newPassword) callback(new Error('两次输入的密码不一致'))
        else callback()
      },
      trigger: 'blur'
    }
  ]
}

async function submitReset(): Promise<void> {
  const formEl = resetFormRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  resetSubmitting.value = true
  try {
    await changePassword({
      oldPassword: resetForm.oldPassword,
      newPassword: resetForm.newPassword
    })
    ElMessage.success('密码已修改，请使用新密码重新登录')
    resetPwd.value = false
    adminStore.reset()
    form.password = ''
    resetForm.oldPassword = ''
    resetForm.newPassword = ''
    resetForm.confirmPassword = ''
  } catch {
    // 拦截器已提示
  } finally {
    resetSubmitting.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <!-- 左：品牌区 -->
    <div class="brand-panel">
      <div class="brand-inner">
        <div class="brand-logo">
          <el-icon :size="34"><Compass /></el-icon>
          <span>司南艺术</span>
        </div>
        <h1 class="brand-title">数字藏品运营管理后台</h1>
        <p class="brand-desc">藏品发行 · 盲盒运营 · 市场监管 · 用户服务</p>
        <div class="brand-marks">
          <span class="mark-item">持仓安全</span>
          <span class="mark-item">链上可信</span>
          <span class="mark-item">合规运营</span>
        </div>
      </div>
      <div class="brand-deco deco-1" />
      <div class="brand-deco deco-2" />
    </div>

    <!-- 右：登录表单 -->
    <div class="form-panel">
      <div class="form-box">
        <div class="form-header">
          <h2>欢迎回来</h2>
          <p>请使用管理员账号登录系统</p>
        </div>

        <el-form
          ref="formRef"
          :model="form"
          :rules="rules"
          size="large"
          @keyup.enter="handleLogin"
          @submit.prevent
        >
          <el-form-item prop="username">
            <el-input v-model="form.username" placeholder="用户名" :prefix-icon="'User'" clearable />
          </el-form-item>
          <el-form-item prop="password">
            <el-input
              v-model="form.password"
              type="password"
              placeholder="密码"
              :prefix-icon="'Lock'"
              show-password
            />
          </el-form-item>
          <el-form-item>
            <el-button class="login-btn" type="primary" :loading="submitting" @click="handleLogin">
              登 录
            </el-button>
          </el-form-item>
        </el-form>

        <div class="form-footer">司南艺术数字藏品平台 · 管理后台 P0</div>
      </div>
    </div>

    <!-- 强制改密弹窗 -->
    <el-dialog
      v-model="resetPwd"
      title="首次登录请修改密码"
      width="420px"
      :close-on-click-modal="false"
      :close-on-press-escape="false"
      :show-close="false"
      append-to-body
    >
      <el-alert
        type="warning"
        :closable="false"
        show-icon
        title="当前账号为初始密码，为保障安全请先修改登录密码"
        style="margin-bottom: 16px"
      />
      <el-form
        ref="resetFormRef"
        :model="resetForm"
        :rules="resetRules"
        label-width="82px"
        @submit.prevent
      >
        <el-form-item label="原密码" prop="oldPassword">
          <el-input v-model="resetForm.oldPassword" type="password" show-password placeholder="当前登录密码" />
        </el-form-item>
        <el-form-item label="新密码" prop="newPassword">
          <el-input v-model="resetForm.newPassword" type="password" show-password placeholder="至少8位，含字母和数字" />
        </el-form-item>
        <el-form-item label="确认密码" prop="confirmPassword">
          <el-input v-model="resetForm.confirmPassword" type="password" show-password placeholder="再次输入新密码" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="primary" :loading="resetSubmitting" @click="submitReset">
          确认修改并重新登录
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.login-page {
  height: 100%;
  display: flex;
  background: $sn-card;
}

// 左侧品牌区（司南红）
.brand-panel {
  flex: 1.15;
  position: relative;
  background: $sn-gradient-ink;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;

  &::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
      radial-gradient(ellipse 60% 50% at 20% 15%, rgba(192, 0, 0, 0.5), transparent 60%),
      radial-gradient(ellipse 45% 40% at 85% 85%, rgba(212, 165, 116, 0.22), transparent 65%);
  }

  .brand-deco {
    position: absolute;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.08);

    &.deco-1 {
      width: 420px;
      height: 420px;
      top: -140px;
      right: -120px;
    }

    &.deco-2 {
      width: 300px;
      height: 300px;
      bottom: -100px;
      left: -80px;
    }
  }

  .brand-inner {
    position: relative;
    z-index: 1;
    color: #fff;
    padding: 0 56px;
  }

  .brand-logo {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 20px;
    font-weight: 600;
    letter-spacing: 2px;
    margin-bottom: 28px;

    .el-icon {
      padding: 8px;
      border-radius: 10px;
      background: $sn-gradient-primary;
      box-shadow: 0 6px 18px rgba(192, 0, 0, 0.4);
    }
  }

  .brand-title {
    font-size: 30px;
    font-weight: 600;
    line-height: 1.4;
    margin: 0 0 14px;
    background: linear-gradient(120deg, #ffffff 30%, #e8b873 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
  }

  .brand-desc {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.55);
    margin: 0 0 36px;
    letter-spacing: 1px;
  }

  .brand-marks {
    display: flex;
    gap: 10px;

    .mark-item {
      padding: 6px 14px;
      border-radius: 999px;
      border: 1px solid rgba(212, 165, 116, 0.35);
      color: $sn-gold;
      font-size: 12px;
      letter-spacing: 1px;
    }
  }
}

// 右侧表单区
.form-panel {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: $sn-bg;
}

.form-box {
  width: 360px;
  padding: 40px 36px;
  background: $sn-card;
  border-radius: $sn-radius-card;
  box-shadow: $sn-shadow-card;
}

.form-header {
  margin-bottom: 28px;

  h2 {
    font-size: 22px;
    font-weight: 600;
    color: $sn-text-main;
    margin: 0 0 6px;
  }

  p {
    font-size: 13px;
    color: $sn-text-muted;
    margin: 0;
  }
}

.login-btn {
  width: 100%;
  height: 42px;
  font-size: 15px;
  letter-spacing: 6px;
  background: $sn-gradient-primary;
  border: none;
  box-shadow: 0 6px 16px rgba(192, 0, 0, 0.26);

  &:hover {
    opacity: 0.92;
  }
}

.form-footer {
  margin-top: 8px;
  text-align: center;
  font-size: 12px;
  color: $sn-text-muted;
}

// 窄屏：隐藏品牌区
@media (max-width: 900px) {
  .brand-panel {
    display: none;
  }
}
</style>
