<script setup lang="ts">
/**
 * 优先购活动管理
 *
 * - 活动列表：藏品 / 起止时间 / 白名单人数 / 状态（服务端分页，支持名称 + 状态筛选）
 * - 新增 / 编辑弹窗：选择藏品（一物一活动）+ 活动时间区间 + 白名单用户 UID 批量输入
 * - 删除白名单：二次确认后清空该活动全部白名单用户
 *
 * 接口：GET / POST /admin/marketing/priority
 * 注意：白名单为覆盖式保存，本次提交的 UID 列表将完全替换旧白名单（留空即清空）
 * 权限：marketing:priority:manage（页面访问为 marketing:priority:list）
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { fetchPriorityList, savePriority } from '@/api/marketing'
import { fetchCollectibles } from '@/api/collectible'
import { useListPage } from '@/utils/useListPage'
import { datetime, money } from '@/utils/format'

/** 活动状态（后端 priority_activities.status：disabled / enabled / ended） */
const STATUS: Record<string, { text: string; type: 'success' | 'info' | 'danger' }> = {
  disabled: { text: '已停用', type: 'danger' },
  enabled: { text: '进行中', type: 'success' },
  ended: { text: '已结束', type: 'info' },
}

// ==================== 列表（useListPage：搜索 / 分页 / 加载） ====================
const listPage = useListPage(fetchPriorityList, { keyword: '', status: '' })

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
const formRef = ref<FormInstance>()
/** 编辑时原白名单人数（用于覆盖式保存提示） */
const currentWhitelistCount = ref(0)

const form = reactive({
  id: undefined as number | undefined,
  collectibleId: undefined as number | undefined,
  name: '',
  timeRange: [] as string[],
  status: 'disabled',
  remark: '',
  /** 白名单用户 UID 批量输入（换行 / 逗号 / 空格分隔） */
  whitelistText: '',
  /** 白名单用户每人限购数量 */
  whitelistMaxQuantity: 1,
})

const rules: FormRules = {
  collectibleId: [{ required: true, message: '请选择活动藏品', trigger: 'change' }],
  name: [{ required: true, message: '请输入活动名称', trigger: 'blur' }],
  status: [{ required: true, message: '请选择活动状态', trigger: 'change' }],
}

function openCreate() {
  form.id = undefined
  form.collectibleId = undefined
  form.name = ''
  form.timeRange = []
  form.status = 'disabled'
  form.remark = ''
  form.whitelistText = ''
  form.whitelistMaxQuantity = 1
  currentWhitelistCount.value = 0
  dialogVisible.value = true
  searchCollectibles()
}

function openEdit(row: any) {
  form.id = row.id
  form.collectibleId = row.collectibleId || undefined
  form.name = row.name || ''
  form.timeRange = row.startTime || row.endTime ? [row.startTime || '', row.endTime || ''] : []
  form.status = row.status || 'disabled'
  form.remark = row.remark || ''
  // 列表接口仅返回白名单人数，不回显明细：留空提交即清空白名单，需保留请重新输入
  form.whitelistText = ''
  form.whitelistMaxQuantity = 1
  currentWhitelistCount.value = Number(row.whitelistCount ?? 0)
  ensureCollectibleOption(row.collectibleId, row.collectibleName)
  dialogVisible.value = true
  searchCollectibles()
}

/** 解析白名单文本 → 去重后的 UID 列表 */
function parseWhitelist(): number[] {
  const text = (form.whitelistText || '').trim()
  if (!text) return []
  const ids = text
    .split(/[\s,，;；、]+/)
    .map((s) => s.trim())
    .filter((s) => s !== '')
    .map(Number)
    .filter((n) => Number.isInteger(n) && n > 0)
  return [...new Set(ids)]
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  const whitelist = parseWhitelist()
  if ((form.whitelistText || '').trim() && whitelist.length === 0) {
    ElMessage.warning('白名单输入无法解析出有效的用户 UID')
    return
  }
  if (whitelist.length > 1000) {
    ElMessage.warning('白名单最多 1000 人')
    return
  }

  saving.value = true
  try {
    const range = form.timeRange || []
    await savePriority({
      ...(form.id ? { id: form.id } : {}),
      collectible_id: form.collectibleId,
      name: form.name.trim(),
      start_time: range[0] || '',
      end_time: range[1] || '',
      status: form.status,
      remark: form.remark,
      whitelist_max_quantity: form.whitelistMaxQuantity,
      whitelist,
    })
    ElMessage.success(form.id ? '优先购活动已更新' : '优先购活动已创建')
    dialogVisible.value = false
    listPage.load()
  } finally {
    saving.value = false
  }
}

/** 删除（清空）白名单：覆盖式提交空列表 */
async function clearWhitelist(row: any) {
  try {
    await ElMessageBox.confirm(
      `删除后活动「${row.name}」的白名单用户（${row.whitelistCount} 人）将全部清空，且不可恢复。确定删除该活动的白名单吗？`,
      '删除白名单',
      { type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  await savePriority({
    id: row.id,
    collectible_id: row.collectibleId,
    name: row.name,
    start_time: row.startTime || '',
    end_time: row.endTime || '',
    status: row.status,
    remark: row.remark || '',
    whitelist: [],
  })
  ElMessage.success('白名单已删除')
  listPage.load()
}

// ==================== 藏品远程搜索（弹窗内选择藏品） ====================
const collectibleOptions = ref<any[]>([])
const collectibleLoading = ref(false)
const collectibleCache = new Map<number, any>()
let collectibleSeq = 0

/** 把指定藏品补进选项列表（编辑回显，避免搜索结果中缺失） */
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
  } catch {
    /* 错误已全局提示 */
  } finally {
    if (seq === collectibleSeq) collectibleLoading.value = false
  }
}

onMounted(() => listPage.load())
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent="listPage.search">
        <el-form-item label="活动名称">
          <el-input
            v-model="listPage.filters.keyword"
            placeholder="活动名称关键词"
            clearable
            style="width: 220px"
            @keyup.enter="listPage.search"
            @clear="listPage.search"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select
            v-model="listPage.filters.status"
            placeholder="全部状态"
            clearable
            style="width: 150px"
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
          <div class="card-title">优先购活动</div>
          <div class="card-sub">一个藏品仅可创建一个优先购活动，白名单用户可在优先购时段内优先购买</div>
        </div>
        <el-button v-permission="'marketing:priority:manage'" type="primary" @click="openCreate">新增活动</el-button>
      </div>

      <el-table v-loading="listPage.loading" :data="listPage.list">
        <el-table-column label="藏品" min-width="220">
          <template #default="{ row }">
            <div class="collectible-cell">
              <img :src="row.image" class="table-img" alt="" />
              <div>
                <div>{{ row.collectibleName }}</div>
                <div class="sub-text">ID：{{ row.collectibleId }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="活动名称" min-width="150" show-overflow-tooltip />
        <el-table-column label="开始时间" width="170">
          <template #default="{ row }">{{ datetime(row.startTime) }}</template>
        </el-table-column>
        <el-table-column label="结束时间" width="170">
          <template #default="{ row }">{{ datetime(row.endTime) }}</template>
        </el-table-column>
        <el-table-column prop="whitelistCount" label="白名单数" width="90" align="center" />
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="STATUS[row.status]?.type ?? 'info'">
              {{ STATUS[row.status]?.text ?? row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'marketing:priority:manage'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'marketing:priority:manage'" link type="danger" @click="clearWhitelist(row)">
              删除白名单
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
      :title="form.id ? '编辑优先购活动' : '新增优先购活动'"
      width="640px"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="130px">
        <el-form-item label="活动藏品" prop="collectibleId">
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
          <div class="form-tip">一个藏品仅可创建一个优先购活动（一物一活动）</div>
        </el-form-item>
        <el-form-item label="活动名称" prop="name">
          <el-input v-model="form.name" maxlength="100" show-word-limit placeholder="请输入活动名称" />
        </el-form-item>
        <el-form-item label="活动时间">
          <el-date-picker
            v-model="form.timeRange"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            range-separator="至"
            start-placeholder="优先购开始时间"
            end-placeholder="优先购结束时间"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="活动状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio v-for="(s, key) in STATUS" :key="key" :value="key">{{ s.text }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="255" placeholder="选填" />
        </el-form-item>
        <el-form-item label="白名单用户 UID">
          <el-input
            v-model="form.whitelistText"
            type="textarea"
            :rows="5"
            placeholder="支持换行、逗号、空格分隔批量输入；最多 1000 个，重复与无效 UID 自动忽略"
          />
          <div class="form-tip">
            <template v-if="form.id">
              白名单为覆盖式保存：当前 {{ currentWhitelistCount }} 人，本次提交将完全替换；留空提交即清空白名单，需保留请重新输入完整 UID 列表
            </template>
            <template v-else>留空表示暂不配置白名单，可稍后在编辑中补充</template>
          </div>
        </el-form-item>
        <el-form-item label="每人限购数量">
          <el-input-number v-model="form.whitelistMaxQuantity" :min="1" :max="99" />
          <span class="form-tip inline">白名单用户在优先购期间每人最多可购买的数量</span>
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

.collectible-cell {
  display: flex;
  align-items: center;
  gap: 10px;
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
