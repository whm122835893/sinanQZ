<script setup lang="ts">
// 顶栏：折叠开关 + 面包屑 + 管理员信息（改密/退出）
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useAppStore } from '@/stores/app'
import { useAdminStore } from '@/stores/admin'
import { usePermissionStore } from '@/stores/permission'
import { logout, changePassword } from '@/api/auth'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const adminStore = useAdminStore()
const permissionStore = usePermissionStore()

/** 面包屑：当前路由命中的菜单链 */
const breadcrumbs = computed(() => {
  const crumbs: { title: string; path?: string }[] = []
  for (const matched of route.matched) {
    if (matched.meta.title && matched.name !== 'root') {
      crumbs.push({ title: matched.meta.title, path: matched.path })
    }
  }
  return crumbs
})

// ---------------- 修改密码 ----------------
const pwdDialogVisible = ref(false)
const pwdSubmitting = ref(false)
const pwdFormRef = ref<FormInstance>()
const pwdForm = reactive({
  oldPassword: '',
  newPassword: '',
  confirmPassword: ''
})

const pwdRules: FormRules = {
  oldPassword: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  newPassword: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '密码长度至少8位', trigger: 'blur' },
    {
      pattern: /^(?=.*[A-Za-z])(?=.*\d).+$/,
      message: '密码必须同时包含字母和数字',
      trigger: 'blur'
    }
  ],
  confirmPassword: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_rule, value: string, callback) => {
        if (value !== pwdForm.newPassword) {
          callback(new Error('两次输入的密码不一致'))
        } else {
          callback()
        }
      },
      trigger: 'blur'
    }
  ]
}

function openPwdDialog(): void {
  pwdForm.oldPassword = ''
  pwdForm.newPassword = ''
  pwdForm.confirmPassword = ''
  pwdDialogVisible.value = true
}

async function submitPwd(): Promise<void> {
  const form = pwdFormRef.value
  if (!form) return
  const valid = await form.validate().catch(() => false)
  if (!valid) return

  pwdSubmitting.value = true
  try {
    await changePassword({
      oldPassword: pwdForm.oldPassword,
      newPassword: pwdForm.newPassword
    })
    pwdDialogVisible.value = false
    ElMessage.success('密码已修改，请使用新密码重新登录')
    doReset()
  } catch {
    // 错误已由拦截器统一提示
  } finally {
    pwdSubmitting.value = false
  }
}

// ---------------- 退出登录 ----------------
async function handleLogout(): Promise<void> {
  try {
    await logout()
  } catch {
    // 登出失败不阻塞本地清理
  }
  doReset()
  ElMessage.success('已退出登录')
}

function doReset(): void {
  adminStore.reset()
  permissionStore.reset()
  appStore.reset()
  router.push('/login')
}
</script>

<template>
  <div class="header">
    <!-- 左：折叠开关 + 面包屑 -->
    <div class="header-left">
      <el-tooltip :content="appStore.sidebarFolded ? '展开菜单' : '收起菜单'" placement="bottom">
        <el-button
          class="fold-btn"
          text
          @click="appStore.toggleSidebar()"
        >
          <el-icon :size="18">
            <Expand v-if="appStore.sidebarFolded" />
            <Fold v-else />
          </el-icon>
        </el-button>
      </el-tooltip>

      <el-breadcrumb separator="/">
        <el-breadcrumb-item
          v-for="(crumb, idx) in breadcrumbs"
          :key="crumb.title + idx"
          :to="idx < breadcrumbs.length - 1 && crumb.path ? crumb.path : undefined"
        >
          {{ crumb.title }}
        </el-breadcrumb-item>
      </el-breadcrumb>
    </div>

    <!-- 右：管理员信息 -->
    <div class="header-right">
      <el-dropdown trigger="click" @command="(cmd: string) => cmd === 'password' ? openPwdDialog() : handleLogout()">
        <div class="admin-chip">
          <div class="admin-avatar">
            {{ (adminStore.admin?.realName || adminStore.admin?.username || '管').slice(0, 1) }}
          </div>
          <div class="admin-meta">
            <span class="admin-name">{{ adminStore.admin?.realName || adminStore.admin?.username }}</span>
            <span class="admin-role">{{ adminStore.admin?.roleName }}</span>
          </div>
          <el-icon class="admin-caret"><ArrowDown /></el-icon>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="password">
              <el-icon><Lock /></el-icon>修改密码
            </el-dropdown-item>
            <el-dropdown-item command="logout" divided>
              <el-icon><SwitchButton /></el-icon>退出登录
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>
  </div>

  <!-- 修改密码弹窗 -->
  <el-dialog
    v-model="pwdDialogVisible"
    title="修改登录密码"
    width="420px"
    :close-on-click-modal="false"
    append-to-body
  >
    <el-form
      ref="pwdFormRef"
      :model="pwdForm"
      :rules="pwdRules"
      label-width="82px"
      @submit.prevent
    >
      <el-form-item label="原密码" prop="oldPassword">
        <el-input v-model="pwdForm.oldPassword" type="password" show-password placeholder="请输入原密码" />
      </el-form-item>
      <el-form-item label="新密码" prop="newPassword">
        <el-input v-model="pwdForm.newPassword" type="password" show-password placeholder="至少8位，含字母和数字" />
      </el-form-item>
      <el-form-item label="确认密码" prop="confirmPassword">
        <el-input v-model="pwdForm.confirmPassword" type="password" show-password placeholder="再次输入新密码" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="pwdDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="pwdSubmitting" @click="submitPwd">确认修改</el-button>
    </template>
  </el-dialog>
</template>

<style scoped lang="scss">
.header {
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;

  .fold-btn {
    padding: 6px;
    color: $sn-text-sub;

    &:hover {
      color: $sn-primary;
    }
  }
}

.header-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.admin-chip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 8px;
  border-radius: 8px;
  cursor: pointer;
  outline: none;

  &:hover {
    background: $sn-surface;
  }

  .admin-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: $sn-gradient-primary;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .admin-meta {
    display: flex;
    flex-direction: column;
    line-height: 1.2;

    .admin-name {
      font-size: 13px;
      font-weight: 500;
      color: $sn-text-main;
    }

    .admin-role {
      font-size: 11px;
      color: $sn-text-muted;
    }
  }

  .admin-caret {
    font-size: 12px;
    color: $sn-text-muted;
  }
}
</style>
