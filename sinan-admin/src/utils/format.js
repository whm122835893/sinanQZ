// ============================================================
// 格式化工具
// ============================================================

const pad = (n) => String(n).padStart(2, '0')

export function fmtDateTime(t) {
  if (!t) return '-'
  const d = new Date(t)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export function fmtDate(t) {
  if (!t) return '-'
  const d = new Date(t)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export function fmtMoney(n, symbol = false) {
  const s = Number(n ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return symbol ? `¥${s}` : s
}

export function fmtNumber(n) {
  return Number(n ?? 0).toLocaleString('zh-CN')
}

// 库存池计算（与后端 InventoryService 同公式）
export function stockPool(c) {
  return (c.edition || 0) - (c.sold || 0) - (c.lockedQuantity || 0)
    - (c.reservedCount || 0) - (c.airdroppedCount || 0) - (c.destroyedCount || 0)
}

// 盲盒库存池 = 发行总量 - 已售出发售 - 已独立空投 - 已销毁
export function blindBoxPool(b) {
  return (b.edition || 0) - (b.sold || 0) - (b.airdroppedCount || 0) - (b.destroyedCount || 0)
}

// ============================================================
// 脱敏工具（列表页默认脱敏，查看完整信息需密码验证并写审计日志）
// ============================================================

// 手机号：138****8888
export function maskPhone(p) {
  const s = String(p || '')
  return s.length === 11 ? `${s.slice(0, 3)}****${s.slice(7)}` : s
}

// 姓名：张*
export function maskName(n) {
  const s = String(n || '')
  if (!s) return '-'
  return s[0] + '*'.repeat(Math.max(1, s.length - 1))
}

// 身份证：110***********1
export function maskIdNo(id) {
  const s = String(id || '')
  return s.length >= 6 ? `${s.slice(0, 3)}${'*'.repeat(s.length - 4)}${s.slice(-1)}` : s
}
