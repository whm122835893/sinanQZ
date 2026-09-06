<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getInventoryAudit, getOrderAudit, getWalletAudit } from '@/api'
import StatCard from '@/components/StatCard.vue'
import { fmtMoney, fmtNumber } from '@/utils/format'

// ============================================================
// 数据审计中心（三大恒等式校验）
// - 库存审计：发行量 = 已售 + 锁定 + 预留 + 空投 + 销毁 + 库存池
// - 订单审计：过期未支付 / 完成无支付 / 持仓缺失 / 退款状态悬空
// - 资金审计：余额合计 + 手续费 + 已提现 = 总充值 + 总奖励（逐用户核对）
// 每次执行审计均写入操作日志（资金审计由后端强制审计）
// ============================================================

const activeTab = ref('inventory')
const loading = ref(false)
const auditTime = ref('')

const inventory = ref(null)
const orders = ref(null)
const wallet = ref(null)

const loaders = {
  inventory: getInventoryAudit,
  orders: getOrderAudit,
  wallet: getWalletAudit
}
const stores = { inventory, orders, wallet }

async function run(tab = activeTab.value) {
  loading.value = true
  const res = await loaders[tab]()
  if (res.code === 0) {
    stores[tab].value = res.data
    auditTime.value = new Date().toLocaleString('zh-CN')
  } else if (res.code !== -1) {
    ElMessage.error(res.message || '审计执行失败')
  }
  loading.value = false
}

onMounted(() => run())

// 订单审计分组（collapsed 状态）
const expandedGroups = ref({})
function toggleGroup(key) {
  expandedGroups.value[key] = !expandedGroups.value[key]
}

const ORDER_AUDIT_GROUPS = [
  { key: 'expired', label: '已过期仍待支付', desc: '超过支付时限但状态仍为 pending' },
  { key: 'noPayment', label: '已完成但无支付记录', desc: '状态 completed 但无成功支付流水' },
  { key: 'missingAssets', label: '持仓缺失', desc: '发售单已完成但用户持仓数不足' },
  { key: 'refundDangling', label: '退款状态悬空', desc: '状态 refunding 但无退款记录' }
]
</script>

<template>
  <div class="adm-page au">
    <!-- 工具栏 -->
    <div class="adm-card au__bar">
      <div class="au__bar-left">
        <span class="au__bar-title">审计执行</span>
        <el-button type="primary" :loading="loading" @click="run()">
          {{ loading ? '校验中…' : '立即校验' }}
        </el-button>
        <span v-if="auditTime" class="t-tertiary" style="font-size: 12px">最近校验：{{ auditTime }}</span>
      </div>
      <el-button @click="run()">刷新结果</el-button>
    </div>

    <el-tabs v-model="activeTab" @tab-change="run">
      <!-- ============ 库存审计 ============ -->
      <el-tab-pane label="库存审计" name="inventory">
        <el-skeleton v-if="loading && !inventory" :rows="4" animated style="padding: 16px" />
        <template v-else-if="inventory">
          <el-alert
            :type="inventory.allOk ? 'success' : 'error'"
            :closable="false"
            show-icon
            :title="inventory.allOk
              ? `全部 ${inventory.checked} 款藏品库存恒等式校验通过`
              : `发现 ${inventory.abnormalCount} 款藏品库存异常（发行量 ≠ 已售 + 锁定 + 预留 + 空投 + 销毁 + 库存池）`"
            class="au__alert"
          />
          <div v-if="!inventory.allOk" class="adm-card">
            <div class="adm-card__title">异常明细</div>
            <el-table :data="inventory.abnormal || []">
              <el-table-column label="藏品 ID" prop="id" width="90" />
              <el-table-column label="藏品名称" prop="name" min-width="200" />
              <el-table-column label="异常项" min-width="320">
                <template #default="{ row }">
                  <ul class="au__issues">
                    <li v-for="(issue, i) in row.issues" :key="i">{{ issue }}</li>
                  </ul>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </template>
      </el-tab-pane>

      <!-- ============ 订单审计 ============ -->
      <el-tab-pane label="订单审计" name="orders">
        <el-skeleton v-if="loading && !orders" :rows="4" animated style="padding: 16px" />
        <template v-else-if="orders">
          <div class="adm-grid">
            <StatCard
              v-for="g in ORDER_AUDIT_GROUPS"
              :key="g.key"
              :icon="g.key === 'expired' ? 'Timer' : g.key === 'noPayment' ? 'CreditCard' : g.key === 'missingAssets' ? 'Box' : 'RefreshLeft'"
              :label="g.label"
              :value="fmtNumber((orders[g.key] || []).length)"
              unit="单"
              :tone="g.key === 'expired' ? 'gold' : g.key === 'noPayment' ? 'blue' : g.key === 'missingAssets' ? 'primary' : 'green'"
            />
          </div>

          <div v-for="g in ORDER_AUDIT_GROUPS" :key="g.key" class="adm-card au__group">
            <div class="au__group-head" @click="toggleGroup(g.key)">
              <div class="au__group-info">
                <span class="au__group-name">{{ g.label }}</span>
                <span class="t-tertiary" style="font-size: 12px">{{ g.desc }}</span>
              </div>
              <el-tag :type="(orders[g.key] || []).length ? 'danger' : 'success'" effect="plain" size="small">
                {{ (orders[g.key] || []).length }} 单
              </el-tag>
            </div>
            <el-collapse-transition>
              <el-table v-show="expandedGroups[g.key] && (orders[g.key] || []).length" :data="orders[g.key]" size="small" class="au__group-table">
                <el-table-column label="订单 ID" prop="id" width="80" />
                <el-table-column label="订单号" prop="orderNo" min-width="190">
                  <template #default="{ row }"><code class="au__code">{{ row.orderNo }}</code></template>
                </el-table-column>
                <el-table-column label="用户 UID" prop="uid" width="120" />
                <el-table-column label="金额" width="110" align="right">
                  <template #default="{ row }">¥{{ fmtNumber(row.totalPrice) }}</template>
                </el-table-column>
                <el-table-column v-if="g.key === 'expired'" label="过期时间" prop="expiresAt" width="170" />
                <el-table-column v-if="g.key === 'noPayment'" label="完成时间" prop="completedAt" width="170" />
                <el-table-column v-if="g.key === 'missingAssets'" label="购买数量" prop="quantity" width="90" align="right" />
                <el-table-column v-if="g.key === 'refundDangling'" label="更新时间" prop="updatedAt" width="170" />
              </el-table>
            </el-collapse-transition>
            <div v-if="expandedGroups[g.key] && !(orders[g.key] || []).length" class="au__empty t-tertiary">
              该维度校验通过，无异常记录
            </div>
          </div>
        </template>
      </el-tab-pane>

      <!-- ============ 资金审计 ============ -->
      <el-tab-pane label="资金审计" name="wallet">
        <el-skeleton v-if="loading && !wallet" :rows="4" animated style="padding: 16px" />
        <template v-else-if="wallet">
          <el-alert
            :type="wallet.conserved ? 'success' : 'error'"
            :closable="false"
            show-icon
            class="au__alert"
            :title="wallet.conserved
              ? '资金守恒校验通过'
              : `资金守恒校验发现差异 ${fmtMoney(wallet.diff)} 元，请核查钱包流水`"
            :description="`恒等式：${wallet.formula}`"
          />
          <div class="adm-grid">
            <StatCard icon="Wallet" label="用户余额合计" :value="fmtMoney(wallet.balanceTotal)" unit="元" tone="primary" />
            <StatCard icon="Coin" label="平台手续费收入" :value="fmtMoney(wallet.feeTotal)" unit="元" tone="gold" />
            <StatCard icon="Download" label="已提现总额" :value="fmtMoney(wallet.withdrawTotal)" unit="元" tone="blue" />
            <StatCard
              icon="Warning"
              label="流水差异用户"
              :value="fmtNumber(wallet.mismatchCount)"
              unit="人"
              :tone="wallet.mismatchCount ? 'primary' : 'green'"
            />
          </div>
          <div class="adm-card">
            <div class="adm-card__title">恒等式分量（元）</div>
            <div class="au__formula">
              <div class="au__formula-side">
                <div class="au__formula-cap t-tertiary">左式（沉淀 + 流出）</div>
                <div class="au__f-item"><span>用户余额合计</span><b>¥{{ fmtNumber(wallet.balanceTotal) }}</b></div>
                <div class="au__f-item"><span>平台手续费</span><b>¥{{ fmtNumber(wallet.feeTotal) }}</b></div>
                <div class="au__f-item"><span>已提现</span><b>¥{{ fmtNumber(wallet.withdrawTotal) }}</b></div>
                <div class="au__f-item au__f-total"><span>左式合计</span><b>¥{{ fmtNumber(wallet.left) }}</b></div>
              </div>
              <div class="au__formula-eq" :class="{ 'is-ok': wallet.conserved, 'is-bad': !wallet.conserved }">
                {{ wallet.conserved ? '=' : '≠' }}
              </div>
              <div class="au__formula-side">
                <div class="au__formula-cap t-tertiary">右式（流入）</div>
                <div class="au__f-item"><span>总充值</span><b>¥{{ fmtNumber(wallet.rechargeTotal) }}</b></div>
                <div class="au__f-item"><span>总奖励发放</span><b>¥{{ fmtNumber(wallet.rewardTotal) }}</b></div>
                <div class="au__f-item"><span>参考：总消费</span><b>¥{{ fmtNumber(wallet.buyTotal) }}</b></div>
                <div class="au__f-item au__f-total"><span>右式合计</span><b>¥{{ fmtNumber(wallet.right) }}</b></div>
              </div>
              <div class="au__formula-side">
                <div class="au__formula-cap t-tertiary">核对</div>
                <div class="au__f-item"><span>已核对用户</span><b>{{ fmtNumber(wallet.checkedUsers) }} 人</b></div>
                <div class="au__f-item"><span>差异用户数</span><b :class="{ 'is-bad': wallet.mismatchCount }">{{ fmtNumber(wallet.mismatchCount) }} 人</b></div>
                <div class="au__f-item"><span>缺失钱包用户</span><b :class="{ 'is-bad': wallet.missingWallets }">{{ fmtNumber(wallet.missingWallets) }} 人</b></div>
                <div class="au__f-item au__f-total"><span>差异金额</span><b :class="{ 'is-bad': wallet.diff }">¥{{ fmtNumber(wallet.diff) }}</b></div>
              </div>
            </div>
          </div>
          <div v-if="(wallet.mismatches || []).length" class="adm-card">
            <div class="adm-card__title">用户流水差异明细（最多 100 条）</div>
            <el-table :data="wallet.mismatches" size="small">
              <el-table-column label="用户 UID" prop="uid" width="120" />
              <el-table-column label="钱包余额" width="120" align="right">
                <template #default="{ row }">¥{{ fmtNumber(row.walletBalance) }}</template>
              </el-table-column>
              <el-table-column label="流水累计" width="120" align="right">
                <template #default="{ row }">¥{{ fmtNumber(row.flowSum) }}</template>
              </el-table-column>
              <el-table-column label="差额" width="110" align="right">
                <template #default="{ row }">
                  <span class="price">¥{{ fmtNumber(row.gap) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="问题" min-width="140">
                <template #default="{ row }">{{ row.issue || '余额与流水累计不一致' }}</template>
              </el-table-column>
            </el-table>
          </div>
        </template>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<style scoped lang="scss">
.au__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.au__bar-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.au__bar-title {
  font-size: 14px;
  font-weight: 600;
  color: $color-text-primary;
}

.au__alert { margin-bottom: 16px; }

.au__issues {
  margin: 0;
  padding-left: 18px;
  color: #c00000;
  font-size: 13px;
  line-height: 1.7;
}

.au__group {
  margin-top: 12px;
  cursor: pointer;
}

.au__group-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.au__group-info {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.au__group-name {
  font-weight: 600;
  color: $color-text-primary;
}

.au__group-table { margin-top: 10px; }

.au__empty {
  padding: 10px 0;
  font-size: 12px;
}

.au__code {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 12px;
  color: $color-text-secondary;
  background: $color-surface;
  padding: 2px 6px;
  border-radius: 4px;
}

.au__formula {
  display: grid;
  grid-template-columns: 1fr 56px 1fr 1fr;
  gap: 16px;
  align-items: stretch;
}

.au__formula-side {
  background: $color-bg;
  border-radius: 10px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.au__formula-cap { font-size: 12px; }

.au__f-item {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
  color: $color-text-secondary;

  b {
    color: $color-text-primary;

    &.is-bad { color: #c00000; }
  }
}

.au__f-total {
  border-top: 1px dashed $color-border;
  padding-top: 8px;

  b { font-size: 15px; }
}

.au__formula-eq {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  font-weight: 700;

  &.is-ok { color: #07c160; }
  &.is-bad { color: #c00000; }
}

@media (max-width: 992px) {
  .au__formula { grid-template-columns: 1fr; }
  .au__formula-eq { padding: 6px 0; }
}
</style>
