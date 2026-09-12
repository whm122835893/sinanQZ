<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import request, { http } from '@/utils/request'
import { downloadCsv } from '@/utils/csv'

const route = useRoute()
const router = useRouter()

// ---------- 活动选择 ----------
const activities = ref([])
const activityId = ref(Number(route.query.activityId) || null)
const activity = ref(null)
// 单用户码总量 = 基础 1 + 邀请上限（开时）+ 购买上限（开时）；管理员发放不受此限制
const codeLimitText = computed(() => {
  if (!activity.value) return '基础 1'
  const invite = activity.value.inviteEnabled ? (activity.value.inviteCodeLimit || 0) : 0
  const buy = activity.value.drawCodeEnabled ? (activity.value.buyCodeLimit || 0) : 0
  const parts = ['基础1']
  if (invite) parts.push(`邀请${invite}`)
  if (buy) parts.push(`购买${buy}`)
  return `共 ${1 + invite + buy}（${parts.join(' + ')}）`
})

// ---------- 列表 ----------
const loading = ref(false)
const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const keyword = ref('')
const statusFilter = ref(null)
const stats = ref({ total: 0, unused: 0, registered: 0, invalid: 0, won: 0 })

const STATUS_MAP = {
  1: { text: '未使用', cls: 'primary' },
  2: { text: '已报名', cls: 'success' },
  3: { text: '已失效', cls: 'info' },
  4: { text: '已中签', cls: 'danger' },
}
const SOURCE_MAP = { 1: '抽签发放', 2: '邀请获得', 3: '购买获得', 4: '后台新增' }

onMounted(async () => {
  const res = await request.get('/raffle', { params: { page: 1, pageSize: 200 } })
  activities.value = res.list || []
  if (activityId.value) {
    activity.value = activities.value.find((a) => a.id === activityId.value) || null
    load()
  }
})

function onActivityChange() {
  page.value = 1
  if (!activityId.value) { list.value = []; total.value = 0; return }
  activity.value = activities.value.find((a) => a.id === activityId.value) || null
  load()
}

async function load() {
  if (!activityId.value) return
  loading.value = true
  try {
    const res = await request.get('/raffle/codes', {
      params: {
        activityId: activityId.value,
        page: page.value,
        pageSize: pageSize.value,
        keyword: keyword.value,
        status: statusFilter.value,
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
function onReset() { keyword.value = ''; statusFilter.value = null; onSearch() }

// ---------- 新增抽签码 ----------
const createShow = ref(false)
const creating = ref(false)
const createForm = ref({ identifier: '', count: 1, code: '', remark: '' })

function openCreate() {
  createForm.value = { identifier: '', count: 1, code: '', remark: '' }
  createShow.value = true
}

async function submitCreate() {
  const id = createForm.value.identifier.trim()
  if (!id) return ElMessage.warning('请填写用户 ID 或手机号')
  if (!activityId.value) return ElMessage.warning('请先选择活动')

  creating.value = true
  try {
    const payload = { activityId: activityId.value, count: createForm.value.count, remark: createForm.value.remark }
    if (/^1\d{10}$/.test(id)) payload.phone = id
    else payload.userId = Number(id)

    const res = await request.post('/raffle/codes', payload)
    ElMessage.success(`成功新增 ${res.count} 个抽签码`)
    createShow.value = false
    load()
  } finally {
    creating.value = false
  }
}

// ---------- Excel 批量导入 ----------
const importShow = ref(false)
const importing = ref(false)
const importFile = ref(null)
const importResult = ref(null)

function openImport() {
  importFile.value = null
  importResult.value = null
  importShow.value = true
}

function onFileChange(file) {
  importFile.value = file.raw
}

/** 下载导入模板（CSV，Excel 可直接另存为 xlsx） */
function downloadTemplate() {
  downloadCsv('抽签码导入模板', ['用户ID或手机号', '数量', '抽签码(可选)', '备注(可选)'], [
    ['13800138000', '2', '', '老用户回馈'],
    ['10086', '1', 'SN-2026-0001', '定向发放'],
  ])
}

async function submitImport() {
  if (!importFile.value) return ElMessage.warning('请选择 Excel 文件（.xlsx）')
  if (!activityId.value) return ElMessage.warning('请先选择活动')

  importing.value = true
  importResult.value = null
  try {
    const fd = new FormData()
    fd.append('activityId', activityId.value)
    fd.append('file', importFile.value)
    const token = localStorage.getItem('sinan_admin_token')
    const res = await http.post('/raffle/codes/import', fd, {
      headers: token ? { Authorization: `Bearer ${token}`, 'Content-Type': 'multipart/form-data' } : { 'Content-Type': 'multipart/form-data' },
    })
    if (res.code === 0) {
      importResult.value = res.data
      ElMessage.success(`导入完成：成功 ${res.data.success} 个，跳过 ${res.data.skippedCount} 行`)
      load()
    } else {
      ElMessage.error(res.message || '导入失败')
    }
  } finally {
    importing.value = false
  }
}

// ---------- 作废 / 删除 ----------
async function doInvalidate(row) {
  const { value } = await ElMessageBox.prompt(
    `确认作废抽签码「${row.code}」？作废后该码不可再用于报名。`,
    '作废抽签码',
    { inputPlaceholder: '作废原因（选填）', type: 'warning' }
  )
  await request.post(`/raffle/codes/${row.id}/invalidate`, { reason: value || '' })
  ElMessage.success('已作废')
  load()
}

async function doDelete(row) {
  await ElMessageBox.confirm(`确认删除抽签码「${row.code}」？（物理删除，不可恢复）`, '删除', { type: 'warning' })
  await request.delete(`/raffle/codes/${row.id}`)
  ElMessage.success('已删除')
  load()
}
</script>

<template>
  <div class="page">
    <div class="page__head">
      <h2>抽签码管理</h2>
      <el-button link @click="router.push('/marketing/raffle')">← 返回活动列表</el-button>
    </div>

    <!-- 活动选择 -->
    <div class="filter">
      <span class="filter__label">抽签活动：</span>
      <el-select v-model="activityId" filterable placeholder="选择抽签活动" style="width: 320px" @change="onActivityChange">
        <el-option v-for="a in activities" :key="a.id" :value="a.id" :label="`#${a.id} ${a.name}`" />
      </el-select>
      <el-tag v-if="activityId" type="warning" style="margin-left: 8px">
        单用户码上限：{{ codeLimitText }}
      </el-tag>
    </div>

    <!-- 统计 -->
    <div class="stats">
      <div class="stat-card">
        <div class="stat-card__num">{{ stats.total }}</div>
        <div class="stat-card__label">抽签码总数</div>
      </div>
      <div class="stat-card stat-card--ok">
        <div class="stat-card__num">{{ stats.unused }}</div>
        <div class="stat-card__label">未使用</div>
      </div>
      <div class="stat-card stat-card--warn">
        <div class="stat-card__num">{{ stats.registered }}</div>
        <div class="stat-card__label">已报名</div>
      </div>
      <div class="stat-card stat-card--danger">
        <div class="stat-card__num">{{ stats.won || 0 }}</div>
        <div class="stat-card__label">已中签</div>
      </div>
      <div class="stat-card stat-card--muted">
        <div class="stat-card__num">{{ stats.invalid }}</div>
        <div class="stat-card__label">已失效</div>
      </div>
    </div>

    <!-- 筛选 -->
    <div class="filter">
      <el-input v-model="keyword" placeholder="抽签码/用户名/手机号" clearable style="width: 220px" @keyup.enter="onSearch" />
      <el-select v-model="statusFilter" placeholder="状态" clearable style="width: 130px">
        <el-option v-for="(v, k) in STATUS_MAP" :key="k" :value="Number(k)" :label="v.text" />
      </el-select>
      <el-button type="primary" @click="onSearch">搜索</el-button>
      <el-button @click="onReset">重置</el-button>
      <div style="flex: 1" />
      <el-button type="success" :disabled="!activityId" @click="openCreate">+ 新增抽签码</el-button>
      <el-button type="primary" :disabled="!activityId" @click="openImport">Excel 批量导入</el-button>
    </div>

    <!-- 列表 -->
    <el-table :data="list" v-loading="loading" stripe style="width: 100%">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="code" label="抽签码" min-width="160">
        <template #default="{ row }">
          <span class="code-text">{{ row.code }}</span>
        </template>
      </el-table-column>
      <el-table-column label="绑定用户" min-width="150">
        <template #default="{ row }">
          <template v-if="row.userId">
            {{ row.username || row.userId }}
            <div style="font-size: 12px; color: #999">{{ row.phone || '-' }}</div>
          </template>
          <span v-else style="color: #999">未绑定</span>
        </template>
      </el-table-column>
      <el-table-column prop="activityName" label="所属活动" min-width="130" show-overflow-tooltip />
      <el-table-column label="状态" width="90" align="center">
        <template #default="{ row }">
          <el-tag :type="STATUS_MAP[row.status]?.cls || 'info'">{{ STATUS_MAP[row.status]?.text || row.status }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="来源" width="90" align="center">
        <template #default="{ row }">{{ SOURCE_MAP[row.source] || row.source }}</template>
      </el-table-column>
      <el-table-column prop="remark" label="备注" min-width="110" show-overflow-tooltip />
      <el-table-column label="时间" min-width="150">
        <template #default="{ row }">
          <div style="font-size: 12px; color: #999">发放: {{ row.createdAt?.substring(0, 19) }}</div>
          <div v-if="row.usedAt" style="font-size: 12px; color: #999">使用: {{ row.usedAt?.substring(0, 19) }}</div>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button v-if="row.status !== 3 && row.status !== 4" size="small" link type="warning" @click="doInvalidate(row)">作废</el-button>
          <el-button size="small" link type="danger" @click="doDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div class="page__foot">
      <el-pagination background layout="total, prev, pager, next, sizes" :total="total" :page-sizes="[10, 20, 50]"
        v-model:current-page="page" v-model:page-size="pageSize" @current-change="load" @size-change="load" />
    </div>

    <!-- 新增抽签码 -->
    <el-dialog v-model="createShow" title="新增抽签码" width="520px" :close-on-click-modal="false">
      <el-alert v-if="activity" :title="`当前活动单用户码上限 ${codeLimitText}；管理员发放不受邀请/购买档位上限限制`" type="info" :closable="false" style="margin-bottom: 14px" />
      <el-form label-width="110px">
        <el-form-item label="用户" required>
          <el-input v-model="createForm.identifier" placeholder="用户 ID 或手机号" style="width: 260px" />
        </el-form-item>
        <el-form-item label="数量">
          <el-input-number v-model="createForm.count" :min="1" :max="100" />
          <span style="margin-left: 10px; color: #999; font-size: 12px">一次最多 100 个</span>
        </el-form-item>
        <el-form-item label="自定义码串">
          <el-input v-model="createForm.code" placeholder="留空则自动生成（自定义时数量只能为 1）" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="createForm.remark" placeholder="选填" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="createShow = false">取消</el-button>
        <el-button type="primary" :loading="creating" @click="submitCreate">确认新增</el-button>
      </template>
    </el-dialog>

    <!-- Excel 批量导入 -->
    <el-dialog v-model="importShow" title="Excel 批量导入抽签码" width="560px" :close-on-click-modal="false">
      <el-alert v-if="activity" :title="`当前活动单用户码上限 ${codeLimitText}；管理员导入发放不受邀请/购买档位上限限制`" type="info" :closable="false" style="margin-bottom: 14px" />
      <el-upload drag accept=".xlsx,.xls" :auto-upload="false" :limit="1" :on-change="onFileChange" :on-remove="() => (importFile = null)">
        <div style="padding: 20px 0">
          <div style="font-size: 14px">将 Excel 文件拖到此处，或点击选择</div>
          <div style="font-size: 12px; color: #999; margin-top: 6px">列：用户ID或手机号 | 数量(可选) | 抽签码(可选) | 备注(可选)</div>
        </div>
      </el-upload>
      <div style="margin-top: 10px; text-align: right">
        <el-button link type="primary" @click="downloadTemplate">下载导入模板</el-button>
      </div>

      <div v-if="importResult" style="margin-top: 12px">
        <el-alert type="success" :closable="false" :title="`导入完成：成功 ${importResult.success} 个，跳过 ${importResult.skippedCount} 行`" />
        <div v-if="importResult.skipped?.length" style="margin-top: 8px; max-height: 160px; overflow: auto; font-size: 12px; color: #f56c6c">
          <div v-for="(s, i) in importResult.skipped" :key="i">{{ s }}</div>
        </div>
      </div>

      <template #footer>
        <el-button @click="importShow = false">关闭</el-button>
        <el-button type="primary" :loading="importing" @click="submitImport">开始导入</el-button>
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
.code-text { font-family: Consolas, Monaco, monospace; font-weight: 600; }

.stats { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.stat-card { flex: 1; min-width: 130px; padding: 14px 18px; background: #f5f7fa; border-radius: 8px; text-align: center; }
.stat-card__num { font-size: 24px; font-weight: 700; color: #303133; }
.stat-card__label { margin-top: 4px; font-size: 12px; color: #999; }
.stat-card--ok { background: #f0f9eb; }
.stat-card--ok .stat-card__num { color: #67c23a; }
.stat-card--warn { background: #fdf6ec; }
.stat-card--warn .stat-card__num { color: #e6a23c; }
.stat-card--muted { background: #f4f4f5; }
.stat-card--muted .stat-card__num { color: #909399; }
.stat-card--danger { background: #fef0f0; }
.stat-card--danger .stat-card__num { color: #f56c6c; }
</style>
