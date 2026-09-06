<script setup>
import { ref, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getChainNetworks,
  saveChainNetwork,
  testChainNetwork,
  getChainContracts,
  toggleChainContract,
  getChainTransactions
} from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import StatCard from '@/components/StatCard.vue'
import { CHAIN_TX_TYPE, CHAIN_TX_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const activeTab = ref('networks')

// ============================================================
// Tab 1：上链配置（文昌链 / 联盟链 / 蚂蚁链）
// ============================================================

const CHAIN_DESC = {
  wenchang: 'BSN-DDC 基础设施，托管网关接入，AccessKey 鉴权',
  consortium: '自建联盟网络，需配置 RPC 节点地址后方可启用',
  antchain: '蚂蚁链开放平台，需同时配置 AccessKey 与 AccessSecret'
}

const netsLoading = ref(true)
const networks = ref([])
const stats = ref(null)

async function loadNetworks() {
  netsLoading.value = true
  const res = await getChainNetworks()
  netsLoading.value = false
  if (res.code === 0) {
    networks.value = res.data?.networks || []
    stats.value = res.data?.stats || null
  }
}
loadNetworks()

async function onToggleNetwork(net) {
  const enabling = net.status !== 1
  try {
    await ElMessageBox.confirm(
      enabling
        ? `确认启用 ${net.chainName}？启用后藏品可选择该链上链。${net.chainCode === 'consortium' && !net.rpcUrl ? '（注意：联盟链启用前必须配置 RPC 地址）' : ''}`
        : `确认停用 ${net.chainName}？停用后藏品将无法选择该链上链。`,
      '链网络启停',
      { type: 'warning' }
    )
  } catch {
    return
  }
  const res = await saveChainNetwork({
    id: net.id,
    env: net.env,
    rpcUrl: net.rpcUrl || '',
    chainId: net.chainId || '',
    explorerUrl: net.explorerUrl || '',
    gasStrategy: net.gasStrategy || 'medium',
    status: enabling ? 1 : 0,
    isDefault: false,
    remark: net.remark || ''
  })
  if (res.code === 0) {
    ElMessage.success(enabling ? '已启用' : '已停用')
    loadNetworks()
  }
}

// ---- 编辑网络配置 ----
const editing = ref(false)
const editForm = ref(null)   // { id, chainCode, chainName, env, rpcUrl, chainId, explorerUrl, gasStrategy, status, isDefault, apiKey, apiSecret, remark }
const editSaving = ref(false)

const GAS_LABELS = { low: '低速（省 Gas）', medium: '中速（推荐）', high: '高速（急件）' }

function openEdit(net) {
  editForm.value = {
    id: net.id,
    chainCode: net.chainCode,
    chainName: net.chainName,
    env: net.env === 'test' ? 'test' : 'main',
    rpcUrl: net.rpcUrl || '',
    chainId: net.chainId || '',
    explorerUrl: net.explorerUrl || '',
    gasStrategy: net.gasStrategy || 'medium',
    status: net.status,
    isDefault: !!net.isDefault,
    apiKey: '',
    apiSecret: '',
    remark: net.remark || '',
    // 展示用（不可编辑）
    apiKeyMasked: net.apiKeyMasked || '',
    apiSecretMasked: net.apiSecretMasked || '',
    hasKey: !!net.hasKey,
    hasSecret: !!net.hasSecret,
    contractCount: net.contractCount || 0
  }
  editing.value = true
}

async function saveNetwork() {
  const f = editForm.value
  editSaving.value = true
  const res = await saveChainNetwork({
    id: f.id,
    env: f.env,
    rpcUrl: f.rpcUrl,
    chainId: f.chainId,
    explorerUrl: f.explorerUrl,
    gasStrategy: f.gasStrategy,
    status: f.status,
    isDefault: f.isDefault,
    apiKey: f.apiKey,
    apiSecret: f.apiSecret,
    remark: f.remark
  })
  editSaving.value = false
  if (res.code === 0) {
    ElMessage.success('链网络配置已保存')
    editing.value = false
    loadNetworks()
  }
}

// ---- 连通性测试 ----
const testingId = ref(0)
const testResult = ref(null)   // { chainName, checks, problems, latency, ok }

async function onTest(net) {
  testingId.value = net.id
  const res = await testChainNetwork(net.id)
  testingId.value = 0
  if (res.code === 0) {
    testResult.value = res.data
  }
}

// ============================================================
// Tab 2：智能合约
// ============================================================

const contractsLoading = ref(true)
const contracts = ref([])

async function loadContracts() {
  contractsLoading.value = true
  const res = await getChainContracts()
  contracts.value = res.data?.list || []
  contractsLoading.value = false
}
loadContracts()

async function onToggleContract(c) {
  const enabling = c.status !== 1
  await ElMessageBox.confirm(
    enabling ? `确认启用合约「${c.contractName}」？启用后藏品可按该合约铸造链上凭证。` : `确认停用合约「${c.contractName}」？停用后该合约地址将无法用于上链铸造。`,
    '合约启停',
    { type: 'warning' }
  )
  const res = await toggleChainContract(c.id)
  if (res.code === 0) {
    c.status = c.status === 1 ? 0 : 1
    ElMessage.success(c.status === 1 ? '已启用' : '已停用')
  }
}

// ============================================================
// Tab 3：链上交易
// ============================================================

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

const ENV_LABEL = { main: '主网', test: '测试网' }
</script>

<template>
  <div class="adm-page ch">
    <el-tabs v-model="activeTab">
      <!-- ==================== 上链配置 ==================== -->
      <el-tab-pane label="上链配置" name="networks">
        <el-skeleton v-if="netsLoading" :rows="6" animated style="padding: 16px" />
        <template v-else>
          <!-- 上链统计 -->
          <div v-if="stats" class="adm-grid">
            <StatCard icon="Link" label="已上链持仓" :value="fmtNumber(stats.onchainHoldings)" unit="件" tone="primary" />
            <StatCard icon="Clock" label="待上链持仓" :value="fmtNumber(stats.pendingHoldings)" unit="件" tone="gold" />
            <StatCard icon="TrendCharts" label="持仓上链率" :value="stats.onchainRate" unit="%" tone="green" />
            <StatCard icon="Document" label="链上交易总量" :value="fmtNumber((stats.networks || []).reduce((s, c) => s + (c.txCount || 0), 0))" unit="笔" tone="blue" />
          </div>

          <!-- 三链网络卡片 -->
          <div class="ch__nets">
            <div v-for="net in networks" :key="net.id" class="adm-card ch__net">
              <div class="ch__net-head">
                <div class="ch__net-name">
                  <span class="ch__net-badge" :class="`is-${net.chainCode}`">{{ net.chainName.slice(0, 2) }}</span>
                  <div>
                    <div class="ch__net-title">
                      {{ net.chainName }}
                      <el-tag v-if="net.isDefault" type="warning" effect="plain" size="small">默认链</el-tag>
                    </div>
                    <div class="t-tertiary" style="font-size: 12px">{{ CHAIN_DESC[net.chainCode] || '—' }}</div>
                  </div>
                </div>
                <el-switch :model-value="net.status === 1" @change="onToggleNetwork(net)" />
              </div>

              <div class="ch__net-body">
                <div class="ch__net-row">
                  <span class="t-tertiary">网络环境</span>
                  <b>{{ ENV_LABEL[net.env] || net.env }}</b>
                </div>
                <div class="ch__net-row">
                  <span class="t-tertiary">RPC 节点</span>
                  <code class="ch__net-code">{{ net.rpcUrl || '托管网关（默认）' }}</code>
                </div>
                <div class="ch__net-row">
                  <span class="t-tertiary">链 ID</span>
                  <b>{{ net.chainId || 'default' }}</b>
                </div>
                <div class="ch__net-row">
                  <span class="t-tertiary">AccessKey</span>
                  <el-tag :type="net.hasKey ? 'success' : 'info'" effect="plain" size="small">
                    {{ net.hasKey ? (net.apiKeyMasked || '已配置') : '未配置' }}
                  </el-tag>
                </div>
                <div class="ch__net-row">
                  <span class="t-tertiary">AccessSecret</span>
                  <el-tag :type="net.hasSecret ? 'success' : 'info'" effect="plain" size="small">
                    {{ net.hasSecret ? (net.apiSecretMasked || '已配置') : '未配置' }}
                  </el-tag>
                </div>
                <div class="ch__net-row">
                  <span class="t-tertiary">Gas 策略</span>
                  <b>{{ GAS_LABELS[net.gasStrategy] || net.gasStrategy }}</b>
                </div>
                <div class="ch__net-row">
                  <span class="t-tertiary">登记合约</span>
                  <b>{{ net.contractCount }} 个 · {{ fmtNumber(((stats?.networks || []).find((c) => c.chainCode === net.chainCode)?.txCount) || 0) }} 笔交易</b>
                </div>
              </div>

              <div class="ch__net-actions">
                <el-button type="primary" size="small" @click="openEdit(net)">编辑配置</el-button>
                <el-button size="small" :loading="testingId === net.id" @click="onTest(net)">连通测试</el-button>
              </div>
            </div>
          </div>
        </template>
      </el-tab-pane>

      <!-- ==================== 智能合约 ==================== -->
      <el-tab-pane label="智能合约" name="contracts" lazy>
        <el-skeleton v-if="contractsLoading" :rows="4" animated />
        <div v-else class="adm-card">
          <div class="adm-card__title">
            合约列表（登记藏品铸造合约地址，启用后方可上链铸造）
          </div>
          <el-table :data="contracts">
            <el-table-column label="合约名称" prop="contractName" min-width="150" fixed="left" />
            <el-table-column label="链 / 网络" width="170" align="center">
              <template #default="{ row }">
                <el-tag effect="plain" size="small" :type="row.chainEnv === 'main' ? 'primary' : 'info'">
                  {{ row.chainType }} · {{ ENV_LABEL[row.chainEnv] || row.chainEnv }}
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
            title="合约地址需与藏品编辑页填写的合约地址一致，藏品上链铸造时按藏品合约地址匹配"
          />
        </div>
      </el-tab-pane>

      <!-- ==================== 链上交易 ==================== -->
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
    </el-tabs>

    <!-- ============ 编辑链网络配置 ============ -->
    <el-dialog v-model="editing" :title="`编辑 ${editForm?.chainName || ''} 配置`" width="560px" destroy-on-close>
      <el-form v-if="editForm" label-width="120px">
        <el-form-item label="网络环境">
          <el-radio-group v-model="editForm.env">
            <el-radio value="main">主网</el-radio>
            <el-radio value="test">测试网</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="RPC 节点地址">
          <el-input v-model="editForm.rpcUrl" placeholder="http(s)://…（联盟链必填，托管链可留空）" />
        </el-form-item>
        <el-form-item label="链 ID">
          <el-input v-model="editForm.chainId" placeholder="如 1024 / bsn-ddc-main" style="width: 260px" />
        </el-form-item>
        <el-form-item label="区块浏览器">
          <el-input v-model="editForm.explorerUrl" placeholder="https://explorer…（用于交易查询跳转）" />
        </el-form-item>
        <el-form-item label="Gas 策略">
          <el-select v-model="editForm.gasStrategy" style="width: 260px">
            <el-option v-for="(label, v) in GAS_LABELS" :key="v" :value="v" :label="label" />
          </el-select>
        </el-form-item>
        <el-form-item label="AccessKey">
          <el-input
            v-model="editForm.apiKey"
            :placeholder="editForm.hasKey ? `已配置（${editForm.apiKeyMasked}），留空保持不变` : '未配置，请输入服务商 AccessKey'"
            show-password
          />
        </el-form-item>
        <el-form-item label="AccessSecret">
          <el-input
            v-model="editForm.apiSecret"
            :placeholder="editForm.hasSecret ? `已配置（${editForm.apiSecretMasked}），留空保持不变` : '未配置，请输入服务商 AccessSecret'"
            show-password
          />
        </el-form-item>
        <el-form-item label="启用状态">
          <el-switch v-model="editForm.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="设为默认链">
          <el-switch v-model="editForm.isDefault" :disabled="editForm.status !== 1" />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
            默认链全局唯一；新建藏品时自动预选该链
          </div>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" type="textarea" :rows="2" maxlength="255" show-word-limit placeholder="接入信息备注（可选）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editing = false">取消</el-button>
        <el-button type="primary" :loading="editSaving" @click="saveNetwork">保存配置</el-button>
      </template>
    </el-dialog>

    <!-- ============ 连通性测试结果 ============ -->
    <el-dialog :model-value="!!testResult" title="连通性测试结果" width="460px" @close="testResult = null">
      <template v-if="testResult">
        <el-alert
          :type="testResult.ok ? 'success' : 'error'"
          :closable="false"
          show-icon
          class="ch__tip"
          :title="testResult.ok ? `${testResult.chainName} 连通性校验通过（延迟 ${testResult.latency}ms）` : `${testResult.chainName} 存在配置问题`"
        />
        <div class="ch__checks">
          <div v-for="(c, i) in testResult.checks" :key="'c' + i" class="ch__check is-ok">
            <el-icon><component :is="'CircleCheck'" /></el-icon>{{ c }}
          </div>
          <div v-for="(p, i) in testResult.problems" :key="'p' + i" class="ch__check is-bad">
            <el-icon><component :is="'CircleClose'" /></el-icon>{{ p }}
          </div>
        </div>
      </template>
      <template #footer>
        <el-button type="primary" @click="testResult = null">知道了</el-button>
      </template>
    </el-dialog>
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

// ---- 网络卡片 ----
.ch__nets {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-top: 4px;

  @media (max-width: 1100px) {
    grid-template-columns: 1fr;
  }
}

.ch__net {
  display: flex;
  flex-direction: column;
}

.ch__net-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.ch__net-name {
  display: flex;
  gap: 10px;
}

.ch__net-title {
  font-weight: 600;
  font-size: 14px;
  color: $color-text-primary;
  display: flex;
  align-items: center;
  gap: 6px;
}

.ch__net-badge {
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 13px;
  color: #fff;

  &.is-wenchang { background: linear-gradient(135deg, #1d4ed8, #3b82f6); }
  &.is-consortium { background: linear-gradient(135deg, #b45309, #f59e0b); }
  &.is-antchain { background: linear-gradient(135deg, #065f46, #10b981); }
}

.ch__net-body {
  flex: 1;
  margin-top: 12px;
}

.ch__net-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 5px 0;
  border-bottom: 1px dashed $color-border;
  font-size: 12px;
  color: $color-text-secondary;

  b { color: $color-text-primary; font-weight: 600; }
}

.ch__net-code {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 11px;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ch__net-actions {
  display: flex;
  gap: 8px;
  margin-top: 12px;
}

// ---- 测试结果 ----
.ch__checks {
  margin-top: 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ch__check {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  padding: 8px 12px;
  border-radius: 6px;

  &.is-ok {
    background: rgba(7, 193, 96, 0.05);
    color: $color-text-secondary;

    .el-icon { color: #07c160; }
  }

  &.is-bad {
    background: rgba(192, 0, 0, 0.05);
    color: $color-text-primary;

    .el-icon { color: #c00000; }
  }
}
</style>
