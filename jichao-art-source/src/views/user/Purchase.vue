<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { showToast } from 'vant'

const router = useRouter()

const tabs = [
  { key: 'all', label: '全部' },
  { key: 'pending', label: '待支付' },
  { key: 'ongoing', label: '进行中' },
  { key: 'canceled', label: '已取消' }
]
const active = ref('all')

const orders = [
  { id: 1, cover: '/images/collections/cover-4.jpg', name: '司南·首发·九色鹿', price: 199, qty: 1, status: 'pending', progress: 0 },
  { id: 2, cover: '/images/collections/cover-1.jpg', name: '司南·青铜纹样', price: 399, qty: 1, status: 'ongoing', progress: 64 },
  { id: 3, cover: '/images/collections/cover-2.jpg', name: '司南·雪山之眼', price: 268, qty: 2, status: 'ongoing', progress: 100 },
  { id: 4, cover: '/images/collections/cover-5.jpg', name: '司南·敦煌飞天', price: 1280, qty: 1, status: 'canceled', progress: 0 }
]

const statusMeta = {
  pending: { text: '待支付', cls: 'pending' },
  ongoing: { text: '进行中', cls: 'ongoing' },
  canceled: { text: '已取消', cls: 'canceled' }
}

const list = computed(() =>
  active.value === 'all' ? orders : orders.filter(o => o.status === active.value)
)

function action(o) {
  if (o.status === 'pending') showToast('前往支付')
  else if (o.status === 'ongoing') showToast('查看申购进度')
  else showToast('重新申购')
}
function goDetail(o) {
  router.push({ name: 'collection-detail', params: { id: o.id } })
}
</script>

<template>
  <div class="purchase page--no-tabbar">
    <AppNavBar title="申购订单" @click-left="$router.back()" />

    <div class="purchase-tabs">
      <div
        v-for="tab in tabs"
        :key="tab.key"
        class="purchase-tabs__item"
        :class="{ active: active === tab.key }"
        @click="active = tab.key"
      >{{ tab.label }}</div>
    </div>

    <div class="purchase__list" v-if="list.length">
      <div v-for="o in list" :key="o.id" class="pur-card">
        <div class="pur-card__head">
          <span class="pur-card__no">申购单 {{ o.id }}</span>
          <span class="pur-card__status" :class="statusMeta[o.status].cls">{{ statusMeta[o.status].text }}</span>
        </div>
        <div class="pur-card__body" @click="goDetail(o)">
          <img class="pur-card__cover" :src="o.cover" alt="" />
          <div class="pur-card__info">
            <p class="pur-card__name">{{ o.name }}</p>
            <p class="pur-card__sub">申购价 ¥{{ o.price }} · {{ o.qty }} 份</p>
            <div class="pur-card__bar" v-if="o.status === 'ongoing'">
              <div class="pur-card__bar-fill" :style="{ width: o.progress + '%' }"></div>
            </div>
            <p class="pur-card__hint" v-if="o.status === 'ongoing'">
              {{ o.progress >= 100 ? '已全额申购，等待发货' : '已申购 ' + o.progress + '%' }}
            </p>
          </div>
        </div>
        <div class="pur-card__foot">
          <button class="pur-card__btn" :class="{ ghost: o.status !== 'pending' }" @click="action(o)">
            {{ o.status === 'pending' ? '去支付' : o.status === 'ongoing' ? '查看进度' : '重新申购' }}
          </button>
        </div>
      </div>
    </div>

    <AppEmpty v-else description="暂无相关申购" />
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

.purchase__list { padding: 0 $page-padding; }
.pur-card {
  background: $color-card; border-radius: $radius-lg; padding: 14px; margin-bottom: 12px;
  &__head {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: 12px; border-bottom: 1px solid $color-border;
  }
  &__no { font-size: 12px; color: $color-text-tertiary; font-family: $font-price; }
  &__status { font-size: 12px; font-weight: 600; }
  &__status.pending { color: $color-primary; }
  &__status.ongoing { color: #E8A33D; }
  &__status.canceled { color: $color-text-tertiary; }
  &__body { display: flex; gap: 12px; padding: 12px 0; cursor: pointer; }
  &__cover { width: 60px; height: 60px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: $color-surface; }
  &__info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
  &__name { margin: 0; font-size: 15px; font-weight: 600; color: $color-text-primary; @include ellipsis; }
  &__sub { margin: 0; font-size: 12px; color: $color-text-tertiary; }
  &__bar { height: 6px; border-radius: 3px; background: $color-surface; overflow: hidden; margin-top: 2px; }
  &__bar-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #D00000, #B00000); }
  &__hint { margin: 0; font-size: 12px; color: $color-text-secondary; }
  &__foot { display: flex; justify-content: flex-end; }
  &__btn {
    border: none; background: $color-primary; color: #fff;
    font-size: 13px; height: 32px; padding: 0 18px; border-radius: $radius-pill; cursor: pointer;
    &.ghost { background: #fff; color: $color-primary; border: 1px solid $color-primary; }
  }
}
</style>
