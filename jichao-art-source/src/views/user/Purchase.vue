<script setup>
import { ref, computed } from 'vue'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'

const tabs = [
  { key: 'all', label: '全部' },
  { key: 'pending', label: '待支付' },
  { key: 'ongoing', label: '进行中' },
  { key: 'canceled', label: '已取消' }
]
const active = ref('all')

// 转赠功能暂未上线，记录为空
const orders = []

const list = computed(() =>
  active.value === 'all' ? orders : orders.filter(o => o.status === active.value)
)
</script>

<template>
  <div class="purchase page--no-tabbar">
    <AppNavBar title="转赠记录" @click-left="$router.back()" />

    <div class="purchase-tabs">
      <div
        v-for="tab in tabs"
        :key="tab.key"
        class="purchase-tabs__item"
        :class="{ active: active === tab.key }"
        @click="active = tab.key"
      >{{ tab.label }}</div>
    </div>

    <AppEmpty v-if="!list.length" description="暂无相关转赠" />
  </div>
</template>

<style scoped lang="scss">
.purchase-tabs {
  display: flex; gap: 10px; padding: 14px $page-padding; background: $color-card; margin-bottom: 8px;
  &__item {
    flex: 1; text-align: center; padding: 8px 0; font-size: 14px; cursor: pointer;
    border-radius: $radius-pill; background: $color-surface; color: $color-text-secondary;
  }
  &__item.active { background: $color-primary; color: #fff; font-weight: 600; }
}
</style>
