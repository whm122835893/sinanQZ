<script setup>
import { computed } from 'vue'
import AppNavBar from '@/components/AppNavBar.vue'
import AppTag from '@/components/AppTag.vue'
import { useCollectionStore } from '@/stores/collection'

const store = useCollectionStore()

function formatDate(ts) {
  if (!ts) return ''
  const d = new Date(ts)
  const pad = (n) => String(n).padStart(2, '0')
  return d.getFullYear() + '年' + pad(d.getMonth() + 1) + '月' + pad(d.getDate()) + '日 ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
}

const STATUS_MAP = { countdown: '待发售', selling: '发售中', soldout: '已售罄' }

const timeline = computed(() =>
  store.featured.map((item) => ({
    time: formatDate(item.saleTime),
    title: item.name,
    count: item.total,
    price: item.price,
    coverImage: item.coverImage,
    status: STATUS_MAP[store.getSaleStatus(item)] || '已售罄'
  }))
)
</script>

<template>
  <div class="calendar page--no-tabbar">
    <AppNavBar title="首发日历" @click-left="$router.back()">
      <template #right>
        <img class="cal-logo-img" src="/images/platform-logo.png" alt="" />
      </template>
    </AppNavBar>

    <p class="calendar-sub">独家发售，先到先得</p>

    <ul class="timeline">
      <li v-for="(item, i) in timeline" :key="i" class="timeline__item">
        <div class="timeline__axis">
          <span class="timeline__dot"></span>
          <span v-if="i < timeline.length - 1" class="timeline__line"></span>
        </div>
        <div class="timeline__content">
          <div class="timeline__time">
            <span>{{ item.time }}</span>
            <AppTag type="gray">{{ item.status }}</AppTag>
          </div>
          <div class="timeline__card">
            <img class="timeline__thumb" :src="item.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
            <div class="timeline__info">
              <span class="timeline__title">{{ item.title }}</span>
              <div class="timeline__meta">
                <span>发售数量 <b>{{ item.count }}</b></span>
                <span>发售价格 <b class="price">¥{{ item.price }}</b></span>
              </div>
            </div>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped lang="scss">
.cal-logo-img { width: 28px; height: 28px; border-radius: 6px; object-fit: cover; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
.calendar-sub { margin: 14px $page-padding; font-size: 14px; color: $color-text-secondary; }

.timeline { padding: 0 $page-padding 24px; }
.timeline__item { display: flex; gap: 12px; }
.timeline__axis { display: flex; flex-direction: column; align-items: center; padding-top: 4px; }
.timeline__dot {
  width: 12px; height: 12px; border-radius: 50%; border: 2px solid $color-text-tertiary; background: #fff; flex-shrink: 0;
}
.timeline__line { width: 2px; flex: 1; background: $color-border; margin: 4px 0; }
.timeline__content { flex: 1; padding-bottom: 16px; }
.timeline__time { display: flex; align-items: center; gap: 10px; font-size: 14px; color: $color-text-secondary; margin-bottom: 10px; }
.timeline__card {
  display: flex; gap: 12px; background: $color-card; border-radius: $radius-lg; padding: 16px;
}
.timeline__thumb {
  width: 56px; height: 56px; border-radius: 8px; object-fit: cover; flex-shrink: 0;
  -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
}
.timeline__info { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.timeline__title { font-size: 16px; font-weight: 700; color: $color-text-primary; }
.timeline__meta { display: flex; gap: 16px; font-size: 13px; color: $color-text-secondary; b { color: $color-text-primary; font-weight: 600; } }
</style>
