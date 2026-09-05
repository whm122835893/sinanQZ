// ============================================================
// 业务状态映射（标签文案 + Vant Tag 类型）
// type: primary(红) / success(绿) / warning(橙) / danger(红) / default(灰)
// ============================================================

export const ORDER_STATUS = {
  pending:   { label: '待支付', type: 'warning' },
  paid:      { label: '已支付', type: 'primary' },
  completed: { label: '已完成', type: 'success' },
  cancelled: { label: '已取消', type: 'default' },
  refunding: { label: '退款中', type: 'warning' },
  refunded:  { label: '已退款', type: 'default' },
  abnormal:  { label: '异常', type: 'danger' }
}

export const USER_STATUS = {
  normal: { label: '正常', type: 'success' },
  frozen: { label: '已冻结', type: 'danger' }
}

export const REALNAME_STATUS = {
  approved: { label: '已实名', type: 'success' },
  pending:  { label: '待审核', type: 'warning' },
  rejected: { label: '已驳回', type: 'danger' },
  none:    { label: '未实名', type: 'default' }
}

export const COLLECTIBLE_STATUS = {
  onsale:   { label: '发售中', type: 'success' },
  upcoming: { label: '待发售', type: 'warning' },
  soldout:  { label: '已售罄', type: 'danger' },
  offline:  { label: '已下架', type: 'default' }
}

export const RESALE_STATUS = {
  onsale:   { label: '挂单中', type: 'primary' },
  frozen:   { label: '已冻结', type: 'warning' },
  sold:     { label: '已成交', type: 'success' },
  cancelled:{ label: '已取消', type: 'default' },
  system_delisted: { label: '系统下架', type: 'danger' }
}

export const TRANSFER_STATUS = {
  pending:   { label: '待接收', type: 'warning' },
  completed: { label: '已完成', type: 'success' },
  rejected:  { label: '已拒绝', type: 'default' },
  revoked:   { label: '已撤销', type: 'danger' }
}

export const REFUND_STATUS = {
  pending:  { label: '待审批', type: 'warning' },
  approved:{ label: '已退款', type: 'success' },
  rejected:{ label: '已驳回', type: 'danger' }
}

export const ACTIVITY_STATUS = {
  enabled:  { label: '进行中', type: 'success' },
  disabled: { label: '已停用', type: 'default' }
}

export const CONTENT_STATUS = {
  published: { label: '已发布', type: 'success' },
  draft:     { label: '草稿', type: 'default' }
}

export const NOTICE_TYPE = {
  system:       { label: '系统公告', type: 'primary' },
  activity:     { label: '活动公告', type: 'success' },
  maintenance:  { label: '维护公告', type: 'warning' }
}

export const WALLET_TYPE = {
  recharge: { label: '充值', type: 'primary' },
  reward:   { label: '奖励', type: 'success' },
  consume:  { label: '消费', type: 'warning' },
  refund:   { label: '退款', type: 'default' }
}

// 配额类型（与后端 quota_type 枚举一致）
export const QUOTA_TYPES = {
  1: '优先购',
  2: '活动空投',
  3: '签到',
  4: '注册',
  5: '邀请',
  6: '抽奖',
  7: '其他'
}

// 订单来源（发售 / 市场 / 优先购 / 资格购 / 盲盒）
export const ORDER_SOURCE = {
  release:     { label: '公售', type: 'primary' },
  priority:    { label: '优先购', type: 'warning' },
  eligibility: { label: '资格购', type: 'success' },
  market:      { label: '市场', type: 'info' },
  blindbox:    { label: '盲盒', type: 'danger' }
}

// 寄售价格管控模式
export const RESALE_PRICE_MODE = {
  limit: { label: '限价模式', type: 'warning' },
  free:  { label: '不限价', type: 'info' }
}

// 风控告警
export const RISK_LEVEL = {
  high:   { label: '高风险', type: 'danger' },
  medium: { label: '中风险', type: 'warning' },
  low:    { label: '低风险', type: 'info' }
}

export const RISK_STATUS = {
  pending:    { label: '待处理', type: 'warning' },
  processing: { label: '处理中', type: 'primary' },
  resolved:   { label: '已处理', type: 'success' }
}

export const RISK_TYPE = {
  abnormal_trade:    '异常交易',
  bulk_refund:       '批量退款',
  bulk_register:     '批量注册',
  price_manipulation: '价格操纵'
}

// 客服工单
export const TICKET_STATUS = {
  pending:    { label: '待处理', type: 'warning' },
  processing: { label: '处理中', type: 'primary' },
  closed:     { label: '已关闭', type: 'success' }
}

export const TICKET_PRIORITY = {
  urgent: { label: '紧急', type: 'danger' },
  high:   { label: '高', type: 'warning' },
  normal: { label: '普通', type: 'info' }
}

export const TICKET_TYPE = {
  order: '订单问题',
  refund: '退款问题',
  account: '账号问题',
  other: '其他'
}

// 区块链
export const CHAIN_TX_TYPE = {
  Mint: { label: '铸造', type: 'primary' },
  Transfer: { label: '转账', type: 'warning' },
  Sale: { label: '交易', type: 'success' }
}

export const CHAIN_TX_STATUS = {
  success: { label: '成功', type: 'success' },
  pending: { label: '上链中', type: 'warning' },
  failed: { label: '失败', type: 'danger' }
}

// 内容审核
export const AUDIT_STATUS = {
  pending:  { label: '待审核', type: 'warning' },
  approved: { label: '已通过', type: 'success' },
  rejected: { label: '已驳回', type: 'danger' }
}

export const CONTENT_AUDIT_TYPE = {
  ugc_collectible: '用户自建藏品',
  community_post: '社区帖子'
}

// 审批工作流
export const APPROVAL_STATUS = {
  pending:  { label: '待审批', type: 'warning' },
  approved: { label: '已通过', type: 'success' },
  rejected: { label: '已驳回', type: 'danger' }
}

export const APPROVAL_TYPE = {
  large_refund:   '大额退款',
  asset_modify:   '强制修改资产',
  config_modify:  '修改支付配置',
  platform_cleanup: '平台清库'
}

// 求购市场
export const BUY_REQUEST_STATUS = {
  active:   { label: '求购中', type: 'primary' },
  delisted: { label: '已下架', type: 'info' }
}

// 资格购条件组合方式
export const QUALIFY_CONDITION_TYPE = {
  1: '满足任一',
  2: '满足全部'
}

// 角色映射（5 角色）
export const ROLE_MAP = {
  super:    { label: '超级管理员', type: 'danger' },
  operator: { label: '运营专员', type: 'primary' },
  finance:  { label: '财务专员', type: 'success' },
  risk:     { label: '风控专员', type: 'warning' },
  support:  { label: '客服专员', type: 'info' }
}
