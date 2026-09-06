<script setup lang="ts">
/**
 * 订单详情
 *
 * 按后端 OrderController::detail 返回结构分组展示：
 * 基本信息（订单聚合字段）/ 支付单信息（payment）/ 资产持仓（userCollectibles）/
 * 退款记录（refund）/ 寄售单信息（listing，市场订单关联）
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { money, datetime, PAY_METHOD, REFUND_STATUS, UC_STATUS, LISTING_STATUS } from '@/utils/format'
import { fetchOrderDetail } from '@/api/order'

const route = useRoute()
const router = useRouter()
const orderId = Number(route.params.id)

const loading = ref(false)
const detail = ref<any>(null)

async function load() {
  loading.value = true
  try {
    detail.value = await fetchOrderDetail(orderId)
  } catch {
    /* 全局提示 */
  } finally {
    loading.value = false
  }
}

onMounted(load)

function goBack() {
  if (window.history.state && window.history.state.back) {
    router.back()
  } else {
    router.push('/order')
  }
}

/** 订单状态（后端字符串枚举） */
const STATUS_MAP: Record<string, { text: string; type: 'primary' | 'success' | 'warning' | 'info' | 'danger' }> = {
  pending: { text: '待支付', type: 'warning' },
  completed: { text: '已完成', type: 'success' },
  cancelled: { text: '已取消', type: 'info' },
  refunding: { text: '退款中', type: 'primary' },
  refunded: { text: '已退款', type: 'danger' },
}

/** 订单来源（后端字符串枚举） */
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

function orderStatusTag(status?: string) {
  return STATUS_MAP[status ?? ''] ?? { text: status || '-', type: 'info' as const }
}

function payStatusTag(status?: string) {
  return PAY_STATUS_MAP[status ?? ''] ?? { text: status || '-', type: 'info' as const }
}

function refundStatusTag(status?: number) {
  return REFUND_STATUS[Number(status)] ?? { text: status ?? '-', type: 'info' as const }
}

function listingStatusTag(status?: string) {
  return LISTING_STATUS[status ?? ''] ?? { text: status || '-', type: 'info' as const }
}

/** 持仓状态（UC_STATUS 未覆盖的值兜底展示，如 recovered 已回收） */
function ucStatusTag(status?: string) {
  if (status === 'recovered') return { text: '已回收', type: 'info' as const }
  return UC_STATUS[status ?? ''] ?? { text: status || '-', type: 'info' as const }
}

function payMethodText(method?: string) {
  return PAY_METHOD[method ?? ''] ?? (method || '-')
}
</script>

<template>
  <div v-loading="loading" class="page-container">
    <!-- 顶部：返回 + 订单号 + 状态 -->
    <div class="detail-header">
      <div class="header-left">
        <el-button @click="goBack">
          <el-icon><ArrowLeft /></el-icon>&nbsp;返回
        </el-button>
        <span class="order-no">{{ detail?.orderNo ?? `订单 #${orderId}` }}</span>
        <el-tag v-if="detail" :type="orderStatusTag(detail.status).type">{{ orderStatusTag(detail.status).text }}</el-tag>
        <el-tag v-if="detail && SOURCE_MAP[detail.source]" effect="plain">
          {{ SOURCE_MAP[detail.source] }}
        </el-tag>
      </div>
      <el-button type="primary" plain @click="load">刷新</el-button>
    </div>

    <template v-if="detail">
      <!-- 基本信息 -->
      <div class="detail-section">
        <div class="section-title">基本信息</div>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="订单号">{{ detail.orderNo || '-' }}</el-descriptions-item>
          <el-descriptions-item label="订单状态">
            <el-tag :type="orderStatusTag(detail.status).type" size="small">{{ orderStatusTag(detail.status).text }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="订单来源">{{ SOURCE_MAP[detail.source] || detail.source || '-' }}</el-descriptions-item>
          <el-descriptions-item label="用户UID">{{ detail.uid || detail.userId }}</el-descriptions-item>
          <el-descriptions-item label="用户昵称">{{ detail.username || '-' }}</el-descriptions-item>
          <el-descriptions-item label="手机号">{{ detail.phone || '-' }}</el-descriptions-item>
          <el-descriptions-item label="藏品" :span="2">{{ detail.collectibleName || '-' }}</el-descriptions-item>
          <el-descriptions-item label="藏品封面">
            <el-image
              v-if="detail.image"
              :src="detail.image"
              :preview-src-list="[detail.image]"
              preview-teleported
              fit="cover"
              class="table-img"
            />
            <span v-else>-</span>
          </el-descriptions-item>
          <el-descriptions-item label="单价（元）">{{ money(detail.unitPrice) }}</el-descriptions-item>
          <el-descriptions-item label="数量">{{ detail.quantity }}</el-descriptions-item>
          <el-descriptions-item label="总价（元）">
            <span class="amount">{{ money(detail.totalPrice) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="下单时间">{{ datetime(detail.createdAt) }}</el-descriptions-item>
          <el-descriptions-item label="支付时间">{{ datetime(detail.paidAt) }}</el-descriptions-item>
          <el-descriptions-item label="完成时间">{{ datetime(detail.completedAt) }}</el-descriptions-item>
          <el-descriptions-item label="支付截止">{{ datetime(detail.expiresAt) }}</el-descriptions-item>
          <el-descriptions-item label="取消时间">{{ datetime(detail.cancelledAt) }}</el-descriptions-item>
          <el-descriptions-item label="取消原因">{{ detail.cancelReason || '-' }}</el-descriptions-item>
        </el-descriptions>
      </div>

      <!-- 支付单信息 -->
      <div class="detail-section">
        <div class="section-title">支付单信息</div>
        <el-descriptions v-if="detail.payment" :column="3" border>
          <el-descriptions-item label="支付单号">PAY-{{ detail.payment.id }}</el-descriptions-item>
          <el-descriptions-item label="支付方式">{{ payMethodText(detail.payment.paymentMethod) }}</el-descriptions-item>
          <el-descriptions-item label="支付状态">
            <el-tag :type="payStatusTag(detail.payment.status).type" size="small">
              {{ payStatusTag(detail.payment.status).text }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="支付金额（元）">{{ money(detail.payment.amount) }}</el-descriptions-item>
          <el-descriptions-item label="三方流水号">{{ detail.payment.transactionNo || '-' }}</el-descriptions-item>
          <el-descriptions-item label="支付时间">{{ datetime(detail.payment.paidAt) }}</el-descriptions-item>
        </el-descriptions>
        <el-empty v-else description="暂无支付记录" :image-size="70" />
      </div>

      <!-- 资产持仓 -->
      <div class="detail-section">
        <div class="section-title">资产持仓（{{ (detail.userCollectibles || []).length }} 份）</div>
        <el-table v-if="(detail.userCollectibles || []).length" :data="detail.userCollectibles" border size="small">
          <el-table-column prop="id" label="持仓ID" width="100" />
          <el-table-column prop="serial" label="藏品编号" min-width="160" />
          <el-table-column label="持仓状态" width="110" align="center">
            <template #default="{ row }">
              <el-tag :type="ucStatusTag(row.status).type" size="small">{{ ucStatusTag(row.status).text }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="获得时间" width="180">
            <template #default="{ row }">{{ datetime(row.acquiredAt) }}</template>
          </el-table-column>
        </el-table>
        <el-empty v-else description="暂无资产持仓（订单未完成支付或非发售来源）" :image-size="70" />
      </div>

      <!-- 退款记录 -->
      <div class="detail-section">
        <div class="section-title">退款记录</div>
        <el-descriptions v-if="detail.refund" :column="3" border>
          <el-descriptions-item label="退款单号">{{ detail.refund.refundNo || '-' }}</el-descriptions-item>
          <el-descriptions-item label="退款金额（元）">
            <span class="amount">{{ money(detail.refund.amount) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="退款状态">
            <el-tag :type="refundStatusTag(detail.refund.status).type" size="small">
              {{ refundStatusTag(detail.refund.status).text }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="退款原因" :span="3">{{ detail.refund.reason || '-' }}</el-descriptions-item>
          <el-descriptions-item label="申请人">{{ detail.refund.applicantName || '-' }}</el-descriptions-item>
          <el-descriptions-item label="审批人">{{ detail.refund.approverName || '-' }}</el-descriptions-item>
          <el-descriptions-item label="审批时间">{{ datetime(detail.refund.approvedAt) }}</el-descriptions-item>
          <el-descriptions-item label="退款渠道">{{ payMethodText(detail.refund.refundChannel) }}</el-descriptions-item>
          <el-descriptions-item label="实际退款时间">{{ datetime(detail.refund.refundedAt) }}</el-descriptions-item>
          <el-descriptions-item label="申请时间">{{ datetime(detail.refund.createdAt) }}</el-descriptions-item>
          <el-descriptions-item label="审批意见" :span="3">{{ detail.refund.comment || '-' }}</el-descriptions-item>
        </el-descriptions>
        <el-empty v-else description="暂无退款记录" :image-size="70" />
      </div>

      <!-- 寄售单信息 -->
      <div class="detail-section">
        <div class="section-title">寄售单信息</div>
        <el-descriptions v-if="detail.listing" :column="3" border>
          <el-descriptions-item label="挂单ID">{{ detail.listing.id }}</el-descriptions-item>
          <el-descriptions-item label="卖家UID">{{ detail.listing.sellerUid || detail.listing.sellerId }}</el-descriptions-item>
          <el-descriptions-item label="挂单状态">
            <el-tag :type="listingStatusTag(detail.listing.status).type" size="small">
              {{ listingStatusTag(detail.listing.status).text }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="挂售价格（元）">{{ money(detail.listing.price) }}</el-descriptions-item>
          <el-descriptions-item label="手续费率">
            {{ detail.listing.feeRate !== null && detail.listing.feeRate !== undefined ? detail.listing.feeRate + '%' : '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="手续费（元）">{{ money(detail.listing.feeAmount) }}</el-descriptions-item>
          <el-descriptions-item label="实际到账（元）">
            <span class="amount">{{ money(detail.listing.actualAmount) }}</span>
          </el-descriptions-item>
          <el-descriptions-item label="挂单时间">{{ datetime(detail.listing.listedAt) }}</el-descriptions-item>
          <el-descriptions-item label="冷却截止">{{ datetime(detail.listing.cooldownUntil) }}</el-descriptions-item>
          <el-descriptions-item label="系统下架时间">{{ datetime(detail.listing.systemDelistedAt) }}</el-descriptions-item>
          <el-descriptions-item label="下架原因" :span="2">{{ detail.listing.delistReason || '-' }}</el-descriptions-item>
        </el-descriptions>
        <el-empty v-else description="该订单非市场寄售订单，无关联挂单" :image-size="70" />
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.detail-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #fff;
  border-radius: 8px;
  padding: 12px 16px;
  margin-bottom: 12px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);

  .header-left {
    display: flex;
    align-items: center;
    gap: 12px;

    .order-no {
      font-size: 16px;
      font-weight: 600;
    }
  }
}

.amount {
  font-weight: 600;
  color: var(--sn-red);
}
</style>
