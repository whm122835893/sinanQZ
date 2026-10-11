// ============================================================
// 藏品编号的展示格式化（全项目唯一出口）
// ------------------------------------------------------------
// 库里存的编号是「机器编号」：SN-{藏品ID}-{序号}，例如 SN-3-0007。
// 产品口径（2026-10-11 定）：C 端只给用户看尾部序号，写成 #7 这种形式，
// 且发行 1000 份的藏品编号最大到 #1000（取号已由后端 SerialService 保证不越界）。
//
// 所以「#」这个前缀符号只在本文件出现一次。新代码请一律
// `import { formatNo } from '@/utils/serial'`，不要在模板里再手写 `#{{ ... }}`，
// 否则同一个人会在一个页面看到 #7、在另一个页面看到 #SN-3-0007。
// （tests/serial-format.spec.js 里有一组防漂移用例专门盯这件事。）
//
// 注意：这里只管「怎么显示」。跳转详情、查资产实例、判断是否寄售锁定
// 都继续用原始编号字符串，因为那是接口和库存数据的主键线索。
// ============================================================

// 标准编号：SN-{藏品ID}-{序号}，序号允许带前导零
const SERIAL_RE = /^SN-\d+-(\d+)$/

/**
 * 从编号里取出尾部序号。
 * @param {string|number|null|undefined} value 原始编号（SN-3-0007）或已经是序号（7/'07'）
 * @returns {number|null} 取不出来返回 null
 */
export function serialNumber(value) {
  if (value === null || value === undefined) return null
  const text = String(value).trim()
  if (text === '') return null

  const hit = text.match(SERIAL_RE)
  if (hit) return Number(hit[1])

  // 接口偶发直接下发纯数字序号（历史数据/其他端拼装的列表），一并兼容
  if (/^\d+$/.test(text)) return Number(text)

  return null
}

/**
 * 展示用编号：#7。
 * 认不出来的编号（例如清理中的历史占位串 TMP-xxxx）原样返回，
 * 宁可显示得难看一点，也不能把「这一件是谁」藏起来。
 * @returns {string} 无编号可显示时返回空串
 */
export function formatNo(value) {
  const no = serialNumber(value)
  if (no !== null) return `#${no}`
  if (value === null || value === undefined) return ''
  const text = String(value).trim()
  return text === '' ? '' : text
}

/**
 * 只要尾部序号的场合（弹窗标题、比较用），不带 #。
 */
export function noLabel(value) {
  const no = serialNumber(value)
  return no === null ? '' : String(no)
}
