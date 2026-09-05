<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAppStore } from '@/stores/app'

// ============================================================
// 多标签页导航：当前标签主色下划线，支持关闭 / 关闭其他 / 关闭全部
// ============================================================

const app = useAppStore()
const route = useRoute()
const router = useRouter()

const activePath = computed(() => route.path)

function isActive(path) {
  return path === activePath.value
}

function go(path) {
  router.push(path)
}

function closeTag(path) {
  if (app.visitedTags.length === 1) return
  const next = app.removeTag(path)
  if (path === activePath.value && next !== path) router.push(next)
}

function closeOthers() {
  app.removeOtherTags(activePath.value)
}

function closeAll() {
  app.removeAllTags()
  app.addTag(route)
}
</script>

<template>
  <div v-if="app.visitedTags.length" class="tags-view">
    <el-scrollbar class="tags-view__scroll">
      <div class="tags-view__list">
        <div
          v-for="tag in app.visitedTags"
          :key="tag.path"
          class="tags-view__tag"
          :class="{ 'is-active': isActive(tag.path) }"
          @click="go(tag.path)"
          @contextmenu.prevent="go(tag.path)"
        >
          <span class="tags-view__dot" />
          <span class="tags-view__title">{{ tag.title }}</span>
          <el-icon
            v-if="app.visitedTags.length > 1"
            class="tags-view__close"
            :size="12"
            @click.stop="closeTag(tag.path)"
          >
            <Close />
          </el-icon>
        </div>
      </div>
    </el-scrollbar>

    <el-dropdown class="tags-view__ops" @command="(cmd) => cmd === 'others' ? closeOthers() : closeAll()">
      <el-icon :size="15"><ArrowDown /></el-icon>
      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item command="others">关闭其他</el-dropdown-item>
          <el-dropdown-item command="all">关闭全部</el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>
  </div>
</template>

<style scoped lang="scss">
.tags-view {
  display: flex;
  align-items: center;
  height: 38px;
  background: rgba(255, 255, 255, 0.75);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid $color-border;
  padding: 0 8px;
  flex-shrink: 0;
}

.tags-view__scroll {
  flex: 1;
}

.tags-view__list {
  display: flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}

.tags-view__tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  height: 26px;
  padding: 0 8px;
  border-radius: 4px;
  font-size: 12px;
  color: $color-text-secondary;
  background: $color-surface;
  cursor: pointer;
  border: 1px solid transparent;
  transition: all 0.15s ease;
  position: relative;

  &:hover {
    color: $color-primary;
  }

  &.is-active {
    background: #fff;
    color: $color-primary;
    border-color: rgba(192, 0, 0, 0.2);

    &::after {
      content: '';
      position: absolute;
      left: 8px;
      right: 8px;
      bottom: -1px;
      height: 2px;
      background: $color-primary;
      border-radius: 1px;
    }
  }
}

.tags-view__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: $color-text-tertiary;
}

.tags-view__tag.is-active .tags-view__dot {
  background: $color-primary;
}

.tags-view__close {
  border-radius: 50%;
  padding: 1px;

  &:hover {
    background: rgba(192, 0, 0, 0.12);
    color: $color-primary;
  }
}

.tags-view__ops {
  cursor: pointer;
  color: $color-text-secondary;
  padding: 0 6px;

  &:hover { color: $color-primary; }
}
</style>
