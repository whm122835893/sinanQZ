<script setup lang="ts">
/**
 * 活动空投管理
 *
 * - 空投活动列表：名称 / 藏品 / 每人发放数量 / 待发放 / 已发放 / 状态
 * - 新增 / 编辑弹窗：类型（直发 / 持仓 / 签到 / 注册 / 登录 / 邀请）、状态、藏品、数量、限量等
 * - 发放操作：向全部待发放（eligible）用户批量发放，二次确认后执行，发放后不可撤销
 *
 * 接口：GET /admin/marketing/airdrop、POST /admin/marketing/airdrop（保存）、
 *      POST /admin/marketing/airdrop/issue（发放，仅「进行中」活动可执行）
 * 权限：marketing:airdrop
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { fetchAirdropList, issueAirdrop, saveAirdrop } from '@/api/marketing'
import { fetchCollectibles } from '@/api/collectible'
import { useListPage } from '@/utils/useListPage'
import { datetime, money } from '@/utils/format'

/** 空投类型（后端 type：direct / hold / checkin / register / login / invite） */
const TYPES: Record<string, string> = {
  direct: '直发',
  hold: '持仓空投',
  checkin: '签到空投',
  register: '注册空投',
  login: '登录空投',
  invite: '邀请空投',
}

/** 活动状态（后端 status：draft / active / paused / ended） */
const STATUS: Record<string, { text: string; type: 'info' | 'success' | 'warning' }> = {
  draft: { text: '草稿', type: 'info' },
  active: { text: '进行中', type: 'success' },
  paused: { text: '已暂停', type: 'warning' },
  ended: { text: '已结束', type: 'info' },
}

/**
 * 列表：后端返回全量活动数组（最多 100 条，非分页），此处在适配器内做
 * 关键词 / 状态 / 类型过滤 + 前端分页，仍复用 useListPage 约定。
 */
const listPage = useListPage(async (params) => {
  // 接口层声明为 PageResult，后端实际返回全量活动数组（最多 100 条），此处按数组断言
  const rows: any[] = ((await fetchAirdropList()) as unknown as any[]) || []
  const kw = String(params.keyword ?? '').trim()
  const status = String(params.status ?? '')
  const type = String(params.type ?? '')
  const filtered = rows.filter(
    (r) =>
      (kw === '' || String(r.name ?? '').includes(kw) || String(r.collectibleName ?? '').includes(kw)) &&
      (status === '' || r.status === status) &&
      (type === '' || r.type === type),
  )
  const page = Number(params.page) || 1
  const pageSize = Number(params.pageSize) || 20
  return {
    list: filtered.slice((page - 1) * pageSize, page * pageSize),
    total: filtered.length,
    page,
    pageSize,
  }
}, { keyword: '', status: '', type: '' })

function onPageChange(page: number) {
  listPage.page = page
  listPage.load()
}

function onSizeChange(size: number) {
  listPage.pageSize = size
  listPage.page = 1
  listPage.load()
}

// ==================== 新增 / 编辑弹窗 ====================
const dialogVisible = ref(false)
const saving = ref(false)
const issuing = ref(false)
const formRef = ref<FormInstance>()

const form = reactive({
  id: undefined as number | undefined,
  name: '',
  type: 'direct',
  status: 'draft',
  collectibleId: undefined as number | undefined,
  /** 每人发放数量（1~100） */
  quantityPerUser: 1,
  airdropMode: 'realtime',
  /** 发放总量上限（份），0 表示不限制 */
  totalLimit: 0,
  /** 持仓空投：快照藏品 */
  snapshotCollectibleId: undefined as number | undefined,
  /** 签到空投：连续签到天数 */
  checkinDays: undefined as number | undefined,
  timeRange: [] as string[],
  description: '',
  conditionConfig: '',
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入活动名称', trigger: 'blur' }],
  type: [{ required: true, message: '请选择空投类型', trigger: 'change' }],
  collectibleId: [{ required: true, message: '请选择空投藏品', trigger: 'change' }],
}

function openCreate() {
  form.id = undefined
  form.name = ''
  form.type = 'direct'
  form.status = 'draft'
  form.collectibleId = undefined
  form.quantityPerUser = 1
  form.airdropMode = 'realtime'
  form.totalLimit = 0
  form.snapshotCollectibleId = undefined
  form.checkinDays = undefined
  form.timeRange = []
  form.description = ''
  form.conditionConfig = ''
  dialogVisible.value = true
  searchCollectibles()
}

function openEdit(row: any) {
  form.id = row.id
  form.name = row.name || ''
  form.type = row.type || 'direct'
  form.status = row.status || 'draft'
  form.collectibleId = row.collectibleId || undefined
  form.quantityPerUser = Number(row.quantityPerUser ?? 1) || 1
  form.airdropMode = row.airdropMode === 'batch' ? 'batch' : 'realtime'
  form.totalLimit = Number(row.totalLimit ?? 0) || 0
  form.snapshotCollectibleId = row.snapshotCollectibleId || undefined
  form.checkinDays = row.checkinDays ? Number(row.checkinDays) : undefined
  form.timeRange = row.startTime || row.endTime ? [row.startTime || '', row.endTime || ''] : []
  form.description = row.description || ''
  form.conditionConfig = row.conditionConfig || ''
  ensureCollectibleOption(row.collectibleId, row.collectibleName)
  dialogVisible.value = true
  searchCollectibles()
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  if (form.quantityPerUser < 1 || form.quantityPerUser > 100) {
    ElMessage.warning('每人发放数量需在 1~100')
    return
  }
  if (form.type === 'checkin' && (!form.checkinDays || form.checkinDays < 1)) {
    ElMessage.warning('签到空投需配置连续签到天数')
    return
  }

  saving.value = true
  try {
    const range = form.timeRange || []
    await saveAirdrop({
      ...(form.id ? { id: form.id } : {}),
      name: form.name.trim(),
      type: form.type,
      status: form.status,
      collectible_id: form.collectibleId,
      quantity_per_user: form.quantityPerUser,
      airdrop_mode: form.airdropMode,
      total_limit: form.totalLimit,
      snapshot_collectible_id: form.snapshotCollectibleId || 0,
      checkin_days: form.checkinDays || 0,
      condition_config: form.conditionConfig,
      description: form.description,
      start_time: range[0] || '',
      end_time: range[1] || '',
    })
    ElMessage.success(form.id ? '空投活动已更新' : '空投活动已创建')
    dialogVisible.value = false
    listPage.load()
  } finally {
    saving.value = false
  }
}

// ==================== 发放（危险操作，二次确认） ====================
async function issue(row: any) {
  if (row.status !== 'active') {
    ElMessage.warning('仅「进行中」的活动可执行发放')
    return
  }
  const count = Number(row.eligibleCount ?? 0)
  const perUser = Number(row.quantityPerUser ?? 0)
  try {
    await ElMessageBox.confirm(
      `即将为空投活动「${row.name}」执行批量发放：\n` +
        `· 待发放用户：${count} 人\n` +
        `· 空投藏品：「${row.collectibleName}」，每人 ${perUser} 份\n` +
        `· 预计共发放：${count * perUser} 份\n` +
        '· 发放后不可撤销，请确认藏品库存与总量限制无误',
      '发放确认',
      { type: 'warning', confirmButtonText: '确认发放', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  issuing.value = true
  try {
    const res = await issueAirdrop({ activity_id: row.id })
    ElMessage.success(`已向 ${res?.issued ?? 0} 位用户发放完成（任务号 ${res?.task_no ?? '-'}）`)
    listPage.load()
  } finally {
    issuing.value = false
  }
}

// ==================== 藏品远程搜索 ====================
const collectibleOptions = ref<any[]>([])
const collectibleLoading = ref(false)
const collectibleCache = new Map<number, any>()
let collectibleSeq = 0

function ensureCollectibleOption(id?: number | null, name?: string) {
  if (!id) return
  if (!collectibleCache.has(id)) collectibleCache.set(id, { id, name: name || `藏品 #${id}`, price: 0 })
  if (!collectibleOptions.value.some((o) => o.id === id)) {
    collectibleOptions.value.unshift(collectibleCache.get(id))
  }
}

async function searchCollectibles(keyword = '') {
  const seq = ++collectibleSeq
  collectibleLoading.value = true
  try {
    const res = await fetchCollectibles({ page: 1, pageSize: 50, keyword })
    if (seq !== collectibleSeq) return
    const rows = res?.list ?? []
    rows.forEach((r: any) => collectibleCache.set(r.id, r))
    collectibleOptions.value = [...rows]
    ensureCollectibleOption(form.collectibleId)
    ensureCollectibleOption(form.snapshotCollectibleId)
  } catch {
    /* 错误已全局提示 */
  } finally {
    if (seq === collectibleSeq) collectibleLoading.value = false
  }
}

onMounted(() => listPage.load())
</script>

<template>
  <div v-loading="issuing" class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent="listPage.search">
        <el-form-item label="关键词">
          <el-input
            v-model="listPage.filters.keyword"
            placeholder="活动名称 / 藏品名称"
            clearable
            style="width: 220px"
            @keyup.enter="listPage.search"
            @clear="listPage.search"
          />
        </el-form-item>
        <el-form-item label="类型">
          <el-select
            v-model="listPage.filters.type"
            placeholder="全部类型"
            clearable
            style="width: 140px"
            @change="listPage.search"
          >
            <el-option v-for="(label, key) in TYPES" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select
            v-model="listPage.filters.status"
            placeholder="全部状态"
            clearable
            style="width: 140px"
            @change="listPage.search"
          >
            <el-option v-for="(s, key) in STATUS" :key="key" :label="s.text" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="listPage.search">查询</el-button>
          <el-button @click="listPage.reset">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 活动列表 -->
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">活动空投</div>
          <div class="card-sub">按活动资格向用户批量发放藏品，仅「进行中」的活动可执行发放</div>
        </div>
        <el-button v-permission="'marketing:airdrop'" type="primary" @click="openCreate">新增活动</el-button>
      </div>

      <el-table v-loading="listPage.loading" :data="listPage.list">
        <el-table-column label="活动名称" min-width="170">
          <template #default="{ row }">
            <div>{{ row.name }}</div>
            <div class="sub-text">ID：{{ row.id }} · {{ TYPES[row.type] ?? row.type }}</div>
          </template>
        </el-table-column>
        <el-table-column label="空投藏品" min-width="170">
          <template #default="{ row }">
            <div>{{ row.collectibleName || '-' }}</div>
            <div class="sub-text">ID：{{ row.collectibleId }}</div>
          </template>
        </el-table-column>
        <el-table-column label="发放数量（每人）" width="120" align="center">
          <template #default="{ row }">{{ row.quantityPerUser }} 份</template>
        </el-table-column>
        <el-table-column label="总限量" width="100" align="center">
          <template #default="{ row }">{{ row.totalLimit ?? '不限' }}</template>
        </el-table-column>
        <el-table-column label="待发放" width="90" align="center">
          <template #default="{ row }">{{ row.eligibleCount ?? 0 }} 人</template>
        </el-table-column>
        <el-table-column label="已发放" width="90" align="center">
          <template #default="{ row }">{{ row.issuedCount ?? 0 }} 人</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="STATUS[row.status]?.type ?? 'info'">
              {{ STATUS[row.status]?.text ?? row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="活动时间" width="300">
          <template #default="{ row }">
            <div class="time-cell">
              <div>{{ datetime(row.startTime) }}</div>
              <div>至 {{ datetime(row.endTime) }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'marketing:airdrop'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button
              v-permission="'marketing:airdrop'"
              link
              type="danger"
              :disabled="row.status !== 'active'"
              @click="issue(row)"
            >
              发放
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          :current-page="listPage.page"
          :page-size="listPage.pageSize"
          :total="listPage.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="onPageChange"
          @size-change="onSizeChange"
        />
      </div>
    </div>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? '编辑空投活动' : '新增空投活动'"
      width="680px"
      destroy-on-close
      top="6vh"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="130px">
        <el-form-item label="活动名称" prop="name">
          <el-input v-model="form.name" maxlength="100" show-word-limit placeholder="请输入活动名称" />
        </el-form-item>
        <el-form-item label="空投类型" prop="type">
          <el-select v-model="form.type" style="width: 100%">
            <el-option v-for="(label, key) in TYPES" :key="key" :label="label" :value="key" />
          </el-select>
          <div class="form-tip">直发：全部用户；持仓：持有指定藏品；签到 / 注册 / 登录 / 邀请：达成对应行为</div>
        </el-form-item>
        <el-form-item label="活动状态">
          <el-radio-group v-model="form.status">
            <el-radio v-for="(s, key) in STATUS" :key="key" :value="key">{{ s.text }}</el-radio>
          </el-radio-group>
          <div class="form-tip">仅「进行中」的活动可执行批量发放</div>
        </el-form-item>
        <el-form-item label="空投藏品" prop="collectibleId">
          <el-select
            v-model="form.collectibleId"
            filterable
            remote
            :remote-method="searchCollectibles"
            :loading="collectibleLoading"
            placeholder="输入藏品名称搜索"
            style="width: 100%"
          >
            <el-option v-for="o in collectibleOptions" :key="o.id" :label="`#${o.id} ${o.name}`" :value="o.id">
              <span>{{ o.name }}</span>
              <span class="option-sub">#{{ o.id }} · ¥{{ money(o.price) }}</span>
            </el-option>
          </el-select>
          <div v-if="form.id && form.collectibleId" class="form-tip">活动已产生发放记录后禁止更换空投藏品</div>
        </el-form-item>
        <el-form-item label="每人发放数量">
          <el-input-number v-model="form.quantityPerUser" :min="1" :max="100" />
          <span class="form-tip inline">每位用户可获得的藏品份数（1~100）</span>
        </el-form-item>
        <el-form-item label="发放方式">
          <el-radio-group v-model="form.airdropMode">
            <el-radio value="realtime">实时发放</el-radio>
            <el-radio value="batch">批量发放</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="发放总量上限">
          <el-input-number v-model="form.totalLimit" :min="0" :max="999999" />
          <span class="form-tip inline">活动期间累计发放上限（份），0 表示不限制</span>
        </el-form-item>
        <el-form-item v-if="form.type === 'hold'" label="持仓快照藏品">
          <el-select
            v-model="form.snapshotCollectibleId"
            filterable
            remote
            clearable
            :remote-method="searchCollectibles"
            :loading="collectibleLoading"
            placeholder="持有该藏品的用户可参与（选填）"
            style="width: 100%"
          >
            <el-option v-for="o in collectibleOptions" :key="o.id" :label="`#${o.id} ${o.name}`" :value="o.id">
              <span>{{ o.name }}</span>
              <span class="option-sub">#{{ o.id }} · ¥{{ money(o.price) }}</span>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item v-if="form.type === 'checkin'" label="连续签到天数">
          <el-input-number v-model="form.checkinDays" :min="1" :max="365" />
          <span class="form-tip inline">连续签到达到该天数的用户可参与</span>
        </el-form-item>
        <el-form-item label="活动时间">
          <el-date-picker
            v-model="form.timeRange"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            range-separator="至"
            start-placeholder="开始时间（选填）"
            end-placeholder="结束时间（选填）"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="活动说明">
          <el-input v-model="form.description" type="textarea" :rows="2" maxlength="500" placeholder="展示给用户的活动说明（选填）" />
        </el-form-item>
        <el-form-item label="高级条件">
          <el-input
            v-model="form.conditionConfig"
            type="textarea"
            :rows="2"
            placeholder="条件配置 JSON 字符串（选填，与后端约定结构）"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .card-title {
    font-size: 15px;
    font-weight: 600;
  }
  .card-sub {
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.time-cell {
  line-height: 1.7;
}

.sub-text {
  font-size: 12px;
  color: var(--sn-text-secondary);
  margin-top: 2px;
}

.form-tip {
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.7;
  margin-top: 4px;

  &.inline {
    display: inline;
    margin-left: 10px;
  }
}

.option-sub {
  float: right;
  font-size: 12px;
  color: var(--sn-text-secondary);
}
</style>
