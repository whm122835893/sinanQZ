<template>
  <div class="page-container">
    <!-- 检索区 -->
    <div class="sn-card">
      <el-form :model="query" inline class="query-form" @submit.prevent="handleSearch">
        <el-form-item label="订单号">
          <el-input v-model="query.orderNo" placeholder="模糊匹配" clearable style="width: 180px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="用户ID">
          <el-input-number v-model="query.userId" :min="1" :controls="false" placeholder="精确" style="width: 110px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option v-for="(label, key) in STATUS_MAP" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="来源">
          <el-select v-model="query.source" placeholder="全部" clearable style="width: 120px">
            <el-option v-for="(label, key) in SOURCE_MAP" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="类型">
          <el-select v-model="query.type" placeholder="全部" clearable style="width: 120px">
            <el-option label="普通藏品" value="collectible" />
            <el-option label="盲盒" value="blindbox" />
          </el-select>
        </el-form-item>
        <el-form-item label="下单时间">
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
        <span class="toolbar-title">订单列表</span>
        <div>
          <el-button v-permission="'order:audit'" :icon="'Warning'" @click="router.push('/order/abnormal')">异常订单</el-button>
          <el-button v-permission="'order:export'" :icon="'Download'" :loading="exporting" @click="handleExport">导出 CSV</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="list" row-key="id">
        <el-table-column label="订单号" min-width="210" fixed="left">
          <template #default="{ row }">
            <el-link type="primary" @click="router.push(`/order/${row.id}`)">{{ row.orderNo }}</el-link>
          </template>
        </el-table-column>
        <el-table-column label="用户" min-width="150">
          <template #default="{ row }">
            <div class="cell-user">
              <span class="col-name">{{ row.username || '—' }}</span>
              <span class="col-sub">ID {{ row.userId }} · {{ row.phone }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="藏品" min-width="180">
          <template #default="{ row }">
            <div class="cell-user">
              <span class="col-name">{{ row.collectibleName || '—' }}</span>
              <span class="col-sub">#{{ row.collectibleId }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="数量" prop="quantity" width="70" align="center" />
        <el-table-column label="单价" width="100" align="right">
          <template #default="{ row }">
            <span class="din">¥{{ row.unitPrice }}</span>
          </template>
        </el-table-column>
        <el-table-column label="总额" width="110" align="right">
          <template #default="{ row }">
            <span class="din amount">¥{{ row.totalPrice }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] ?? 'info'" size="small" effect="light">
              {{ STATUS_MAP[row.status] ?? row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="90">
          <template #default="{ row }">
            <el-tag size="small" effect="plain">{{ SOURCE_MAP[row.source] ?? row.source }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="支付时间" width="170">
          <template #default="{ row }">{{ row.paidAt || '—' }}</template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ row.createdAt }}</template>
        </el-table-column>
        <el-table-column label="操作" width="250" fixed="right">
          <template #default="{ row }">
            <el-button text type="primary" @click="router.push(`/order/${row.id}`)">详情</el-button>
            <el-button v-if="row.status === 'pending'" v-permission="'order:manage'" text type="danger" @click="openCancel(row)">强制取消</el-button>
            <el-button v-if="row.status === 'pending'" v-permission="'order:manage'" text type="warning" @click="openMarkPaid(row)">标记支付</el-button>
            <el-button v-if="row.status === 'completed'" v-permission="'order:refund'" text type="primary" @click="openRefund(row)">发起退款</el-button>
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

    <!-- 高风险：强制取消（reason + password，释放锁定库存） -->
    <PasswordVerify ref="cancelRef" title="强制取消订单" reason-label="取消原因" hint="仅待支付订单可取消；发售单将释放锁定库存，市场单挂单恢复在售（文档 8.8 #70）。" />
    <!-- 高风险：标记已支付（reason + method + password） -->
    <PasswordVerify ref="markPaidRef" title="标记已支付" reason-label="补单原因" hint="用于第三方支付回调丢失的补单，将按与 C 端一致的计数器路径完成履约（文档 8.8 #71）。" />

    <!-- 标记已支付：选择支付方式 -->
    <el-dialog v-model="methodVisible" title="选择支付方式" width="420px" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent>
        <el-form-item label="支付方式（补单按此方式入账）" required>
          <el-radio-group v-model="methodForm.method">
            <el-radio value="balance">余额</el-radio>
            <el-radio value="alipay">支付宝</el-radio>
            <el-radio value="wechat">微信</el-radio>
          </el-radio-group>
          <div class="form-hint">balance 将实际扣除用户余额；alipay/wechat 视为已实际收款。</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="methodVisible = false">取 消</el-button>
        <el-button type="primary" :loading="markPaidSubmitting" @click="submitMarkPaidStep1">下一步（验证密码）</el-button>
      </template>
    </el-dialog>

    <!-- 发起退款（amount + reason，无需密码，进入退款审批流） -->
    <el-dialog v-model="refundVisible" title="发起退款申请" width="460px" :close-on-click-modal="false">
      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="退款申请提交后进入审批流，需在「退款管理」中由具备权限的管理员批准后执行（文档 8.8 #72）"
        style="margin-bottom: 12px"
      />
      <el-form ref="refundFormRef" :model="refundForm" :rules="refundRules" label-position="top" @submit.prevent>
        <el-form-item label="退款金额（元，≤ 实付金额）" prop="amount">
          <el-input-number
            v-model="refundForm.amount"
            :min="0.01"
            :max="Number(refundTarget?.totalPrice ?? 0.01)"
            :precision="2"
            :step="1"
            style="width: 100%"
          />
          <div class="form-hint">订单总额：<b class="din">¥{{ refundTarget?.totalPrice ?? '—' }}</b>（以详情页支付金额为准）</div>
        </el-form-item>
        <el-form-item label="退款原因" prop="reason">
          <el-input v-model="refundForm.reason" type="textarea" :rows="3" maxlength="200" show-word-limit placeholder="请输入退款原因（必填）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="refundVisible = false">取 消</el-button>
        <el-button type="primary" :loading="refundSubmitting" @click="submitRefund">提交申请</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 订单列表（文档 8.8 #68；强制取消/标记支付为高风险走 PasswordVerify；发起退款走审批流）
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { fetchOrders, cancelOrder, markOrderPaid, createRefund, exportOrders } from '@/api/order'
import type { OrderRow, PageData } from '@/types/api'

const router = useRouter()

const STATUS_MAP: Record<string, string> = {
  pending: '待支付',
  completed: '已完成',
  cancelled: '已取消'
}
const STATUS_TAG: Record<string, string> = {
  pending: 'warning',
  completed: 'success',
  cancelled: 'info'
}
const SOURCE_MAP: Record<string, string> = {
  release: '公售',
  market: '市场',
  priority: '优先购',
  eligibility: '资格购'
}

// ---------------- 检索 ----------------
const query = reactive({ orderNo: '', userId: undefined as number | undefined, status: '', source: '', type: '' })
const dateRange = ref<[string, string] | null>(null)

function handleSearch(): void {
  page.value = 1
  load()
}

function resetSearch(): void {
  query.orderNo = ''
  query.userId = undefined
  query.status = ''
  query.source = ''
  query.type = ''
  dateRange.value = null
  handleSearch()
}

// ---------------- 列表 ----------------
const loading = ref(false)
const list = ref<OrderRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

async function load(): Promise<void> {
  loading.value = true
  try {
    const params: Record<string, unknown> = { page: page.value, pageSize: pageSize.value }
    if (query.orderNo.trim()) params.orderNo = query.orderNo.trim()
    if (query.userId && query.userId > 0) params.userId = query.userId
    if (query.status) params.status = query.status
    if (query.source) params.source = query.source
    if (query.type) params.type = query.type
    if (dateRange.value?.[0]) params.createdAtStart = dateRange.value[0]
    if (dateRange.value?.[1]) params.createdAtEnd = dateRange.value[1]
    const data = (await fetchOrders(params)) as PageData<OrderRow>
    list.value = data.list
    total.value = data.total
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 强制取消（#70） ----------------
const cancelRef = ref<InstanceType<typeof PasswordVerify>>()
let cancelTarget: OrderRow | null = null

async function openCancel(row: OrderRow): Promise<void> {
  cancelTarget = row
  const ok = await cancelRef.value?.open({ title: '强制取消订单', reasonLabel: '取消原因' })
  if (!ok || !cancelTarget) return
  try {
    await cancelOrder(cancelTarget.id, { reason: ok.reason, password: ok.password })
    ElMessage.success(`订单 ${cancelTarget.orderNo} 已强制取消`)
    load()
  } catch {
    // 拦截器已提示
  }
}

// ---------------- 标记已支付（#71） ----------------
const methodVisible = ref(false)
const markPaidSubmitting = ref(false)
const markPaidTarget = ref<OrderRow | null>(null)
const methodForm = reactive({ method: 'alipay' })
const markPaidRef = ref<InstanceType<typeof PasswordVerify>>()

function openMarkPaid(row: OrderRow): void {
  markPaidTarget.value = row
  methodForm.method = 'alipay'
  methodVisible.value = true
}

async function submitMarkPaidStep1(): Promise<void> {
  const target = markPaidTarget.value
  if (!target) return
  markPaidSubmitting.value = true
  try {
    const ok = await markPaidRef.value?.open({ title: '标记已支付', reasonLabel: '补单原因' })
    if (!ok) return
    await markOrderPaid(target.id, { reason: ok.reason, method: methodForm.method, password: ok.password })
    ElMessage.success(`订单 ${target.orderNo} 已标记支付并完成履约`)
    methodVisible.value = false
    load()
  } catch {
    // 拦截器已提示
  } finally {
    markPaidSubmitting.value = false
  }
}

// ---------------- 发起退款（#72，amount + reason） ----------------
const refundVisible = ref(false)
const refundSubmitting = ref(false)
const refundTarget = ref<OrderRow | null>(null)
const refundFormRef = ref<FormInstance>()
const refundForm = reactive({ amount: 0.01, reason: '' })
const refundRules: FormRules = {
  amount: [{ required: true, message: '请输入退款金额', trigger: 'blur' }],
  reason: [{ required: true, message: '退款原因不能为空', trigger: 'blur' }]
}

function openRefund(row: OrderRow): void {
  refundTarget.value = row
  refundForm.amount = Number(row.totalPrice)
  refundForm.reason = ''
  refundVisible.value = true
}

async function submitRefund(): Promise<void> {
  const target = refundTarget.value
  if (!target) return
  const valid = await refundFormRef.value?.validate().catch(() => false)
  if (!valid) return
  refundSubmitting.value = true
  try {
    const res = await createRefund(target.id, {
      amount: refundForm.amount.toFixed(2),
      reason: refundForm.reason.trim()
    })
    ElMessage.success(`退款申请已提交（${res.refundNo}），待审批`)
    refundVisible.value = false
    load()
  } catch {
    // 拦截器已提示
  } finally {
    refundSubmitting.value = false
  }
}

// ---------------- 导出（#75，后端 CSV 流式下载） ----------------
const exporting = ref(false)

async function handleExport(): Promise<void> {
  exporting.value = true
  try {
    await exportOrders({
      status: query.status || undefined,
      source: query.source || undefined,
      createdAtStart: dateRange.value?.[0] || undefined,
      createdAtEnd: dateRange.value?.[1] || undefined
    })
    ElMessage.success('导出已开始下载')
  } catch {
    // 拦截器已提示
  } finally {
    exporting.value = false
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

.form-hint {
  font-size: 12px;
  color: $sn-text-muted;
  margin-top: 4px;

  b {
    color: $sn-primary;
  }
}
</style>
