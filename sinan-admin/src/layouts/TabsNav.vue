<script setup lang="ts">
// 多标签页导航：访问过的路由页签（固定页签不可关闭）
import { watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAppStore, type TagView } from '@/stores/app'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()

/** 关闭页签：跳到相邻页签 */
function closeTab(view: TagView): void {
  if (view.affix) return
  const views = appStore.removeVisitedView(view.path)

  if (route.path === view.path) {
    const next = views[views.length - 1]
    if (next) {
      router.push(next.path)
    } else {
      router.push('/dashboard')
    }
  }
}

/** 关闭其他（保留固定页签） */
function closeOthers(): void {
  appStore.visitedViews
    .filter((v) => !v.affix)
    .forEach((v) => appStore.removeVisitedView(v.path))
  if (!appStore.visitedViews.some((v) => v.path === route.path)) {
    router.push('/dashboard')
  }
}

/** 确保当前路由已在页签中（刷新后重建场景） */
watch(
  () => route.path,
  () => {
    if (route.name && !['login', 'not-found', '403'].includes(String(route.name))) {
      appStore.addVisitedView({
        path: route.path,
        title: route.meta.title ?? '未命名',
        name: String(route.name),
        affix: !!route.meta.affix
      })
    }
  },
  { immediate: true }
)
</script>

<template>
  <div class="tabs-nav">
    <div class="tabs-scroll">
      <div
        v-for="view in appStore.visitedViews"
        :key="view.path"
        class="tab-item"
        :class="{ active: view.path === route.path }"
        @click="router.push(view.path)"
      >
        <span class="tab-title">{{ view.title }}</span>
        <el-icon
          v-if="!view.affix"
          class="tab-close"
          @click.stop="closeTab(view)"
        >
          <Close />
        </el-icon>
      </div>
    </div>

    <el-dropdown trigger="click" @command="(cmd: string) => cmd === 'closeOthers' && closeOthers()">
      <el-button class="tabs-more" text size="small">
        <el-icon><ArrowDown /></el-icon>
      </el-button>
      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item command="closeOthers">关闭其他页签</el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>
  </div>
</template>

<style scoped lang="scss">
.tabs-nav {
  height: 100%;
  display: flex;
  align-items: center;
  gap: 4px;
}

.tabs-scroll {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 4px;
  overflow-x: auto;
  overflow-y: hidden;
  height: 100%;

  &::-webkit-scrollbar {
    height: 0;
  }
}

.tab-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 30px;
  padding: 0 10px;
  border-radius: 6px;
  background: $sn-surface;
  color: $sn-text-sub;
  font-size: 12px;
  cursor: pointer;
  white-space: nowrap;
  border: 1px solid transparent;
  transition: all 0.15s ease;

  &:hover {
    color: $sn-primary;
  }

  &.active {
    background: #fff;
    color: $sn-primary;
    border-color: rgba(192, 0, 0, 0.28);
  }

  .tab-close {
    font-size: 12px;
    border-radius: 50%;
    padding: 1px;

    &:hover {
      background: rgba(192, 0, 0, 0.12);
      color: $sn-primary;
    }
  }
}

.tabs-more {
  flex-shrink: 0;
  color: $sn-text-muted;
}
</style>
