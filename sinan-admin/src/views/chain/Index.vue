<script setup>
import { ref, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getChainContracts, toggleChainContract, getChainTransactions } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import EChart from '@/components/EChart.vue'
import { CHAIN_TX_TYPE, CHAIN_TX_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const activeTab = ref('contracts')

// ---- 合约管理 ----
const contractsLoading = ref(true)
const contracts = ref([])

async function loadContracts() {
  contractsLoading.value = true
  const res = await getChainContracts()
  contracts.value = res.data
  contractsLoading.value = false
}
loadContracts()

async function onToggleContract(c) {
  const enabling = c.status !== 1
  await ElMessageBox.confirm(
    enabling ? `确认启用合约「${c.contractName}」？启用后将开始监听链上事件。` : `确认停用合约「${c.contractName}」？停用后不再同步该合约链上事件。`,
    '合约启停',
    { type: 'warning' }
  )
  const res = await toggleChainContract(c.id)
  if (res.code === 0) {
    c.status = res.data
    ElMessage.success(c.status === 1 ? '已启用' : '已停用')
  }
}

// ---- 链上交易筛选 ----
const txFilters = [
  {
    field: 'type',
    label: '事件类型',
    options: Object.entries(CHAIN_TX_TYPE).map(([value, o]) => ({ value, label: o.label }))
  },
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'success', label: '成功' },
      { value: 'pending', label: '上链中' },
      { value: 'failed', label: '失败' }
    ]
  }
]

// ---- Gas 预估（Mock 演示） ----
const gasOption = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 50, right: 16, top: 20, bottom: 24 },
  xAxis: {
    type: 'category',
    data: ['极低', '低', '中', '高', '极高'],
    axisLine: { lineStyle: { color: '#ddd' } },
    axisLabel: { color: '#999' }
  },
  yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
  series: [{
    name: 'Gas (Gwei)',
    type: 'bar',
    barWidth: 26,
    data: [8, 14, 22, 38, 65],
    itemStyle: {
      borderRadius: [4, 4, 0, 0],
      color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: [
        { offset: 0, color: '#C00000' }, { offset: 1, color: 'rgba(192,0,0,0.4)' }
      ] }
    }
  }]
}))
</script>

<template>
  <div class="adm-page ch">
    <el-tabs v-model="activeTab">
      <!-- 合约管理 -->
      <el-tab-pane label="智能合约" name="contracts">
        <el-skeleton v-if="contractsLoading" :rows="4" animated />
        <div v-else class="adm-card">
          <div class="adm-card__title">
            合约列表（多链多合约，监听 Mint / Transfer / Sale 事件）
          </div>
          <el-table :data="contracts">
            <el-table-column label="合约名称" prop="contractName" min-width="150" fixed="left" />
            <el-table-column label="链 / 网络" width="140" align="center">
              <template #default="{ row }">
                <el-tag effect="plain" size="small" :type="row.chainEnv === 'mainnet' ? 'primary' : 'info'">
                  {{ row.chainType }} · {{ row.chainEnv === 'mainnet' ? '主网' : '测试网' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="合约地址" min-width="160">
              <template #default="{ row }">
                <code class="ch__addr">{{ row.contractAddress }}</code>
              </template>
            </el-table-column>
            <el-table-column label="累计交易" width="110" align="right">
              <template #default="{ row }">{{ fmtNumber(row.txCount) }}</template>
            </el-table-column>
            <el-table-column label="监听状态" width="100" align="center">
              <template #default="{ row }">
                <el-switch :model-value="row.status === 1" @change="onToggleContract(row)" />
              </template>
            </el-table-column>
            <el-table-column label="部署时间" prop="createTime" width="160" />
          </el-table>
          <el-alert
            type="info"
            :closable="false"
            show-icon
            class="ch__tip"
            title="合约 ABI 文件上传与部署由后端 ChainService 执行；事件通过 WebSocket 实时同步至 nft_chain_transactions 表"
          />
        </div>
      </el-tab-pane>

      <!-- 链上交易 -->
      <el-tab-pane label="链上交易" name="transactions" lazy>
        <AdminTablePage
          :fetch="getChainTransactions"
          :filters="txFilters"
          search-placeholder="搜索交易哈希 / 用户 / 资产"
        >
          <template #default="{ items }">
            <el-table-column label="交易哈希" min-width="150" fixed="left">
              <template #default="{ row }">
                <code class="ch__hash">{{ row.txHash }}</code>
              </template>
            </el-table-column>
            <el-table-column label="事件" width="90" align="center">
              <template #default="{ row }">
                <StatusTag :value="row.type" :map="CHAIN_TX_TYPE" />
              </template>
            </el-table-column>
            <el-table-column label="合约" prop="contractName" min-width="130" />
            <el-table-column label="用户" prop="userName" min-width="110" />
            <el-table-column label="资产" prop="token" min-width="180" show-overflow-tooltip />
            <el-table-column label="Gas" width="110" align="right">
              <template #default="{ row }">
                <span class="t-secondary">{{ row.gas }}</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="90" align="center">
              <template #default="{ row }">
                <StatusTag :value="row.status" :map="CHAIN_TX_STATUS" />
              </template>
            </el-table-column>
            <el-table-column label="区块时间" prop="blockTime" width="160" />
            <el-table-column label="操作" width="100" fixed="right">
              <template #default>
                <el-button link type="primary" size="small">浏览器查询</el-button>
              </template>
            </el-table-column>
          </template>
        </AdminTablePage>
      </el-tab-pane>

      <!-- Gas 管理 -->
      <el-tab-pane label="Gas 管理" name="gas" lazy>
        <div class="adm-card">
          <div class="adm-card__title">Gas 费用预估与补贴策略</div>
          <div class="ch__gas-grid">
            <div class="ch__gas-main">
              <EChart :option="gasOption" :height="280" />
            </div>
            <div class="ch__gas-side">
              <div class="ch__gas-item">
                <div class="t-tertiary" style="font-size: 12px">当前策略</div>
                <div class="ch__gas-val">中速（Medium）</div>
              </div>
              <div class="ch__gas-item">
                <div class="t-tertiary" style="font-size: 12px">平台补贴比例</div>
                <div class="ch__gas-val">50%</div>
              </div>
              <div class="ch__gas-item">
                <div class="t-tertiary" style="font-size: 12px">预估单笔 Mint 成本</div>
                <div class="ch__gas-val">¥ 4.68</div>
              </div>
              <el-alert
                type="warning"
                :closable="false"
                show-icon
                title="高波动时段建议切换低速策略或延后批量铸造"
              />
            </div>
          </div>
        </div>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<style scoped lang="scss">
.ch__addr,
.ch__hash {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 12px;
  color: $color-text-secondary;
  background: $color-surface;
  padding: 2px 6px;
  border-radius: 4px;
}

.ch__tip { margin-top: 12px; }

.ch__gas-grid {
  display: grid;
  grid-template-columns: 1fr 280px;
  gap: 20px;
}

.ch__gas-side {
  display: flex;
  flex-direction: column;
  gap: 10px;
  justify-content: center;
}

.ch__gas-item {
  background: $color-bg;
  border-radius: 8px;
  padding: 12px 14px;
}

.ch__gas-val {
  font-size: 20px;
  font-weight: 700;
  color: $color-text-primary;
  margin-top: 4px;
}

@media (max-width: 900px) {
  .ch__gas-grid { grid-template-columns: 1fr; }
}
</style>
