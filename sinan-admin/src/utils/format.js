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
