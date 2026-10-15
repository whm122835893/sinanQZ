/**
 * 公告「类型标签」的底色守卫 —— 列表页与详情页共用这一个出口（RUNBOOK §7.41）。
 *
 * 接口下发的 tagColor 已由后端 AnnouncementCover::colorFor 收敛过一轮
 * （公告自带 tag_color 只收 #rrggbb，否则退回该类型主题色），这里再核一次是因为
 * 颜色要写进 style 绑定，脏值最好别进到浏览器；认不下来就用平台主红兜底。
 *
 * 为什么收成单独一个文件：这两处各自写一遍正则，早晚会分叉 ——
 * 一处退回主红、另一处直接把 'red' 塞进 style，同一个标签在列表和详情就两种长相。
 */
export const FALLBACK_TAG_COLOR = '#C00000'

export function safeTagColor(raw) {
  const color = String(raw || '')
  return /^#[0-9a-fA-F]{6}$/.test(color) ? color : FALLBACK_TAG_COLOR
}
