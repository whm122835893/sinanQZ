<script setup lang="ts">
/**
 * 市场寄售列表
 *
 * - 搜索：藏品ID / 卖家UID / 状态（后端 collectibleId / sellerKeyword / status，暂不支持按藏品名称筛选）
 * - 表格：字段与 MarketController::list 返回严格一致（后端已驼峰化）
 * - 操作：冻结 / 解冻 / 强制下架（market:manage，均需填写原因，写入审计日志）
 * - 顶部「手续费配置」弹窗（market:config）：读取并保存 resale_fee_rate 等配置，实时生效
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useListPage } from '@/utils/useListPage'
import { money, datetime, LISTING_STATUS } from '@/utils/format'
import { fetchListings, manageListing, fetchMarketConfig, saveMarketConfig } from '@/api/order'

/** 状态筛选选项（后端 in_array 白名单） */
const STATUS_OPTIONS = [
  { value: 'selling', label: '寄售中' },
  { value: 'sold', label: '已售出' },
  { value: 'cancelled', label: '已取消' },
]

function listingStatusTag(status?: string) {
  return LISTING_STATUS[status ?? ''] ?? { text: status || '-', type: 'info' as const }
}

interface ListingRow {
  id: number
  sellerId: number
  uid: string
  sellerName: string
  collectibleId: number
  collectibleName: string
  image: string
  serial: string
  price: number | string
  feeRate: number | string | null
  feeAmount: number | string
  actualAmount: number | string
  status: string
  isSystemDelisted: number
  delistReason?: string
  listedAt: string
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

const pager = useListPage(withDateRange(fetchListings), {
  collectibleId: '',
  sellerKeyword: '',
  status: '',
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

// ===== 挂单管理：冻结 / 解冻 / 强制下架（均需填原因）=====

const ACTION_TEXT: Record<string, string> = {
  freeze: '冻结挂单',
  unfreeze: '解冻挂单',
  delist: '强制下架',
}

const ACTION_TIP: Record<string, string> = {
  freeze: '冻结后挂单下架且资产置为冻结状态，用于风控审查',
  unfreeze: '解冻后资产恢复持有状态，用户可重新上架寄售',
  delist: '强制下架后挂单取消，资产恢复为持有状态',
}

async function handleManage(row: ListingRow, action: 'freeze' | 'unfreeze' | 'delist') {
  try {
    const { value } = await ElMessageBox.prompt(
      `${ACTION_TIP[action]}（挂单ID：${row.id}，藏品：${row.collectibleName || '-'}）`,
      ACTION_TEXT[action],
      {
        confirmButtonText: `确认${ACTION_TEXT[action]}`,
        cancelButtonText: '取消',
        type: 'warning',
        inputPlaceholder: '请输入操作原因（必填，将写入审计日志）',
        inputValidator: (v: string) => (v && v.trim() ? true : '操作原因不能为空'),
      },
    )
    await manageListing(row.id, { action, reason: value.trim() })
    await pager.done(`${ACTION_TEXT[action]}成功`)
  } catch {
    /* 弹窗取消或接口失败（错误已全局提示） */
  }
}

// ===== 手续费配置（market:config）=====

const configVisible = ref(false)
const configLoading = ref(false)
const configSaving = ref(false)
const configFormRef = ref<FormInstance>()
const configStats = ref<{ totalFee: number | string; soldCount: number } | null>(null)

const configForm = reactive({
  feeRate: undefined as number | undefined,
  globalMax: undefined as number | undefined,
  cooldownSeconds: undefined as number | undefined,
})

const configRules: FormRules = {
  feeRate: [
    { required: true, message: '请输入寄售手续费率', trigger: 'blur' },
    { type: 'number', min: 0, max: 20, message: '手续费率需在 0~20% 之间', trigger: 'blur' },
  ],
  globalMax: [{ type: 'number', min: 1, message: '全局最高挂售价需大于 0', trigger: 'blur' }],
  cooldownSeconds: [{ type: 'number', min: 0, max: 604800, message: '冷却时间需在 0 秒 ~ 7 天之间', trigger: 'blur' }],
}

async function openConfig() {
  configVisible.value = true
  configLoading.value = true
  configStats.value = null
  try {
    const data = await fetchMarketConfig()
    configForm.feeRate = Number(data?.feeRate ?? 0)
    configForm.globalMax = data?.globalMax ? Number(data.globalMax) : undefined
    configForm.cooldownSeconds = data?.cooldownSeconds ? Number(data.cooldownSeconds) : undefined
    configStats.value = data?.stats ?? null
  } catch {
    configVisible.value = false
  } finally {
    configLoading.value = false
  }
}

async function submitConfig() {
  const valid = await configFormRef.value?.validate().catch(() => false)
  if (!valid) return
  configSaving.value = true
  try {
    const payload: Record<string, any> = { fee_rate: configForm.feeRate }
    if (configForm.globalMax !== undefined && configForm.globalMax !== null) {
      payload.global_max = configForm.globalMax
    }
    if (configForm.cooldownSeconds !== undefined && configForm.cooldownSeconds !== null) {
      payload.cooldown_seconds = configForm.cooldownSeconds
    }
    await saveMarketConfig(payload)
    ElMessage.success('手续费配置已保存并实时生效')
    configVisible.value = false
    await pager.refresh()
  } catch {
    /* 全局提示 */
  } finally {
    configSaving.value = false
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="藏品ID">
          <el-input
            v-model="pager.filters.collectibleId"
            placeholder="藏品ID（后端暂不支持名称筛选）"
            clearable
            style="width: 210px"
          />
        </el-form-item>
        <el-form-item label="卖家UID">
          <el-input
            v-model="pager.filters.sellerKeyword"
            placeholder="卖家UID / 手机号"
            clearable
            style="width: 170px"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="pager.filters.status" placeholder="全部状态" clearable style="width: 130px">
            <el-option v-for="opt in STATUS_OPTIONS" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
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
        <span class="table-title">寄售挂单列表</span>
        <el-button v-permission="'market:config'" type="primary" plain @click="openConfig">
          <el-icon><Setting /></el-icon>&nbsp;手续费配置
        </el-button>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column label="藏品" min-width="220" fixed="left">
          <template #default="{ row }">
            <div class="collectible-cell">
              <el-image
                v-if="row.image"
                :src="row.image"
                :preview-src-list="[row.image]"
                preview-teleported
                fit="cover"
                class="table-img"
              />
              <div class="collectible-info">
                <div>{{ row.collectibleName || '-' }}</div>
                <div class="cell-sub">编号：{{ row.serial || '-' }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="卖家" min-width="130">
          <template #default="{ row }">
            <div>{{ row.uid || row.sellerId }}</div>
            <div class="cell-sub">{{ row.sellerName || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="价格（元）" width="110" align="right">
          <template #default="{ row }">
            <span class="amount">{{ money(row.price) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="手续费（元）" width="110" align="right">
          <template #default="{ row }">
            <el-tooltip
              :content="`手续费率：${row.feeRate ?? '-'}%（挂单时点费率）`"
              placement="top"
              :disabled="row.feeRate === null || row.feeRate === undefined"
            >
              <span>{{ money(row.feeAmount) }}</span>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="实际到账（元）" width="120" align="right">
          <template #default="{ row }">{{ money(row.actualAmount) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="150" align="center">
          <template #default="{ row }">
            <el-tag :type="listingStatusTag(row.status).type" size="small">
              {{ listingStatusTag(row.status).text }}
            </el-tag>
            <el-tooltip
              v-if="Number(row.isSystemDelisted) === 1"
              :content="`系统下架：${row.delistReason || '无'}`"
              placement="top"
            >
              <el-tag type="danger" size="small" effect="plain" class="delist-tag">系统下架</el-tag>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="挂单时间" width="170">
          <template #default="{ row }">{{ datetime(row.listedAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="210" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 'selling'"
              v-permission="'market:manage'"
              link
              type="warning"
              size="small"
              @click="handleManage(row, 'freeze')"
            >冻结</el-button>
            <el-button
              v-if="row.status === 'cancelled' && Number(row.isSystemDelisted) === 1"
              v-permission="'market:manage'"
              link
              type="success"
              size="small"
              @click="handleManage(row, 'unfreeze')"
            >解冻</el-button>
            <el-button
              v-if="row.status === 'selling'"
              v-permission="'market:manage'"
              link
              type="danger"
              size="small"
              @click="handleManage(row, 'delist')"
            >强制下架</el-button>
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

    <!-- 手续费配置弹窗 -->
    <el-dialog v-model="configVisible" title="市场手续费配置" width="560px">
      <div v-loading="configLoading">
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="手续费率实时生效（新挂单按新费率计算手续费），配置变更将写入操作审计日志"
          style="margin-bottom: 16px"
        />
        <el-form ref="configFormRef" :model="configForm" :rules="configRules" label-width="120px">
          <el-form-item label="手续费率（%）" prop="feeRate">
            <el-input-number
              v-model="configForm.feeRate"
              :min="0"
              :max="20"
              :precision="2"
              :step="0.5"
              controls-position="right"
              style="width: 220px"
            />
            <span class="form-tip">0 ~ 20，精确到两位小数</span>
          </el-form-item>
          <el-form-item label="全局最高价（元）" prop="globalMax">
            <el-input-number
              v-model="configForm.globalMax"
              :min="1"
              :step="100"
              controls-position="right"
              placeholder="不修改可留空"
              style="width: 220px"
            />
            <span class="form-tip">寄售价格上限（选填）</span>
          </el-form-item>
          <el-form-item label="取消后冷却（秒）" prop="cooldownSeconds">
            <el-input-number
              v-model="configForm.cooldownSeconds"
              :min="0"
              :max="604800"
              :step="60"
              controls-position="right"
              placeholder="不修改可留空"
              style="width: 220px"
            />
            <span class="form-tip">0 秒 ~ 7 天（选填）</span>
          </el-form-item>
        </el-form>
        <div v-if="configStats" class="config-stats">
          历史累计：已成交 {{ configStats.soldCount ?? 0 }} 单 · 手续费合计
          <b class="amount">{{ money(configStats.totalFee) }}</b> 元
        </div>
      </div>
      <template #footer>
        <el-button @click="configVisible = false">取消</el-button>
        <el-button type="primary" :loading="configSaving" @click="submitConfig">保存配置</el-button>
      </template>
    </el-dialog>
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

.collectible-cell {
  display: flex;
  align-items: center;
  gap: 10px;

  .collectible-info {
    min-width: 0;

    > div:first-child {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
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

.delist-tag {
  margin-left: 4px;
}

.form-tip {
  margin-left: 10px;
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.config-stats {
  padding: 10px 12px;
  background: var(--sn-red-bg);
  border-radius: 6px;
  font-size: 13px;
  color: var(--sn-text);
}
</style>
