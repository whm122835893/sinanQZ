<template>
  <div v-loading="loading" class="page-container">
    <!-- 顶部：返回 + 统计（文档 10.2-14） -->
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push(`/blindbox/${id}`)">返回详情</el-button>
        <div>
          <h3 class="head-title">子藏品与概率</h3>
          <span class="head-sub">{{ detail?.name || '—' }} · 仅草稿/已下架可修改概率，发售中需先下架（文档 6.2）</span>
        </div>
      </div>
      <div class="stock-strip">
        <div class="stock-item">
          <span class="stock-label">盲盒发行总量</span>
          <span class="stock-value din">{{ detail?.edition ?? '—' }}</span>
        </div>
        <div class="stock-item">
          <span class="stock-label">盲盒已售出发售</span>
          <span class="stock-value din">{{ detail?.sold ?? '—' }}</span>
        </div>
        <div class="stock-item">
          <span class="stock-label">盲盒库存池</span>
          <span class="stock-value din">{{ detail?.stockPool ?? '—' }}</span>
        </div>
        <div class="stock-item">
          <span class="stock-label">盲盒流通量</span>
          <span class="stock-value din">{{ detail?.circulate ?? '—' }}</span>
        </div>
      </div>
    </div>

    <template v-if="detail">
      <el-alert
        v-if="!editable"
        type="warning"
        :closable="false"
        show-icon
        title="发售中禁止修改子藏品概率"
        :description="`需先下架盲盒再修改概率，当前状态为「${STATUS_MAP[detail.status] ?? detail.status}」（文档 6.2）。`"
        style="margin-bottom: 12px"
      />

      <!-- 概率分布可视化（文档 5.4.2 / 10.2-18：饼图预览） -->
      <div class="sn-card">
        <div class="card-title-row">
          <span class="card-title">概率分布可视化（已保存配置）</span>
          <span class="sum-badge" :class="{ 'sum-badge--bad': !sumOk }">
            概率总和：{{ fmtPercent(detail.probabilitySum) }}%
          </span>
        </div>
        <div class="viz-grid">
          <EChartsWrapper :option="pieOption" :empty="!detail.items.length" :height="300" />
          <div class="viz-bars">
            <div v-for="item in detail.items" :key="item.id" class="viz-bar-row">
              <span class="viz-bar-name" :title="item.name">#{{ item.collectibleId }} {{ item.name }}</span>
              <el-progress
                :percentage="fmtPercent(item.probability)"
                :stroke-width="12"
                :color="SN_CHART_COLORS[0]"
                class="viz-bar"
              />
            </div>
            <el-empty v-if="!detail.items.length" description="尚未配置子藏品" :image-size="60" />
          </div>
        </div>
      </div>

      <!-- 当前生效配置：逐项修改（#56）/ 删除（#57） -->
      <div class="sn-card">
        <div class="card-title">当前生效配置（逐项修改 / 删除）</div>
        <el-table :data="detail.items" size="small" empty-text="尚未配置子藏品">
          <el-table-column label="子藏品" min-width="200">
            <template #default="{ row }">
              <div class="cell-item">
                <el-image :src="row.image" fit="cover" class="item-cover">
                  <template #error>
                    <div class="item-cover item-cover--fallback"><el-icon><Picture /></el-icon></div>
                  </template>
                </el-image>
                <div class="item-meta">
                  <span class="item-name">{{ row.name }}</span>
                  <span class="item-sub">#{{ row.collectibleId }} · 流通 {{ row.prizeCirculate }}/{{ row.prizeEdition }}</span>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="概率" width="120" align="right">
            <template #default="{ row }">
              <span class="din">{{ fmtPercent(row.probability) }}%</span>
            </template>
          </el-table-column>
          <el-table-column label="计划数量" width="90" align="right">
            <template #default="{ row }">{{ row.plannedQuantity ?? '不限' }}</template>
          </el-table-column>
          <el-table-column label="已发放" prop="distributed" width="80" align="right" />
          <el-table-column label="操作" width="130" fixed="right">
            <template #default="{ row }">
              <el-button text type="primary" :disabled="!editable" @click="openItemEdit(row)">修改</el-button>
              <el-button text type="danger" :disabled="!editable" @click="removeItem(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 全量配置（#55：items[] 全量替换） -->
      <div class="sn-card">
        <div class="card-title-row">
          <span class="card-title">全量配置（保存后替换全部子藏品配置）</span>
          <el-button type="primary" :icon="'Plus'" size="small" :disabled="!editable" @click="addRow">添加子藏品</el-button>
        </div>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="子藏品需 ≥ 2 个且概率总和 ≤ 100%；计划数量 0 表示不限；保存需管理员密码（文档 5.4.2 / 11.1）"
          style="margin-bottom: 12px"
        />
        <el-table :data="rows" size="small" empty-text="尚未添加子藏品，点击右上角「添加子藏品」">
          <el-table-column label="序号" type="index" width="60" align="center" />
          <el-table-column label="子藏品（库存池 > 0 或流通量 > 0）" min-width="280">
            <template #default="{ row, $index }">
              <el-select
                v-model="row.collectibleId"
                filterable
                placeholder="选择子藏品"
                :disabled="!editable"
                :loading="optionsLoading"
                style="width: 100%"
              >
                <el-option
                  v-for="opt in collectibleOptions"
                  :key="opt.value"
                  :label="opt.label"
                  :value="opt.value"
                  :disabled="isUsedByOther($index, opt.value)"
                />
              </el-select>
            </template>
          </el-table-column>
          <el-table-column label="概率（%）" width="190">
            <template #default="{ row }">
              <el-input-number
                v-model="row.percent"
                :min="0.0001"
                :max="100"
                :precision="4"
                :step="1"
                :controls="false"
                :disabled="!editable"
                style="width: 130px"
              />
            </template>
          </el-table-column>
          <el-table-column label="计划数量（0=不限）" width="190">
            <template #default="{ row }">
              <el-input-number
                v-model="row.planned"
                :min="0"
                :max="editionOf(row.collectibleId)"
                :step="1"
                step-strictly
                :disabled="!editable"
                style="width: 140px"
              />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="90" fixed="right">
            <template #default="{ $index }">
              <el-button text type="danger" :disabled="!editable" @click="removeRow($index)">移除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <!-- 实时概率总和 -->
        <div class="sum-row">
          <span class="sum-label">实时概率总和</span>
          <el-progress
            :percentage="fmtPercent(rowsSum)"
            :status="rowsSumOk ? undefined : 'exception'"
            :stroke-width="14"
            text-inside
            class="sum-progress"
          />
          <span class="sum-text" :class="{ 'sum-text--bad': !rowsSumOk }">{{ fmtPercent(rowsSum) }}%</span>
        </div>

        <div class="submit-row">
          <el-button
            v-permission="'blindbox:config'"
            type="primary"
            :loading="submitting"
            :disabled="!editable"
            @click="submitAll"
          >
            保存全部配置（验证密码）
          </el-button>
          <el-button :disabled="!editable" @click="resetRows">还原为当前生效配置</el-button>
        </div>
      </div>
    </template>

    <!-- 高风险：全量保存 / 单项修改（密码） -->
    <PasswordVerify
      ref="allPwdRef"
      title="保存子藏品配置"
      :require-reason="false"
      hint="盲盒子藏品概率修改属高风险操作，保存后将替换全部配置并写入审计日志。"
    />
    <PasswordVerify
      ref="itemPwdRef"
      title="修改子藏品"
      :require-reason="false"
      hint="修改概率/计划数量将写入审计日志。"
    />

    <!-- 单项修改弹窗（#56） -->
    <el-dialog v-model="itemEditVisible" title="修改子藏品" width="440px" :close-on-click-modal="false">
      <el-form v-if="itemEditTarget" label-width="110px" @submit.prevent>
        <el-form-item label="子藏品">
          <div class="cell-item">
            <el-image :src="itemEditTarget.image" fit="cover" class="item-cover">
              <template #error>
                <div class="item-cover item-cover--fallback"><el-icon><Picture /></el-icon></div>
              </template>
            </el-image>
            <span>{{ itemEditTarget.name }}（#{{ itemEditTarget.collectibleId }}）</span>
          </div>
        </el-form-item>
        <el-form-item label="概率（%）">
          <el-input-number v-model="itemEditForm.percent" :min="0.0001" :max="100" :precision="4" :step="1" :controls="false" style="width: 160px" />
        </el-form-item>
        <el-form-item label="计划数量">
          <el-input-number v-model="itemEditForm.planned" :min="0" :max="itemEditTarget.prizeEdition" :step="1" step-strictly style="width: 160px" />
          <div class="form-hint">0 表示不限；不可超过子藏品发行总量 {{ itemEditTarget.prizeEdition }}</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="itemEditVisible = false">取 消</el-button>
        <el-button type="primary" :loading="itemEditSubmitting" @click="submitItemEdit">下一步（验证密码）</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 子藏品与概率配置（文档 8.7 #55/#56/#57，5.4.2 概率闭环，10.2-18 可视化）
// - #55 全量配置：items[] 替换 + 密码 + 概率总和 ≤ 100%
// - #56 逐项修改：probability / planned_quantity + 密码
// - #57 逐项删除：仅 draft/off 且未发放
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { EChartsOption } from 'echarts'
import PasswordVerify from '@/components/PasswordVerify.vue'
import EChartsWrapper from '@/components/EChartsWrapper.vue'
import {
  fetchBlindboxDetail,
  fetchBlindboxes,
  configBlindboxItems,
  updateBlindboxItem,
  deleteBlindboxItem
} from '@/api/blindbox'
import { fetchCollectibles } from '@/api/collectible'
import { SN_CHART_COLORS } from '@/utils/charts'
import type { BlindboxDetail, BlindboxItem, CollectibleRow, PageData } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const STATUS_MAP: Record<string, string> = {
  draft: '草稿', upcoming: '待发售', onsale: '发售中', soldout: '已售罄', off: '已下架'
}

const loading = ref(false)
const detail = ref<BlindboxDetail | null>(null)

/** 配置仅 draft/off（文档 6.2：发售中禁止改概率，需先下架） */
const editable = computed(() => !!detail.value && ['draft', 'off'].includes(detail.value.status))

/** 已保存配置的概率总和是否合法 */
const sumOk = computed(() => !!detail.value && detail.value.probabilitySum <= 1.0001)

/** 百分比格式化（4 位小数，去尾零） */
function fmtPercent(decimal: number): number {
  return Math.round(decimal * 100 * 10000) / 10000
}

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchBlindboxDetail(id)
    resetRows()
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 概率分布饼图（文档 5.4.2） ----------------
const pieOption = computed<EChartsOption>(() => {
  const items = detail.value?.items ?? []
  const data = items.map((it) => ({
    name: `#${it.collectibleId} ${it.name}`,
    value: fmtPercent(it.probability)
  }))
  const sum = fmtPercent(detail.value?.probabilitySum ?? 0)
  if (sum < 100) {
    data.push({ name: '未分配', value: Math.round((100 - sum) * 10000) / 10000 })
  }
  return {
    tooltip: { trigger: 'item', formatter: '{b}: {c}%' },
    legend: { bottom: 0, type: 'scroll', textStyle: { color: '#6B7280', fontSize: 12 } },
    series: [
      {
        type: 'pie',
        radius: ['42%', '66%'],
        center: ['50%', '44%'],
        itemStyle: { borderRadius: 4, borderColor: '#fff', borderWidth: 2 },
        label: { formatter: '{c}%', color: '#6B7280', fontSize: 11 },
        data
      }
    ]
  }
})

// ---------------- 子藏品选项（库存池 > 0 或流通量 > 0，排除盲盒与自身） ----------------
interface OptionRow {
  value: number
  label: string
  edition: number
}

const optionsLoading = ref(false)
const collectibleOptions = ref<OptionRow[]>([])

async function loadOptions(): Promise<void> {
  optionsLoading.value = true
  try {
    // 藏品全量（分页拉取，上限 5 页防失控）
    const collectibles: CollectibleRow[] = []
    for (let page = 1; page <= 5; page++) {
      const data = (await fetchCollectibles({ page, pageSize: 100 })) as PageData<CollectibleRow>
      collectibles.push(...data.list)
      if (collectibles.length >= data.total) break
    }
    // 盲盒藏品行（不可作为子藏品）
    const blindboxIds = new Set<number>()
    for (let page = 1; page <= 5; page++) {
      const data = (await fetchBlindboxes({ page, pageSize: 100 })) as PageData<{ id: number; collectibleId: number }>
      data.list.forEach((row) => blindboxIds.add(row.collectibleId))
      if (page * 100 >= data.total) break
    }
    const selfId = detail.value?.collectibleId ?? -1
    // 文档 5.4.2：从库存池 > 0 或流通量 > 0 的藏品中选择；已配置的子藏品保留可选
    const configuredIds = new Set((detail.value?.items ?? []).map((it) => it.collectibleId))
    collectibleOptions.value = collectibles
      .filter((c) => !blindboxIds.has(c.id) && c.id !== selfId)
      .filter((c) => c.stockPool > 0 || c.circulate > 0 || configuredIds.has(c.id))
      .map((c) => ({
        value: c.id,
        label: `#${c.id} ${c.name}（库存 ${c.stockPool} · 流通 ${c.circulate}）`,
        edition: c.edition
      }))
  } catch {
    // 拦截器已提示
  } finally {
    optionsLoading.value = false
  }
}

function editionOf(collectibleId: number | undefined): number | undefined {
  if (!collectibleId) return undefined
  return collectibleOptions.value.find((o) => o.value === collectibleId)?.edition
}

// ---------------- 全量配置编辑器（#55） ----------------
interface EditRow {
  collectibleId: number | undefined
  percent: number
  planned: number
}

const rows = ref<EditRow[]>([])
const submitting = ref(false)
const allPwdRef = ref<InstanceType<typeof PasswordVerify>>()

/** 行概率总和（百分比） */
const rowsSum = computed(() => rows.value.reduce((acc, r) => acc + (r.percent || 0), 0))
const rowsSumOk = computed(() => rowsSum.value <= 100.0001)

/** 从已保存配置还原编辑器 */
function resetRows(): void {
  rows.value = (detail.value?.items ?? []).map((it) => ({
    collectibleId: it.collectibleId,
    percent: fmtPercent(it.probability),
    planned: it.plannedQuantity ?? 0
  }))
}

function addRow(): void {
  rows.value.push({ collectibleId: undefined, percent: 10, planned: 0 })
}

function removeRow(index: number): void {
  rows.value.splice(index, 1)
}

/** 同一子藏品不可重复配置 */
function isUsedByOther(rowIndex: number, collectibleId: number): boolean {
  return rows.value.some((r, i) => i !== rowIndex && r.collectibleId === collectibleId)
}

async function submitAll(): Promise<void> {
  if (!rows.value.length) {
    ElMessage.warning('请至少添加一个子藏品')
    return
  }
  if (rows.value.length < 2) {
    ElMessage.warning('盲盒子藏品至少 2 个（文档 10.2-20）')
    return
  }
  const invalidRow = rows.value.findIndex((r) => !r.collectibleId)
  if (invalidRow >= 0) {
    ElMessage.warning(`第 ${invalidRow + 1} 行未选择子藏品`)
    return
  }
  const seen = new Set<number>()
  for (const r of rows.value) {
    if (seen.has(r.collectibleId as number)) {
      ElMessage.warning(`子藏品 #${r.collectibleId} 重复配置`)
      return
    }
    seen.add(r.collectibleId as number)
  }
  if (!rowsSumOk.value) {
    ElMessage.warning(`概率总和超过 100%（当前 ${fmtPercent(rowsSum.value)}%）`)
    return
  }

  const ok = await allPwdRef.value?.open({ title: '保存子藏品配置', requireReason: false })
  if (!ok) return

  submitting.value = true
  try {
    const result = await configBlindboxItems(id, {
      items: rows.value.map((r) => ({
        collectible_id: r.collectibleId as number,
        probability: Math.round((r.percent / 100) * 1e8) / 1e8,
        planned_quantity: r.planned > 0 ? r.planned : null
      })),
      password: ok.password
    })
    ElMessage.success(`子藏品配置已保存（${result.items.length} 项，概率总和 ${fmtPercent(result.probabilitySum)}%）`)
    await load()
  } catch {
    // 拦截器已提示
  } finally {
    submitting.value = false
  }
}

// ---------------- 单项修改（#56） ----------------
const itemEditVisible = ref(false)
const itemEditSubmitting = ref(false)
const itemEditTarget = ref<BlindboxItem | null>(null)
const itemEditForm = reactive({ percent: 10, planned: 0 })
const itemPwdRef = ref<InstanceType<typeof PasswordVerify>>()

function openItemEdit(row: BlindboxItem): void {
  itemEditTarget.value = row
  itemEditForm.percent = fmtPercent(row.probability)
  itemEditForm.planned = row.plannedQuantity ?? 0
  itemEditVisible.value = true
}

async function submitItemEdit(): Promise<void> {
  const target = itemEditTarget.value
  if (!target) return
  if (itemEditForm.percent <= 0 || itemEditForm.percent > 100) {
    ElMessage.warning('概率必须在 0.0001 ~ 100 之间')
    return
  }

  const ok = await itemPwdRef.value?.open({ title: '修改子藏品', requireReason: false })
  if (!ok) return

  itemEditSubmitting.value = true
  try {
    await updateBlindboxItem(id, target.id, {
      probability: Math.round((itemEditForm.percent / 100) * 1e8) / 1e8,
      planned_quantity: itemEditForm.planned > 0 ? itemEditForm.planned : null,
      password: ok.password
    })
    ElMessage.success('子藏品已更新')
    itemEditVisible.value = false
    await load()
  } catch {
    // 拦截器已提示（概率总和超限等）
  } finally {
    itemEditSubmitting.value = false
  }
}

// ---------------- 单项删除（#57，仅未发放） ----------------
async function removeItem(row: BlindboxItem): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `确认删除子藏品「${row.name}」？仅未发放（已发放 = 0）的子藏品可删除。`,
      '删除子藏品',
      { type: 'warning', confirmButtonText: '删 除', cancelButtonText: '取 消' }
    )
  } catch {
    return
  }
  try {
    await deleteBlindboxItem(id, row.id)
    ElMessage.success('子藏品已删除')
    await load()
  } catch {
    // 拦截器已提示（已发放不可删除等）
  }
}

onMounted(async () => {
  await load()
  await loadOptions()
})
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.stock-strip {
  display: flex;
  gap: 10px;

  .stock-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 18px;
    background: $sn-bg;
    border-radius: 10px;
    min-width: 120px;

    .stock-label {
      font-size: 12px;
      color: $sn-text-sub;
    }

    .stock-value {
      font-size: 20px;
      font-weight: 600;
      color: $sn-text-main;
    }
  }
}

.card-title {
  font-size: 14px;
  font-weight: 600;
  color: $sn-text-main;
  margin-bottom: 12px;
}

.card-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;

  .card-title {
    margin-bottom: 0;
  }
}

.sum-badge {
  font-size: 13px;
  font-weight: 600;
  color: $sn-success;
  background: rgba(82, 196, 26, 0.08);
  border-radius: 6px;
  padding: 4px 10px;

  &--bad {
    color: $sn-danger;
    background: rgba(192, 21, 44, 0.08);
  }
}

.viz-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  align-items: start;
}

.viz-bars {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-top: 8px;
  max-height: 300px;
  overflow-y: auto;

  .viz-bar-row {
    display: flex;
    align-items: center;
    gap: 10px;

    .viz-bar-name {
      width: 180px;
      flex-shrink: 0;
      font-size: 12px;
      color: $sn-text-sub;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .viz-bar {
      flex: 1;
    }
  }
}

.cell-item {
  display: flex;
  align-items: center;
  gap: 10px;

  .item-cover {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    flex-shrink: 0;
    background: $sn-surface;

    &--fallback {
      display: flex;
      align-items: center;
      justify-content: center;
      color: $sn-text-muted;
    }
  }

  .item-meta {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;

    .item-name {
      font-weight: 500;
      color: $sn-text-main;
      @include ellipsis;
    }

    .item-sub {
      font-size: 12px;
      color: $sn-text-muted;
    }
  }
}

.sum-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 14px;

  .sum-label {
    font-size: 13px;
    color: $sn-text-sub;
    flex-shrink: 0;
  }

  .sum-progress {
    flex: 1;
  }

  .sum-text {
    font-size: 14px;
    font-weight: 600;
    color: $sn-text-main;
    min-width: 70px;
    text-align: right;

    &--bad {
      color: $sn-danger;
    }
  }
}

.submit-row {
  margin-top: 16px;
  display: flex;
  gap: 12px;
}

.form-hint {
  font-size: 12px;
  color: $sn-text-muted;
  line-height: 1.6;
  margin-top: 4px;
}

@media (max-width: 1365px) {
  .viz-grid {
    grid-template-columns: 1fr;
  }
}
</style>
