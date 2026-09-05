// ============================================================================
// API 类型定义（与后端 {code, message, data} 响应结构对齐）
// ============================================================================

/** 后端统一响应体 */
export interface ApiResponse<T = unknown> {
  code: number
  message: string
  data: T
}

/** 分页响应 */
export interface PageData<T> {
  list: T[]
  total: number
  page: number
  pageSize: number
  lastPage: number
}

/** 管理员档案 */
export interface AdminProfile {
  id: number
  username: string
  realName: string
  role: number
  roleName: string
  phone: string | null
  email: string | null
  avatar: string | null
}

/** 登录响应 */
export interface LoginResult {
  token: string
  refreshToken: string
  admin: AdminProfile
  permissions: string[]
  mustChangePwd: boolean
}

/** /auth/me 响应 */
export interface MeResult {
  admin: AdminProfile
  permissions: string[]
}

/** 仪表盘指标 */
export interface DashboardMetrics {
  newUsersToday: number
  salesToday: string
  paidOrdersToday: number
  ordersToday: number
  onsaleCollectibles: number
  activeListings: number
  onsaleBlindboxes: number
  totalUsers: number
}

/** 用户列表行 */
export interface UserRow {
  id: number
  uid: string
  username: string
  avatar: string
  phone: string
  isRealname: boolean
  status: number
  lastLoginAt: string | null
  loginCount: number
  createdAt: string
}

/** 用户详情 */
export interface UserDetail extends UserRow {
  balance: string
  inviteCode: string
  heldCollectibles: number
  heldBlindboxes: number
  orderCount: number
  updatedAt: string
}

/** 管理员列表行 */
export interface AdminRow {
  id: number
  username: string
  realName: string
  role: number
  roleName: string
  phone: string | null
  email: string | null
  status: number
  lastLoginAt: string | null
  lastActionAt: string | null
  createdAt: string
}

/** 操作日志行 */
export interface OperationLogRow {
  id: number
  adminId: number
  adminName: string
  module: string
  action: string
  targetType: string | null
  targetId: string | null
  targetDesc: string | null
  reason: string | null
  ip: string
  createdAt: string
}

/** 登录日志行 */
export interface LoginLogRow {
  id: number
  adminId: number | null
  username: string
  ip: string
  userAgent: string | null
  success: boolean
  failReason: string | null
  createdAt: string
}

/** 角色选项 */
export interface RoleOption {
  value: number
  label: string
}

// ============================================================================
// P1 类型定义（文档 8.5~8.9、8.3 扩展）
// ============================================================================

/** 藏品列表行 */
export interface CollectibleRow {
  id: number
  name: string
  subtitle: string | null
  image: string
  price: string
  category: string
  categoryId: number
  status: string
  isResaleable: boolean
  isTransferable: boolean
  onsaleAt: string | null
  offSaleAt: string | null
  createdAt: string
  edition: number
  sold: number
  lockedQuantity: number
  reservedCount: number
  airdroppedCount: number
  destroyedCount: number
  circulate: number
  stockPool: number
}

/** 藏品配额（detail / audit 返回结构） */
export interface QuotaRow {
  id: number
  quotaType: number
  quotaName: string
  plannedQuantity: number
  usedQuantity: number
  status: number
  activityId?: number | null
  activityType?: string | null
  remark?: string | null
  createdAt?: string
}

/** 优先购/资格购配置 */
export interface QualificationConfig {
  isEnabled: boolean
  requiredCollectibleIds: number[]
  requiredCheckinDays: number
  requiredInviteCount: number
  conditionType: number
  validStartAt: string | null
  validEndAt: string | null
  whitelist: Array<{ userId: number; phone: string; expiresAt: string | null }>
}

/** 藏品详情 */
export interface CollectibleDetail extends CollectibleRow {
  releaseQuantity: number | null
  perUserLimit: number
  resalePriceMode: number
  resalePriceMin: string | null
  resalePriceMax: string | null
  issuer: string | null
  creator: string | null
  tag: string | null
  description: string | null
  saleable: number
  updatedAt: string
  quotas: QuotaRow[]
  qualification: QualificationConfig | null
  isBlindbox: boolean
}

/** 盲盒列表行 */
export interface BlindboxRow {
  id: number
  collectibleId: number
  name: string
  image: string
  price: string
  status: string
  isOpenable: boolean
  isTransferable: boolean
  isResaleable: boolean
  description: string | null
  onsaleAt: string | null
  offSaleAt: string | null
  createdAt: string
  edition: number
  sold: number
  lockedQuantity: number
  reservedCount: number
  airdroppedCount: number
  destroyedCount: number
  circulate: number
  stockPool: number
}

/** 盒子内子藏品配置 */
export interface BlindboxItem {
  id: number
  collectibleId: number
  name: string
  image: string
  probability: number
  plannedQuantity: number | null
  distributed: number
  prizeCirculate: number
  prizeEdition: number
}

/** 盲盒详情 */
export interface BlindboxDetail extends BlindboxRow {
  releaseQuantity: number | null
  perUserLimit: number
  saleable: number
  updatedAt: string
  items: BlindboxItem[]
  probabilitySum: number
}

/** 订单列表行 */
export interface OrderRow {
  id: number
  orderNo: string
  userId: number
  username: string
  phone: string
  collectibleId: number
  collectibleName: string
  image: string
  quantity: number
  unitPrice: string
  totalPrice: string
  status: string
  source: string
  paidAt: string | null
  createdAt: string
}

/** 订单详情（含资产/支付/退款） */
export interface OrderDetail extends OrderRow {
  resaleListingId?: number | null
  completedAt?: string | null
  cancelledAt?: string | null
  cancelReason?: string | null
  payment?: {
    id: number
    amount: string
    method: string
    transactionNo: string | null
    status: string
    paidAt: string | null
  } | null
  assets?: Array<{
    id: number
    serial: string
    status: string
    source: string
    acquiredPrice: string
    acquiredAt: string
  }>
  refunds?: Array<{
    id: number
    refundNo: string
    amount: string
    status: number
    reason: string | null
    createdAt: string
  }>
}

/** 异常订单行（#73，type=missing_asset/duplicate_charge/amount_mismatch） */
export interface AbnormalOrderRow {
  id: number
  orderNo: string
  username: string
  collectibleName: string
  quantity: number
  totalPrice: string
  paidAmount: string | null
  paymentStatus: string | null
  status: string
  source: string
  abnormalType: string
  createdAt: string
  paidAt: string | null
}

/** 退款列表行 */
export interface RefundRow {
  id: number
  refundNo: string
  orderId: number
  orderNo: string
  userId: number
  username: string
  phone: string
  amount: string
  reason: string
  status: number
  statusText: string
  applicantName: string
  refundChannel: string
  createdAt: string
}

/** 退款详情 */
export interface RefundDetail extends RefundRow {
  applicant: { id: number; name: string } | null
  approver: { id: number; name: string } | null
  approvedAt: string | null
  refundedAt: string | null
  order: {
    id: number
    orderNo: string
    status: string
    source: string
    quantity: number
    unitPrice: string
    totalPrice: string
  }
  user: { id: number; username: string; phone: string }
  payment: { id: number; method: string; amount: string; status: string; paidAt: string | null }
  pendingAssets: number
}

/** 实名列表行（脱敏） */
export interface RealnameRow {
  id: number
  uid: string
  username: string
  avatar: string
  phone: string
  realName: string
  idCard: string
  status: string
  createdAt: string
}

/** 实名详情（脱敏，#30） */
export interface RealnameDetail extends RealnameRow {
  accountStatus: string
  realnamedAt: string
}

/** 实名完整信息（明文，需密码，#31） */
export interface RealnameFull {
  id: number
  uid: string
  username: string
  phone: string
  realName: string
  idCard: string
  realnamedAt: string
}

/** 实名查看审计日志行（#32） */
export interface RealnameAuditLog {
  id: number
  adminId: number
  adminName: string
  action: string
  targetDesc: string | null
  reason: string | null
  ip: string
  createdAt: string
}

/** 用户钱包资产与流水 */
export interface UserWalletResult {
  wallet: { balance: string; available: string; frozen: string; points: number }
  stats: { totalInflow: string; totalOutflow: string }
  transactions: PageData<{
    id: number
    transType: string
    title: string
    direction: number
    amount: string
    balanceAfter: string
    bizNo: string | null
    createdAt: string
  }>
}

/** 用户持仓藏品行 */
export interface UserCollectibleRow {
  id: number
  collectibleId: number
  name: string
  image: string
  serial: string
  source: string
  sourceText: string
  status: string
  statusText: string
  acquiredPrice: string
  acquiredAt: string
}

/** 用户持仓盲盒行 */
export interface UserBlindboxRow {
  id: number
  collectibleId: number
  name: string
  image: string
  serial: string
  source: string
  status: string
  statusText: string
  opened: boolean
  acquiredPrice: string
  acquiredAt: string
}

/** 用户邀请记录 */
export interface UserInviteResult {
  stats: { totalInvites: number; registered: number; rewarded: number; inviteCode: string }
  invitedBy: {
    inviterId: number | null
    inviterName: string
    inviterPhone: string
    inviteCode: string
    status: string
    createdAt: string
  } | null
  list: PageData<{
    id: number
    inviteeId: number
    inviteeName: string
    inviteePhone: string
    inviteCode: string
    status: string
    statusText: string
    inviterRewarded: boolean
    inviteeRewarded: boolean
    createdAt: string
  }>
}

/** 用户优先购资格 */
export interface UserQualificationResult {
  summary: { valid: number; expired: number; usedUp: number; disabled: number }
  list: PageData<{
    id: number
    activityId: number
    activityName: string
    collectibleId: number
    collectibleName: string
    image: string
    phone: string
    maxQuantity: number
    usedQuantity: number
    remaining: number
    state: string
    stateText: string
    expiresAt: string | null
    activityWindow: { start: string; end: string }
    createdAt: string
  }>
}

/** 资金监控图表（#9） */
export interface FinanceChart {
  days: number
  series: Array<{ date: string; recharge: number; sales: number; refund: number }>
  totals: { recharge: number; sales: number; refund: number }
}

/** 库存预警面板（#10） */
export interface AlertsPanel {
  lowStock: Array<{ collectibleId: number; name: string; status: string; edition: number; stockPool: number; threshold: number }>
  lowStockCount: number
  abnormal: Array<{ collectibleId: number; name: string; status: string; issue: string; edition: number; stockPool: number; circulate: number }>
  abnormalCount: number
  blindboxShortage: Array<{ collectibleId: number; name: string; status: string; edition: number; stockPool: number }>
  blindboxShortageCount: number
}

/** 实时动态事件（#11） */
export interface ActivityEvent {
  type: string
  typeText: string
  content: string
  status: string
  createdAt: string
}

/** 趋势图数据（#12） */
export interface TrendData {
  days: number
  metric: string
  label: string
  series: Array<{ date: string; value: number }>
  total: number
}

/** 优先购统计（#13） */
export interface PriorityStats {
  summary: {
    activeActivities: number
    validWhitelists: number
    totalGranted: number
    totalUsed: number
    totalRemaining: number
  }
  byActivity: Array<{
    activityId: number
    name: string
    window: { start: string; end: string }
    whitelistCount: number
    granted: number
    used: number
    remaining: number
  }>
}

/** 空投记录行（藏品 #49：独立空投与活动空投） */
export interface AirdropRecordRow {
  id: number
  username: string
  phone: string
  quantity: number
  status: string
  source: string
  issuedAt: string | null
  createdAt: string
}

/** 销毁记录行（藏品 #50 / 盲盒 #67） */
export interface DestroyRecordRow {
  id: number
  quantity: number
  reason: string | null
  adminName: string
  ip: string
  createdAt: string
}

/** 独立空投结果（#44 / #63：users/perUser/total/stockPoolAfter/issued[]） */
export interface AirdropResult {
  users: number
  perUser: number
  total: number
  stockPoolAfter: number
  issued: Array<{ phone: string; userId: number; userCollectibleId: number; serial: string }>
}

/** 库存审计结果（#48 / #66，守恒校验） */
export interface InventoryAuditResult {
  counters: {
    edition: number
    sold: number
    lockedQuantity: number
    reservedCount: number
    airdroppedCount: number
    destroyedCount: number
    circulate: number
    stockPool: number
  }
  conservation: { ok: boolean; expected: number; actual: number; formula: string }
  holding: {
    ok: boolean
    expected: number
    actual: number
    byStatus: { held: number; consigned: number; frozen: number; transferred: number; consumed: number }
    bySource: Record<string, number>
    formula: string
  }
  quotas: {
    list: Array<{
      id: number
      quotaType: number
      quotaName: string
      plannedQuantity: number
      usedQuantity: number
      status: number
      activityType: string | null
    }>
    activePlanned: number
    usedTotal: number
    reservedMatch: boolean
  }
  ok: boolean
}

/** 盲盒审计结果（#66，含 blindBoxId） */
export interface BlindboxAuditResult extends InventoryAuditResult {
  blindBoxId: number
}

/** 盲盒开盒记录行（#65） */
export interface BlindboxOpenRecordRow {
  id: number
  username: string
  serial: string
  prizeName: string
  prizeImage: string
  openedAt: string
}

/** 回收回退信息（#64 / #28 / #29） */
export interface RecoverRevert {
  source: string
  reverted: boolean
  counter: string
}
