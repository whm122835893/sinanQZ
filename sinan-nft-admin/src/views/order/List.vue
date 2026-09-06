<script setup lang="ts">
/**
 * 订单列表
 *
 * - 搜索：订单号 / 用户UID / 状态 / 来源 / 下单时间区间（后端 startDate/endDate）
 * - 表格：字段与 OrderController::list 返回严格一致（后端已驼峰化）
 * - 行级操作：详情 / 取消（order:manage）/ 标记支付（order:manage）/ 退款（order:refund）
 * - 顶部「异常订单审计」抽屉（order:audit）：四类异常清单 + CSV 导出
 */
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import dayjs from 'dayjs'
import { useListPage } from '@/utils/useListPage'
import { money, datetime } from '@/utils/format'
import { downloadCsv } from '@/api/http'
import {
  fetchOrders,
  fetchOrderAudit,
  exportOrderAudit,
  cancelOrder,
  markOrderPaid,
  refundOrder,
} from '@/api/order'

const router = useRouter()

/** 订单状态（后端 nft_orders.status 字符串枚举） */
const STATUS_MAP: Record<string, { text: string; type: 'primary' | 'success' | 'warning' | 'info' | 'danger' }> = {
  pending: { text: '待支付', type: 'warning' },
  completed: { text: '已完成', type: 'success' },
  cancelled: { text: '已取消', type: 'info' },
  refunding: { text: '退款中', type: 'primary' },
  refunded: { text: '已退款', type: 'danger' },
}

/** 订单来源（后端 nft_orders.source 字符串枚举） */
const SOURCE_MAP: Record<string, string> = {
  release: '发售',
  market: '市场',
  priority: '优先购',
  eligibility: '资格购',
}

function statusTag(status?: string) {
  return STATUS_MAP[status ?? ''] ?? { text: status || '-', type: 'info' as const }
}

interface OrderRow {
  id: number
  orderNo: string
  userId: number
  uid: string
  username: string
  collectibleId: number
  collectibleName: string
  unitPrice: number | string
  quantity: number
  totalPrice: number | string
  status: string
  source: string
  createdAt: string
  paidAt?: string
  completedAt?: string
  expiresAt?: string
}

/** 时间区间数组 → 后端 startDate/endDate，并剔除空参数 */
function withDateRange(fetcher: (p: Record<string, any>) => Promise<any>) {
  return (params: Record<string, any>) => {
    const { dateRange, ...rest } = params
    const query: Record<string, any> = {}
    Object.keys(rest).forEach((k) => {
      if (rest[k] !== '' && rest[k] !== null && rest[k] !== undefined) query[k] = rest[k]
    })
    if (Array.isArray(dateRange)) {
      if (dateRange[0]) query.startDate = dateRange[0]
      if (dateRange[1]) query.endDate = dateRange[1]
    }
    return fetcher(query)
  }
}

const pager = useListPage(withDateRange(fetchOrders), {
  orderNo: '',
  userId: '',
  status: '',
  source: '',
  dateRange: [],
})

onMounted(() => {
  pager.refresh()
})

function handlePageChange() {
  pager.load()
}

function handleSizeChange() {
  pager.page = 1
  pager.load()
}

// ===== 行级操作 =====

function goDetail(id: number) {
  router.push(`/order/${id}`)
}

/** 取消订单（仅待支付；需填写原因） */
async function handleCancel(row: OrderRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      `取消后订单占用的库存或挂单将被释放，订单号：${row.orderNo}`,
      '取消订单',
      {
        confirmButtonText: '确认取消',
        cancelButtonText: '再想想',
        type: 'warning',
        inputPlaceholder: '请输入取消原因（必填）',
        inputValidator: (v: string) => (v && v.trim() ? true : '取消原因不能为空'),
      },
    )
    await cancelOrder(row.id, value.trim())
    await pager.done('订单已取消并释放占用资源')
  } catch {
    /* 弹窗取消或接口失败（错误已全局提示） */
  }
}

/** 标记支付（弹窗选择支付方式/流水号，事务与 C 端一致） */
const markPaidVisible = ref(false)
const markPaidSubmitting = ref(false)
const markPaidForm = reactive({
  id: 0,
  orderNo: '',
  paymentMethod: 'alipay',
  transactionNo: '',
})

function openMarkPaid(row: OrderRow) {
  markPaidForm.id = row.id
  markPaidForm.orderNo = row.orderNo
  markPaidForm.paymentMethod = 'alipay'
  markPaidForm.transactionNo = ''
  markPaidVisible.value = true
}

async function submitMarkPaid() {
  markPaidSubmitting.value = true
  try {
    await markOrderPaid(markPaidForm.id, {
      payment_method: markPaidForm.paymentMethod,
      transaction_no: markPaidForm.transactionNo.trim(),
    })
    markPaidVisible.value = false
    await pager.done('订单已完成支付与资产交割')
  } catch {
    /* 全局提示 */
  } finally {
    markPaidSubmitting.value = false
  }
}

/** 发起退款（仅已完成且非市场单；默认全额，进入财务审批） */
async function handleRefund(row: OrderRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      `将按订单实付金额 ${money(row.totalPrice)} 元发起退款申请（默认全额），提交后进入财务审批流程`,
      `发起退款 · ${row.orderNo}`,
      {
        confirmButtonText: '提交退款申请',
        cancelButtonText: '取消',
        type: 'warning',
        inputPlaceholder: '请输入退款原因（必填）',
        inputValidator: (v: string) => (v && v.trim() ? true : '退款原因不能为空'),
      },
    )
    await refundOrder(row.id, { reason: value.trim() })
    await pager.done('退款申请已提交，等待财务审批')
  } catch {
    /* 弹窗取消或接口失败（错误已全局提示） */
  }
}

// ===== 异常订单审计抽屉（order:audit）=====

interface AuditRow {
  id: number
  orderNo: string
  userId: number
  uid: string
  totalPrice: number | string
  quantity?: number
  source?: string
  expiresAt?: string
  completedAt?: string
  updatedAt?: string
}

interface AuditResult {
  expired: AuditRow[]
  noPayment: AuditRow[]
  missingAssets: AuditRow[]
  refundDangling: AuditRow[]
  totalAbnormal: number
}

const auditVisible = ref(false)
const auditLoading = ref(false)
const auditExporting = ref(false)
const auditTab = ref('expired')
const audit = ref<AuditResult | null>(null)

async function openAudit() {
  auditVisible.value = true
  auditLoading.value = true
  try {
    audit.value = await fetchOrderAudit()
    auditTab.value = 'expired'
  } catch {
    auditVisible.value = false
  } finally {
    auditLoading.value = false
  }
}

async function handleExportAudit() {
  auditExporting.value = true
  try {
    const csv = await exportOrderAudit()
    downloadCsv(`orders_audit_${dayjs().format('YYYYMMDD_HHmmss')}.csv`, csv)
    ElMessage.success('异常订单审计清单已导出')
  } catch {
    /* 全局提示 */
  } finally {
    auditExporting.value = false
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="订单号">
          <el-input
            v-model="pager.filters.orderNo"
            placeholder="订单号模糊搜索"
            clearable
            style="width: 200px"
          />
        </el-form-item>
        <el-form-item label="用户UID">
          <el-input
            v-model="pager.filters.userId"
            placeholder="用户UID"
            clearable
            style="width: 150px"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="pager.filters.status" placeholder="全部状态" clearable style="width: 130px">
            <el-option v-for="(meta, key) in STATUS_MAP" :key="key" :label="meta.text" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="来源">
          <el-select v-model="pager.filters.source" placeholder="全部来源" clearable style="width: 130px">
            <el-option v-for="(label, key) in SOURCE_MAP" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="下单时间">
          <el-date-picker
            v-model="pager.filters.dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="pager.search">查询</el-button>
          <el-button @click="pager.reset">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 表格卡片 -->
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">订单列表</span>
        <el-button v-permission="'order:audit'" type="warning" plain @click="openAudit">
          <el-icon><Warning /></el-icon>&nbsp;异常订单审计
        </el-button>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column prop="orderNo" label="订单号" min-width="185" fixed="left" show-overflow-tooltip />
        <el-table-column label="用户" min-width="130">
          <template #default="{ row }">
            <div>{{ row.uid || row.userId }}</div>
            <div class="cell-sub">{{ row.username || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="collectibleName" label="藏品名" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.collectibleName || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="单价（元）" width="100" align="right">
          <template #default="{ row }">{{ money(row.unitPrice) }}</template>
        </el-table-column>
        <el-table-column prop="quantity" label="数量" width="70" align="center" />
        <el-table-column label="总价（元）" width="110" align="right">
          <template #default="{ row }">
            <span class="amount">{{ money(row.totalPrice) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="90" align="center">
          <template #default="{ row }">
            <el-tag v-if="SOURCE_MAP[row.source]" size="small" effect="plain">{{ SOURCE_MAP[row.source] }}</el-tag>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="95" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status).type" size="small">{{ statusTag(row.status).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="260" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="goDetail(row.id)">详情</el-button>
            <el-button
              v-if="row.status === 'pending'"
              v-permission="'order:manage'"
              link
              type="warning"
              size="small"
              @click="handleCancel(row)"
            >取消</el-button>
            <el-button
              v-if="row.status === 'pending'"
              v-permission="'order:manage'"
              link
              type="success"
              size="small"
              @click="openMarkPaid(row)"
            >标记支付</el-button>
            <el-button
              v-if="row.status === 'completed' && row.source !== 'market'"
              v-permission="'order:refund'"
              link
              type="danger"
              size="small"
              @click="handleRefund(row)"
            >退款</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pager.page"
          v-model:page-size="pager.pageSize"
          :total="pager.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </div>
    </div>

    <!-- 标记支付弹窗 -->
    <el-dialog v-model="markPaidVisible" title="标记订单支付成功" width="520px">
      <el-alert
        type="warning"
        :closable="false"
        show-icon
        title="仅用于第三方支付确认到账场景；余额支付将实时扣减用户钱包，支付事务与 C 端完全一致"
        style="margin-bottom: 16px"
      />
      <el-form label-width="100px">
        <el-form-item label="订单号">
          <span>{{ markPaidForm.orderNo }}</span>
        </el-form-item>
        <el-form-item label="支付方式" required>
          <el-radio-group v-model="markPaidForm.paymentMethod">
            <el-radio value="alipay">支付宝</el-radio>
            <el-radio value="wechat">微信</el-radio>
            <el-radio value="balance">余额</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="三方流水号">
          <el-input v-model="markPaidForm.transactionNo" placeholder="第三方支付流水号（选填）" maxlength="64" clearable />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="markPaidVisible = false">取消</el-button>
        <el-button type="primary" :loading="markPaidSubmitting" @click="submitMarkPaid">确认标记支付</el-button>
      </template>
    </el-dialog>

    <!-- 异常订单审计抽屉 -->
    <el-drawer v-model="auditVisible" title="异常订单审计" size="72%">
      <div v-loading="auditLoading" class="audit-body">
        <template v-if="audit">
          <el-alert type="warning" :closable="false" show-icon class="audit-summary">
            <template #title>
              共发现 <b>{{ audit.totalAbnormal }}</b> 笔异常订单：过期未取消 {{ audit.expired.length }} 笔、已完成无支付记录
              {{ audit.noPayment.length }} 笔、资产缺失 {{ audit.missingAssets.length }} 笔、退款状态悬空
              {{ audit.refundDangling.length }} 笔（每类最多返回 100 条）
            </template>
          </el-alert>
          <div class="audit-toolbar">
            <span class="audit-tip">异常订单需人工核查处理，导出 CSV 可提交财务 / 技术对账</span>
            <el-button type="primary" size="small" :loading="auditExporting" @click="handleExportAudit">
              <el-icon><Download /></el-icon>&nbsp;导出 CSV
            </el-button>
          </div>
          <el-tabs v-model="auditTab">
            <el-tab-pane name="expired">
              <template #label>过期未取消（{{ audit.expired.length }}）</template>
              <el-table :data="audit.expired" border size="small">
                <el-table-column prop="orderNo" label="订单号" min-width="185" show-overflow-tooltip />
                <el-table-column prop="uid" label="用户UID" width="110" />
                <el-table-column label="总价（元）" width="100" align="right">
                  <template #default="{ row }">{{ money(row.totalPrice) }}</template>
                </el-table-column>
                <el-table-column label="来源" width="90" align="center">
                  <template #default="{ row }">{{ SOURCE_MAP[row.source || ''] || '-' }}</template>
                </el-table-column>
                <el-table-column label="过期时间" width="170">
                  <template #default="{ row }">{{ datetime(row.expiresAt) }}</template>
                </el-table-column>
                <el-table-column label="操作" width="70" align="center">
                  <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="goDetail(row.id)">详情</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </el-tab-pane>
            <el-tab-pane name="noPayment">
              <template #label>已完成无支付记录（{{ audit.noPayment.length }}）</template>
              <el-table :data="audit.noPayment" border size="small">
                <el-table-column prop="orderNo" label="订单号" min-width="185" show-overflow-tooltip />
                <el-table-column prop="uid" label="用户UID" width="110" />
                <el-table-column label="总价（元）" width="100" align="right">
                  <template #default="{ row }">{{ money(row.totalPrice) }}</template>
                </el-table-column>
                <el-table-column label="完成时间" width="170">
                  <template #default="{ row }">{{ datetime(row.completedAt) }}</template>
                </el-table-column>
                <el-table-column label="操作" width="70" align="center">
                  <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="goDetail(row.id)">详情</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </el-tab-pane>
            <el-tab-pane name="missingAssets">
              <template #label>资产缺失（{{ audit.missingAssets.length }}）</template>
              <el-table :data="audit.missingAssets" border size="small">
                <el-table-column prop="orderNo" label="订单号" min-width="185" show-overflow-tooltip />
                <el-table-column prop="uid" label="用户UID" width="110" />
                <el-table-column prop="quantity" label="购买数量" width="90" align="center" />
                <el-table-column label="总价（元）" width="100" align="right">
                  <template #default="{ row }">{{ money(row.totalPrice) }}</template>
                </el-table-column>
                <el-table-column label="操作" width="70" align="center">
                  <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="goDetail(row.id)">详情</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </el-tab-pane>
            <el-tab-pane name="refundDangling">
              <template #label>退款状态悬空（{{ audit.refundDangling.length }}）</template>
              <el-table :data="audit.refundDangling" border size="small">
                <el-table-column prop="orderNo" label="订单号" min-width="185" show-overflow-tooltip />
                <el-table-column prop="uid" label="用户UID" width="110" />
                <el-table-column label="总价（元）" width="100" align="right">
                  <template #default="{ row }">{{ money(row.totalPrice) }}</template>
                </el-table-column>
                <el-table-column label="更新时间" width="170">
                  <template #default="{ row }">{{ datetime(row.updatedAt) }}</template>
                </el-table-column>
                <el-table-column label="操作" width="70" align="center">
                  <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="goDetail(row.id)">详情</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </el-tab-pane>
          </el-tabs>
        </template>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.table-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .table-title {
    font-size: 15px;
    font-weight: 600;
  }
}

.cell-sub {
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.amount {
  font-weight: 600;
  color: var(--sn-red);
}

.audit-body {
  min-height: 200px;

  .audit-summary {
    margin-bottom: 12px;
  }

  .audit-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;

    .audit-tip {
      font-size: 12px;
      color: var(--sn-text-secondary);
    }
  }
}
</style>
