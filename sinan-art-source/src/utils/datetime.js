/**
 * 时间解析工具（跨浏览器引擎安全）
 *
 * 为什么需要它：后端（ThinkPHP + MySQL datetime(3)）返回的时间是
 * "2026-10-09 00:00:00.000" 这种**非 ISO 的本地时间串**。`new Date(字符串)` 对这种格式
 * 没有规范约束，各引擎行为不一致：Chrome / 桌面 Safari 会尽力猜出来，iOS Safari 直接给
 * Invalid Date（getTime() 为 NaN）。
 *
 * 线上真实故障：同一件藏品，电脑端首页显示「发售时间：2026.10.09 00:00」，
 * iPhone 上显示「发售中」——因为 saleTime 解析成 NaN，`if (saleTime && now < saleTime)`
 * 短路，状态一路落到 selling。
 *
 * 所以这里完全不依赖引擎的字符串猜测：正则取出年/月/日/时/分/秒，
 * 再用 `new Date(y, m - 1, d, h, mi, s)` 按**本地时区**构造（与后端 PHP 的本地时间语义一致）。
 * 该构造函数所有引擎都支持。
 */

// "2026-10-09 00:00:00.000" / "2026/10/9 0:00" / "2026-10-09" / "2026-10-09T00:00:00"
const LOCAL_RE = /^(\d{4})[-/](\d{1,2})[-/](\d{1,2})(?:[T\s]+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?(?:\.\d{1,9})?)?/
// 带时区标记（Z 或 ±hh:mm）的 ISO 串：必须按 UTC 基准解析，不能走本地构造
const ISO_ZONED_RE = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/

/**
 * 后端时间值 → 毫秒时间戳；解析不出来时返回 0（调用方普遍用 `if (ts && …)` 判空）
 * @param {string|number|null|undefined} value
 * @returns {number}
 */
export function toTs(value) {
  if (value === null || value === undefined || value === '') return 0

  // 数字或纯数字串：按时间戳处理（10 位=秒，13 位=毫秒）
  const raw = typeof value === 'number' ? value : String(value).trim()
  if (typeof raw === 'number' || /^\d{10}$|^\d{13}$/.test(raw)) {
    const n = Number(raw)
    if (!Number.isFinite(n) || n <= 0) return 0
    return n < 1e12 ? n * 1000 : n
  }

  if (ISO_ZONED_RE.test(raw)) {
    const t = new Date(raw).getTime()
    return Number.isNaN(t) ? 0 : t
  }

  const m = raw.match(LOCAL_RE)
  if (m) {
    return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)).getTime()
  }

  // 兜底：其它可读格式（如 "Oct 9, 2026"）交给引擎，失败返回 0，绝不把 NaN 漏给调用方
  const fallback = new Date(raw.replace(/-/g, '/')).getTime()
  return Number.isNaN(fallback) ? 0 : fallback
}

/**
 * 时间戳（毫秒）→ Date；无效返回 null，避免调用方拿到 Invalid Date 再算出 NaN
 * @param {number} ts
 * @returns {Date|null}
 */
export function toDate(ts) {
  const n = Number(ts)
  if (!Number.isFinite(n) || n <= 0) return null
  const d = new Date(n)
  return Number.isNaN(d.getTime()) ? null : d
}
