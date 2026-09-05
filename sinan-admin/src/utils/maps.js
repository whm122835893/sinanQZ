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
  cancelled:{ label: '已取消', type: 'default' }
}

export const TRANSFER_STATUS = {
  pending:   { label: '待接收', type: 'warning' },
  completed: { label: '已完成', type: 'success' },
  rejected:  { label: '已拒绝', type: 'default' }
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

// 角色映射
export const ROLE_MAP = {
  super: { label: '超级管理员', type: 'danger' },
  operator: { label: '运营专员', type: 'primary' },
  auditor: { label: '审核专员', type: 'warning' }
}
