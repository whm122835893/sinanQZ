<script setup lang="ts">
/**
 * 合成活动管理
 *
 * - 活动列表：素材藏品（含消耗数量）/ 目标藏品 / 类型 / 活动状态（按时间推导）/ 限量进度
 * - 新增 / 编辑弹窗：类型（限时 / 长期）、标题、目标藏品、素材配置（藏品 + 消耗数量）、
 *   每人限合成、总限量、规则说明、封面图
 *
 * 接口：GET / POST /admin/marketing/synthesis
 * 注意：列表接口不返回素材藏品 ID（仅名称），编辑时素材需重新选择后提交
 * 权限：marketing:synthesis:manage（页面访问为 marketing:synthesis:list）
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { fetchSynthesisList, saveSynthesis } from '@/api/marketing'
import { fetchCollectibles } from '@/api/collectible'
import { useListPage } from '@/utils/useListPage'
import { datetime, money } from '@/utils/format'

/** 活动类型（后端 type：limit 限时 / permanent 长期） */
const TYPES: Record<string, { text: string; type: 'warning' | 'success' }> = {
  limit: { text: '限时合成', type: 'warning' },
  permanent: { text: '长期合成', type: 'success' },
}

/** 活动状态：按类型 + 起止时间推导（后端无独立状态字段） */
function activityStatus(row: any): { text: string; type: 'success' | 'info' | 'primary' } {
  if (row.type === 'permanent') return { text: '长期有效', type: 'success' }
  const now = Date.now()
  const start = row.startTime ? new Date(String(row.startTime).replace(/-/g, '/')).getTime() : null
  const end = row.endTime ? new Date(String(row.endTime).replace(/-/g, '/')).getTime() : null
  if (start && now < start) return { text: '未开始', type: 'primary' }
  if (end && now > end) return { text: '已结束', type: 'info' }
  return { text: '进行中', type: 'success' }
}

// ==================== 列表（useListPage：搜索 / 分页 / 加载） ====================
const listPage = useListPage(fetchSynthesisList, { keyword: '', type: '' })

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
interface MaterialRow {
  collectibleId?: number
  count: number
  /** 编辑回显时的原素材名称（接口不返回素材 ID，需重新选择） */
  originName?: string
}

const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()
/** 编辑时已完成合成数（总限量不得低于该值） */
const usedCount = ref(0)

const form = reactive({
  id: undefined as number | undefined,
  type: 'limit',
  title: '',
  rules: '',
  resultCollectibleId: undefined as number | undefined,
  materials: [] as MaterialRow[],
  perUserLimit: 0,
  totalLimit: 0,
  image: '',
  timeRange: [] as string[],
})

const rules: FormRules = {
  title: [{ required: true, message: '请输入活动标题', trigger: 'blur' }],
  rules: [{ required: true, message: '请输入规则说明', trigger: 'blur' }],
  resultCollectibleId: [{ required: true, message: '请选择合成目标藏品', trigger: 'change' }],
}

function openCreate() {
  form.id = undefined
  form.type = 'limit'
  form.title = ''
  form.rules = ''
  form.resultCollectibleId = undefined
  form.materials = [{ collectibleId: undefined, count: 1 }]
  form.perUserLimit = 0
  form.totalLimit = 0
  form.image = ''
  form.timeRange = []
  usedCount.value = 0
  dialogVisible.value = true
  searchCollectibles()
}

function openEdit(row: any) {
  form.id = row.id
  form.type = row.type === 'permanent' ? 'permanent' : 'limit'
  form.title = row.title || ''
  form.rules = row.rules || ''
  form.resultCollectibleId = row.resultCollectibleId || undefined
  form.materials = (row.materials || []).map((m: any) => ({
    collectibleId: undefined,
    count: Number(m.count) || 1,
    originName: m.name,
  }))
  form.perUserLimit = Number(row.perUserLimit ?? 0) || 0
  form.totalLimit = Number(row.totalLimit ?? 0) || 0
  form.image = row.image || ''
  form.timeRange = row.startTime || row.endTime ? [row.startTime || '', row.endTime || ''] : []
  usedCount.value = Number(row.usedCount ?? 0) || 0
  ensureCollectibleOption(row.resultCollectibleId, row.resultName)
  dialogVisible.value = true
  searchCollectibles()
}

function addMaterial() {
  form.materials.push({ collectibleId: undefined, count: 1 })
}

function removeMaterial(index: number) {
  form.materials.splice(index, 1)
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  if (form.materials.length < 1) {
    ElMessage.warning('至少配置一种合成素材')
    return
  }
  for (const m of form.materials) {
    if (!m.collectibleId) {
      ElMessage.warning(`素材「${m.originName || '未选择'}」尚未选择藏品，请重新选择后提交`)
      return
    }
    if (m.count < 1 || m.count > 100) {
      ElMessage.warning('素材消耗数量需在 1~100')
      return
    }
    if (m.collectibleId === form.resultCollectibleId) {
      ElMessage.warning('素材不能包含合成结果藏品本身')
      return
    }
  }
  const ids = form.materials.map((m) => m.collectibleId) as number[]
  if (new Set(ids).size !== ids.length) {
    ElMessage.warning('素材列表存在重复藏品')
    return
  }
  if (form.totalLimit > 0 && usedCount.value > 0 && form.totalLimit < usedCount.value) {
    ElMessage.warning(`总限量不能低于已完成合成数 ${usedCount.value}`)
    return
  }

  saving.value = true
  try {
    const range = form.timeRange || []
    await saveSynthesis({
      ...(form.id ? { id: form.id } : {}),
      type: form.type,
      title: form.title.trim(),
      rules: form.rules,
      result_collectible_id: form.resultCollectibleId,
      materials: form.materials.map((m) => ({ collectible_id: m.collectibleId, count: m.count })),
      per_user_limit: form.perUserLimit,
      total_limit: form.totalLimit,
      image: form.image || '',
      start_time: range[0] || '',
      end_time: range[1] || '',
    })
    ElMessage.success(form.id ? '合成活动已更新' : '合成活动已创建')
    dialogVisible.value = false
    listPage.load()
  } finally {
    saving.value = false
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
    ensureCollectibleOption(form.resultCollectibleId)
    form.materials.forEach((m) => ensureCollectibleOption(m.collectibleId))
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
        <el-form-item label="活动标题">
          <el-input
            v-model="listPage.filters.keyword"
            placeholder="活动标题关键词"
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
            style="width: 150px"
            @change="listPage.search"
          >
            <el-option v-for="(t, key) in TYPES" :key="key" :label="t.text" :value="key" />
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
          <div class="card-title">合成活动</div>
          <div class="card-sub">用户消耗指定素材藏品合成目标藏品，素材不能包含目标藏品本身</div>
        </div>
        <el-button v-permission="'marketing:synthesis:manage'" type="primary" @click="openCreate">新增活动</el-button>
      </div>

      <el-table v-loading="listPage.loading" :data="listPage.list">
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column prop="title" label="活动标题" min-width="150" show-overflow-tooltip />
        <el-table-column label="类型" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="TYPES[row.type]?.type ?? 'info'">{{ TYPES[row.type]?.text ?? row.type }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="素材藏品（消耗数量）" min-width="200">
          <template #default="{ row }">
            <div v-if="(row.materials || []).length">
              <div v-for="(m, i) in row.materials" :key="i" class="material-line">
                <span>{{ m.name }}</span>
                <span class="material-count">× {{ m.count }}</span>
              </div>
            </div>
            <span v-else class="sub-text">-</span>
          </template>
        </el-table-column>
        <el-table-column label="目标藏品" min-width="200">
          <template #default="{ row }">
            <div class="collectible-cell">
              <img :src="row.resultImage" class="table-img" alt="" />
              <div>
                <div>{{ row.resultName }}</div>
                <div class="sub-text">ID：{{ row.resultCollectibleId }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="已合成 / 总限量" width="130" align="center">
          <template #default="{ row }">
            {{ row.usedCount ?? 0 }} / {{ row.totalLimit ?? '不限' }}
          </template>
        </el-table-column>
        <el-table-column label="每人限合成" width="100" align="center">
          <template #default="{ row }">{{ row.perUserLimit > 0 ? row.perUserLimit : '不限' }}</template>
        </el-table-column>
        <el-table-column label="活动状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="activityStatus(row).type">{{ activityStatus(row).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="活动时间" width="310">
          <template #default="{ row }">
            <div class="time-cell">
              <div>{{ datetime(row.startTime) }}</div>
              <div>至 {{ datetime(row.endTime) }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'marketing:synthesis:manage'" link type="primary" @click="openEdit(row)">编辑</el-button>
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
      :title="form.id ? '编辑合成活动' : '新增合成活动'"
      width="860px"
      destroy-on-close
      top="6vh"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="120px">
        <el-form-item label="活动类型">
          <el-radio-group v-model="form.type">
            <el-radio v-for="(t, key) in TYPES" :key="key" :value="key">{{ t.text }}</el-radio>
          </el-radio-group>
          <div class="form-tip">限时活动建议配置活动时间，长期活动时间可留空</div>
        </el-form-item>
        <el-form-item label="活动标题" prop="title">
          <el-input v-model="form.title" maxlength="100" show-word-limit placeholder="请输入活动标题" />
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
        <el-form-item label="目标藏品" prop="resultCollectibleId">
          <el-select
            v-model="form.resultCollectibleId"
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
          <div class="form-tip">合成产出的藏品</div>
        </el-form-item>

        <el-form-item label="素材配置">
          <div class="materials-wrap">
            <el-table :data="form.materials" size="small" border>
              <el-table-column label="素材藏品" min-width="260">
                <template #default="{ row }">
                  <el-select
                    v-model="row.collectibleId"
                    filterable
                    remote
                    clearable
                    :remote-method="searchCollectibles"
                    :loading="collectibleLoading"
                    :placeholder="row.originName ? `原素材：${row.originName}，请重新选择` : '输入藏品名称搜索'"
                    style="width: 100%"
                  >
                    <el-option v-for="o in collectibleOptions" :key="o.id" :label="`#${o.id} ${o.name}`" :value="o.id">
                      <span>{{ o.name }}</span>
                      <span class="option-sub">#{{ o.id }} · ¥{{ money(o.price) }}</span>
                    </el-option>
                  </el-select>
                </template>
              </el-table-column>
              <el-table-column label="消耗数量" width="170" align="center">
                <template #default="{ row }">
                  <el-input-number v-model="row.count" :min="1" :max="100" size="small" controls-position="right" />
                </template>
              </el-table-column>
              <el-table-column label="操作" width="80" align="center">
                <template #default="{ $index }">
                  <el-button link type="danger" @click="removeMaterial($index)">删除</el-button>
                </template>
              </el-table-column>
            </el-table>
            <el-button class="add-material" type="primary" plain size="small" @click="addMaterial">添加素材</el-button>
            <div v-if="form.id" class="form-tip">
              列表接口不返回素材藏品 ID，编辑时请为每行素材重新选择藏品后再保存；素材不能重复、不能包含目标藏品
            </div>
            <div v-else class="form-tip">素材不能重复、不能包含目标藏品本身；消耗数量 1~100</div>
          </div>
        </el-form-item>

        <el-form-item label="每人限合成">
          <el-input-number v-model="form.perUserLimit" :min="0" :max="9999" />
          <span class="form-tip inline">每位用户最多可合成次数，0 表示不限制</span>
        </el-form-item>
        <el-form-item label="总限量">
          <el-input-number v-model="form.totalLimit" :min="0" :max="999999" />
          <span class="form-tip inline">活动总合成份数上限，0 表示不限制<template v-if="usedCount > 0">（已完成 {{ usedCount }} 次，不得低于该值）</template></span>
        </el-form-item>
        <el-form-item label="规则说明" prop="rules">
          <el-input v-model="form.rules" type="textarea" :rows="3" maxlength="1000" placeholder="展示给用户的合成规则说明" />
        </el-form-item>
        <el-form-item label="封面图">
          <el-input v-model="form.image" maxlength="255" placeholder="封面图 URL（选填）" />
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

.material-line {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  line-height: 1.9;

  .material-count {
    color: var(--sn-red);
    font-weight: 600;
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

.materials-wrap {
  width: 100%;

  .add-material {
    margin-top: 8px;
  }
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
