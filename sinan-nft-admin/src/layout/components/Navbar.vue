<script setup lang="ts">
/**
 * 顶栏：折叠按钮 + 面包屑 + 管理员下拉
 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { Fold, Expand, User, SwitchButton } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'

defineProps<{ collapse: boolean }>()
const emit = defineEmits<{ (e: 'toggle-collapse'): void }>()

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const breadcrumbs = computed(() => {
  const matched = route.matched.filter((r) => r.meta?.title)
  return matched.map((r) => ({ title: r.meta.title as string, path: r.path }))
})

async function handleCommand(cmd: string) {
  if (cmd === 'profile') {
    router.push('/profile')
  } else if (cmd === 'logout') {
    try {
      await ElMessageBox.confirm('确定退出登录吗？', '提示', { type: 'warning', confirmButtonText: '退出', cancelButtonText: '取消' })
      await auth.logout()
      router.push('/login')
    } catch {
      /* 取消 */
    }
  }
}
</script>

<template>
  <header class="navbar">
    <div class="left">
      <el-icon class="collapse-btn" :size="18" @click="emit('toggle-collapse')">
        <component :is="collapse ? Expand : Fold" />
      </el-icon>
      <el-breadcrumb separator="/">
        <el-breadcrumb-item v-for="item in breadcrumbs" :key="item.path" :to="item.path">
          {{ item.title }}
        </el-breadcrumb-item>
      </el-breadcrumb>
    </div>

    <div class="right">
      <el-dropdown trigger="click" @command="handleCommand">
        <div class="admin-chip">
          <el-icon class="avatar-icon" :size="22"><User /></el-icon>
          <div class="admin-meta">
            <span class="name">{{ auth.displayName }}</span>
            <span class="role">{{ auth.adminInfo?.roleName || '-' }}</span>
          </div>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="profile" :icon="User">个人中心</el-dropdown-item>
            <el-dropdown-item command="logout" :icon="SwitchButton" divided>退出登录</el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>
  </header>
</template>

<style scoped lang="scss">
.navbar {
  height: var(--sn-header-height);
  background: #fff;
  border-bottom: 1px solid var(--sn-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  flex-shrink: 0;
}

.left {
  display: flex;
  align-items: center;
  gap: 12px;

  .collapse-btn {
    cursor: pointer;
    color: var(--sn-text-secondary);
    &:hover {
      color: var(--sn-red);
    }
  }
}

.admin-chip {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 8px;

  &:hover {
    background: #f5f5f6;
  }

  .avatar-icon {
    color: var(--sn-red);
    background: var(--sn-red-bg);
    padding: 4px;
    border-radius: 50%;
  }

  .admin-meta {
    display: flex;
    flex-direction: column;
    line-height: 1.2;

    .name {
      font-size: 13px;
      font-weight: 600;
    }
    .role {
      font-size: 11px;
      color: var(--sn-text-secondary);
    }
  }
}
</style>
