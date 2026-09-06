/**
 * 通用格式化工具（与后端枚举严格对齐）
 */
import dayjs from 'dayjs'

/** 金额（分/元统一按元展示，两位小数） */
export function money(v: any): string {
  const n = Number(v ?? 0)
  return isNaN(n) ? '0.00' : n.toFixed(2)
}

/** 日期时间 */
export function datetime(v?: string | null): string {
  if (!v) return '-'
  const d = dayjs(v)
  return d.isValid() ? d.format('YYYY-MM-DD HH:mm:ss') : String(v)
}

/** 手机号脱敏 138****1234 */
export function maskPhone(v?: string | null): string {
  if (!v) return '-'
  return v.length === 11 ? v.replace(/^(\d{3})\d{4}(\d{4})$/, '$1****$2') : v
}

/** 身份证脱敏 前3后4 */
export function maskIdCard(v?: string | null): string {
  if (!v) return '-'
  return v.length >= 8 ? v.slice(0, 3) + '****' + v.slice(-4) : '****'
}

/** 用户状态 */
export const USER_STATUS: Record<number, { text: string; type: string }> = {
  1: { text: '正常', type: 'success' },
  0: { text: '已冻结', type: 'danger' },
}

/** 藏品状态 */
export const COLLECTIBLE_STATUS: Record<string, { text: string; type: string }> = {
  upcoming: { text: '待发售', type: 'info' },
  onsale: { text: '发售中', type: 'success' },
  soldout: { text: '已售罄', type: 'warning' },
  delisted: { text: '已下架', type: 'danger' },
}

/** 订单状态 */
export const ORDER_STATUS: Record<number, { text: string; type: string }> = {
  1: { text: '待支付', type: 'warning' },
  2: { text: '已支付', type: 'primary' },
  3: { text: '已完成', type: 'success' },
  4: { text: '已取消', type: 'info' },
  5: { text: '已退款', type: 'danger' },
}

/** 订单来源 */
export const ORDER_SOURCE: Record<number, string> = {
  1: '发售',
  2: '市场',
  3: '优先购',
  4: '资格购',
}

/** 支付方式 */
export const PAY_METHOD: Record<string, string> = {
  balance: '余额',
  alipay: '支付宝',
  wechat: '微信',
  huifu: '汇付',
  unionpay: '银联',
}

/** 支付状态 */
export const PAY_STATUS: Record<number, { text: string; type: string }> = {
  1: { text: '待支付', type: 'warning' },
  2: { text: '支付中', type: 'primary' },
  3: { text: '成功', type: 'success' },
  4: { text: '失败', type: 'danger' },
  5: { text: '已退款', type: 'info' },
}

/** 退款状态 */
export const REFUND_STATUS: Record<number, { text: string; type: string }> = {
  1: { text: '待审批', type: 'warning' },
  2: { text: '已通过', type: 'primary' },
  3: { text: '已退款', type: 'success' },
  4: { text: '已拒绝', type: 'danger' },
  5: { text: '已取消', type: 'info' },
}

/** 钱包流水方向 */
export const TRANS_DIRECTION: Record<number, string> = {
  1: '收入',
  2: '支出',
}

/** 钱包流水类型 */
export const TRANS_TYPE: Record<string, string> = {
  recharge: '充值',
  buy: '购买',
  refund: '退款',
  withdraw: '提现',
  reward: '奖励',
  resale_income: '寄售收入',
  resale_fee: '寄售手续费',
  resale_buy: '寄售购买',
  airdrop: '空投',
  adjustment: '人工调账',
}

/** 寄售状态 */
export const LISTING_STATUS: Record<string, { text: string; type: string }> = {
  selling: { text: '寄售中', type: 'success' },
  sold: { text: '已售出', type: 'primary' },
  cancelled: { text: '已撤销', type: 'info' },
  frozen: { text: '已冻结', type: 'warning' },
  system_delisted: { text: '强制下架', type: 'danger' },
}

/** 持仓状态 */
export const UC_STATUS: Record<string, { text: string; type: string }> = {
  held: { text: '持有中', type: 'success' },
  consigned: { text: '寄售中', type: 'warning' },
  frozen: { text: '已冻结', type: 'danger' },
  transferred: { text: '已转赠', type: 'info' },
  destroyed: { text: '已销毁', type: 'info' },
  refunded: { text: '已退款', type: 'info' },
}

/** 工单状态 */
export const TICKET_STATUS: Record<number, { text: string; type: string }> = {
  1: { text: '待受理', type: 'warning' },
  2: { text: '处理中', type: 'primary' },
  3: { text: '已解决', type: 'success' },
  4: { text: '已关闭', type: 'info' },
}

/** 通用布尔 */
export function boolTag(v: any): string {
  return Number(v) === 1 ? '是' : '否'
}
