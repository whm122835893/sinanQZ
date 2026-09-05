<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useAppStore } from '@/stores/app'
import { useAdminStore } from '@/stores/admin'
import { logout } from '@/api'
import { menuGroups } from '@/router/menu'

// ============================================================
// 顶部 Header：折叠按钮 + 面包屑 + 全局搜索 + 全屏 + 用户下拉
// ============================================================

const app = useAppStore()
const admin = useAdminStore()
const route = useRoute()
const router = useRouter()

// ---- 面包屑（顶级分组 / 当前页）----
const breadcrumb = computed(() => {
  for (const g of menuGroups) {
    const hit = g.items.find((i) => i.path === route.path)
    if (hit) return [{ title: g.group }, { title: hit.title }]
  }
  return [{ title: '控制台' }]
})

// ---- 全局搜索（菜单跳转）----
const searchKey = ref('')
const searchOptions = computed(() =>
  menuGroups.flatMap((g) =>
    g.items.map((i) => ({
      value: i.path,
      label: `${g.group} / ${i.title}`
    }))
  )
)
function onSearch(path) {
  if (path) router.push(path)
  searchKey.value = ''
}

// ---- 全屏 ----
const isFullscreen = ref(false)
function toggleFullscreen() {
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen()
    isFullscreen.value = true
  } else {
    document.exitFullscreen()
    isFullscreen.value = false
  }
}

// ---- 退出登录 ----
async function onLogout() {
  await ElMessageBox.confirm('确认退出当前账号？', '退出登录', { type: 'warning' })
  await logout()
  admin.clearSession()
  ElMessage.success('已退出登录')
  router.replace('/login')
}
</script>

<template>
  <header class="app-header">
    <!-- 左：折叠 / 抽屉触发 + 面包屑 -->
    <div class="app-header__left">
      <el-icon class="app-header__fold" :size="18" @click="app.isMobile ? app.toggleDrawer() : app.toggleSidebar()">
        <Fold v-if="!app.sidebarCollapsed && !app.isMobile" />
        <Expand v-else />
      </el-icon>
      <el-breadcrumb separator="/">
        <el-breadcrumb-item v-for="(b, i) in breadcrumb" :key="i">{{ b.title }}</el-breadcrumb-item>
      </el-breadcrumb>
    </div>

    <!-- 右：搜索 + 全屏 + 用户 -->
    <div class="app-header__right">
      <el-select
        v-model="searchKey"
        filterable
        placeholder="搜索菜单..."
        class="app-header__search"
        @change="onSearch"
      >
        <el-option v-for="o in searchOptions" :key="o.value" :label="o.label" :value="o.value" />
      </el-select>

      <el-tooltip content="全屏切换" placement="bottom">
        <el-icon class="app-header__action" :size="17" @click="toggleFullscreen">
          <FullScreen />
        </el-icon>
      </el-tooltip>

      <el-dropdown trigger="click">
        <div class="app-header__user">
          <img class="app-header__avatar" :src="admin.info?.avatar || '/images/avatar-new.png'" alt="avatar" />
          <div class="app-header__user-info">
            <div class="app-header__name">{{ admin.displayName }}</div>
            <div class="app-header__role">{{ admin.roleLabel }}</div>
          </div>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item disabled>
              <el-icon><User /></el-icon>{{ admin.info?.username || 'admin' }}
            </el-dropdown-item>
            <el-dropdown-item divided @click="onLogout">
              <el-icon><SwitchButton /></el-icon>退出登录
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>
  </header>
</template>

<style scoped lang="scss">
.app-header {
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  background: rgba(255, 255, 255, 0.88);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid $color-border;
  position: sticky;
  top: 0;
  z-index: 90;
  flex-shrink: 0;
}

.app-header__left {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.app-header__fold {
  cursor: pointer;
  color: $color-text-secondary;
  flex-shrink: 0;

  &:hover { color: $color-primary; }
}

.app-header__right {
  display: flex;
  align-items: center;
  gap: 16px;
}

.app-header__search {
  width: 180px;

  @media (max-width: 768px) {
    display: none;
  }
}

.app-header__action {
  cursor: pointer;
  color: $color-text-secondary;

  &:hover { color: $color-primary; }
}

.app-header__user {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 6px;

  &:hover { background: $color-bg; }
}

.app-header__avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  object-fit: cover;
  border: 1.5px solid rgba(192, 0, 0, 0.25);
}

.app-header__user-info {
  line-height: 1.25;

  @media (max-width: 768px) {
    display: none;
  }
}

.app-header__name {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
}

.app-header__role {
  font-size: 11px;
  color: $color-text-tertiary;
}
</style>
