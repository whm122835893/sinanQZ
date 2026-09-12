<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import request, { http } from '@/utils/request'

const route = useRoute()
const router = useRouter()

// ---------- 活动选择 ----------
const activities = ref([])
const activityId = ref(Number(route.query.activityId) || null)
const activity = ref(null)

// ---------- 标签页 ----------
const activeTab = ref('registrations')

// ---------- 报名记录 ----------
const loading = ref(false)
const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const keyword = ref('')
const drawStatusFilter = ref(null)
const forceWinFilter = ref(null)
const selection = ref([])
const stats = ref({ registrationCount: 0, forceWinCount: 0, winnerCount: 0, totalWinCount: 0, drawWinQuota: 0, winLocked: 0 })

const quotaLeft = computed(() => Math.max(0, (stats.value.drawWinQuota || 0) - (stats.value.forceWinCount || 0)))
const winLocked = computed(() => stats.value.winLocked === 1)

// ---------- 按手机号设必中 ----------
const phoneDialogShow = ref(false)
const phoneInput = ref('')
const phoneSubmitting = ref(false)

function openPhoneForceWin() {
  if (winLocked.value) return ElMessage.warning('该活动已开奖，名额已锁定，不可再设置必中')
  phoneInput.value = ''
  phoneDialogShow.value = true
}

/** 解析输入框：换行 / 逗号 / 分号分隔，去重去空 */
function parsePhones() {
  return [...new Set(
    phoneInput.value.split(/[\s,，;；]+/).map((s) => s.trim()).filter(Boolean)
  )]
}

async function submitPhoneForceWin() {
  const phones = parsePhones()
  if (!phones.length) return ElMessage.warning('请输入至少一个手机号')

  await ElMessageBox.confirm(
    `确认将 ${phones.length} 个手机号对应的报名记录设为强制必中？未报名该活动的手机号将被忽略。`,
    '按手机号设必中',
    { type: 'warning' }
  )

  phoneSubmitting.value = true
  try {
    const res = await request.post('/raffle/registrations/force-win', {
      activityId: activityId.value,
      phones,
      forceWin: true,
    })
    const notFound = res.notFoundPhones || []
    if (notFound.length) {
      ElMessage.warning(`已设为必中 ${res.updated} 人；${notFound.length} 个手机号未匹配到报名记录：${notFound.slice(0, 10).join('、')}${notFound.length > 10 ? ' 等' : ''}`)
    } else {
      ElMessage.success(`已设为必中 ${res.updated} 人，当前必中 ${res.forceWinCount}/${res.quota} 人`)
    }
    phoneDialogShow.value = false
    load()
  } finally {
    phoneSubmitting.value = false
  }
}

// ---------- 中签记录 ----------
const winLoading = ref(false)
const winList = ref([])
const winTotal = ref(0)
const winPage = ref(1)
const winPageSize = ref(20)
const winKeyword = ref('')
const winResultFilter = ref('win')

onMounted(async () => {
  // 活动下拉
  const res = await request.get('/raffle', { params: { page: 1, pageSize: 200 } })
  activities.value = res.list || []
  if (activityId.value) {
    const act = activities.value.find((a) => a.id === activityId.value)
    if (act) activity.value = act
    load()
    loadWinners()
  }
})

function onActivityChange() {
  page.value = 1
  winPage.value = 1
  selection.value = []
  if (!activityId.value) { list.value = []; winList.value = []; return }
  activity.value = activities.value.find((a) => a.id === activityId.value) || null
  load()
  loadWinners()
}

// ============ 报名记录 ============
async function load() {
  if (!activityId.value) return
  loading.value = true
  try {
    const res = await request.get('/raffle/registrations', {
      params: {
        activityId: activityId.value,
        page: page.value,
        pageSize: pageSize.value,
        keyword: keyword.value,
        drawStatus: drawStatusFilter.value,
        forceWin: forceWinFilter.value,
      },
    })
    list.value = res.list || []
    total.value = res.total || 0
    if (res.stats) stats.value = res.stats
  } finally {
    loading.value = false
  }
}

function onSearch() { page.value = 1; load() }
function onReset() {
  keyword.value = ''
  drawStatusFilter.value = null
  forceWinFilter.value = null
  onSearch()
}
function onSelectionChange(rows) { selection.value = rows }

/** 单条/批量 设为必中（forceWin=true）/ 取消必中（forceWin=false） */
async function setForceWin(ids, forceWin) {
  if (!ids || !ids.length) return ElMessage.warning('请先勾选报名记录')
  const action = forceWin ? '设为必中' : '取消必中'
  if (winLocked.value) return ElMessage.warning('该活动已开奖，名额已锁定，不可再设置必中')
  if (forceWin && quotaLeft.value > 0 && ids.length > quotaLeft.value) {
    return ElMessage.warning(`剩余可选名额仅 ${quotaLeft.value} 人，当前勾选 ${ids.length} 人`)
  }

  await ElMessageBox.confirm(
    forceWin
      ? `确认将选中的 ${ids.length} 条报名记录设为强制必中？开奖时直接中签，剩余名额随机抽取，一次开奖完成。`
      : `确认取消选中的 ${ids.length} 条报名记录的必中标记？`,
    action,
    { type: 'warning' }
  )

  const res = await request.post('/raffle/registrations/force-win', {
    activityId: activityId.value,
    registrationIds: ids,
    forceWin,
  })
  ElMessage.success(`${action}成功，当前必中 ${res.forceWinCount}/${res.quota} 人`)
  selection.value = []
  load()
}

// ============ 中签记录 ============
async function loadWinners() {
  if (!activityId.value) return
  winLoading.value = true
  try {
    const res = await request.get('/raffle/winners', {
      params: {
        activityId: activityId.value,
        page: winPage.value,
        pageSize: winPageSize.value,
        keyword: winKeyword.value,
        result: winResultFilter.value,
      },
    })
    winList.value = res.list || []
    winTotal.value = res.total || 0
  } finally {
    winLoading.value = false
  }
}

function onWinSearch() { winPage.value = 1; loadWinners() }

async function markPaid(row) {
  await ElMessageBox.confirm(`确认标记用户「${row.username || row.userId}」已付款？`, '标记付款', { type: 'warning' })
  await request.post(`/raffle/winners/${row.id}/mark-paid`)
  ElMessage.success('已标记付款')
  loadWinners()
}

async function verify(row) {
  await ElMessageBox.confirm(`确认核销用户「${row.username || row.userId}」的中签资格？核销后不可撤销。`, '核销', { type: 'warning' })
  await request.post(`/raffle/winners/${row.id}/verify`)
  ElMessage.success('已核销')
  loadWinners()
}

/** 导出中签记录 Excel（带令牌 blob 下载） */
async function exportWinners() {
  if (!activityId.value) return ElMessage.warning('请先选择活动')
  const token = localStorage.getItem('sinan_admin_token')
  const resp = await http.get('/raffle/winners/export', {
    params: { activityId: activityId.value, result: winResultFilter.value },
    responseType: 'blob',
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
  const url = URL.createObjectURL(new Blob([resp]))
  const a = document.createElement('a')
  a.href = url
  a.download = `中签记录_活动${activityId.value}_${new Date().toISOString().slice(0, 10)}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="page">
    <div class="page__head">
      <h2>报名与中签记录</h2>
      <el-button link @click="router.push('/marketing/raffle')">← 返回活动列表</el-button>
    </div>

    <!-- 活动选择 -->
    <div class="filter">
      <span class="filter__label">抽签活动：</span>
      <el-select v-model="activityId" filterable placeholder="选择抽签活动" style="width: 320px" @change="onActivityChange">
        <el-option v-for="a in activities" :key="a.id" :value="a.id" :label="`#${a.id} ${a.name}`" />
      </el-select>
      <el-tag v-if="activity" :type="activity.winLocked ? 'danger' : 'info'" style="margin-left: 8px">
        {{ activity.winLocked ? '已开奖·名额锁定' : '未开奖' }}
      </el-tag>
    </div>

    <!-- 实时统计 -->
    <div class="stats">
      <div class="stat-card">
        <div class="stat-card__num">{{ stats.registrationCount }}</div>
        <div class="stat-card__label">报名人数</div>
      </div>
      <div class="stat-card stat-card--warn">
        <div class="stat-card__num">
          {{ stats.forceWinCount }}<span class="stat-card__sub"> / {{ stats.drawWinQuota }}</span>
        </div>
        <div class="stat-card__label">强制必中 / 本次名额</div>
      </div>
      <div class="stat-card stat-card--ok">
        <div class="stat-card__num">
          {{ stats.winnerCount }}<span class="stat-card__sub"> 人 / {{ stats.totalWinCount || 0 }} 签</span>
        </div>
        <div class="stat-card__label">已中签人数 / 总签数</div>
      </div>
      <div class="stat-card" :class="{ 'stat-card--danger': quotaLeft === 0 }">
        <div class="stat-card__num">{{ quotaLeft }}</div>
        <div class="stat-card__label">剩余可选必中名额</div>
      </div>
    </div>

    <el-tabs v-model="activeTab">
      <!-- ================= 报名记录 ================= -->
      <el-tab-pane label="报名记录" name="registrations">
        <div class="filter">
          <el-input v-model="keyword" placeholder="用户名/手机号" clearable style="width: 200px" @keyup.enter="onSearch" />
          <el-select v-model="drawStatusFilter" placeholder="抽签状态" clearable style="width: 130px">
            <el-option :value="0" label="待开奖" />
            <el-option :value="1" label="中签" />
            <el-option :value="2" label="未中签" />
          </el-select>
          <el-select v-model="forceWinFilter" placeholder="必中状态" clearable style="width: 130px">
            <el-option :value="1" label="强制必中" />
            <el-option :value="0" label="普通" />
          </el-select>
          <el-button type="primary" @click="onSearch">搜索</el-button>
          <el-button @click="onReset">重置</el-button>
          <div style="flex: 1" />
          <el-button type="warning" :disabled="winLocked" @click="openPhoneForceWin">按手机号设必中</el-button>
          <el-button type="warning" :disabled="winLocked || !selection.length" @click="setForceWin(selection.map((r) => r.id), true)">
            批量设为必中（{{ selection.length }}）
          </el-button>
          <el-button :disabled="winLocked || !selection.length" @click="setForceWin(selection.map((r) => r.id), false)">
            批量取消必中
          </el-button>
        </div>

        <el-table :data="list" v-loading="loading" stripe style="width: 100%" @selection-change="onSelectionChange">
          <el-table-column type="selection" width="46" :selectable="() => !winLocked" />
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="用户" min-width="150">
            <template #default="{ row }">
              {{ row.username || row.userId }}
              <div style="font-size: 12px; color: #999">{{ row.phone || '-' }}</div>
            </template>
          </el-table-column>
          <el-table-column prop="ticketCount" label="报名票数" width="90" align="center" />
          <el-table-column prop="codeCount" label="持有抽签码" width="95" align="center" />
          <el-table-column label="必中标记" width="110" align="center">
            <template #default="{ row }">
              <el-tag v-if="row.isForceWin" type="danger">强制必中</el-tag>
              <el-tag v-else type="info">普通</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="抽签状态" width="110" align="center">
            <template #default="{ row }">
              <el-tag :type="row.drawStatus === 1 ? 'success' : row.drawStatus === 2 ? 'info' : 'warning'">
                {{ row.drawStatus === 1 ? `中签×${row.winCount || 1}` : row.drawStatus === 2 ? '未中签' : '待开奖' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="报名时间" min-width="150">
            <template #default="{ row }">{{ row.createdAt?.substring(0, 19) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="120" fixed="right">
            <template #default="{ row }">
              <el-button
                v-if="!row.isForceWin"
                size="small" link type="warning" :disabled="winLocked"
                @click="setForceWin([row.id], true)"
              >设为必中</el-button>
              <el-button
                v-else
                size="small" link type="info" :disabled="winLocked"
                @click="setForceWin([row.id], false)"
              >取消必中</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="page__foot">
          <el-pagination background layout="total, prev, pager, next, sizes" :total="total" :page-sizes="[10, 20, 50]"
            v-model:current-page="page" v-model:page-size="pageSize" @current-change="load" @size-change="load" />
        </div>
      </el-tab-pane>

      <!-- ================= 中签记录 ================= -->
      <el-tab-pane label="中签记录" name="winners">
        <div class="filter">
          <el-select v-model="winResultFilter" style="width: 130px" @change="onWinSearch">
            <el-option value="win" label="中签" />
            <el-option value="lose" label="未中签" />
          </el-select>
          <el-input v-model="winKeyword" placeholder="用户名/手机号" clearable style="width: 200px" @keyup.enter="onWinSearch" />
          <el-button type="primary" @click="onWinSearch">搜索</el-button>
          <div style="flex: 1" />
          <el-button type="success" :disabled="!activityId" @click="exportWinners">导出 Excel</el-button>
        </div>

        <el-table :data="winList" v-loading="winLoading" stripe style="width: 100%">
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="用户" min-width="150">
            <template #default="{ row }">
              {{ row.username || row.userId }}
              <div style="font-size: 12px; color: #999">{{ row.phone || '-' }}</div>
            </template>
          </el-table-column>
          <el-table-column label="抽签结果" width="95" align="center">
            <template #default="{ row }">
              <el-tag :type="row.drawStatus === 1 ? 'success' : 'info'">{{ row.drawStatus === 1 ? '中签' : '未中签' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="中签次数" width="85" align="center">
            <template #default="{ row }">{{ row.drawStatus === 1 ? (row.winCount || 1) : 0 }}</template>
          </el-table-column>
          <el-table-column label="强制必中" width="90" align="center">
            <template #default="{ row }">
              <el-tag v-if="row.isForceWin" type="danger" size="small">是</el-tag>
              <span v-else style="color: #999">否</span>
            </template>
          </el-table-column>
          <el-table-column label="付款状态" width="95" align="center">
            <template #default="{ row }">
              <el-tag v-if="row.winPaid" type="success" size="small">已付款</el-tag>
              <el-tag v-else type="warning" size="small">未付款</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="核销状态" width="95" align="center">
            <template #default="{ row }">
              <el-tag v-if="row.winVerified" type="success" size="small">已核销</el-tag>
              <el-tag v-else type="info" size="small">未核销</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="已购数量" width="95" align="center">
            <template #default="{ row }">
              {{ row.purchasedQuantity || 0 }} / {{ row.drawStatus === 1 ? (row.winCount || 1) * (row.saleQuantity || 0) : '-' }}
            </template>
          </el-table-column>
          <el-table-column label="中签价" width="85" align="center">
            <template #default="{ row }">¥{{ row.salePrice }}</template>
          </el-table-column>
          <el-table-column label="操作" width="130" fixed="right">
            <template #default="{ row }">
              <template v-if="row.drawStatus === 1">
                <el-button v-if="!row.winPaid" size="small" link type="primary" @click="markPaid(row)">标记付款</el-button>
                <el-button v-if="row.winPaid && !row.winVerified" size="small" link type="success" @click="verify(row)">核销</el-button>
                <span v-if="row.winVerified" style="color: #999; font-size: 12px">已完成</span>
              </template>
              <span v-else style="color: #999; font-size: 12px">-</span>
            </template>
          </el-table-column>
        </el-table>

        <div class="page__foot">
          <el-pagination background layout="total, prev, pager, next, sizes" :total="winTotal" :page-sizes="[10, 20, 50]"
            v-model:current-page="winPage" v-model:page-size="winPageSize" @current-change="loadWinners" @size-change="loadWinners" />
        </div>
      </el-tab-pane>
    </el-tabs>

    <!-- 按手机号设必中弹窗 -->
    <el-dialog v-model="phoneDialogShow" title="按手机号设必中" width="480px" :close-on-click-modal="false">
      <el-alert
        type="info"
        :closable="false"
        show-icon
        style="margin-bottom: 12px"
        title="输入用户手机号（支持换行、逗号、分号分隔，可批量粘贴），匹配到本活动报名记录的用户将设为强制必中；未报名该活动的手机号将被忽略并提示。"
      />
      <el-input
        v-model="phoneInput"
        type="textarea"
        :rows="8"
        placeholder="13800000001&#10;13800000002，13800000003；13800000004"
      />
      <div style="margin-top: 8px; color: #999; font-size: 12px">
        已识别 {{ parsePhones().length }} 个手机号 · 当前必中 {{ stats.forceWinCount }}/{{ stats.drawWinQuota }} 人
      </div>
      <template #footer>
        <el-button @click="phoneDialogShow = false">取消</el-button>
        <el-button type="warning" :loading="phoneSubmitting" @click="submitPhoneForceWin">确认设为必中</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page { padding: 16px; }
.page__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.page__head h2 { margin: 0; font-size: 18px; }
.filter { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; flex-wrap: wrap; }
.filter__label { color: #666; font-size: 13px; }
.page__foot { display: flex; justify-content: flex-end; margin-top: 16px; }

.stats { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.stat-card {
  flex: 1; min-width: 150px; padding: 14px 18px;
  background: #f5f7fa; border-radius: 8px; text-align: center;
}
.stat-card__num { font-size: 24px; font-weight: 700; color: #303133; }
.stat-card__sub { font-size: 14px; color: #999; font-weight: 400; }
.stat-card__label { margin-top: 4px; font-size: 12px; color: #999; }
.stat-card--warn { background: #fdf6ec; }
.stat-card--warn .stat-card__num { color: #e6a23c; }
.stat-card--ok { background: #f0f9eb; }
.stat-card--ok .stat-card__num { color: #67c23a; }
.stat-card--danger { background: #fef0f0; }
.stat-card--danger .stat-card__num { color: #f56c6c; }
</style>
