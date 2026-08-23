<script setup>
import AppNavBar from '@/components/AppNavBar.vue'
import AppTag from '@/components/AppTag.vue'

const timeline = [
  { time: '2026年07月01日 10:28', title: '司南暴富', count: '500份', price: '0.10', status: '已售罄' },
  { time: '2026年07月05日 14:00', title: '龙纹罗盘', count: '300份', price: '0.20', status: '已售罄' },
  { time: '2026年07月11日 20:00', title: '虎纹卡牌', count: '200份', price: '0.30', status: '已售罄' }
]
</script>

<template>
  <div class="calendar page--no-tabbar">
    <AppNavBar title="首发日历" @click-left="$router.back()">
      <template #right>
        <span class="calligraphy cal-logo">司</span>
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
            <div class="timeline__thumb calligraphy">司</div>
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
.cal-logo { font-size: 22px; color: #C00000; font-weight: 700; }
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
  width: 56px; height: 56px; border-radius: 8px; background: $color-primary-light; color: #C00000;
  display: flex; align-items: center; justify-content: center; font-size: 30px; flex-shrink: 0;
}
.timeline__info { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.timeline__title { font-size: 16px; font-weight: 700; color: $color-text-primary; }
.timeline__meta { display: flex; gap: 16px; font-size: 13px; color: $color-text-secondary; b { color: $color-text-primary; font-weight: 600; } }
</style>
