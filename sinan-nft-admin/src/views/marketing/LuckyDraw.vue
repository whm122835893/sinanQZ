<script setup lang="ts">
/**
 * 抽奖活动管理
 *
 * - 活动列表：按期聚合（期数 / 奖项数 / 总库存 / 已中奖 / 概率合计 / 抽奖次数）
 * - 新增一期 / 配置奖项弹窗：奖品名、类型（藏品 / 司南币 / 谢谢参与）、数量、概率行内编辑
 * - 概率合计必须为 1，已有人中奖的奖项禁止删除、库存不能低于已中奖数（后端强校验）
 *
 * 接口：GET / POST /admin/marketing/lucky（保存为奖项整体覆盖式，prizes 以 activity_id 分组）
 * 权限：marketing:lucky:manage（页面访问为 marketing:lucky:list）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { fetchLuckyList, saveLucky } from '@/api/marketing'
import { fetchCollectibles } from '@/api/collectible'
import { useListPage } from '@/utils/useListPage'
import { money } from '@/utils/format'

/** 奖品类型（后端 prize_type：collectible / points / none） */
const PRIZE_TYPES: Record<string, string> = {
  collectible: '藏品',
  points: '司南币',
  none: '谢谢参与',
}

/**
 * 列表：后端返回全量期数数组（非分页），此处在适配器内做关键词过滤 + 前端分页，
 * 仍复用 useListPage 的搜索 / 分页 / 加载约定。
 */
const listPage = useListPage(async (params) => {
  // 接口层声明为 PageResult，后端实际返回全量期数数组，此处按数组断言
  const rows: any[] = ((await fetchLuckyList()) as unknown as any[]) || []
  const kw = String(params.keyword ?? '').trim()
  const filtered =
    kw === ''
      ? rows
      : rows.filter(
          (r) => String(r.name ?? '').includes(kw) || String(r.activityId ?? '') === kw,
        )
  const page = Number(params.page) || 1
  const pageSize = Number(params.pageSize) || 20
  return {
    list: filtered.slice((page - 1) * pageSize, page * pageSize),
    total: filtered.length,
    page,
    pageSize,
  }
}, { keyword: '' })

function onPageChange(page: number) {
  listPage.page = page
  listPage.load()
}

function onSizeChange(size: number) {
  listPage.pageSize = size
  listPage.page = 1
  listPage.load()
}

// ==================== 奖项配置弹窗 ====================
interface PrizeRow {
  id?: number
  tierName: string
  prizeType: string
  collectibleId?: number
  coinAmount?: number
  total: number
  probability: number
  sortOrder: number
  won: number
}

const dialogVisible = ref(false)
const saving = ref(false)
const isEdit = ref(false)

const form = reactive({
  activityId: 1,
  prizes: [] as PrizeRow[],
})

function defaultPrize(): PrizeRow {
  return {
    tierName: '',
    prizeType: 'collectible',
    collectibleId: undefined,
    coinAmount: undefined,
    total: 1,
    probability: 0,
    sortOrder: 0,
    won: 0,
  }
}

/** 概率合计（须为 1） */
const probabilitySum = computed(() =>
  form.prizes.reduce((s, p) => s + Number(p.probability || 0), 0),
)
const probabilityOk = computed(() => Math.abs(probabilitySum.value - 1) <= 0.0001)

/** 新增一期：期数 = 最大期数 + 1 */
async function openCreate() {
  try {
    const rows: any[] = ((await fetchLuckyList()) as unknown as any[]) || []
    form.activityId = rows.reduce((m, r) => Math.max(m, Number(r.activityId) || 0), 0) + 1
  } catch {
    form.activityId = 1
  }
  isEdit.value = false
  form.prizes = [defaultPrize()]
  dialogVisible.value = true
  searchCollectibles()
}

/** 配置某一期奖项 */
function openEdit(row: any) {
  isEdit.value = true
  form.activityId = Number(row.activityId) || 1
  form.prizes = (row.prizes || []).map((p: any) => ({
    id: p.id,
    tierName: p.tierName || '',
    prizeType: p.prizeType || 'collectible',
    collectibleId: p.collectibleId || undefined,
    coinAmount: p.coinAmount ?? undefined,
    total: Number(p.total ?? 1) || 1,
    probability: Number(p.probability ?? 0) || 0,
    sortOrder: Number(p.sortOrder ?? 0) || 0,
    won: Number(p.won ?? 0) || 0,
  }))
  // 回显奖品藏品
  ;(row.prizes || []).forEach((p: any) => {
    if (p.collectibleId) ensureCollectibleOption(p.collectibleId, p.collectibleName)
  })
  dialogVisible.value = true
  searchCollectibles()
}

function addPrize() {
  form.prizes.push(defaultPrize())
}

function removePrize(index: number) {
  const prize = form.prizes[index]
  if (prize.won > 0) {
    ElMessage.warning(`奖项「${prize.tierName}」已有人中奖（${prize.won}），禁止删除`)
    return
  }
  form.prizes.splice(index, 1)
}

async function submit() {
  if (!form.activityId || form.activityId < 1) {
    ElMessage.warning('请填写有效的期数')
    return
  }
  if (form.prizes.length < 1) {
    ElMessage.warning('至少配置一个奖项')
    return
  }
  for (let i = 0; i < form.prizes.length; i++) {
    const p = form.prizes[i]
    if (!p.tierName.trim()) {
      ElMessage.warning(`第 ${i + 1} 个奖项名称不能为空`)
      return
    }
    if (p.prizeType === 'collectible' && !p.collectibleId) {
      ElMessage.warning(`奖项「${p.tierName}」为藏品奖项，请选择奖品藏品`)
      return
    }
    if (p.prizeType === 'points' && (!p.coinAmount || p.coinAmount <= 0)) {
      ElMessage.warning(`奖项「${p.tierName}」为司南币奖项，请配置司南币数量`)
      return
    }
    if (p.total < 1) {
      ElMessage.warning(`奖项「${p.tierName}」库存需大于等于 1`)
      return
    }
    if (p.won > 0 && p.total < p.won) {
      ElMessage.warning(`奖项「${p.tierName}」库存不能低于已中奖数 ${p.won}`)
      return
    }
    if (Number(p.probability) < 0) {
      ElMessage.warning(`奖项「${p.tierName}」概率不能为负数`)
      return
    }
  }
  if (!probabilityOk.value) {
    ElMessage.warning(`概率合计必须为 1（当前 ${probabilitySum.value.toFixed(4)}）`)
    return
  }

  saving.value = true
  try {
    await saveLucky({
      activity_id: form.activityId,
      prizes: form.prizes.map((p, i) => ({
        ...(p.id ? { id: p.id } : {}),
        tier_name: p.tierName.trim(),
        prize_type: p.prizeType,
        collectible_id: p.prizeType === 'collectible' ? p.collectibleId : null,
        coin_amount: p.prizeType === 'points' ? p.coinAmount : null,
        total: p.total,
        probability: p.probability,
        sort_order: p.sortOrder || i + 1,
      })),
    })
    ElMessage.success(isEdit.value ? '抽奖奖项已更新' : '新一期抽奖已创建')
    dialogVisible.value = false
    listPage.load()
  } finally {
    saving.value = false
  }
}

// ==================== 藏品远程搜索（奖品藏品选择） ====================
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
    form.prizes.forEach((p) => ensureCollectibleOption(p.collectibleId))
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
        <el-form-item label="关键词">
          <el-input
            v-model="listPage.filters.keyword"
            placeholder="期数名称或期数编号"
            clearable
            style="width: 220px"
            @keyup.enter="listPage.search"
            @clear="listPage.search"
          />
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
          <div class="card-title">抽奖活动（按期管理）</div>
          <div class="card-sub">每期奖项概率合计必须为 1；已产生中奖记录的奖项禁止删除</div>
        </div>
        <el-button v-permission="'marketing:lucky:manage'" type="primary" @click="openCreate">新增一期</el-button>
      </div>

      <el-table v-loading="listPage.loading" :data="listPage.list">
        <el-table-column label="期数" width="90" align="center">
          <template #default="{ row }">第 {{ row.activityId }} 期</template>
        </el-table-column>
        <el-table-column prop="name" label="活动名称" min-width="150" show-overflow-tooltip />
        <el-table-column label="奖项数" width="90" align="center">
          <template #default="{ row }">{{ (row.prizes || []).length }}</template>
        </el-table-column>
        <el-table-column prop="totalStock" label="总库存" width="90" align="center" />
        <el-table-column prop="totalWon" label="已中奖" width="90" align="center" />
        <el-table-column label="概率合计" width="130" align="center">
          <template #default="{ row }">
            <el-tag :type="row.probabilityOk ? 'success' : 'danger'">
              {{ Number(row.probabilitySum ?? 0).toFixed(4) }}{{ row.probabilityOk ? '' : '（未归一）' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="drawCount" label="抽奖次数" width="100" align="center" />
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'marketing:lucky:manage'" link type="primary" @click="openEdit(row)">
              配置奖项
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

    <!-- 新增一期 / 配置奖项弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? `配置第 ${form.activityId} 期奖项` : '新增一期抽奖'"
      width="960px"
      destroy-on-close
      top="6vh"
    >
      <el-form label-width="90px" @submit.prevent="submit">
        <el-form-item label="期数">
          <el-input-number v-model="form.activityId" :min="1" :max="9999" :disabled="isEdit" />
          <span class="form-tip inline">同一期奖项将整体覆盖保存</span>
        </el-form-item>
      </el-form>

      <div class="prizes-toolbar">
        <el-button type="primary" plain @click="addPrize">添加奖项</el-button>
        <div class="probability-panel">
          概率合计：
          <el-tag :type="probabilityOk ? 'success' : 'danger'">
            {{ probabilitySum.toFixed(4) }}{{ probabilityOk ? '' : '（须为 1）' }}
          </el-tag>
        </div>
      </div>

      <el-table :data="form.prizes" size="small" border>
        <el-table-column label="排序" width="90" align="center">
          <template #default="{ row }">
            <el-input-number v-model="row.sortOrder" :min="0" :max="999" size="small" controls-position="right" style="width: 80px" />
          </template>
        </el-table-column>
        <el-table-column label="奖品名" width="150">
          <template #default="{ row }">
            <el-input v-model="row.tierName" maxlength="20" placeholder="如：一等奖" />
          </template>
        </el-table-column>
        <el-table-column label="类型" width="120">
          <template #default="{ row }">
            <el-select v-model="row.prizeType">
              <el-option v-for="(label, key) in PRIZE_TYPES" :key="key" :label="label" :value="key" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="奖品内容" min-width="220">
          <template #default="{ row }">
            <el-select
              v-if="row.prizeType === 'collectible'"
              v-model="row.collectibleId"
              filterable
              remote
              clearable
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
            <el-input-number
              v-else-if="row.prizeType === 'points'"
              v-model="row.coinAmount"
              :min="1"
              :max="999999"
              placeholder="司南币数量"
              style="width: 100%"
            />
            <span v-else class="sub-text">无奖品（未中奖）</span>
          </template>
        </el-table-column>
        <el-table-column label="库存 / 已中奖" width="180" align="center">
          <template #default="{ row }">
            <el-input-number v-model="row.total" :min="1" :max="999999" size="small" controls-position="right" style="width: 90px" />
            <span class="won-text">{{ row.won }}</span>
          </template>
        </el-table-column>
        <el-table-column label="概率" width="150" align="center">
          <template #default="{ row }">
            <el-input-number
              v-model="row.probability"
              :min="0"
              :max="1"
              :step="0.01"
              size="small"
              controls-position="right"
              style="width: 120px"
            />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" align="center">
          <template #default="{ $index }">
            <el-button link type="danger" :disabled="form.prizes[$index].won > 0" @click="removePrize($index)">
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>

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

.prizes-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;

  .probability-panel {
    font-size: 13px;
    color: var(--sn-text-secondary);
    display: flex;
    align-items: center;
    gap: 6px;
  }
}

.won-text {
  margin-left: 8px;
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.sub-text {
  font-size: 12px;
  color: var(--sn-text-secondary);
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
