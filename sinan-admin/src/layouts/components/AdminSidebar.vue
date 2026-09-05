<script setup>
import { useRoute } from 'vue-router'
import { menuGroups } from '@/router/menu'

const route = useRoute()

function isActive(item) {
  if (item.path === '/dashboard') return route.path === item.path
  // 父级高亮：/collectible/edit/9001 → /collectible
  if (route.path.startsWith(item.path + '/')) return true
  return route.path === item.path
}
</script>

<template>
  <aside class="sidebar thin-scrollbar">
    <div class="sidebar__brand">
      <img class="sidebar__logo" src="/images/platform-logo.png" alt="logo" />
      <div class="sidebar__brandtext">
        <div class="name"><span class="calligraphy">司南</span>珍藏</div>
        <div class="sub">管理后台 · ADMIN</div>
      </div>
    </div>

    <nav class="sidebar__nav">
      <div v-for="g in menuGroups" :key="g.group" class="sidebar__group">
        <div class="sidebar__group-label">{{ g.group }}</div>
        <router-link
          v-for="item in g.items"
          :key="item.path"
          :to="item.path"
          class="sidebar__item"
          :class="{ 'is-active': isActive(item) }"
        >
          <van-icon :name="item.icon" size="17" />
          <span>{{ item.title }}</span>
        </router-link>
      </div>
    </nav>

    <div class="sidebar__footer">
      <img src="/images/brand-text-xiaozhuan.png" alt="brand" />
    </div>
  </aside>
</template>

<style scoped lang="scss">
.sidebar {
  width: $sidebar-width;
  flex-shrink: 0;
  position: sticky;
  top: 0;
  height: 100vh;
  display: flex;
  flex-direction: column;
  background: $color-card;
  border-right: 1px solid $color-border;
}

.sidebar__brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px;
  border-bottom: 1px solid $color-border;

  .name {
    font-size: 16px;
    font-weight: 700;
    color: $color-primary;
  }
  .sub {
    font-size: 10px;
    color: $color-text-tertiary;
    letter-spacing: 1px;
    margin-top: 1px;
  }
}

.sidebar__logo {
  width: 36px;
  height: 36px;
  border-radius: $radius-md;
  object-fit: cover;
}

.sidebar__nav {
  flex: 1;
  overflow-y: auto;
  padding: 10px 10px 16px;
}

.sidebar__group {
  margin-bottom: 6px;
}

.sidebar__group-label {
  font-size: 11px;
  color: $color-text-tertiary;
  padding: 10px 10px 4px;
  letter-spacing: 1px;
}

.sidebar__item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 9px 10px;
  border-radius: $radius-md;
  font-size: 13.5px;
  color: $color-text-secondary;
  margin-bottom: 2px;
  position: relative;
  transition: all 0.15s ease;

  &:hover {
    color: $color-primary;
    background: rgba(192, 0, 0, 0.04);
  }

  &.is-active {
    color: $color-primary;
    background: var(--color-primary-bg);
    font-weight: 600;

    &::before {
      content: '';
      position: absolute;
      left: -10px;
      top: 50%;
      transform: translateY(-50%);
      width: 3px;
      height: 18px;
      border-radius: 0 3px 3px 0;
      background: linear-gradient(180deg, $color-primary-dark, $color-primary);
    }
  }
}

.sidebar__footer {
  padding: 12px 16px;
  border-top: 1px solid $color-border;
  display: flex;
  justify-content: center;

  img {
    height: 22px;
    opacity: 0.55;
    object-fit: contain;
  }
}
</style>
