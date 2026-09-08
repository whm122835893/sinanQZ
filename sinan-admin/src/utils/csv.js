// ============================================================
// CSV 导出工具（客户端生成）
// - 带 UTF-8 BOM，Excel 打开中文不乱码
// - downloadCsv：单表导出（表头 + 行数据）
// - downloadCsvSections：多区块导出（报表场景，概览 + 多张明细表拼一个文件）
// ============================================================

function esc(v) {
  if (v === null || v === undefined) return ''
  const s = String(v)
  // 含逗号 / 引号 / 换行时用双引号包裹，内部双引号转义
  return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
}

function dateStamp() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}`
}

function save(filename, lines) {
  const csv = '\uFEFF' + lines.join('\r\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename.endsWith('.csv') ? filename : `${filename}_${dateStamp()}.csv`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

/**
 * 下载单表 CSV
 * @param {string} filename 文件名（自动追加日期与 .csv 后缀）
 * @param {string[]} headers 表头
 * @param {Array<Array>} rows 行数据（二维数组）
 */
export function downloadCsv(filename, headers, rows) {
  const lines = [headers.map(esc).join(',')]
  ;(rows || []).forEach((r) => lines.push(r.map(esc).join(',')))
  save(filename, lines)
}

/**
 * 下载多区块 CSV（区块之间空行分隔，区块标题以 # 开头）
 * @param {string} filename 文件名
 * @param {Array<{title: string, headers: string[], rows: Array<Array>}>} sections
 */
export function downloadCsvSections(filename, sections) {
  const lines = []
  ;(sections || []).forEach((sec, i) => {
    if (i > 0) lines.push('')
    lines.push(`# ${sec.title}`)
    if (sec.headers?.length) lines.push(sec.headers.map(esc).join(','))
    ;(sec.rows || []).forEach((r) => lines.push(r.map(esc).join(',')))
  })
  save(filename, lines)
}
