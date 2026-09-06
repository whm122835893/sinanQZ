<script setup lang="ts">
/**
 * 退款列表
 *
 * - 搜索：退款单号 / 状态 / 申请时间区间（后端 startDate/endDate）
 * - 表格：字段与 RefundController::list 返回严格一致（后端已驼峰化）
 * - 操作：详情 / 审批通过、拒绝（refund:approve，弹窗填备注）/ 执行退款（order:refund）
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useListPage } from '@/utils/useListPage'
import { money, datetime, PAY_METHOD, REFUND_STATUS, UC_STATUS } from '@/utils/format'
import { fetchRefunds, fetchRefundDetail, approveRefund, executeRefund } from '@/api/order'

/** 退款状态选项（后端实际使用 1-4） */
const STATUS_OPTIONS = [1, 2, 3, 4].map((key) => ({
  value: key,
  label: REFUND_STATUS[key]?.text ?? String(key),
}))

/** 订单来源（后端字符串枚举，详情抽屉展示用） */
const SOURCE_MAP: Record<string, string> = {
  release: '发售',
  market: '市场',
  priority: '优先购',
  eligibility: '资格购',
}

/** 支付状态（后端 nft_payments.status 字符串枚举） */
const PAY_STATUS_MAP: Record<string, { text: string; type: 'success' | 'warning' | 'info' | 'danger' }> = {
  pending: { text: '待支付', type: 'warning' },
  success: { text: '支付成功', type: 'success' },
  failed: { text: '支付失败', type: 'danger' },
  refunded: { text: '已退款', type: 'info' },
}

function refundStatusTag(status?: number) {
  return REFUND_STATUS[Number(status)] ?? { text: status ?? '-', type: 'info' as const }
}

function payStatusTag(status?: string) {
  return PAY_STATUS_MAP[status ?? ''] ?? { text: status || '-', type: 'info' as const }
}

function payMethodText(method?: string) {
  return PAY_METHOD[method ?? ''] ?? (method || '-')
}

function ucStatusTag(status?: string) {
  if (status === 'recovered') return { text: '已回收', type: 'info' as const }
  return UC_STATUS[status ?? ''] ?? { text: status || '-', type: 'info' as const }
}

interface RefundRow {
  id: number
  refundNo: string
  orderId: number
  orderNo: string
  userId: number
  uid: string
  username: string
  collectibleName: string
  amount: number | string
  reason: string
  status: number
  statusText: string
  createdAt: string
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

const pager = useListPage(withDateRange(fetchRefunds), {
  refundNo: '',
  status: '',
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

// ===== 审批（approve / reject 共用备注弹窗）=====

const approveVisible = ref(false)
const approveSubmitting = ref(false)
const approveForm = reactive({
  id: 0,
  refundNo: '',
  amount: '' as string | number,
  action: 'approve' as 'approve' | 'reject',
  comment: '',
})

function openApprove(row: RefundRow, action: 'approve' | 'reject') {
  approveForm.id = row.id
  approveForm.refundNo = row.refundNo
  approveForm.amount = row.amount
  approveForm.action = action
  approveForm.comment = ''
  approveVisible.value = true
}

async function submitApprove() {
  const comment = approveForm.comment.trim()
  if (approveForm.action === 'reject' && !comment) {
    ElMessage.warning('拒绝退款必须填写审批意见')
    return
  }
  approveSubmitting.value = true
  try {
    await approveRefund(approveForm.id, { action: approveForm.action, comment })
    approveVisible.value = false
    await pager.done(
      approveForm.action === 'approve' ? '退款已批准，请执行退款操作' : '退款已拒绝，订单恢复完成状态',
    )
  } catch {
    /* 全局提示 */
  } finally {
    approveSubmitting.value = false
  }
}

// ===== 执行退款 =====

async function handleExecute(row: RefundRow) {
  try {
    await ElMessageBox.confirm(
      `执行退款将把 ${money(row.amount)} 元退回用户余额，同时回收订单全部资产并回滚藏品库存，操作不可逆`,
      `执行退款 · ${row.refundNo}`,
      {
        confirmButtonText: '确认执行退款',
        cancelButtonText: '取消',
        type: 'warning',
      },
    )
    await executeRefund(row.id, {})
    await pager.done('退款完成：资金已入账、资产已回收、库存已回滚')
  } catch {
    /* 弹窗取消或接口失败（错误已全局提示） */
  }
}

// ===== 详情抽屉 =====

const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<any>(null)

async function openDetail(row: RefundRow) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await fetchRefundDetail(row.id)
  } catch {
    detailVisible.value = false
  } finally {
    detailLoading.value = false
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="退款单号">
          <el-input
            v-model="pager.filters.refundNo"
            placeholder="退款单号模糊搜索"
            clearable
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="pager.filters.status" placeholder="全部状态" clearable style="width: 130px">
            <el-option v-for="opt in STATUS_OPTIONS" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="申请时间">
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
        <span class="table-title">退款列表</span>
        <span class="table-tip">工作流：发起（订单页）→ 财务审批 → 执行退款（资金 + 资产 + 库存原子结转）</span>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column prop="refundNo" label="退款单号" min-width="195" fixed="left" show-overflow-tooltip />
        <el-table-column prop="orderNo" label="订单号" min-width="185" show-overflow-tooltip />
        <el-table-column label="用户" min-width="130">
          <template #default="{ row }">
            <div>{{ row.uid || row.userId }}</div>
            <div class="cell-sub">{{ row.username || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="金额（元）" width="100" align="right">
          <template #default="{ row }">
            <span class="amount">{{ money(row.amount) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="reason" label="原因" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.reason || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="95" align="center">
          <template #default="{ row }">
            <el-tag :type="refundStatusTag(row.status).type" size="small">{{ refundStatusTag(row.status).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="申请时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="250" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
            <template v-if="Number(row.status) === 1">
              <el-button
                v-permission="'refund:approve'"
                link
                type="success"
                size="small"
                @click="openApprove(row, 'approve')"
              >审批通过</el-button>
              <el-button
                v-permission="'refund:approve'"
                link
                type="warning"
                size="small"
                @click="openApprove(row, 'reject')"
              >拒绝</el-button>
            </template>
            <el-button
              v-if="Number(row.status) === 2"
              v-permission="'order:refund'"
              link
              type="danger"
              size="small"
              @click="handleExecute(row)"
            >执行退款</el-button>
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

    <!-- 审批弹窗（通过 / 拒绝共用） -->
    <el-dialog
      v-model="approveVisible"
      :title="approveForm.action === 'approve' ? '审批通过退款' : '拒绝退款'"
      width="520px"
    >
      <el-alert
        v-if="approveForm.action === 'approve'"
        type="info"
        :closable="false"
        show-icon
        title="批准后退款单进入待执行状态，需继续执行退款完成资金退回与资产回收"
        style="margin-bottom: 16px"
      />
      <el-alert
        v-else
        type="warning"
        :closable="false"
        show-icon
        title="拒绝后订单将恢复为已完成状态，审批意见必填"
        style="margin-bottom: 16px"
      />
      <el-form label-width="90px">
        <el-form-item label="退款单号">{{ approveForm.refundNo }}</el-form-item>
        <el-form-item label="退款金额">{{ money(approveForm.amount) }} 元</el-form-item>
        <el-form-item :label="approveForm.action === 'approve' ? '审批备注' : '拒绝原因'">
          <el-input
            v-model="approveForm.comment"
            type="textarea"
            :rows="3"
            maxlength="255"
            show-word-limit
            :placeholder="approveForm.action === 'approve' ? '审批备注（选填）' : '请输入拒绝原因 / 审批意见（必填）'"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="approveVisible = false">取消</el-button>
        <el-button
          :type="approveForm.action === 'approve' ? 'success' : 'danger'"
          :loading="approveSubmitting"
          @click="submitApprove"
        >
          {{ approveForm.action === 'approve' ? '确认批准' : '确认拒绝' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 退款详情抽屉 -->
    <el-drawer v-model="detailVisible" title="退款单详情" size="56%">
      <div v-loading="detailLoading" class="detail-body">
        <template v-if="detail">
          <div class="detail-section">
            <div class="section-title">退款单信息</div>
            <el-descriptions :column="2" border>
              <el-descriptions-item label="退款单号">{{ detail.refundNo || '-' }}</el-descriptions-item>
              <el-descriptions-item label="关联订单号">{{ detail.orderNo || '-' }}</el-descriptions-item>
              <el-descriptions-item label="用户UID">{{ detail.uid || detail.userId }}</el-descriptions-item>
              <el-descriptions-item label="用户昵称">{{ detail.username || '-' }}</el-descriptions-item>
              <el-descriptions-item label="藏品">{{ detail.collectibleName || '-' }}</el-descriptions-item>
              <el-descriptions-item label="订单来源">{{ SOURCE_MAP[detail.orderSource] || detail.orderSource || '-' }}</el-descriptions-item>
              <el-descriptions-item label="退款金额（元）">
                <span class="amount">{{ money(detail.amount) }}</span>
              </el-descriptions-item>
              <el-descriptions-item label="退款状态">
                <el-tag :type="refundStatusTag(detail.status).type" size="small">
                  {{ refundStatusTag(detail.status).text }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="退款原因" :span="2">{{ detail.reason || '-' }}</el-descriptions-item>
              <el-descriptions-item label="申请人">{{ detail.applicantName || '-' }}</el-descriptions-item>
              <el-descriptions-item label="申请时间">{{ datetime(detail.createdAt) }}</el-descriptions-item>
              <el-descriptions-item label="审批人">{{ detail.approverName || '-' }}</el-descriptions-item>
              <el-descriptions-item label="审批时间">{{ datetime(detail.approvedAt) }}</el-descriptions-item>
              <el-descriptions-item label="退款渠道">{{ payMethodText(detail.refundChannel) }}</el-descriptions-item>
              <el-descriptions-item label="实际退款时间">{{ datetime(detail.refundedAt) }}</el-descriptions-item>
              <el-descriptions-item label="审批意见" :span="2">{{ detail.comment || '-' }}</el-descriptions-item>
            </el-descriptions>
          </div>

          <div class="detail-section">
            <div class="section-title">支付单信息</div>
            <el-descriptions v-if="detail.payment" :column="2" border>
              <el-descriptions-item label="支付单号">PAY-{{ detail.payment.id }}</el-descriptions-item>
              <el-descriptions-item label="支付方式">{{ payMethodText(detail.payment.paymentMethod) }}</el-descriptions-item>
              <el-descriptions-item label="支付金额（元）">{{ money(detail.payment.amount) }}</el-descriptions-item>
              <el-descriptions-item label="支付状态">
                <el-tag :type="payStatusTag(detail.payment.status).type" size="small">
                  {{ payStatusTag(detail.payment.status).text }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="三方流水号">{{ detail.payment.transactionNo || '-' }}</el-descriptions-item>
              <el-descriptions-item label="支付时间">{{ datetime(detail.payment.paidAt) }}</el-descriptions-item>
            </el-descriptions>
            <el-empty v-else description="暂无关联支付单" :image-size="70" />
          </div>

          <div class="detail-section">
            <div class="section-title">关联资产（{{ (detail.userCollectibles || []).length }} 份）</div>
            <el-table v-if="(detail.userCollectibles || []).length" :data="detail.userCollectibles" border size="small">
              <el-table-column prop="serial" label="藏品编号" min-width="160" />
              <el-table-column label="持仓状态" width="110" align="center">
                <template #default="{ row }">
                  <el-tag :type="ucStatusTag(row.status).type" size="small">{{ ucStatusTag(row.status).text }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="userId" label="持有人ID" width="110" align="center" />
            </el-table>
            <el-empty v-else description="暂无关联资产" :image-size="70" />
          </div>
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

  .table-tip {
    font-size: 12px;
    color: var(--sn-text-secondary);
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

.detail-body {
  min-height: 200px;
}
</style>
