<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getAirdropActivities,
  saveAirdropActivity,
  generateAirdropEligibility,
  getAirdropEligibilities,
  issueAirdrop,
  deleteAirdropActivity,
  getCollectibleList
} from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { fmtNumber } from '@/utils/format'

// ---------- 状态/类型映射 ----------
const AIRDROP_STATUS = {
  draft:  { label: '草稿', type: 'info' },
  active: { label: '进行中', type: 'success' },
  paused: { label: '已暂停', type: 'warning' },
  ended:  { label: '已结束', type: 'info' }
}

const AIRDROP_TYPES = {
  direct:    { label: '全量直投', tag: 'primary', snapshottable: true, desc: '向全部有效用户（未删除、非黑名单）生成名单' },
  hold:      { label: '持有快照', tag: 'warning', snapshottable: true, desc: '向持有指定快照藏品的用户生成名单' },
  condition: { label: '条件筛选', tag: 'danger',  snapshottable: true, desc: '按手机尾号/注册时间/实名状态/持有藏品组合筛选生成名单' },
  checkin:   { label: '累计签到', tag: 'success', snapshottable: true, desc: '按累计签到天数圈定名单（生成时按阈值筛选）' },
  register:  { label: '注册行为', tag: 'info',    snapshottable: true, desc: '按活动起止时间内注册的新用户圈定名单' },
  login:     { label: '登录行为', tag: 'info',    snapshottable: true, desc: '按累计登录次数圈定名单' },
  invite:    { label: '邀请行为', tag: 'info',    snapshottable: true, desc: '按累计成功邀请人数圈定名单' }
}

const REALNAME_OPTIONS = [
  { value: '', label: '不限' },
  { value: 0, label: '未提交' },
  { value: 1, label: '待审核' },
  { value: 2, label: '已通过' },
  { value: 3, label: '已驳回' }
]

// 手机尾号可选项（0-9）
const PHONE_TAIL_OPTIONS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']

// ---------- 列表 ----------
const loading = ref(false)
const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const filters = reactive({ status: '', type: '', keyword: '' })

onMounted(() => {
  load()
  loadCollectibles()
})

async function load() {
  loading.value = true
  try {
    const params = { page: page.value, pageSize: pageSize.value }
    if (filters.status) params.status = filters.status
    if (filters.type) params.type = filters.type
    if (filters.keyword.trim()) params.keyword = filters.keyword.trim()
    const res = await getAirdropActivities(params)
    list.value = (res.data && res.data.list) || []
    total.value = (res.data && res.data.total) || 0
  } finally {
    loading.value = false
  }
}

function onSearch() {
  page.value = 1
  load()
}

function onReset() {
  filters.status = ''
  filters.type = ''
  filters.keyword = ''
  onSearch()
}

// ---------- 下拉数据源 ----------
const collectibles = ref([])
const cname = (id) => collectibles.value.find((c) => c.id === id)?.name || `藏品 #${id}`

async function loadCollectibles() {
  const res = await getCollectibleList({ page: 1, pageSize: 200 })
  if (res.code === 0) collectibles.value = res.data.list || []
}

// ---------- 新建/编辑 ----------
const editShow = ref(false)
const editing = ref(null)
const submitting = ref(false)
const form = ref(emptyForm())

function emptyForm() {
  return {
    name: '',
    type: 'condition',
    status: 'draft',
    collectibleId: null,
    quantityPerUser: 1,
    totalLimit: null,
    startTime: '',
    endTime: '',
    snapshotCollectibleId: null,
    description: '',
    // 条件筛选（type=condition）+ 行为型阈值（checkin.days / login.count / invite.count）
    condition: {
      phoneTails: [],
      registeredStart: '',
      registeredEnd: '',
      realnameStatus: '',
      holdCollectibleId: null,
      holdMinQty: 1,
      days: 1,
      count: 1
    }
  }
}

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  editShow.value = true
}

function openEdit(row) {
  editing.value = row
  const cfg = row.conditionConfig || {}
  form.value = {
    ...emptyForm(),
    name: row.name,
    type: row.type,
    status: row.status,
    collectibleId: row.collectibleId,
    quantityPerUser: row.quantityPerUser || 1,
    totalLimit: row.totalLimit ?? null,
    startTime: row.startTime || '',
    endTime: row.endTime || '',
    snapshotCollectibleId: row.snapshotCollectibleId || null,
    description: row.description || '',
    condition: {
      phoneTails: (cfg.phoneTails || []).map(String),
      registeredStart: cfg.registeredStart || '',
      registeredEnd: cfg.registeredEnd || '',
      realnameStatus: cfg.realnameStatus ?? '',
      holdCollectibleId: cfg.holdCollectibleId || null,
      holdMinQty: cfg.holdMinQty || 1,
      days: cfg.days || 1,
      count: cfg.count || 1
    }
  }
  editShow.value = true
}

function hasCondition(f) {
  const c = f.condition
  return !!(c.phoneTails.length || c.registeredStart || c.registeredEnd
    || (c.realnameStatus !== '' && c.realnameStatus !== null) || c.holdCollectibleId)
}

/** 条件摘要（列表展示用；后端 camelize_keys 递归转换，conditionConfig 为驼峰键） */
function conditionText(row) {
  const cfg = row.conditionConfig
  if (row.type !== 'condition' || !cfg) return ''
  const parts = []
  if ((cfg.phoneTails || []).length) parts.push('尾号 ' + cfg.phoneTails.join('/'))
  if (cfg.registeredStart || cfg.registeredEnd) {
    parts.push(`注册 ${cfg.registeredStart || '…'} ~ ${cfg.registeredEnd || '…'}`)
  }
  if (cfg.realnameStatus !== undefined && cfg.realnameStatus !== null) {
    const opt = REALNAME_OPTIONS.find((o) => o.value === cfg.realnameStatus)
    parts.push('实名' + (opt ? opt.label : cfg.realnameStatus))
  }
  if (cfg.holdCollectibleId) parts.push(`持有 ${cname(cfg.holdCollectibleId)} ≥${cfg.holdMinQty || 1}`)
  return parts.join(' 且 ')
}

async function onSave() {
  const f = form.value
  if (!f.name.trim()) return ElMessage.warning('请输入活动名称')
  if (!f.collectibleId) return ElMessage.warning('请选择空投藏品')
  if (!Number.isInteger(f.quantityPerUser) || f.quantityPerUser < 1 || f.quantityPerUser > 100) {
    return ElMessage.warning('每人发放数量需在 1~100')
  }
  if (f.type === 'condition' && !hasCondition(f)) {
    return ElMessage.warning('条件筛选型活动需至少配置一个筛选条件（手机尾号/注册时间/实名状态/持有藏品）')
  }
  if (f.type === 'hold' && !f.snapshotCollectibleId) {
    return ElMessage.warning('持有快照型活动需选择快照藏品')
  }

  submitting.value = true
  const res = await saveAirdropActivity({
    id: editing.value?.id,
    name: f.name.trim(),
    type: f.type,
    status: f.status,
    collectibleId: f.collectibleId,
    quantityPerUser: f.quantityPerUser,
    totalLimit: f.totalLimit,
    startTime: f.startTime,
    endTime: f.endTime,
    snapshotCollectibleId: f.snapshotCollectibleId,
    description: f.description,
    condition: f.condition
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(editing.value ? '空投活动已更新' : '空投活动已创建')
    editShow.value = false
    load()
  }
}

// ---------- 名单生成 ----------
const generatingId = ref(0)

async function onGenerate(row) {
  await ElMessageBox.confirm(
    `确认重新生成「${row.name}」资格名单？将按当前${row.type === 'condition' ? '筛选条件' : '活动规则'}重新筛选用户，未发放的资格记录将被替换（已发放记录保留）。`,
    '生成资格名单',
    { type: 'warning' }
  )
  generatingId.value = row.id
  const res = await generateAirdropEligibility(row.id)
  generatingId.value = 0
  if (res.code === 0) {
    ElMessage.success(`名单已更新：新增待发放 ${res.data.generated} 人（保留已发放 ${res.data.keptIssued} 人）`)
    load()
  }
}

// ---------- 资格名单抽屉 ----------
const drawerShow = ref(false)
const drawerAct = ref(null)
const eligLoading = ref(false)
const eligList = ref([])
const eligTotal = ref(0)
const eligPage = ref(1)
const eligPageSize = ref(20)
const eligStatus = ref('')

const ELIG_STATUS = {
  eligible: { label: '待发放', type: 'warning' },
  issued:   { label: '已发放', type: 'success' }
}

function openEligibilities(row) {
  drawerAct.value = row
  eligStatus.value = ''
  eligPage.value = 1
  drawerShow.value = true
  loadEligibilities()
}

async function loadEligibilities() {
  if (!drawerAct.value) return
  eligLoading.value = true
  try {
    const params = { activity_id: drawerAct.value.id, page: eligPage.value, pageSize: eligPageSize.value }
    if (eligStatus.value) params.status = eligStatus.value
    const res = await getAirdropEligibilities(params)
    eligList.value = (res.data && res.data.list) || []
    eligTotal.value = (res.data && res.data.total) || 0
  } finally {
    eligLoading.value = false
  }
}

// ---------- 发放 ----------
const issuingId = ref(0)

async function onIssue(row) {
  await ElMessageBox.confirm(
    `确认向「${row.name}」全部待发放用户（${row.eligibleCount} 人）发放藏品？将从藏品库存池扣减并计入活动已发放份数。`,
    '批量发放',
    { type: 'warning' }
  )
  issuingId.value = row.id
  const res = await issueAirdrop(row.id)
  issuingId.value = 0
  if (res.code === 0) {
    ElMessage.success(`已向 ${res.data.issued} 位用户发放完成（任务号 ${res.data.task_no}）`)
    load()
  }
}

// ---------- 删除 ----------
async function onDelete(row) {
  await ElMessageBox.confirm(
    `确认删除空投活动「${row.name}」？未发放的资格记录将一并清除，已发放过的活动禁止删除。`,
    '删除活动',
    { type: 'danger' }
  )
  const res = await deleteAirdropActivity(row.id)
  if (res.code === 0) {
    ElMessage.success('空投活动已删除')
    load()
  }
}
</script>

<template>
  <div class="adm-page am">
    <!-- 筛选工具栏 -->
    <div class="adm-card am__toolbar">
      <el-select v-model="filters.status" placeholder="状态" clearable style="width: 120px" @change="onSearch">
        <el-option v-for="(v, k) in AIRDROP_STATUS" :key="k" :value="k" :label="v.label" />
      </el-select>
      <el-select v-model="filters.type" placeholder="资格类型" clearable style="width: 130px" @change="onSearch">
        <el-option v-for="(v, k) in AIRDROP_TYPES" :key="k" :value="k" :label="v.label" />
      </el-select>
      <el-input
        v-model="filters.keyword"
        placeholder="活动名称关键词"
        clearable
        style="width: 200px"
        @keyup.enter="onSearch"
        @clear="onSearch"
      />
      <el-button @click="onSearch">查询</el-button>
      <el-button @click="onReset">重置</el-button>
      <div class="am__toolbar-right">
        <el-button type="primary" :icon="Plus" @click="openCreate">新建空投活动</el-button>
      </div>
    </div>

    <el-alert
      type="info"
      :closable="false"
      show-icon
      class="am__tip"
      title="空投管理流程：创建活动 → 生成资格名单（快照）→ 核对名单 → 批量发放；发放从藏品库存池扣减、写入用户持仓与收件箱通知，全程记录审计日志"
    />

    <!-- 活动列表 -->
    <el-table v-loading="loading" :data="list" class="adm-card">
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column label="活动名称" min-width="180">
        <template #default="{ row }">
          <div class="am__name">{{ row.name }}</div>
          <div v-if="row.description" class="am__desc">{{ row.description }}</div>
        </template>
      </el-table-column>
      <el-table-column label="资格类型" width="100">
        <template #default="{ row }">
          <el-tag :type="AIRDROP_TYPES[row.type]?.tag || 'info'" effect="plain" size="small">
            {{ AIRDROP_TYPES[row.type]?.label || row.type }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="空投藏品" min-width="160">
        <template #default="{ row }">
          <div class="am__collectible">
            <el-image
              v-if="row.collectibleImage"
              :src="row.collectibleImage"
              fit="cover"
              class="am__collectible-img"
              :preview-src-list="[row.collectibleImage]"
              preview-teleported
            />
            <span>{{ row.collectibleName || `#${row.collectibleId}` }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="筛选条件" min-width="200">
        <template #default="{ row }">
          <span v-if="row.type === 'condition'">{{ conditionText(row) || '—' }}</span>
          <span v-else-if="row.type === 'hold'">持有 {{ cname(row.snapshotCollectibleId) }}</span>
          <span v-else-if="row.type === 'direct'">全部有效用户</span>
          <span v-else-if="row.type === 'checkin'">累计签到 ≥ {{ row.conditionConfig?.days || 1 }} 天</span>
          <span v-else-if="row.type === 'login'">累计登录 ≥ {{ row.conditionConfig?.count || 1 }} 次</span>
          <span v-else-if="row.type === 'invite'">成功邀请 ≥ {{ row.conditionConfig?.count || 1 }} 人</span>
          <span v-else-if="row.type === 'register'">活动期间注册的新用户</span>
          <span v-else class="t-tertiary">{{ AIRDROP_TYPES[row.type]?.desc || row.type }}</span>
        </template>
      </el-table-column>
      <el-table-column label="每人份数" width="80" align="center">
        <template #default="{ row }">{{ fmtNumber(row.quantityPerUser) }}</template>
      </el-table-column>
      <el-table-column label="总限量" width="90" align="center">
        <template #default="{ row }">{{ row.totalLimit == null ? '不限' : fmtNumber(row.totalLimit) }}</template>
      </el-table-column>
      <el-table-column label="名单（待发/已发）" width="120" align="center">
        <template #default="{ row }">
          <span class="am__pending">{{ fmtNumber(row.eligibleCount) }}</span>
          <span class="t-tertiary"> / </span>
          <span class="am__issued">{{ fmtNumber(row.issuedUserCount) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="已发份数" width="90" align="center">
        <template #default="{ row }">{{ fmtNumber(row.issuedCount) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <StatusTag :value="row.status" :map="AIRDROP_STATUS" />
        </template>
      </el-table-column>
      <el-table-column label="创建时间" width="160" prop="createdAt" />
      <el-table-column label="操作" width="250" fixed="right">
        <template #default="{ row }">
          <el-button
            v-if="AIRDROP_TYPES[row.type]?.snapshottable"
            link type="primary" size="small" :loading="generatingId === row.id"
            @click="onGenerate(row)"
          >生成名单</el-button>
          <el-button link type="primary" size="small" @click="openEligibilities(row)">查看名单</el-button>
          <el-button
            v-if="row.status === 'active' && AIRDROP_TYPES[row.type]?.snapshottable"
            link type="success" size="small" :loading="issuingId === row.id"
            @click="onIssue(row)"
          >批量发放</el-button>
          <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" size="small" @click="onDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="pageSize"
      :total="total"
      :page-sizes="[10, 20, 50]"
      layout="total, sizes, prev, pager, next"
      class="am__pager"
      @current-change="load"
      @size-change="onSearch"
    />

    <!-- 新建/编辑弹窗 -->
    <el-dialog
      v-model="editShow"
      :title="editing ? `编辑空投活动 · ${editing.name}` : '新建空投活动'"
      width="680px"
      :close-on-click-modal="false"
    >
      <el-form label-width="110px">
        <el-form-item label="活动名称">
          <el-input v-model="form.name" placeholder="如：青铜纪事 · 尾号空投第一期" maxlength="50" show-word-limit />
        </el-form-item>

        <el-form-item label="资格类型">
          <el-radio-group v-model="form.type" :disabled="!!editing && editing.issuedCount > 0">
            <el-radio v-for="k in ['condition', 'direct', 'hold', 'checkin', 'register', 'login', 'invite']" :key="k" :value="k">
              {{ AIRDROP_TYPES[k].label }}
            </el-radio>
          </el-radio-group>
          <div class="t-tertiary am__hint">{{ AIRDROP_TYPES[form.type].desc }}；已发放过藏品的活动禁止更换资格类型</div>
        </el-form-item>

        <el-form-item label="活动状态">
          <el-select v-model="form.status" style="width: 180px">
            <el-option v-for="(v, k) in AIRDROP_STATUS" :key="k" :value="k" :label="v.label" />
          </el-select>
          <div class="t-tertiary am__hint">仅「进行中」的活动可执行批量发放</div>
        </el-form-item>

        <el-form-item label="空投藏品">
          <el-select v-model="form.collectibleId" filterable placeholder="选择要空投的藏品" style="width: 100%">
            <el-option v-for="c in collectibles" :key="c.id" :value="c.id" :label="`#${c.id} ${c.name}`" />
          </el-select>
          <div class="t-tertiary am__hint">发放时从该藏品库存池扣减；已发放过的活动禁止更换藏品</div>
        </el-form-item>

        <el-form-item label="每人份数">
          <el-input-number v-model="form.quantityPerUser" :min="1" :max="100" style="width: 160px" />
        </el-form-item>

        <el-form-item label="发放总限量">
          <el-input-number v-model="form.totalLimit" :min="1" :step="100" placeholder="留空不限" style="width: 160px" />
          <div class="t-tertiary am__hint">限制本活动累计发放份数（已发放 {{ fmtNumber(editing?.issuedCount || 0) }} 份），留空表示不限</div>
        </el-form-item>

        <el-form-item label="起止时间">
          <el-date-picker
            v-model="form.startTime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="开始时间（可留空）"
            style="width: 46%; margin-right: 4px"
          />
          <el-date-picker
            v-model="form.endTime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="截止时间（可留空）"
            style="width: 48%"
          />
        </el-form-item>

        <!-- 持有快照：选择快照藏品 -->
        <template v-if="form.type === 'hold'">
          <el-form-item label="快照藏品">
            <el-select v-model="form.snapshotCollectibleId" filterable placeholder="持有该藏品的用户进入名单" style="width: 100%">
              <el-option v-for="c in collectibles" :key="c.id" :value="c.id" :label="`#${c.id} ${c.name}`" />
            </el-select>
          </el-form-item>
        </template>

        <!-- 条件筛选：组合条件 -->
        <template v-if="form.type === 'condition'">
          <el-divider content-position="left">筛选条件（多选组合，需至少配置一项）</el-divider>
          <el-form-item label="手机尾号">
            <el-select v-model="form.condition.phoneTails" multiple placeholder="选择手机尾号（0-9，可多选）" style="width: 100%">
              <el-option v-for="t in PHONE_TAIL_OPTIONS" :key="t" :value="t" :label="`尾号 ${t}`" />
            </el-select>
            <div class="t-tertiary am__hint">可选 0-9 多个尾号，命中任一尾号即符合该条件</div>
          </el-form-item>

          <el-form-item label="注册时间">
            <el-input v-model="form.condition.registeredStart" placeholder="起始 2026-09-01 00:00:00（可留空）" style="width: 46%; margin-right: 4px" />
            <el-input v-model="form.condition.registeredEnd" placeholder="截止（可留空）" style="width: 48%" />
          </el-form-item>

          <el-form-item label="实名状态">
            <el-select v-model="form.condition.realnameStatus" style="width: 180px">
              <el-option v-for="o in REALNAME_OPTIONS" :key="String(o.value)" :value="o.value" :label="o.label" />
            </el-select>
          </el-form-item>

          <el-form-item label="持有藏品">
            <el-select
              v-model="form.condition.holdCollectibleId"
              filterable clearable placeholder="选择条件藏品（可留空）" style="width: 60%"
            >
              <el-option v-for="c in collectibles" :key="c.id" :value="c.id" :label="`#${c.id} ${c.name}`" />
            </el-select>
            <el-input-number
              v-if="form.condition.holdCollectibleId"
              v-model="form.condition.holdMinQty"
              :min="1" :max="99"
              style="width: 120px; margin-left: 8px"
            />
            <div class="t-tertiary am__hint">选择后仅统计有效持仓（持有中/寄售中/冻结）数量达标的用户</div>
          </el-form-item>
        </template>

        <!-- 行为型：阈值配置（生成名单时按此筛选） -->
        <template v-if="['checkin', 'login', 'invite'].includes(form.type)">
          <el-divider content-position="left">行为条件（生成名单时按此筛选）</el-divider>
          <el-form-item v-if="form.type === 'checkin'" label="累计签到天数">
            <el-input-number v-model="form.condition.days" :min="1" :max="3650" style="width: 160px" />
            <div class="t-tertiary am__hint">仅累计签到（按日去重）达到该天数的用户进入名单</div>
          </el-form-item>
          <el-form-item v-else label="累计阈值">
            <el-input-number v-model="form.condition.count" :min="1" :max="10000" style="width: 160px" />
            <div class="t-tertiary am__hint">
              {{ form.type === 'login' ? '仅累计登录次数达到该值的用户进入名单' : '仅累计成功邀请（被邀请人已注册）人数达到该值的用户进入名单' }}
            </div>
          </el-form-item>
        </template>
        <el-alert
          v-if="form.type === 'register'"
          type="info" :closable="false" show-icon
          title="注册行为型：以活动起止时间圈定该期间注册的新用户（未配置时间则为全部有效用户），请在上方「起止时间」中设置"
        />

        <el-form-item label="活动说明">
          <el-input v-model="form.description" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="内部备注说明" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onSave">
          {{ editing ? '保存修改' : '创建活动' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 资格名单抽屉 -->
    <el-drawer v-model="drawerShow" :title="`资格名单 · ${drawerAct?.name || ''}`" size="720px">
      <div class="am__elig-toolbar">
        <el-select v-model="eligStatus" placeholder="全部状态" clearable style="width: 140px" @change="onEligSearch">
          <el-option v-for="(v, k) in ELIG_STATUS" :key="k" :value="k" :label="v.label" />
        </el-select>
        <span class="t-tertiary" style="font-size: 12px">
          快照时间：{{ drawerAct?.snapshotAt || '未生成' }}
        </span>
      </div>
      <el-table v-loading="eligLoading" :data="eligList">
        <el-table-column prop="userId" label="用户ID" width="80" />
        <el-table-column prop="uid" label="UID" width="100" />
        <el-table-column prop="username" label="昵称" min-width="100" />
        <el-table-column prop="phone" label="手机号" width="130" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="ELIG_STATUS" />
          </template>
        </el-table-column>
        <el-table-column prop="createdAt" label="进入名单时间" width="160" />
        <el-table-column prop="handledAt" label="发放时间" width="160" />
      </el-table>
      <el-pagination
        v-model:current-page="eligPage"
        v-model:page-size="eligPageSize"
        :total="eligTotal"
        :page-sizes="[10, 20, 50]"
        layout="total, prev, pager, next"
        class="am__pager"
        @current-change="loadEligibilities"
        @size-change="onEligSearch"
      />
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.am__toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}

.am__toolbar-right { margin-left: auto; }

.am__tip { margin-bottom: 12px; }

.am__name { font-weight: 600; color: $color-text-primary; }
.am__desc { font-size: 12px; color: $color-text-tertiary; margin-top: 2px; }

.am__collectible {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: $color-text-primary;
}

.am__collectible-img {
  width: 32px;
  height: 32px;
  border-radius: 6px;
  flex-shrink: 0;
}

.am__pending { color: var(--color-warning); font-weight: 600; }
.am__issued { color: var(--color-success); font-weight: 600; }

.am__hint { font-size: 12px; margin-top: 4px; width: 100%; }

.am__pager { margin-top: 14px; justify-content: flex-end; }

.am__elig-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}
</style>
