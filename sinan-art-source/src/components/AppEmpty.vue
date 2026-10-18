<script setup>
// 全局空状态：珊瑚粉红龙盘铜司南 + 脚下白瓷祥云（透明底位图）。全站只有这一个空状态组件，14 个页面共用。
//
// 2026-10-19 用户给第二张图并点名（原话）：「空状态换成这一张」—— 换的就是下面这张 -v2。
// 这是空状态的第六版：SVG 展柜 → 纸箱位图 → 铜鼎位图 → SVG 开箱回纹（§7.28）
//   → 玉雕龙盘司南位图（§7.54，就是上一版 -v1）→ 这一张。
// 中间还绕了一趟「把纸箱位图接回来」，同一天被位图取代，经过记在 RUNBOOK §7.54 / §7.55。
//
// 抠图口径：七步流水线沿用 §7.54（背景场 → 边界连通洪水 → 连通块筛选 → 线性 alpha →
//   去溢色 → 1px 羽化 → 裁方收到 448 出 WebP q88 method6），**但阈值是这批现量的，没继承**：
//   这批的底近乎均匀（四条边 mean (244,245,239)，边界离底最大 3.2），主体与底之间在
//   d 直方图上有一个浅谷（谷底 10~11）⇒ 阈值夹在 3.2 < T_LO=6 … T_HI=12 < 28.3（保留像素 d 的 p10）。
//   为什么不是 §7.54 那个 16：四档（8/12/16/20）量下来剪影最外一圈的平均色离源底都有 21~38 远，
//   **也就是根本没有 halo 可抠**；再往上抬阈值只会啃云的软边（每 +1 档少留约 1,040 px）。
//   上一批那条"云比底还亮、只能靠不连边界才活得下来"的坑，这批不存在：全图比底还亮的像素只有 21 个。
//
// 换回位图的**代价**，与 §7.54 同，照旧认下来：
//   1) 图是死的，后台改主题色时它**不会跟着变**（$color-primary 那条联动随 §7.28 一起下线）；
//   2) public/ 下的图没有构建期 hash，会撞 nginx 启发式缓存 ⇒ 文件名带 -v2，
//      下次换图**必须再 +1 换 URL**，原地覆盖的话老访客必然看不到新图。
defineProps({
  description: { type: String, default: '暂无数据' }
})
</script>

<template>
  <div class="app-empty">
    <img class="app-empty__img" src="/images/tab/empty-jade-compass-v2.webp" alt="" draggable="false" @contextmenu.prevent />
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
