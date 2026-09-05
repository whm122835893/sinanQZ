<template>
  <div class="page-container">
    <!-- 检索区（异常类型切换） -->
    <div class="sn-card">
      <div class="type-tabs">
        <div
          v-for="(meta, key) in ABNORMAL_TYPES"
          :key="key"
          class="type-tab"
          :class="{ 'type-tab--active': query.type === key }"
          @click="switchType(key as string)"
        >
          <el-icon><Warning /></el-icon>
          <span>{{ meta.label }}</span>
        </div>
      </div>
      <el-alert
        :type="query.type === 'missing_asset' ? 'error' : 'warning'"
        :closable="false"
        show-icon
        :title="ABNORMAL_TYPES[query.type]?.title"
        :description="ABNORMAL_TYPES[query.type]?.desc"
        style="margin-top: 12px"
      />
    </div>

    <!-- 列表 -->
    <div class="sn-card">
      <div class="table-toolbar">
        <span class="toolbar-title">{{ ABNORMAL_TYPES[query.type]?.label }}（{{ total }}）</span>
        <el-button :icon="'Refresh'" :loading="loading" @click="load">刷新</el-button>
      </div>

      <el-table v-loading="loading" :data="list" row-key="id">
        <el-table-column label="订单号" min-width="210" fixed="left">
          <template #default="{ row }">
            <el-link type="primary" @click="router.push(`/order/${row.id}`)">{{ row.orderNo }}</el-link>
          </template>
        </el-table-column>
        <el-table-column label="用户" prop="username" min-width="120">
          <template #default="{ row }">{{ row.username || '—' }}</template>
        </el-table-column>
        <el-table-column label="藏品" prop="collectibleName" min-width="150">
          <template #default="{ row }">{{ row.collectibleName || '—' }}</template>
        </el-table-column>
        <el-table-column label="数量" prop="quantity" width="70" align="center" />
        <el-table-column label="订单金额" width="100" align="right">
          <template #default="{ row }">
            <span class="din">¥{{ row.totalPrice }}</span>
          </template>
        </el-table-column>
        <el-table-column label="实付金额" width="100" align="right">
          <template #default="{ row }">
            <span class="din" :class="{ 'mismatch-danger': query.type === 'amount_mismatch' }">
              {{ row.paidAmount !== null ? `¥${row.paidAmount}` : '—' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="支付状态" width="90">
          <template #default="{ row }">
            <el-tag v-if="row.paymentStatus" :type="row.paymentStatus === 'success' ? 'success' : 'warning'" size="small">
              {{ row.paymentStatus === 'success' ? '成功' : row.paymentStatus }}
            </el-tag>
            <span v-else>—</span>
          </template>
        </el-table-column>
        <el-table-column label="订单状态" width="90">
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
        <el-table-column label="创建时间" width="165">
          <template #default="{ row }">{{ row.createdAt }}</template>
        </el-table-column>
        <el-table-column label="操作" width="110" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'order:manage'" text type="primary" @click="openRepair(row)">修复</el-button>
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

    <!-- 高风险：修复异常订单（repairType + reason + password） -->
    <PasswordVerify ref="repairRef" title="修复异常订单" reason-label="修复原因" :hint="repairHint" />
  </div>
</template>

<script setup lang="ts">
// 异常订单列表与修复（文档 8.8 #73/#74）
// missing_asset：已完成发售单缺资产行 → 补齐资产行（不动计数器）
// duplicate_charge：支付成功但订单待支付（回调丢失）→ 补全履约
// amount_mismatch：支付金额与订单不符 → 修正支付金额
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { fetchAbnormalOrders, repairOrder } from '@/api/order'
import type { AbnormalOrderRow, PageData } from '@/types/api'

const router = useRouter()

const STATUS_MAP: Record<string, string> = { pending: '待支付', completed: '已完成', cancelled: '已取消' }
const STATUS_TAG: Record<string, string> = { pending: 'warning', completed: 'success', cancelled: 'info' }
const SOURCE_MAP: Record<string, string> = { release: '公售', market: '市场', priority: '优先购', eligibility: '资格购' }

const ABNORMAL_TYPES: Record<string, { label: string; title: string; desc: string; hint: string }> = {
  missing_asset: {
    label: '已支付无资产',
    title: '订单已完成但资产行数少于购买数量',
    desc: '常见于支付回调部分成功后中断。修复将按缺口补齐资产行，不改动库存计数器（支付时计数器已更新，文档 8.8 #74）。',
    hint: '将补齐缺失的资产行（不动库存计数器，支付时已更新）。'
  },
  duplicate_charge: {
    label: '重复扣款/回调丢失',
    title: '支付成功但订单状态未流转',
    desc: '支付单已 success 而订单仍为待支付（回调丢失）。修复将按已成功支付补全订单履约（与标记已支付同路径，文档 8.8 #74）。',
    hint: '将按已成功支付的记录补全订单履约（同标记已支付逻辑）。'
  },
  amount_mismatch: {
    label: '金额不符',
    title: '支付金额与订单金额不一致',
    desc: '支付单金额 ≠ 订单总额。修复将把支付金额修正为与订单一致，修正前后金额将写入审计日志（文档 8.8 #74）。',
    hint: '将把支付金额修正为与订单总额一致。'
  }
}

// ---------------- 检索 ----------------
const query = reactive({ type: 'missing_asset' })

function switchType(type: string): void {
  if (query.type === type) return
  query.type = type
  page.value = 1
  load()
}

function handleSearch(): void {
  page.value = 1
  load()
}

// ---------------- 列表 ----------------
const loading = ref(false)
const list = ref<AbnormalOrderRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

async function load(): Promise<void> {
  loading.value = true
  try {
    const data = (await fetchAbnormalOrders({
      page: page.value,
      pageSize: pageSize.value,
      type: query.type
    })) as PageData<AbnormalOrderRow>
    list.value = data.list
    total.value = data.total
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 修复（#74：repairType + reason + password） ----------------
const repairRef = ref<InstanceType<typeof PasswordVerify>>()
const repairHint = computed(() => ABNORMAL_TYPES[query.type]?.hint)
let repairTarget: AbnormalOrderRow | null = null

async function openRepair(row: AbnormalOrderRow): Promise<void> {
  repairTarget = row
  const ok = await repairRef.value?.open({
    title: '修复异常订单',
    reasonLabel: '修复原因',
    hint: ABNORMAL_TYPES[query.type]?.hint
  })
  if (!ok || !repairTarget) return
  try {
    const res = await repairOrder(repairTarget.id, {
      repairType: query.type,
      reason: ok.reason,
      password: ok.password
    })
    let extra = ''
    if (res && typeof res === 'object') {
      if ('repairedAssets' in res) extra = `，已补齐 ${res.repairedAssets} 份资产`
      else if ('status' in res) extra = `，订单状态已流转为 ${res.status}`
      else if ('before' in res && 'after' in res) extra = `，金额 ${res.before} → ${res.after}`
    }
    ElMessage.success(`订单 ${repairTarget.orderNo} 修复成功${extra}`)
    load()
  } catch {
    // 拦截器已提示
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.type-tabs {
  display: flex;
  gap: 8px;

  .type-tab {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    border: 1px solid $sn-border;
    color: $sn-text-main;
    cursor: pointer;
    font-size: 13px;
    background: $sn-surface;
    transition: all 0.2s;

    &:hover {
      border-color: $sn-primary;
      color: $sn-primary;
    }

    &--active {
      border-color: $sn-primary;
      color: $sn-primary;
      background: rgba(9, 88, 217, 0.06);
      font-weight: 600;
    }
  }
}

.mismatch-danger {
  color: $sn-danger;
  font-weight: 600;
}
</style>
