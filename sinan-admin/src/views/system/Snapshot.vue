<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { generateSnapshots, getHoldingsSnapshots, getTradeSnapshots, getSnapshotDates } from '@/api'

// ------------------------------------------------------------
// 数据快照：持仓快照（状态定格）/ 交易快照（按日聚合），手动触发生成
// ------------------------------------------------------------
const tab = ref('holdings')
const today = new Date().toISOString().slice(0, 10)

// ---- 生成表单 ----
const genForm = reactive({ date: today, userId: '' })
const generating = ref(false)
const lastResult = ref(null)   // { date, holdings, trades, generatedAt }

// ---- 列表状态 ----
const loading = ref(false)
const list = ref([])
const total = ref(0)
const query = reactive({ page: 1, pageSize: 20, date: '', userId: '' })

onMounted(() => { loadDates(); load() })

async function loadDates() {
  const res = await getSnapshotDates()
  dates.value = (res.data || []).map((d) => d.date)
}
const dates = ref([])

async function load() {
  loading.value = true
  try {
    const params = {
      page: query.page,
      pageSize: query.pageSize,
      date: query.date || undefined,
      userId: query.userId || undefined
    }
    const res = tab.value === 'holdings'
      ? await getHoldingsSnapshots(params)
      : await getTradeSnapshots(params)
    if (res.code !== 0) return
    list.value = res.data.list || []
    total.value = res.data.total || 0
  } finally {
    loading.value = false
  }
}

function switchTab() {
  query.page = 1
  load()
}

function search() {
  query.page = 1
  load()
}

async function doGenerate() {
  await ElMessageBox.confirm(
    `将生成 ${genForm.date} 的快照${genForm.userId ? `（用户 #${genForm.userId}）` : '（全部用户）'}，同日已有快照将被覆盖重跑。`,
    '生成快照',
    { type: 'info', confirmButtonText: '生成', cancelButtonText: '取消' }
  )
  generating.value = true
  try {
    const res = await generateSnapshots({ date: genForm.date, userId: genForm.userId || null })
    if (res.code !== 0) return
    lastResult.value = res.data
    ElMessage.success(`快照生成完成：持仓 ${res.data.holdings} 行 / 交易 ${res.data.trades} 行`)
    loadDates()
    // 查询条件对齐刚生成的日期，立即能看到结果
    if (genForm.userId) { query.userId = genForm.userId; query.date = genForm.date }
    else { query.userId = ''; query.date = genForm.date }
    query.page = 1
    load()
  } finally {
    generating.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page__head">
      <h2>数据快照</h2>
      <p class="page__tip">手动生成用户持仓/交易快照，用于对账、审计与资产回溯；同日重跑覆盖旧快照</p>
    </div>

    <!-- 生成入口 -->
    <el-card shadow="never" class="gen-card">
      <div class="gen-form">
        <div class="gen-field">
          <span class="gen-label">基准日期</span>
          <el-date-picker v-model="genForm.date" type="date" value-format="YYYY-MM-DD" :clearable="false" style="width:160px" />
          <span class="gen-tip">持仓=当日状态定格；交易=当日发生的流水（可补跑历史日期）</span>
        </div>
        <div class="gen-field">
          <span class="gen-label">指定用户</span>
          <el-input v-model="genForm.userId" placeholder="用户ID（留空=全部用户）" clearable style="width:220px" />
        </div>
        <el-button type="primary" :loading="generating" @click="doGenerate">生成快照</el-button>
        <div style="flex:1" />
        <div v-if="lastResult" class="gen-last">
          上次生成 {{ lastResult.date }}：持仓 {{ lastResult.holdings }} 行 · 交易 {{ lastResult.trades }} 行（{{ lastResult.generatedAt }}）
        </div>
      </div>
      <div v-if="dates.length" class="gen-dates">
        已有快照：
        <el-tag v-for="d in dates" :key="d" size="small" class="gen-date-tag" @click="query.date = d; search()">{{ d }}</el-tag>
      </div>
    </el-card>

    <el-tabs v-model="tab" @tab-change="switchTab">
      <el-tab-pane label="持仓快照" name="holdings" />
      <el-tab-pane label="交易快照" name="trades" />
    </el-tabs>

    <!-- 筛选 -->
    <div class="toolbar">
      <el-date-picker v-model="query.date" type="date" value-format="YYYY-MM-DD" placeholder="快照日期" clearable style="width:150px" />
      <el-input v-model="query.userId" placeholder="用户ID" clearable style="width:140px" @keyup.enter="search" />
      <el-button @click="search">查询</el-button>
      <div style="flex:1" />
      <span style="color:#999;font-size:13px">共 {{ total }} 条</span>
    </div>

    <!-- 持仓快照表 -->
    <el-table v-if="tab === 'holdings'" :data="list" v-loading="loading" stripe style="width:100%">
      <el-table-column prop="snapshotDate" label="快照日期" width="110" />
      <el-table-column label="用户" min-width="170">
        <template #default="{ row }">
          <b>{{ row.userName || row.userId }}</b>
          <span style="color:#999;font-size:12px"> / {{ row.userPhone || '-' }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="collectibleName" label="藏品" min-width="130">
        <template #default="{ row }">
          {{ row.collectibleName }}
          <span style="color:#999;font-size:12px">#{{ row.collectibleId }}</span>
        </template>
      </el-table-column>
      <el-table-column label="总持有" width="80" align="center">
        <template #default="{ row }"><b style="color:#C00000">{{ row.totalCount }}</b></template>
      </el-table-column>
      <el-table-column label="持有中" width="75" align="center">
        <template #default="{ row }">{{ row.heldCount }}</template>
      </el-table-column>
      <el-table-column label="寄售中" width="75" align="center">
        <template #default="{ row }"><span style="color:#e6a23c">{{ row.consignedCount }}</span></template>
      </el-table-column>
      <el-table-column label="冻结中" width="75" align="center">
        <template #default="{ row }"><span style="color:#909399">{{ row.frozenCount }}</span></template>
      </el-table-column>
      <el-table-column label="平均成本" width="100" align="right">
        <template #default="{ row }">¥{{ Number(row.avgCost).toFixed(2) }}</template>
      </el-table-column>
      <el-table-column prop="createdAt" label="生成时间" width="180" />
    </el-table>

    <!-- 交易快照表 -->
    <el-table v-else :data="list" v-loading="loading" stripe style="width:100%">
      <el-table-column prop="snapshotDate" label="快照日期" width="110" />
      <el-table-column label="用户" min-width="170">
        <template #default="{ row }">
          <b>{{ row.userName || row.userId }}</b>
          <span style="color:#999;font-size:12px"> / {{ row.userPhone || '-' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="买入" width="130" align="right">
        <template #default="{ row }">
          <span v-if="row.buyCount">{{ row.buyCount }} 笔 / <b>¥{{ Number(row.buyAmount).toFixed(2) }}</b></span>
          <span v-else style="color:#ccc">—</span>
        </template>
      </el-table-column>
      <el-table-column label="卖出（实收）" width="130" align="right">
        <template #default="{ row }">
          <span v-if="row.sellCount" style="color:#67c23a">{{ row.sellCount }} 笔 / ¥{{ Number(row.sellAmount).toFixed(2) }}</span>
          <span v-else style="color:#ccc">—</span>
        </template>
      </el-table-column>
      <el-table-column label="转赠" width="90" align="center">
        <template #default="{ row }">
          <span v-if="row.transferInCount || row.transferOutCount">入 {{ row.transferInCount }} / 出 {{ row.transferOutCount }}</span>
          <span v-else style="color:#ccc">—</span>
        </template>
      </el-table-column>
      <el-table-column label="开盒" width="70" align="center">
        <template #default="{ row }">
          <span v-if="row.blindboxOpenCount">{{ row.blindboxOpenCount }}</span>
          <span v-else style="color:#ccc">—</span>
        </template>
      </el-table-column>
      <el-table-column label="消耗" width="70" align="center">
        <template #default="{ row }">
          <span v-if="row.consumeCount">{{ row.consumeCount }}</span>
          <span v-else style="color:#ccc">—</span>
        </template>
      </el-table-column>
      <el-table-column prop="createdAt" label="生成时间" width="180" />
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pageSize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="load"
        @size-change="search"
      />
    </div>
  </div>
</template>

<style scoped>
.page { padding: 16px; }
.page__head { margin-bottom: 12px; }
.page__head h2 { margin: 0 0 4px; font-size: 18px; }
.page__tip { margin: 0; font-size: 13px; color: #999; }

.gen-card { margin-bottom: 12px; }
.gen-card :deep(.el-card__body) { padding: 14px 16px; }
.gen-form { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
.gen-field { display: flex; align-items: center; gap: 8px; }
.gen-label { font-size: 13px; color: #666; }
.gen-tip { font-size: 12px; color: #bbb; }
.gen-last { font-size: 12px; color: #999; }
.gen-dates { margin-top: 10px; font-size: 12px; color: #999; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.gen-date-tag { cursor: pointer; }

.toolbar { display: flex; align-items: center; gap: 8px; padding: 8px 0; }
.pager { display: flex; justify-content: flex-end; padding: 12px 0; }
</style>
