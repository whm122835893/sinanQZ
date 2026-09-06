<script setup lang="ts">
/**
 * 异常资金监控
 *
 * - 数据源：WalletController::abnormal → { threshold, largeTrans[], frequent[], negative[], bigFrozen[] }
 *   四类异常合并为统一明细表（异常类型 / 用户 / 金额 / 说明 / 时间），类型筛选与分页在前端完成
 * - 顶部「资金守恒校验」：WalletController::auditList → el-dialog 展示恒等式
 *   用户余额总和 + 平台手续费 + 已提现 = 总充值 + 总奖励收入 的各分量与差值（正常绿 / 异常红）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useListPage } from '@/utils/useListPage'
import { TRANS_TYPE, money, datetime } from '@/utils/format'
import { fetchAbnormalFunds, fetchWalletAudit } from '@/api/wallet'

/** 异常类型定义（标签 + 配色） */
const ABNORMAL_TYPES: Record<string, { label: string; tag: 'primary' | 'success' | 'warning' | 'danger' | 'info' }> = {
  large: { label: '大额流水', tag: 'warning' },
  frequent: { label: '高频交易', tag: 'primary' },
  negative: { label: '负余额', tag: 'danger' },
  frozen: { label: '冻结大户', tag: 'info' },
}

interface AbnormalRow {
  type: string
  user: string
  username?: string
  amount: number | string
  desc: string
  createdAt?: string
}

/** 四类异常响应概览计数（用于顶部统计卡） */
const overview = reactive({ threshold: 0, large: 0, frequent: 0, negative: 0, frozen: 0 })

/** 合并四类异常为统一明细行 */
function buildRows(res: any): AbnormalRow[] {
  const threshold = Number(res?.threshold ?? 0)
  const large: AbnormalRow[] = (res?.largeTrans || []).map((r: any) => ({
    type: 'large',
    user: r.uid || r.userId,
    username: r.username,
    amount: r.amount,
    desc: `${TRANS_TYPE[r.transType] || r.transType || '流水'} · ${r.title || '-'}（单笔 ≥ 阈值 ${money(threshold)} 元）`,
    createdAt: r.createdAt,
  }))
  const frequent: AbnormalRow[] = (res?.frequent || []).map((r: any) => ({
    type: 'frequent',
    user: r.uid || r.userId,
    amount: r.totalAmount,
    desc: `24 小时内交易 ${r.cnt ?? 0} 笔，合计 ${money(r.totalAmount)} 元（≥ 50 笔判定为高频）`,
  }))
  const negative: AbnormalRow[] = (res?.negative || []).map((r: any) => ({
    type: 'negative',
    user: r.uid || r.userId,
    amount: r.balance,
    desc: `余额 ${money(r.balance)} / 可用 ${money(r.available)} / 冻结 ${money(r.frozen)}，存在负数`,
  }))
  const frozen: AbnormalRow[] = (res?.bigFrozen || []).map((r: any) => ({
    type: 'frozen',
    user: r.uid || r.userId,
    amount: r.frozen,
    desc: `冻结 ${money(r.frozen)} 元，当前余额 ${money(r.balance)} 元`,
  }))
  return [...large, ...frequent, ...negative, ...frozen]
}

/**
 * 包装 fetcher：后端 abnormal 接口不分页，前端完成类型筛选 + 分页切片，
 * 并摘出各分类计数供顶部统计卡使用
 */
async function abnormalFetcher(params: Record<string, any>) {
  const res: any = await fetchAbnormalFunds({})
  overview.threshold = Number(res?.threshold ?? 0)
  overview.large = (res?.largeTrans || []).length
  overview.frequent = (res?.frequent || []).length
  overview.negative = (res?.negative || []).length
  overview.frozen = (res?.bigFrozen || []).length

  const rows = buildRows(res)
  const type = String(params.type || '')
  const filtered = type ? rows.filter((r) => r.type === type) : rows

  const page = Math.max(1, Number(params.page) || 1)
  const pageSize = Math.max(1, Number(params.pageSize) || 20)
  const start = (page - 1) * pageSize
  return { list: filtered.slice(start, start + pageSize), total: filtered.length, page, pageSize }
}

const pager = useListPage(abnormalFetcher, { type: '' })

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

const statCards = computed(() => [
  {
    label: '大额流水',
    value: overview.large,
    sub: `单笔 ≥ ${overview.threshold ? money(overview.threshold) : '—'} 元，最多展示 50 条`,
    icon: 'Warning',
    color: 'orange',
  },
  { label: '高频交易用户', value: overview.frequent, sub: '24 小时内 ≥ 50 笔，最多展示 20 个', icon: 'Timer', color: 'blue' },
  { label: '负余额钱包', value: overview.negative, sub: '余额 / 可用 / 冻结出现负数', icon: 'CircleClose', color: '' },
  { label: '冻结大户', value: overview.frozen, sub: '冻结金额降序 TOP20', icon: 'Lock', color: 'purple' },
])

// ===== 资金守恒校验（fetchWalletAudit）=====

interface AuditResult {
  formula: string
  balanceTotal: number
  feeTotal: number
  withdrawTotal: number
  rechargeTotal: number
  rewardTotal: number
  buyTotal: number
  left: number
  right: number
  diff: number
  conserved: boolean
  checkedUsers: number
  mismatchCount: number
  mismatches: any[]
  missingWallets: number
}

const auditVisible = ref(false)
const auditLoading = ref(false)
const audit = ref<AuditResult | null>(null)

async function openAudit() {
  auditVisible.value = true
  auditLoading.value = true
  try {
    audit.value = await fetchWalletAudit()
  } catch {
    auditVisible.value = false
  } finally {
    auditLoading.value = false
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 顶部操作栏 -->
    <div class="monitor-header">
      <div>
        <div class="monitor-title">异常资金监控</div>
        <div class="monitor-sub">
          实时扫描大额流水（单笔 ≥ {{ overview.threshold ? money(overview.threshold) : '—' }} 元）、高频交易（24 小时内 ≥
          50 笔）、负余额与冻结大户
        </div>
      </div>
      <el-button type="primary" :loading="auditLoading" @click="openAudit">
        <el-icon><DataAnalysis /></el-icon>&nbsp;资金守恒校验
      </el-button>
    </div>

    <!-- 异常概览统计卡 -->
    <div class="stat-grid">
      <div v-for="card in statCards" :key="card.label" class="stat-card">
        <div class="stat-info">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ card.value }}</div>
          <div class="stat-sub">{{ card.sub }}</div>
        </div>
        <div class="stat-icon" :class="card.color">
          <el-icon :size="22"><component :is="card.icon" /></el-icon>
        </div>
      </div>
    </div>

    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="异常类型">
          <el-select v-model="pager.filters.type" placeholder="全部类型" clearable style="width: 150px">
            <el-option
              v-for="(meta, key) in ABNORMAL_TYPES"
              :key="key"
              :label="meta.label"
              :value="key"
            />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="pager.search">查询</el-button>
          <el-button @click="pager.reset">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 异常明细表 -->
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">异常资金明细</span>
        <span class="table-tip">大额阈值由系统配置 large_recharge_alert 控制；高频 / 负余额 / 冻结大户无时间字段时显示 -</span>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column label="异常类型" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="ABNORMAL_TYPES[row.type]?.tag || 'info'" size="small" effect="plain">
              {{ ABNORMAL_TYPES[row.type]?.label || row.type }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="用户" min-width="130">
          <template #default="{ row }">
            <div>{{ row.user }}</div>
            <div v-if="row.username" class="cell-sub">{{ row.username }}</div>
          </template>
        </el-table-column>
        <el-table-column label="金额（元）" width="120" align="right">
          <template #default="{ row }">
            <span class="amount-bad">{{ money(row.amount) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="说明" min-width="280" show-overflow-tooltip>
          <template #default="{ row }">{{ row.desc }}</template>
        </el-table-column>
        <el-table-column label="时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
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

    <!-- 资金守恒校验结果弹窗 -->
    <el-dialog v-model="auditVisible" title="资金守恒校验" width="760px">
      <div v-loading="auditLoading" class="audit-body">
        <template v-if="audit">
          <el-alert :type="audit.conserved ? 'success' : 'error'" :closable="false" show-icon style="margin-bottom: 14px">
            <template #title>
              {{ audit.conserved
                ? '资金守恒校验通过，恒等式成立'
                : `资金守恒校验发现差异：${money(audit.diff)} 元，请核查流水` }}
            </template>
          </el-alert>

          <!-- 恒等式各分量 -->
          <div class="formula-box">
            <div class="formula-title">守恒恒等式：{{ audit.formula }}</div>
            <div class="formula-grid">
              <div class="formula-side">
                <div class="side-title">资金去向（左式）</div>
                <div class="formula-item">
                  <span>用户余额总和</span>
                  <b>{{ money(audit.balanceTotal) }}</b>
                </div>
                <div class="formula-item">
                  <span>平台手续费</span>
                  <b>{{ money(audit.feeTotal) }}</b>
                </div>
                <div class="formula-item">
                  <span>已提现</span>
                  <b>{{ money(audit.withdrawTotal) }}</b>
                </div>
                <div class="formula-item total">
                  <span>左式合计</span>
                  <b>{{ money(audit.left) }}</b>
                </div>
              </div>
              <div class="formula-eq">=</div>
              <div class="formula-side">
                <div class="side-title">资金来源（右式）</div>
                <div class="formula-item">
                  <span>总充值</span>
                  <b>{{ money(audit.rechargeTotal) }}</b>
                </div>
                <div class="formula-item">
                  <span>总奖励收入</span>
                  <b>{{ money(audit.rewardTotal) }}</b>
                </div>
                <div class="formula-item total">
                  <span>右式合计</span>
                  <b>{{ money(audit.right) }}</b>
                </div>
              </div>
            </div>
            <div class="formula-diff" :class="audit.conserved ? 'ok' : 'bad'">
              <span>差值（左式 - 右式）：<b>{{ money(audit.diff) }}</b></span>
              <span>{{ audit.conserved ? '守恒正常' : '守恒异常' }}</span>
            </div>
          </div>

          <!-- 核对概览 -->
          <div class="audit-stats">
            <div class="audit-stat">
              <span>核对用户数</span>
              <b>{{ audit.checkedUsers }}</b>
            </div>
            <div class="audit-stat" :class="{ bad: audit.mismatchCount > 0 }">
              <span>差异用户数</span>
              <b>{{ audit.mismatchCount }}</b>
            </div>
            <div class="audit-stat" :class="{ bad: audit.missingWallets > 0 }">
              <span>缺失钱包用户</span>
              <b>{{ audit.missingWallets }}</b>
            </div>
            <div class="audit-stat">
              <span>购买总支出（参考）</span>
              <b>{{ money(audit.buyTotal) }}</b>
            </div>
          </div>

          <!-- 差异明细 -->
          <template v-if="audit.mismatchCount > 0">
            <div class="mismatch-title">差异明细（最多展示 100 条）</div>
            <el-table :data="audit.mismatches" border size="small" max-height="320">
              <el-table-column label="用户UID" width="110">
                <template #default="{ row }">{{ row.uid || row.userId }}</template>
              </el-table-column>
              <el-table-column label="钱包余额（元）" width="120" align="right">
                <template #default="{ row }">{{ money(row.walletBalance) }}</template>
              </el-table-column>
              <el-table-column label="流水累计（元）" width="120" align="right">
                <template #default="{ row }">{{ row.flowSum !== undefined ? money(row.flowSum) : '-' }}</template>
              </el-table-column>
              <el-table-column label="差值（元）" width="110" align="right">
                <template #default="{ row }">
                  <span v-if="row.gap !== undefined" class="bad-text">{{ money(row.gap) }}</span>
                  <span v-else>-</span>
                </template>
              </el-table-column>
              <el-table-column label="问题说明" min-width="200" show-overflow-tooltip>
                <template #default="{ row }">
                  {{ row.issue || (row.gap !== undefined ? '钱包余额与流水累计不一致' : '-') }}
                </template>
              </el-table-column>
            </el-table>
          </template>
        </template>
      </div>
      <template #footer>
        <el-button @click="auditVisible = false">关闭</el-button>
        <el-button type="primary" :loading="auditLoading" @click="openAudit">重新校验</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.monitor-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #fff;
  border-radius: 8px;
  padding: 16px;
  margin-bottom: 12px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);

  .monitor-title {
    font-size: 16px;
    font-weight: 600;
  }

  .monitor-sub {
    margin-top: 6px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

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

.amount-bad {
  color: #c62828;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.audit-body {
  min-height: 160px;
}

.formula-box {
  border: 1px solid var(--sn-border);
  border-radius: 8px;
  padding: 14px 16px;
  margin-bottom: 14px;

  .formula-title {
    font-size: 13px;
    color: var(--sn-text-secondary);
    margin-bottom: 12px;
  }

  .formula-grid {
    display: flex;
    align-items: stretch;
    gap: 12px;
  }

  .formula-side {
    flex: 1;
    border: 1px solid var(--sn-border);
    border-radius: 6px;
    padding: 10px 12px;

    .side-title {
      font-size: 12px;
      color: var(--sn-text-secondary);
      margin-bottom: 8px;
    }
  }

  .formula-eq {
    align-self: center;
    font-size: 22px;
    font-weight: 700;
    color: var(--sn-text-secondary);
  }

  .formula-item {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    line-height: 26px;

    b {
      font-variant-numeric: tabular-nums;
    }

    &.total {
      border-top: 1px dashed var(--sn-border);
      margin-top: 6px;
      padding-top: 6px;
      font-weight: 600;
    }
  }

  .formula-diff {
    margin-top: 12px;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 13px;
    display: flex;
    justify-content: space-between;
    align-items: center;

    &.ok {
      background: #e8f5e9;
      color: #2e7d32;
    }

    &.bad {
      background: #fdecea;
      color: #c62828;
    }
  }
}

.audit-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 14px;

  .audit-stat {
    border: 1px solid var(--sn-border);
    border-radius: 6px;
    padding: 10px 12px;
    text-align: center;

    span {
      display: block;
      font-size: 12px;
      color: var(--sn-text-secondary);
      margin-bottom: 4px;
    }

    b {
      font-size: 18px;
      font-variant-numeric: tabular-nums;
    }

    &.bad b {
      color: #c62828;
    }
  }
}

.mismatch-title {
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 8px;
}

.bad-text {
  color: #c62828;
  font-weight: 600;
}
</style>
