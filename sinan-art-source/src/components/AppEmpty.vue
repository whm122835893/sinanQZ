<script setup>
// 全局空状态：玉雕龙盘铜司南 + 脚下白云（透明底位图）。全站只有这一个空状态组件，14 个页面共用。
//
// 2026-10-19 用户给图并点名（原话）：「用这个图做空状态图标，把背景抠掉。所有页面没有数据的时候就显示这个图。」
// 这是空状态的第五版：SVG 展柜 → 纸箱位图 → 铜鼎位图 → SVG 开箱回纹（§7.28，上一版）→ 这一张。
// 中间还绕了一趟「把纸箱位图接回来」，同一天被这张图取代，经过记在 RUNBOOK §7.54。
//
// 抠图口径（要重做先读 §7.54，别照抄色键）：
//   底不是纯色，是 顶部纯白 → 底部浅灰 (219,223,226) 的渐变，而**云本身也是白的**
//   ⇒ 任何"离某个固定色多远"的全局判据都会把云啃掉一块。
//   做法：先用四条边建背景场 B(x,y)，判据换成"离**它所在位置该长的底**多远"；
//   再只灌「与画布边界相连 && 离 B 够近」的像素 —— 云内部那些跟底同档却被自己暗边围住的，因为不连边界而活下来。
//   阈值夹在两个实测数之间：边界离底最大 4.2（背景噪声上界）< T_HI=16 < 保留像素 d 的 p10=18.9（主体下界）。
//
// 换回位图的**代价**，认下来：
//   1) 图是死的，后台改主题色时它**不会跟着变**（上一版 SVG 的回纹会 —— 那条 $color-primary 的联动随 §7.28 一起下线）；
//   2) public/ 下的图没有构建期 hash，会撞 nginx 启发式缓存 ⇒ 文件名带 -v1，
//      下次换图**必须再 +1 换 URL**，原地覆盖的话老访客必然看不到新图。
defineProps({
  description: { type: String, default: '暂无数据' }
})
</script>

<template>
  <div class="app-empty">
    <img class="app-empty__img" src="/images/tab/empty-jade-compass-v1.webp" alt="" draggable="false" @contextmenu.prevent />
    <p class="app-empty__text">{{ description }}</p>
  </div>
</template>

<style scoped lang="scss">
.app-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 0;
  width: 100%;
  // 网格容器内占满整行，避免空状态落到第一列而靠左
  grid-column: 1 / -1;
}
.app-empty__img {
  // 显示档仍按 §7.28 的 120px（编译期 px→vw 换算成 32vw，375 设计宽下正好 120 CSS px）
  width: 120px;
  height: 120px;
  object-fit: contain;
  display: block;
  -webkit-user-drag: none;
  -webkit-touch-callout: none;
  user-select: none;
  pointer-events: none;
}
.app-empty__text {
  margin: 12px 0 0;
  font-size: 14px;
  color: $color-text-secondary;
}
</style>
