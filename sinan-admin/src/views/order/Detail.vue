<template>
  <div v-loading="loading" class="page-container">
    <!-- 顶部：返回 + 订单概要 -->
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push('/order')">返回列表</el-button>
        <div class="head-meta">
          <div class="head-name">
            <h3 class="din">{{ detail?.orderNo || '订单详情' }}</h3>
            <el-tag v-if="detail" :type="STATUS_TAG[detail.status] ?? 'info'" size="small">
              {{ STATUS_MAP[detail.status] ?? detail.status }}
            </el-tag>
            <el-tag v-if="detail" size="small" effect="plain">{{ SOURCE_MAP[detail.source] ?? detail.source }}</el-tag>
          </div>
          <span class="head-sub">
            {{ detail?.username || '—' }}（ID {{ detail?.userId }}）购买 {{ detail?.collectibleName || '—' }} × {{ detail?.quantity }}
          </span>
        </div>
      </div>
      <div class="head-actions">
        <el-button v-if="detail?.status === 'pending'" v-permission="'order:manage'" type="danger" plain @click="openCancel">强制取消</el-button>
        <el-button v-if="detail?.status === 'pending'" v-permission="'order:manage'" type="warning" plain @click="openMarkPaid">标记已支付</el-button>
        <el-button v-if="detail?.status === 'completed'" v-permission="'order:refund'" type="primary" @click="openRefund">发起退款</el-button>
      </div>
    </div>

    <template v-if="detail">
      <!-- 金额概览 -->
      <div class="stock-grid">
        <div class="sn-card stock-card">
          <span class="stock-label">订单总额</span>
          <span class="stock-value din amount">¥{{ detail.totalPrice }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">单价</span>
          <span class="stock-value din">¥{{ detail.unitPrice }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">数量</span>
          <span class="stock-value din">{{ detail.quantity }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">实付金额</span>
          <span class="stock-value din amount">¥{{ detail.payment?.amount ?? '—' }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">关联资产</span>
          <span class="stock-value din">{{ detail.assets?.length ?? 0 }}</span>
        </div>
      </div>

      <div class="detail-grid">
        <!-- 基础信息 -->
        <div class="sn-card">
          <div class="card-title">基础信息</div>
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="订单ID">{{ detail.id }}</el-descriptions-item>
            <el-descriptions-item label="订单号">{{ detail.orderNo }}</el-descriptions-item>
            <el-descriptions-item label="用户">
              <span>{{ detail.username || '—' }}（ID {{ detail.userId }}）</span>
            </el-descriptions-item>
            <el-descriptions-item label="手机号">{{ detail.phone || '—' }}</el-descriptions-item>
            <el-descriptions-item label="藏品">
              <el-link type="primary" @click="router.push(`/collectible/${detail.collectibleId}`)">
                {{ detail.collectibleName || '—' }}（#{{ detail.collectibleId }}）
              </el-link>
            </el-descriptions-item>
            <el-descriptions-item label="购买来源">{{ SOURCE_MAP[detail.source] ?? detail.source }}</el-descriptions-item>
            <el-descriptions-item label="寄售挂单">{{ detail.resaleListingId ? `#${detail.resaleListingId}` : '—（发售单）' }}</el-descriptions-item>
            <el-descriptions-item label="创建时间">{{ detail.createdAt }}</el-descriptions-item>
            <el-descriptions-item label="支付时间">{{ detail.paidAt || '—' }}</el-descriptions-item>
            <el-descriptions-item label="完成时间">{{ detail.completedAt || '—' }}</el-descriptions-item>
            <el-descriptions-item label="取消时间">{{ detail.cancelledAt || '—' }}</el-descriptions-item>
            <el-descriptions-item label="取消原因">{{ detail.cancelReason || '—' }}</el-descriptions-item>
          </el-descriptions>
        </div>

        <!-- 支付信息 -->
        <div class="sn-card">
          <div class="card-title">支付信息</div>
          <template v-if="detail.payment">
            <el-descriptions :column="2" border size="small">
              <el-descriptions-item label="支付单号">{{ detail.payment.id }}</el-descriptions-item>
              <el-descriptions-item label="支付方式">{{ METHOD_MAP[detail.payment.method] ?? detail.payment.method }}</el-descriptions-item>
              <el-descriptions-item label="支付金额">
                <span class="din amount">¥{{ detail.payment.amount }}</span>
              </el-descriptions-item>
              <el-descriptions-item label="支付状态">
                <el-tag :type="detail.payment.status === 'success' ? 'success' : 'warning'" size="small">
                  {{ detail.payment.status === 'success' ? '成功' : detail.payment.status }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="交易号" :span="2">{{ detail.payment.transactionNo || '—' }}</el-descriptions-item>
              <el-descriptions-item label="支付时间" :span="2">{{ detail.payment.paidAt || '—' }}</el-descriptions-item>
            </el-descriptions>
          </template>
          <el-empty v-else description="暂无支付记录（待支付订单）" :image-size="60" />
        </div>

        <!-- 关联资产 -->
        <div class="sn-card">
          <div class="card-title">关联资产（{{ detail.assets?.length ?? 0 }}）</div>
          <el-empty v-if="!detail.assets?.length" description="暂无资产行（支付履约后生成）" :image-size="60" />
          <el-table v-else :data="detail.assets" size="small">
            <el-table-column label="资产ID" prop="id" width="80" />
            <el-table-column label="编号" prop="serial" min-width="140" />
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag :type="ASSET_STATUS_TAG[row.status] ?? 'info'" size="small" effect="light">
                  {{ ASSET_STATUS_MAP[row.status] ?? row.status }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="来源" width="90">
              <template #default="{ row }">{{ ASSET_SOURCE_MAP[row.source] ?? row.source }}</template>
            </el-table-column>
            <el-table-column label="获得价" width="100" align="right">
              <template #default="{ row }">
                <span class="din">¥{{ row.acquiredPrice }}</span>
              </template>
            </el-table-column>
            <el-table-column label="获得时间" prop="acquiredAt" min-width="165" />
          </el-table>
        </div>

        <!-- 退款记录 -->
        <div class="sn-card">
          <div class="card-title">退款记录（{{ detail.refunds?.length ?? 0 }}）</div>
          <el-empty v-if="!detail.refunds?.length" description="暂无退款记录" :image-size="60" />
          <el-table v-else :data="detail.refunds" size="small">
            <el-table-column label="退款单号" prop="refundNo" min-width="190" />
            <el-table-column label="金额" width="110" align="right">
              <template #default="{ row }">
                <span class="din amount">¥{{ row.amount }}</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag :type="REFUND_STATUS_TAG[row.status] ?? 'info'" size="small">
                  {{ REFUND_STATUS_MAP[row.status] ?? row.status }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="原因" prop="reason" min-width="150" show-overflow-tooltip />
            <el-table-column label="创建时间" prop="createdAt" min-width="165" />
          </el-table>
        </div>
      </div>
    </template>

    <!-- 高风险：强制取消 -->
    <PasswordVerify ref="cancelRef" title="强制取消订单" reason-label="取消原因" hint="仅待支付订单可取消；发售单将释放锁定库存，市场单挂单恢复在售（文档 8.8 #70）。" />
    <!-- 高风险：标记已支付 -->
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

    <!-- 发起退款（amount + reason） -->
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
            :max="Number(detail?.payment?.amount ?? detail?.totalPrice ?? 0.01)"
            :precision="2"
            :step="1"
            style="width: 100%"
          />
          <div class="form-hint">实付金额：<b class="din">¥{{ detail?.payment?.amount ?? detail?.totalPrice ?? '—' }}</b></div>
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
// 订单详情（文档 8.8 #69：基础/支付/资产/退款四块；高风险操作与列表页同路径）
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { fetchOrderDetail, cancelOrder, markOrderPaid, createRefund } from '@/api/order'
import type { OrderDetail } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const STATUS_MAP: Record<string, string> = { pending: '待支付', completed: '已完成', cancelled: '已取消' }
const STATUS_TAG: Record<string, string> = { pending: 'warning', completed: 'success', cancelled: 'info' }
const SOURCE_MAP: Record<string, string> = { release: '公售', market: '市场', priority: '优先购', eligibility: '资格购' }
const METHOD_MAP: Record<string, string> = { balance: '余额', alipay: '支付宝', wechat: '微信' }
const REFUND_STATUS_MAP: Record<number, string> = { 1: '待审批', 2: '已批准', 3: '已拒绝', 4: '已退款' }
const REFUND_STATUS_TAG: Record<number, string> = { 1: 'warning', 2: 'primary', 3: 'info', 4: 'success' }
const ASSET_STATUS_MAP: Record<string, string> = {
  held: '持有中',
  consigned: '寄售中',
  frozen: '转赠冻结',
  transferred: '已转赠',
  consumed: '已消耗'
}
const ASSET_STATUS_TAG: Record<string, string> = { held: 'success', consigned: 'warning', frozen: 'warning', transferred: 'info', consumed: 'danger' }
const ASSET_SOURCE_MAP: Record<string, string> = { purchase: '购买', airdrop: '空投', synthesis: '合成', lottery: '抽奖' }

// ---------------- 加载 ----------------
const loading = ref(false)
const detail = ref<OrderDetail | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchOrderDetail(id)
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 强制取消（#70） ----------------
const cancelRef = ref<InstanceType<typeof PasswordVerify>>()

async function openCancel(): Promise<void> {
  const target = detail.value
  if (!target) return
  const ok = await cancelRef.value?.open({ title: '强制取消订单', reasonLabel: '取消原因' })
  if (!ok) return
  try {
    await cancelOrder(target.id, { reason: ok.reason, password: ok.password })
    ElMessage.success(`订单 ${target.orderNo} 已强制取消`)
    load()
  } catch {
    // 拦截器已提示
  }
}

// ---------------- 标记已支付（#71） ----------------
const methodVisible = ref(false)
const markPaidSubmitting = ref(false)
const methodForm = reactive({ method: 'alipay' })
const markPaidRef = ref<InstanceType<typeof PasswordVerify>>()

function openMarkPaid(): void {
  methodForm.method = 'alipay'
  methodVisible.value = true
}

async function submitMarkPaidStep1(): Promise<void> {
  const target = detail.value
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

// ---------------- 发起退款（#72） ----------------
const refundVisible = ref(false)
const refundSubmitting = ref(false)
const refundFormRef = ref<FormInstance>()
const refundForm = reactive({ amount: 0.01, reason: '' })
const refundRules: FormRules = {
  amount: [{ required: true, message: '请输入退款金额', trigger: 'blur' }],
  reason: [{ required: true, message: '退款原因不能为空', trigger: 'blur' }]
}

function openRefund(): void {
  const target = detail.value
  if (!target) return
  refundForm.amount = Number(target.payment?.amount ?? target.totalPrice)
  refundForm.reason = ''
  refundVisible.value = true
}

async function submitRefund(): Promise<void> {
  const target = detail.value
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

onMounted(load)
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.form-hint {
  font-size: 12px;
  color: $sn-text-muted;
  margin-top: 4px;

  b {
    color: $sn-primary;
  }
}
</style>
