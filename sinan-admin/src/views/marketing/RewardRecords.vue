<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Download, Promotion } from '@element-plus/icons-vue'
import { getRewardRecords, exportRewardRecords, issueRewardRecords } from '@/api'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const list = ref([])
const total = ref(0)
const stats = ref({})
const page = ref(1)
const pageSize = ref(20)
const exporting = ref(false)
const issuing = ref(false)
const selected = ref([])

const filters = reactive({
  activityType: 'all',
  status: 'all',
  phone: '',
  keyword: ''
})

/** 活动类型（与后端 ActivityRewardService::ACTIVITY_TYPES 对齐） */
const ACTIVITY_TYPES = {
  synthesis: '合成',
  lucky_draw: '抽奖',
  checkin: '签到',
  invite: '邀请',
  register: '注册'
}

/** 奖励名单状态 */
const RECORD_STATUS = {
  pending:  { label: '待发放', type: 'warning' },
  issued:   { label: '已发放', type: 'success' },
  failed:   { label: '发放失败', type: 'danger' },
  cancelled:{ label: '已取消', type: 'info' }
}

/** 奖励类型标签 */
const REWARD_TYPES = {
  points: '司南币',
  collectible: '藏品',
  draw_chance: '抽奖次数',
  priority_qualification: '优先购资格',
  eligibility_qualification: '资格购白名单',
  blindbox: '盲盒'
}

const STATUS_KEYS = ['pending', 'issued', 'failed', 'cancelled']

onMounted(load)

async function load() {
  loading.value = true
  const res = await getRewardRecords({
    page: page.value,
    pageSize: pageSize.value,
    activityType: filters.activityType === 'all' ? '' : filters.activityType,
    status: filters.status === 'all' ? '' : filters.status,
    phone: filters.phone.trim() || '',
    keyword: filters.keyword.trim() || ''
  })
  if (res.code === 0) {
    list.value = res.data.list || []
    total.value = res.data.total || 0
    stats.value = res.data.stats || {}
  } else {
    list.value = []
    total.value = 0
    stats.value = {}
  }
  loading.value = false
}

function onSearch() {
  page.value = 1
  load()
}

function onReset() {
  filters.activityType = 'all'
  filters.status = 'all'
  filters.phone = ''
  filters.keyword = ''
  onSearch()
}

function onChangePage() {
  load()
}

/** 当前筛选参数（导出/发放共用） */
function filterParams() {
  return {
    activityType: filters.activityType === 'all' ? '' : filters.activityType,
    status: filters.status === 'all' ? '' : filters.status,
    phone: filters.phone.trim() || '',
    keyword: filters.keyword.trim() || ''
  }
}

// ---- 导出 CSV ----
async function onExport() {
  exporting.value = true
  try {
    await exportRewardRecords(filterParams())
    ElMessage.success('名单已导出')
  } finally {
    exporting.value = false
  }
}

// ---- 统一发放 ----
async function onIssueSelected() {
  const pendingIds = selected.value
    .filter((r) => r.status === 'pending')
    .map((r) => r.id)
  if (!pendingIds.length) return ElMessage.warning('请勾选待发放的记录')

  await ElMessageBox.confirm(
    `确认对勾选的 ${pendingIds.length} 条待发放记录执行统一发放？`,
    '统一发放',
    { type: 'warning' }
  )
  issuing.value = true
  const res = await issueRewardRecords({ recordIds: pendingIds })
  issuing.value = false
  if (res.code === 0) {
    ElMessage.success(res.message || '发放完成')
    selected.value = []
    load()
  }
}

async function onIssueAllPending() {
  const pendingCount = Number(stats.value.pending || 0)
  if (!pendingCount) return ElMessage.warning('当前没有待发放记录')

  const typeLabel = filters.activityType === 'all'
    ? '全部活动类型'
    : ACTIVITY_TYPES[filters.activityType]
  await ElMessageBox.confirm(
    `确认对${typeLabel}的 ${fmtNumber(pendingCount)} 条待发放记录执行统一发放？`,
    '统一发放',
    { type: 'warning' }
  )
  issuing.value = true
  const res = await issueRewardRecords({
    activityType: filters.activityType === 'all' ? '' : filters.activityType
  })
  issuing.value = false
  if (res.code === 0) {
    ElMessage.success(res.message || '发放完成')
    load()
  }
}

function onSelectionChange(rows) {
  selected.value = rows
}
</script>

<template>
  <div class="adm-page rr">
    <!-- 统计卡片 -->
    <div class="rr__stats">
      <div v-for="k in STATUS_KEYS" :key="k" class="adm-card rr__stat">
        <div class="rr__stat-num" :class="`is-${k}`">{{ fmtNumber(stats[k] || 0) }}</div>
        <div class="t-tertiary">{{ RECORD_STATUS[k]?.label }}</div>
      </div>
    </div>

    <!-- 筛选 -->
    <div class="adm-card rr__filter">
      <el-select v-model="filters.activityType" style="width: 130px" @change="onSearch">
        <el-option label="全部活动类型" value="all" />
        <el-option v-for="(label, k) in ACTIVITY_TYPES" :key="k" :label="label" :value="k" />
      </el-select>
      <el-select v-model="filters.status" style="width: 130px" @change="onSearch">
        <el-option label="全部状态" value="all" />
        <el-option v-for="(v, k) in RECORD_STATUS" :key="k" :label="v.label" :value="k" />
      </el-select>
      <el-input
        v-model="filters.phone"
        placeholder="手机号搜索"
        clearable
        style="width: 180px"
        @keyup.enter="onSearch"
        @clear="onSearch"
      />
      <el-input
        v-model="filters.keyword"
        placeholder="活动名称搜索"
        clearable
        style="width: 180px"
        @keyup.enter="onSearch"
        @clear="onSearch"
      />
      <el-button type="primary" @click="onSearch">查询</el-button>
      <el-button @click="onReset">重置</el-button>
      <div class="rr__filter-ops">
        <el-button :icon="Download" :loading="exporting" @click="onExport">导出名单</el-button>
        <el-button
          type="success"
          plain
          :icon="Promotion"
          :loading="issuing"
          :disabled="!Number(stats.pending || 0)"
          @click="onIssueAllPending"
        >
          发放全部待发放
        </el-button>
      </div>
    </div>

    <!-- 名单表格 -->
    <div class="adm-card">
      <div class="adm-card__title">
        奖励名单
        <el-button
          link
          type="primary"
          size="small"
          :loading="issuing"
          :disabled="!selected.filter((r) => r.status === 'pending').length"
          style="margin-left: auto"
          @click="onIssueSelected"
        >
          发放勾选（{{ selected.filter((r) => r.status === 'pending').length }}）
        </el-button>
      </div>

      <el-table
        v-loading="loading"
        :data="list"
        @selection-change="onSelectionChange"
      >
        <el-table-column type="selection" width="44" :selectable="(r) => r.status === 'pending'" />
        <el-table-column label="记录ID" prop="id" width="80" />
        <el-table-column label="活动类型" width="90">
          <template #default="{ row }">
            <el-tag size="small" effect="plain">{{ ACTIVITY_TYPES[row.activityType] || row.activityType }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="活动名称" prop="activityTitle" min-width="150" show-overflow-tooltip />
        <el-table-column label="用户" min-width="130">
          <template #default="{ row }">
            <div>#{{ row.userId }}</div>
            <div class="t-tertiary" style="font-size: 12px">{{ row.phone || '—' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="奖励类型" width="110">
          <template #default="{ row }">
            {{ REWARD_TYPES[row.rewardType] || row.rewardType }}
          </template>
        </el-table-column>
        <el-table-column label="奖励内容" prop="rewardLabel" min-width="160" show-overflow-tooltip />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="RECORD_STATUS[row.status]?.type || 'info'" size="small">
              {{ RECORD_STATUS[row.status]?.label || row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="发放时间" width="160">
          <template #default="{ row }">{{ row.issuedAt || '—' }}</template>
        </el-table-column>
        <el-table-column label="发放结果" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.issueResult || '—' }}</template>
        </el-table-column>
        <el-table-column label="创建时间" prop="createdAt" width="160" />
      </el-table>

      <div class="rr__foot">
        <el-pagination
          background
          layout="total, prev, pager, next, sizes"
          :total="total"
          :page-sizes="[10, 20, 50]"
          v-model:current-page="page"
          v-model:page-size="pageSize"
          @current-change="onChangePage"
          @size-change="onSearch"
        />
      </div>
    </div>

    <el-alert
      type="info"
      :closable="false"
      show-icon
      title="「记录名单 · 统一发放」的活动奖励进入本名单；可按活动类型/状态/手机号筛选后导出 CSV 线下统一发放，或直接勾选执行统一发放（发放时动态校验配额预留 / 盲盒库存池）"
    />
  </div>
</template>

<style scoped lang="scss">
.rr__stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 14px;

  @media (max-width: 768px) {
    grid-template-columns: repeat(2, 1fr);
  }
}

.rr__stat {
  text-align: center;
  padding: 14px 0;
}

.rr__stat-num {
  font-size: 22px;
  font-weight: 700;

  &.is-pending { color: var(--color-warning); }
  &.is-issued { color: var(--color-success); }
  &.is-failed { color: $color-primary; }
  &.is-cancelled { color: $color-text-tertiary; }
}

.rr__filter {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}

.rr__filter-ops {
  display: flex;
  gap: 8px;
  margin-left: auto;
}

.rr__foot {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.rr :deep(.el-alert) {
  margin-top: 14px;
}
</style>
