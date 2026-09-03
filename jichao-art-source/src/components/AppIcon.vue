<script setup>
import { computed } from 'vue'

// 统一矢量图标（全部 SVG，禁止位图图标）
const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 24 },
  color: { type: String, default: 'currentColor' }
})

// 每个图标返回 svg 内部内容；filled 表示实心填充
const ICONS = {
  back: { path: '<path d="M15 18l-6-6 6-6"/>' },
  close: { path: '<path d="M6 6l12 12M18 6L6 18"/>' },
  search: { path: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/>' },
  star: { path: '<path d="M12 3l2.6 5.7 6.2.6-4.7 4.2 1.4 6.1L12 16.9 6.5 19.6l1.4-6.1L3.2 9.3l6.2-.6z"/>' },
  starFill: { fill: true, path: '<path d="M12 3l2.6 5.7 6.2.6-4.7 4.2 1.4 6.1L12 16.9 6.5 19.6l1.4-6.1L3.2 9.3l6.2-.6z"/>' },
  heart: { path: '<path d="M12 20.5s-8.2-4.7-10-9.6C.9 7.7 3 4.5 6.3 4.5c2 0 3.6 1 4.6 2.6.3.5 1 .5 1.3 0 1-1.6 2.6-2.6 4.6-2.6 3.3 0 5.4 3.2 4.3 6.4-1.8 4.9-10 9.6-10 9.6z"/>' },
  heartFill: { fill: true, path: '<path d="M12 21s-8.5-4.9-10.3-10C.5 7.6 2.8 4 6.4 4c2.1 0 3.8 1.1 4.8 2.7.4.6 1.2.6 1.6 0C13.8 5.1 15.5 4 17.6 4c3.6 0 5.9 3.6 4.7 7-1.8 5.1-10.3 10-10.3 10z"/>' },
  gift: { path: '<rect x="3.6" y="9.2" width="16.8" height="11" rx="2.4"/><path d="M3.6 13.4h16.8"/><path d="M12 9.2v11"/><path d="M12 9.2C12 9.2 9.9 5.8 7.6 6.8 5.6 7.7 6.4 9.2 12 9.2zM12 9.2c0 0 2.1-3.4 4.4-2.4C18.7 7.7 17.9 9.2 12 9.2z"/>' },
  calendar: { path: '<rect x="3.6" y="5.2" width="16.8" height="15.2" rx="3.2"/><path d="M3.6 9.8h16.8"/><path d="M8.2 3.2v3.6M15.8 3.2v3.6"/><circle cx="12" cy="14.8" r="1.5"/>' },
  cube: { path: '<path d="M12 3l8.2 4.4v9.2L12 21l-8.2-4.4V7.4z"/><path d="M12 12l8.2-4.4M12 12v9M12 12L3.8 7.6"/>' },
  wallet: { path: '<rect x="3.6" y="6.4" width="16.8" height="11.8" rx="2.8"/><path d="M3.6 10.6h16.8"/><circle cx="16.4" cy="13" r="1.5"/>' },
  // 导航栏：现代线性风格
  person: { path: '<circle cx="12" cy="8.6" r="4.3"/><path d="M4.6 20.2c.3-4.1 3.7-6.7 7.4-6.7s7.1 2.6 7.4 6.7"/>' },
  shield: { path: '<path d="M12 2l8 3v6c0 5-3.5 8.5-8 11-4.5-2.5-8-6-8-11V5z"/>' },
  idcard: { path: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.5"/><path d="M5.5 16c.6-2 2-3 3.5-3s2.9 1 3.5 3M14.5 10h4M14.5 13h4M14.5 16h4"/>' },
  community: { path: '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="9" r="2.4"/><path d="M15.5 14.5c2.6.2 5 1.8 5 5.5"/>' },
  headset: { path: '<path d="M4 13v-1a8 8 0 0116 0v1"/><path d="M4 13a2 2 0 012-2h1v6H6a2 2 0 01-2-2v-2zM20 13a2 2 0 00-2-2h-1v6h1a2 2 0 002-2v-2z"/><path d="M20 17v1a3 3 0 01-3 3h-3"/>' },
  ticket: { path: '<path d="M4 7h16v3a2 2 0 000 4v3H4v-3a2 2 0 000-4z"/><path d="M12 7v10" stroke-dasharray="2 2"/>' },
  file: { path: '<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v4h4"/>' },
  arrowRight: { path: '<path d="M9 6l6 6-6 6"/>' },
  chevronLeft: { path: '<path d="M15 5l-7 7 7 7"/>' },
  chevronRight: { path: '<path d="M9 5l7 7-7 7"/>' },
  // 导航栏：现代线性风格（四个圆角方块）
  grid: { fill: true, path: '<rect x="4" y="4" width="6.6" height="6.6" rx="2.4"/><rect x="13.4" y="4" width="6.6" height="6.6" rx="2.4"/><rect x="4" y="13.4" width="6.6" height="6.6" rx="2.4"/><rect x="13.4" y="13.4" width="6.6" height="6.6" rx="2.4"/>' },
  list: { fill: true, path: '<rect x="4" y="5" width="16" height="3.2" rx="1.6"/><rect x="4" y="11" width="16" height="3.2" rx="1.6"/><rect x="4" y="17" width="16" height="3.2" rx="1.6"/>' },
  percent: { path: '<path d="M18.6 5.4L5.4 18.6"/><circle cx="8" cy="8" r="2.7"/><circle cx="16" cy="16" r="2.7"/>' },
  percent2: { path: '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><path d="M15.2 7.3 8.8 13.7"/><circle cx="8.3" cy="8.3" r="2"/><circle cx="11.7" cy="11.7" r="2"/>' },
  activity: { path: '<path d="M4 12 11 5h9a2 2 0 012 2v10a2 2 0 01-2 2h-9z"/><circle cx="16" cy="12" r="1.6"/>' },
  gift2: { path: '<rect x="4" y="10.5" width="16" height="9.5" rx="2.8"/><rect x="3" y="7.8" width="18" height="4.8" rx="2.4"/><path d="M12 7.8V20"/><path d="M12 7.8C12 7.8 9.3 4.4 6.8 5.4 4.8 6.2 5.8 7.8 12 7.8zM12 7.8c0 0 2.7-3.4 5.2-2.4 2 0.8 1 2.4-5.2 2.4z"/>' },
  coin: { path: '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5c0-1.4 1.1-2 2.5-2s2.5.8 2.5 2-1.1 2-2.5 2-2.5.9-2.5 2 1.1 2 2.5 2 2.5-.6 2.5-2"/>' },
  horn: { path: '<path d="M5 5.5h13.5a2.2 2.2 0 012.2 2.2v7a2.2 2.2 0 01-2.2 2.2H10.5L6 20.5v-3.3H5a2.2 2.2 0 01-2.2-2.2v-7A2.2 2.2 0 015 5.5z"/>' },
  shop: { path: '<path d="M4 8h16l-1 12H5z"/><path d="M8 8V6a4 4 0 018 0v2"/><path d="M4 8l1.5-3h13L20 8"/>' },
  relic: { path: '<circle cx="12" cy="12" r="9"/><path d="M8 8h8M9 8v2.4a3 3 0 006 0V8M7.4 8V6.6M16.6 8V6.6M9 14.4L8 19M15 14.4L16 19M12 14.4V19"/>' },
  lock: { path: '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/><circle cx="12" cy="15" r="1.4"/>' },
  key: { path: '<circle cx="8" cy="8" r="4"/><path d="M11 11l9 9M17 17l2-2M14 14l2-2"/>' },
  refresh: { path: '<path d="M21 12a9 9 0 11-3-6.7M21 4v5h-5"/>' },
  quote: { fill: true, path: '<path d="M9 6C5.5 7.5 4 10.5 4 14c0 2.8 1.8 4.5 4 4.5S12 16.8 12 14s-1.8-4.5-4-4.5c0-2.2.9-4 2-5.5L9 6zm11 0c-3.5 1.5-5 4.5-5 8 0 2.8 1.8 4.5 4 4.5s4-1.7 4-4.5-1.8-4.5-4-4.5c0-2.2.9-4 2-5.5L20 6z"/>' },
  check: { path: '<polyline points="20 7 9.5 17.5 4 12"/>' },
  clock: { path: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>' },
  // 导航栏：现代线性风格（房屋轮廓）
  home: { path: '<path d="M3 10.8 12 4l9 6.8"/><path d="M5.4 9.3V19.6h13.2V9.3"/>' },
  bag: { path: '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>' },
  // 导航栏：现代线性风格（铃铛）
  bell: { path: '<path d="M6 9a6 6 0 1112 0c0 5 1.6 6.6 2.2 7.2H3.8C4.4 15.6 6 14 6 9z"/><path d="M9.8 20a2.2 2.2 0 004.4 0"/>' },
  // 订单：剪贴板（订单记录）
  clipboard: { path: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3v2h6V3"/><path d="M8 12h8M8 16h5"/>' },
  // 转赠：双向箭头（藏品转移）
  transfer: { path: '<path d="M7 7h13l-3-3"/><path d="M17 17H4l3 3"/>' },
  // 邀请好友：人形 + 加号
  invite: { path: '<circle cx="8" cy="8" r="3"/><path d="M2 20c.5-3.3 3.2-5.5 6-5.5s5.5 2.2 6 5.5"/><path d="M16 7v6M13 10h6"/>' },
  // 空藏品台：展柜
  showcase: { path: '<rect x="3" y="6" width="18" height="2" rx="1"/><path d="M5 8v11h14V8"/><rect x="7" y="10" width="10" height="7" rx="1"/><path d="M9 13h6"/>' }
}

const icon = computed(() => ICONS[props.name] || ICONS.back)
const inner = computed(() =>
  icon.value.fill
    ? `<svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">${icon.value.path}</svg>`
    : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">${icon.value.path}</svg>`
)
</script>

<template>
  <span
    class="app-icon"
    :style="{ width: size + 'px', height: size + 'px', color }"
    v-html="inner"
  ></span>
</template>

<style scoped lang="scss">
.app-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 0;
  :deep(svg) {
    width: 100%;
    height: 100%;
    display: block;
  }
}
</style>
