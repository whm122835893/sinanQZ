<template>
  <div class="page-container">
    <!-- 检索区 -->
    <div class="sn-card">
      <el-form :model="query" inline class="query-form" @submit.prevent="handleSearch">
        <el-form-item label="退款单号">
          <el-input v-model="query.refundNo" placeholder="模糊匹配" clearable style="width: 200px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部状态" clearable style="width: 130px">
            <el-option v-for="(label, key) in REFUND_STATUS_MAP" :key="key" :label="label" :value="Number(key)" />
          </el-select>
        </el-form-item>
        <el-form-item label="申请时间">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">查 询</el-button>
          <el-button @click="resetSearch">重 置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 列表 -->
    <div class="sn-card">
      <div class="table-toolbar">
        <span class="toolbar-title">退款审批</span>
        <el-button :icon="'Refresh'" :loading="loading" @click="load">刷新</el-button>
      </div>

      <el-table v-loading="loading" :data="list" row-key="id">
        <el-table-column label="退款单号" min-width="205" fixed="left">
          <template #default="{ row }">
            <el-link type="primary" @click="openDetail(row)">{{ row.refundNo }}</el-link>
          </template>
        </el-table-column>
        <el-table-column label="订单号" min-width="205">
          <template #default="{ row }">
            <el-link type="primary" @click="router.push(`/order/${row.orderId}`)">{{ row.orderNo || `#${row.orderId}` }}</el-link>
          </template>
        </el-table-column>
        <el-table-column label="用户" min-width="140">
          <template #default="{ row }">
            <div class="cell-user">
              <span class="col-name">{{ row.username || '—' }}</span>
              <span class="col-sub">ID {{ row.userId }} · {{ row.phone }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="退款金额" width="110" align="right">
          <template #default="{ row }">
            <span class="din amount">¥{{ row.amount }}</span>
          </template>
        </el-table-column>
        <el-table-column label="退款原因" prop="reason" min-width="160" show-overflow-tooltip />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="REFUND_STATUS_TAG[row.status] ?? 'info'" size="small">
              {{ row.statusText || REFUND_STATUS_MAP[row.status] || row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="申请人" prop="applicantName" width="100">
          <template #default="{ row }">{{ row.applicantName || '—' }}</template>
        </el-table-column>
        <el-table-column label="退款渠道" width="90">
          <template #default="{ row }">
            {{ row.refundChannel ? (METHOD_MAP[row.refundChannel] ?? row.refundChannel) : '—' }}
          </template>
        </el-table-column>
        <el-table-column label="申请时间" prop="createdAt" width="165" />
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button text @click="openDetail(row)">详情</el-button>
            <template v-if="row.status === 1">
              <el-button v-permission="'refund:approve'" text type="primary" @click="openApprove(row)">批准</el-button>
              <el-button v-permission="'refund:approve'" text type="danger" @click="openReject(row)">拒绝</el-button>
            </template>
          </template>
        </el-table-column>
      </el-table>

      <div class="table-pagination">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="load"
          @size-change="handleSearch"
        />
      </div>
    </div>

    <!-- 退款详情（#77：订单/支付/金额/申请人/审批人） -->
    <el-dialog v-model="detailVisible" title="退款详情" width="680px" :close-on-click-modal="false">
      <div v-loading="detailLoading">
        <template v-if="detail">
          <div class="detail-head">
            <span class="detail-no din">{{ detail.refundNo }}</span>
            <el-tag :type="REFUND_STATUS_TAG[detail.status] ?? 'info'" size="small">
              {{ detail.statusText || REFUND_STATUS_MAP[detail.status] || detail.status }}
            </el-tag>
          </div>

          <el-descriptions :column="2" border size="small" class="detail-block">
            <el-descriptions-item label="退款金额">
              <span class="din amount">¥{{ detail.amount }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="退款渠道">
              {{ detail.refundChannel ? (METHOD_MAP[detail.refundChannel] ?? detail.refundChannel) : '—（批准后留痕）' }}
            </el-descriptions-item>
            <el-descriptions-item label="申请人">{{ detail.applicant?.name || '—' }}（ID {{ detail.applicant?.id ?? '—' }}）</el-descriptions-item>
            <el-descriptions-item label="审批人">{{ detail.approver?.name || '—（待审批）' }}</el-descriptions-item>
            <el-descriptions-item label="申请时间">{{ detail.createdAt }}</el-descriptions-item>
            <el-descriptions-item label="审批时间">{{ detail.approvedAt || '—' }}</el-descriptions-item>
            <el-descriptions-item label="退款完成时间">{{ detail.refundedAt || '—' }}</el-descriptions-item>
            <el-descriptions-item label="待回收资产">{{ detail.status === 1 ? `${detail.pendingAssets} 份（held 状态）` : '已回收' }}</el-descriptions-item>
            <el-descriptions-item label="退款原因" :span="2">{{ detail.reason || '—' }}</el-descriptions-item>
          </el-descriptions>

          <div class="detail-section-title">关联订单</div>
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="订单号" :span="2">
              <el-link type="primary" @click="router.push(`/order/${detail.order.id}`)">{{ detail.order.orderNo }}</el-link>
            </el-descriptions-item>
            <el-descriptions-item label="订单状态">{{ STATUS_MAP[detail.order.status] ?? detail.order.status }}</el-descriptions-item>
            <el-descriptions-item label="购买来源">{{ SOURCE_MAP[detail.order.source] ?? detail.order.source }}</el-descriptions-item>
            <el-descriptions-item label="数量">{{ detail.order.quantity }}</el-descriptions-item>
            <el-descriptions-item label="订单总额">
              <span class="din">¥{{ detail.order.totalPrice }}</span>
            </el-descriptions-item>
          </el-descriptions>

          <div class="detail-section-title">支付信息</div>
          <template v-if="detail.payment">
            <el-descriptions :column="2" border size="small">
              <el-descriptions-item label="支付方式">{{ METHOD_MAP[detail.payment.method] ?? detail.payment.method }}</el-descriptions-item>
              <el-descriptions-item label="支付金额">
                <span class="din">¥{{ detail.payment.amount }}</span>
              </el-descriptions-item>
              <el-descriptions-item label="支付状态">
                <el-tag :type="detail.payment.status === 'success' ? 'success' : 'warning'" size="small">
                  {{ detail.payment.status === 'success' ? '成功' : detail.payment.status }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="支付时间">{{ detail.payment.paidAt || '—' }}</el-descriptions-item>
            </el-descriptions>
          </template>
          <el-empty v-else description="无支付记录" :image-size="60" />

          <div class="detail-section-title">退款用户</div>
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="用户">{{ detail.user.username || '—' }}（ID {{ detail.user.id }}）</el-descriptions-item>
            <el-descriptions-item label="手机号">{{ detail.user.phone || '—' }}</el-descriptions-item>
          </el-descriptions>

          <div v-if="detail.status === 1" class="detail-actions">
            <el-button v-permission="'refund:approve'" type="primary" @click="detailVisible = false; openApprove(detailRow!)">批准退款</el-button>
            <el-button v-permission="'refund:approve'" type="danger" plain @click="detailVisible = false; openReject(detailRow!)">拒绝退款</el-button>
          </div>
        </template>
      </div>
    </el-dialog>

    <!-- 高风险：批准退款（comment + password） -->
    <PasswordVerify ref="approveRef" title="批准退款" reason-label="审批意见" hint="批准即执行：回收资产（非 held 拦截）→ 回退库存计数器 → 原路退款（balance 加回余额写流水；alipay/wechat 线下原路退回）（文档 8.9 #78）。" />
    <!-- 高风险：拒绝退款（comment + password） -->
    <PasswordVerify ref="rejectRef" title="拒绝退款" reason-label="拒绝意见" hint="拒绝后退款单闭环，订单与资产状态不变（文档 8.9 #79）。" />
  </div>
</template>

<script setup lang="ts">
// 退款审批列表（文档 8.9 #76~#79；批准/拒绝为高风险走 PasswordVerify）
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { fetchRefunds, fetchRefundDetail, approveRefund, rejectRefund } from '@/api/order'
import type { RefundDetail, RefundRow, PageData } from '@/types/api'

const router = useRouter()

const REFUND_STATUS_MAP: Record<number, string> = { 1: '待审批', 2: '已批准', 3: '已拒绝', 4: '已退款' }
const REFUND_STATUS_TAG: Record<number, string> = { 1: 'warning', 2: 'primary', 3: 'info', 4: 'success' }
const STATUS_MAP: Record<string, string> = { pending: '待支付', completed: '已完成', cancelled: '已取消' }
const SOURCE_MAP: Record<string, string> = { release: '公售', market: '市场', priority: '优先购', eligibility: '资格购' }
const METHOD_MAP: Record<string, string> = { balance: '余额', alipay: '支付宝', wechat: '微信' }

// ---------------- 检索 ----------------
const query = reactive({ refundNo: '', status: undefined as number | undefined })
const dateRange = ref<[string, string] | null>(null)

function handleSearch(): void {
  page.value = 1
  load()
}

function resetSearch(): void {
  query.refundNo = ''
  query.status = undefined
  dateRange.value = null
  handleSearch()
}

// ---------------- 列表 ----------------
const loading = ref(false)
const list = ref<RefundRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

async function load(): Promise<void> {
  loading.value = true
  try {
    const params: Record<string, unknown> = { page: page.value, pageSize: pageSize.value }
    if (query.refundNo.trim()) params.refundNo = query.refundNo.trim()
    if (query.status) params.status = query.status
    if (dateRange.value?.[0]) params.createdAtStart = dateRange.value[0]
    if (dateRange.value?.[1]) params.createdAtEnd = dateRange.value[1]
    const data = (await fetchRefunds(params)) as PageData<RefundRow>
    list.value = data.list
    total.value = data.total
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 详情（#77） ----------------
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<RefundDetail | null>(null)
const detailRow = ref<RefundRow | null>(null)

async function openDetail(row: RefundRow): Promise<void> {
  detailRow.value = row
  detailVisible.value = true
  detailLoading.value = true
  try {
    detail.value = await fetchRefundDetail(row.id)
  } catch {
    // 拦截器已提示
  } finally {
    detailLoading.value = false
  }
}

// ---------------- 批准（#78：comment + password） ----------------
const approveRef = ref<InstanceType<typeof PasswordVerify>>()
let approveTarget: RefundRow | null = null

async function openApprove(row: RefundRow): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `确认批准退款单 ${row.refundNo}（¥${row.amount}）？批准后将执行资产回收、计数器回退与原路退款。`,
      '批准退款',
      { type: 'warning', confirmButtonText: '继续审批', cancelButtonText: '返 回' }
    )
  } catch {
    return
  }
  approveTarget = row
  const ok = await approveRef.value?.open({ title: '批准退款', reasonLabel: '审批意见' })
  if (!ok || !approveTarget) return
  try {
    const res = await approveRefund(approveTarget.id, { comment: ok.reason, password: ok.password })
    const parts = [`渠道：${METHOD_MAP[res.channel] ?? res.channel}`]
    if (typeof res.recoveredAssets === 'number') parts.push(`回收资产 ${res.recoveredAssets} 份`)
    ElMessage.success(`退款已批准并执行（${parts.join('，')}）`)
    detailVisible.value = false
    load()
  } catch {
    // 拦截器已提示
  }
}

// ---------------- 拒绝（#79：comment + password） ----------------
const rejectRef = ref<InstanceType<typeof PasswordVerify>>()
let rejectTarget: RefundRow | null = null

async function openReject(row: RefundRow): Promise<void> {
  rejectTarget = row
  const ok = await rejectRef.value?.open({ title: '拒绝退款', reasonLabel: '拒绝意见' })
  if (!ok || !rejectTarget) return
  try {
    await rejectRefund(rejectTarget.id, { comment: ok.reason, password: ok.password })
    ElMessage.success(`退款单 ${rejectTarget.refundNo} 已拒绝`)
    detailVisible.value = false
    load()
  } catch {
    // 拦截器已提示
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.cell-user {
  display: flex;
  flex-direction: column;
  gap: 2px;

  .col-name {
    font-weight: 500;
    color: $sn-text-main;
    @include ellipsis;
  }

  .col-sub {
    font-size: 12px;
    color: $sn-text-muted;
    @include ellipsis;
  }
}

.detail-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;

  .detail-no {
    font-size: 16px;
    font-weight: 600;
    color: $sn-text-main;
  }
}

.detail-block {
  margin-bottom: 16px;
}

.detail-section-title {
  font-size: 13px;
  font-weight: 600;
  color: $sn-text-main;
  margin: 16px 0 8px;

  &::before {
    content: '';
    display: inline-block;
    width: 3px;
    height: 12px;
    border-radius: 2px;
    background: $sn-primary;
    margin-right: 6px;
  }
}

.detail-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid $sn-border;
}
</style>
