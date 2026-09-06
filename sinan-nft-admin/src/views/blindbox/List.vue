<script setup lang="ts">
/**
 * 盲盒列表
 *
 * - 搜索：名称关键词 / 状态（父藏品状态）
 * - 顶部操作：新增盲盒（blindbox:create）、盲盒审计（blindbox:audit）
 * - 行操作：详情 / 配置奖品 / 发售 / 强制售罄 / 销毁库存
 */
import { computed, nextTick, onMounted, reactive, ref, toRefs } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useListPage } from '@/utils/useListPage'
import {
  fetchBlindBoxAudit,
  fetchBlindBoxDetail,
  fetchBlindBoxes,
  createBlindBox,
  saveBlindBoxConfig,
  releaseBlindBox,
  manageBlindBox,
  destroyBlindBoxStock,
} from '@/api/collectible'
import { COLLECTIBLE_STATUS, datetime, money } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

// ===== 列表（useListPage 返回 reactive 包装，内部为 ref，需 toRefs 解构保持响应式） =====
const pager = useListPage(fetchBlindBoxes, { keyword: '', status: '' })
const { list, total, loading, page, pageSize } = toRefs(pager)
const { filters, search, reset, load, done } = pager

onMounted(() => load())

/** 盲盒状态沿用父藏品状态；后端已下架存储值为 off，format.ts 字典键为 delisted */
function statusInfo(status?: string) {
  const key = status === 'off' ? 'delisted' : status
  return COLLECTIBLE_STATUS[key as string] ?? { text: status || '-', type: 'info' }
}

const statusOptions = [
  { label: COLLECTIBLE_STATUS.upcoming.text, value: 'upcoming' },
  { label: COLLECTIBLE_STATUS.onsale.text, value: 'onsale' },
  { label: COLLECTIBLE_STATUS.soldout.text, value: 'soldout' },
  { label: COLLECTIBLE_STATUS.delisted.text, value: 'off' }, // 后端筛选值
]

function onPageChange() {
  load()
}

function onSizeChange() {
  page.value = 1
  load()
}

function goDetail(row: any) {
  router.push(`/blindbox/${row.id}`)
}

/** 「更多」下拉：任一危险操作权限即可见，具体菜单项仍由 v-permission 控制 */
const showMore = computed(
  () => auth.hasPermission('blindbox:manage') || auth.hasPermission('blindbox:destroy'),
)

// ===== 奖品行（新增 / 配置奖品共用结构与校验，字段与后端 blind_box_items 对齐） =====
interface PrizeRow {
  id?: number
  prize_collectible_id?: number
  probability?: number
  quantity_limit?: number
  quantity_distributed?: number
}

function blankPrizeRow(): PrizeRow {
  return { prize_collectible_id: undefined, probability: undefined, quantity_limit: undefined }
}

function addPrize(target: PrizeRow[]) {
  if (target.length >= 50) {
    ElMessage.warning('奖池最多 50 个奖品')
    return
  }
  target.push(blankPrizeRow())
}

function removePrize(target: PrizeRow[], index: number) {
  if (target.length <= 2) {
    ElMessage.warning('奖池至少需要 2 个奖品')
    return
  }
  target.splice(index, 1)
}

function prizeSum(target: PrizeRow[]): number {
  return target.reduce((s, it) => s + Number(it.probability ?? 0), 0)
}

function prizeSumOk(target: PrizeRow[]): boolean {
  return Math.abs(prizeSum(target) - 1) <= 0.0001
}

/** 奖池校验：数量 / 概率区间 / 限量 / 重复奖品 / 概率合计（±0.0001 容差） */
function validatePrizes(target: PrizeRow[], protectDistributed = false): boolean {
  if (target.length < 2) {
    ElMessage.warning('奖池至少需要 2 个奖品')
    return false
  }
  const ids: number[] = []
  for (let i = 0; i < target.length; i++) {
    const it = target[i]
    if (!it.prize_collectible_id || it.prize_collectible_id < 1) {
      ElMessage.warning(`第 ${i + 1} 行：请填写正确的奖品藏品 ID`)
      return false
    }
    if (it.probability == null || it.probability <= 0 || it.probability > 1) {
      ElMessage.warning(`第 ${i + 1} 行：概率需在 (0, 1] 区间`)
      return false
    }
    if (it.quantity_limit != null && it.quantity_limit < 1) {
      ElMessage.warning(`第 ${i + 1} 行：限量需 ≥ 1（留空为不限量）`)
      return false
    }
    if (
      protectDistributed
      && it.id
      && it.quantity_limit != null
      && Number(it.quantity_distributed ?? 0) > it.quantity_limit
    ) {
      ElMessage.warning(`第 ${i + 1} 行：限量不能低于已发放量 ${it.quantity_distributed}`)
      return false
    }
    if (ids.includes(it.prize_collectible_id)) {
      ElMessage.warning(`奖池中存在重复奖品（ID ${it.prize_collectible_id}）`)
      return false
    }
    ids.push(it.prize_collectible_id)
  }
  if (!prizeSumOk(target)) {
    ElMessage.warning(`概率合计必须为 1（当前 ${prizeSum(target).toFixed(4)}）`)
    return false
  }
  return true
}

function buildPrizePayload(target: PrizeRow[]) {
  return target.map((it) => ({
    ...(it.id ? { id: it.id } : {}),
    prize_collectible_id: it.prize_collectible_id,
    probability: it.probability,
    ...(it.quantity_limit != null ? { quantity_limit: it.quantity_limit } : {}),
  }))
}

// ===== 新增盲盒 =====
const createDialog = reactive({ visible: false, submitting: false })
const createFormRef = ref<FormInstance>()
const createForm = reactive({
  name: '',
  category_id: undefined as number | undefined,
  image: '',
  price: undefined as number | undefined,
  edition: undefined as number | undefined,
  per_user_limit: 0,
  is_openable: 1,
  description: '',
})
const createItems = ref<PrizeRow[]>([blankPrizeRow(), blankPrizeRow()])

const createRules: FormRules = {
  name: [
    { required: true, message: '请输入盲盒名称', trigger: 'blur' },
    { max: 100, message: '名称不能超过 100 字', trigger: 'blur' },
  ],
  category_id: [{ required: true, message: '请输入所属类目 ID', trigger: 'blur' }],
  image: [{ required: true, message: '请输入图片 URL', trigger: 'blur' }],
  price: [{ required: true, message: '请输入单价（元）', trigger: 'blur' }],
  edition: [{ required: true, message: '请输入发行总量', trigger: 'blur' }],
}

function openCreate() {
  Object.assign(createForm, {
    name: '',
    category_id: undefined,
    image: '',
    price: undefined,
    edition: undefined,
    per_user_limit: 0,
    is_openable: 1,
    description: '',
  })
  createItems.value = [blankPrizeRow(), blankPrizeRow()]
  createDialog.visible = true
  nextTick(() => createFormRef.value?.clearValidate())
}

async function submitCreate() {
  const valid = await createFormRef.value?.validate().catch(() => false)
  if (!valid) return
  if (!validatePrizes(createItems.value)) return
  createDialog.submitting = true
  try {
    await createBlindBox({
      name: createForm.name.trim(),
      category_id: createForm.category_id,
      image: createForm.image.trim(),
      price: createForm.price,
      edition: createForm.edition,
      per_user_limit: createForm.per_user_limit,
      is_openable: createForm.is_openable,
      description: createForm.description.trim(),
      items: buildPrizePayload(createItems.value),
    })
    ElMessage.success('盲盒创建成功（待发售状态）')
    createDialog.visible = false
    await load()
  } catch {
    /* 全局已提示 */
  } finally {
    createDialog.submitting = false
  }
}

// ===== 配置奖品（整体覆盖式保存，已发放量保护） =====
const configDialog = reactive({ visible: false, loading: false, submitting: false, id: 0, name: '' })
const configItems = ref<PrizeRow[]>([])

async function openConfig(row: any) {
  configDialog.id = row.id
  configDialog.name = row.name
  configDialog.visible = true
  configDialog.loading = true
  configItems.value = []
  try {
    const d = await fetchBlindBoxDetail(row.id)
    configItems.value = (d.items ?? []).map((it: any) => ({
      id: it.id,
      prize_collectible_id: it.prizeCollectibleId != null ? Number(it.prizeCollectibleId) : undefined,
      probability: it.probability != null ? Number(it.probability) : undefined,
      quantity_limit: it.quantityLimit != null ? Number(it.quantityLimit) : undefined,
      quantity_distributed: Number(it.quantityDistributed ?? 0),
    }))
    if (!configItems.value.length) {
      configItems.value = [blankPrizeRow(), blankPrizeRow()]
    }
  } catch {
    /* 全局已提示 */
  } finally {
    configDialog.loading = false
  }
}

async function submitConfig() {
  if (!validatePrizes(configItems.value, true)) return
  configDialog.submitting = true
  try {
    await saveBlindBoxConfig(configDialog.id, { items: buildPrizePayload(configItems.value) })
    ElMessage.success('奖池配置已保存')
    configDialog.visible = false
    await load()
  } catch {
    /* 全局已提示 */
  } finally {
    configDialog.submitting = false
  }
}

// ===== 发售配置 =====
const releaseDialog = reactive({ visible: false, submitting: false, id: 0, name: '' })
const releaseForm = reactive({
  status: 'onsale',
  onsale_at: null as string | null,
})

function openRelease(row: any) {
  releaseDialog.id = row.id
  releaseDialog.name = row.name
  releaseForm.status = 'onsale'
  releaseForm.onsale_at = null
  releaseDialog.visible = true
}

async function submitRelease() {
  releaseDialog.submitting = true
  try {
    await releaseBlindBox(releaseDialog.id, {
      status: releaseForm.status,
      onsale_at: releaseForm.onsale_at || undefined,
    })
    ElMessage.success('发售配置已生效')
    releaseDialog.visible = false
    await load()
  } catch {
    /* 全局已提示 */
  } finally {
    releaseDialog.submitting = false
  }
}

// ===== 强制售罄 =====
async function handleSoldout(row: any) {
  try {
    await ElMessageBox.confirm(
      `确认将盲盒「${row.name}」强制标记为已售罄？前台将立即展示售罄状态。`,
      '强制售罄',
      { confirmButtonText: '确认售罄', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await manageBlindBox(row.id, { action: 'soldout' })
    await done('盲盒已标记售罄')
  } catch {
    /* 全局已提示 */
  }
}

// ===== 销毁库存（两步：数量 → 原因） =====
async function handleDestroy(row: any) {
  const pool = Number(row.availablePool ?? 0)
  if (pool <= 0) {
    ElMessage.warning('当前库存池为空，无可销毁份额')
    return
  }
  let quantity = 0
  try {
    const { value } = await ElMessageBox.prompt(
      `仅可销毁库存池中的份额（不可触碰已售/锁定/空投），当前可销毁 ${pool} 份`,
      `销毁盲盒库存「${row.name}」`,
      {
        confirmButtonText: '下一步',
        cancelButtonText: '取消',
        type: 'warning',
        inputPattern: /^[1-9]\d*$/,
        inputErrorMessage: '销毁数量必须为正整数',
        inputPlaceholder: `最多可销毁 ${pool} 份`,
      },
    )
    quantity = Number(value)
  } catch {
    return
  }
  if (quantity > pool) {
    ElMessage.warning(`销毁数量不能超过库存池（${pool} 份）`)
    return
  }
  let reason = ''
  try {
    const { value } = await ElMessageBox.prompt('请填写销毁原因（将记入销毁台账，供审计追溯）', '销毁原因', {
      confirmButtonText: '确认销毁',
      cancelButtonText: '取消',
      inputType: 'textarea',
      inputValidator: (v: string) => (v && v.trim().length >= 2 ? true : '原因至少输入 2 个字'),
    })
    reason = value.trim()
  } catch {
    return
  }
  try {
    await destroyBlindBoxStock(row.id, { quantity, reason })
    await done('盲盒库存已销毁')
  } catch {
    /* 全局已提示 */
  }
}

function handleCommand(cmd: string, row: any) {
  if (cmd === 'soldout') handleSoldout(row)
  else if (cmd === 'destroy') handleDestroy(row)
}

// ===== 盲盒审计 =====
const auditDialog = reactive({ visible: false, loading: false, data: null as any })

async function handleAudit() {
  auditDialog.loading = true
  try {
    auditDialog.data = await fetchBlindBoxAudit()
    auditDialog.visible = true
  } catch {
    /* 全局已提示 */
  } finally {
    auditDialog.loading = false
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="名称关键词">
          <el-input
            v-model="filters.keyword"
            placeholder="盲盒名称"
            clearable
            style="width: 220px"
            @keyup.enter="search"
            @clear="search"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 160px" @change="search">
            <el-option v-for="opt in statusOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">查询</el-button>
          <el-button @click="reset">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 表格 -->
    <div class="table-card">
      <div class="toolbar">
        <div class="toolbar-title">盲盒列表</div>
        <div>
          <el-button v-permission="'blindbox:create'" type="primary" @click="openCreate">新增盲盒</el-button>
          <el-button
            v-permission="'blindbox:audit'"
            type="warning"
            plain
            :loading="auditDialog.loading"
            @click="handleAudit"
          >
            盲盒审计
          </el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="盲盒（父藏品）" min-width="230">
          <template #default="{ row }">
            <div class="cell-collectible">
              <el-image
                class="table-img"
                :src="row.image"
                fit="cover"
                :preview-src-list="row.image ? [row.image] : []"
                preview-teleported
                hide-on-click-modal
              >
                <template #error>
                  <div class="img-fallback">暂无图片</div>
                </template>
              </el-image>
              <div class="collectible-info">
                <div class="name">{{ row.name }}</div>
                <div class="sub">盲盒 ID：{{ row.id }} · 藏品 ID：{{ row.collectibleId }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="单价（元）" width="100" align="right">
          <template #default="{ row }">{{ money(row.price) }}</template>
        </el-table-column>
        <el-table-column prop="edition" label="发行量" width="90" align="right" />
        <el-table-column prop="sold" label="已售" width="80" align="right" />
        <el-table-column label="库存池" width="90" align="right">
          <template #default="{ row }">
            <el-tag :type="Number(row.availablePool) > 0 ? 'success' : 'danger'" size="small">
              {{ row.availablePool }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="itemCount" label="奖品数" width="80" align="right" />
        <el-table-column label="概率合计" width="130" align="center">
          <template #default="{ row }">
            <span class="prob-num">{{ Number(row.probabilitySum ?? 0).toFixed(4) }}</span>
            <el-tag :type="row.probabilityOk ? 'success' : 'danger'" size="small" style="margin-left: 4px">
              {{ row.probabilityOk ? '合规' : '未配置' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="openedCount" label="已开盒" width="80" align="right" />
        <el-table-column label="可开盒" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="Number(row.isOpenable) === 1 ? 'success' : 'info'" size="small">
              {{ Number(row.isOpenable) === 1 ? '是' : '否' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="96" align="center">
          <template #default="{ row }">
            <el-tag :type="statusInfo(row.status).type" size="small">{{ statusInfo(row.status).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="开售时间" width="170">
          <template #default="{ row }">{{ datetime(row.onsaleAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" v-permission="'blindbox:detail'" @click="goDetail(row)">
              详情
            </el-button>
            <el-button link type="primary" size="small" v-permission="'blindbox:config'" @click="openConfig(row)">
              配置奖品
            </el-button>
            <el-button link type="primary" size="small" v-permission="'blindbox:release'" @click="openRelease(row)">
              发售
            </el-button>
            <el-dropdown v-if="showMore" trigger="click" @command="(cmd: string) => handleCommand(cmd, row)">
              <el-button link type="primary" size="small">
                更多<el-icon class="el-icon--right"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="soldout" v-permission="'blindbox:manage'">强制售罄</el-dropdown-item>
                  <el-dropdown-item command="destroy" divided v-permission="'blindbox:destroy'">销毁库存</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="onPageChange"
          @size-change="onSizeChange"
        />
      </div>
    </div>

    <!-- 新增盲盒弹窗 -->
    <el-dialog v-model="createDialog.visible" title="新增盲盒" width="860px" top="6vh" :close-on-click-modal="false">
      <el-form ref="createFormRef" :model="createForm" :rules="createRules" label-width="96px">
        <el-row :gutter="12">
          <el-col :span="12">
            <el-form-item label="盲盒名称" prop="name">
              <el-input v-model="createForm.name" maxlength="100" show-word-limit placeholder="不超过 100 字" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="类目 ID" prop="category_id">
              <el-input-number
                v-model="createForm.category_id"
                :min="1"
                :step="1"
                step-strictly
                controls-position="right"
                placeholder="盲盒资产所属类目 ID"
                style="width: 100%"
              />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="图片 URL" prop="image">
              <el-input v-model="createForm.image" placeholder="盲盒主图地址（https://...）" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="单价（元）" prop="price">
              <el-input-number
                v-model="createForm.price"
                :min="0"
                :max="9999999.99"
                :precision="2"
                :step="1"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="发行总量" prop="edition">
              <el-input-number
                v-model="createForm.edition"
                :min="1"
                :max="100000"
                :step="1"
                step-strictly
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="每人限购" prop="per_user_limit">
              <el-input-number
                v-model="createForm.per_user_limit"
                :min="0"
                :step="1"
                step-strictly
                controls-position="right"
                style="width: 100%"
              />
              <div class="form-tip">0 表示不限制</div>
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="可开盒" prop="is_openable">
              <el-switch
                v-model="createForm.is_openable"
                :active-value="1"
                :inactive-value="0"
                active-text="允许"
                inactive-text="禁止"
              />
            </el-form-item>
          </el-col>
          <el-col :span="24">
            <el-form-item label="盲盒描述" prop="description">
              <el-input
                v-model="createForm.description"
                type="textarea"
                :rows="2"
                maxlength="500"
                show-word-limit
                placeholder="选填"
              />
            </el-form-item>
          </el-col>
        </el-row>
      </el-form>

      <div class="prize-block-title">奖品配置（至少 2 个奖品，概率合计必须为 1，上架前必须配置完整）</div>
      <el-table :data="createItems" border size="small">
        <el-table-column type="index" label="#" width="44" align="center" />
        <el-table-column label="奖品藏品 ID" min-width="170">
          <template #default="{ row }">
            <el-input-number
              v-model="row.prize_collectible_id"
              :min="1"
              :step="1"
              step-strictly
              controls-position="right"
              placeholder="奖品藏品 ID"
              style="width: 100%"
            />
          </template>
        </el-table-column>
        <el-table-column label="概率（0~1）" min-width="150">
          <template #default="{ row }">
            <el-input-number
              v-model="row.probability"
              :min="0.0001"
              :max="1"
              :step="0.05"
              :precision="4"
              controls-position="right"
              placeholder="如 0.1"
              style="width: 100%"
            />
          </template>
        </el-table-column>
        <el-table-column label="限量（空=不限）" min-width="150">
          <template #default="{ row }">
            <el-input-number
              v-model="row.quantity_limit"
              :min="1"
              :step="1"
              step-strictly
              controls-position="right"
              placeholder="不限量"
              style="width: 100%"
            />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="60" align="center">
          <template #default="{ $index }">
            <el-button link type="danger" size="small" @click="removePrize(createItems, $index)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="prize-footer">
        <el-button type="primary" plain size="small" @click="addPrize(createItems)">添加奖品</el-button>
        <div class="prob-summary">
          概率合计
          <b :class="prizeSumOk(createItems) ? 'ok' : 'bad'">{{ prizeSum(createItems).toFixed(4) }}</b>
          <el-tag :type="prizeSumOk(createItems) ? 'success' : 'danger'" size="small">
            {{ prizeSumOk(createItems) ? '合计为 1' : '必须等于 1' }}
          </el-tag>
        </div>
      </div>

      <template #footer>
        <el-button @click="createDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="createDialog.submitting" @click="submitCreate">确认创建</el-button>
      </template>
    </el-dialog>

    <!-- 配置奖品弹窗 -->
    <el-dialog
      v-model="configDialog.visible"
      :title="`配置奖品 - ${configDialog.name}`"
      width="780px"
      :close-on-click-modal="false"
    >
      <div v-loading="configDialog.loading">
        <el-alert
          type="info"
          :closable="false"
          show-icon
          style="margin-bottom: 12px"
          title="整体覆盖式保存：已有条目限量不能低于已发放量；已发放量大于 0 的条目不可删除，仅可调整概率/限量；已开过盒的盲盒请谨慎调整概率"
        />
        <el-table :data="configItems" border size="small">
          <el-table-column type="index" label="#" width="44" align="center" />
          <el-table-column label="奖品藏品 ID" min-width="170">
            <template #default="{ row }">
              <el-input-number
                v-model="row.prize_collectible_id"
                :min="1"
                :step="1"
                step-strictly
                controls-position="right"
                placeholder="奖品藏品 ID"
                style="width: 100%"
              />
            </template>
          </el-table-column>
          <el-table-column label="概率（0~1）" min-width="150">
            <template #default="{ row }">
              <el-input-number
                v-model="row.probability"
                :min="0.0001"
                :max="1"
                :step="0.05"
                :precision="4"
                controls-position="right"
                placeholder="如 0.1"
                style="width: 100%"
              />
            </template>
          </el-table-column>
          <el-table-column label="限量（空=不限）" min-width="150">
            <template #default="{ row }">
              <el-input-number
                v-model="row.quantity_limit"
                :min="1"
                :step="1"
                step-strictly
                controls-position="right"
                placeholder="不限量"
                style="width: 100%"
              />
            </template>
          </el-table-column>
          <el-table-column label="已发放" width="70" align="center">
            <template #default="{ row }">{{ row.quantity_distributed ?? 0 }}</template>
          </el-table-column>
          <el-table-column label="操作" width="60" align="center">
            <template #default="{ row, $index }">
              <el-tooltip
                content="已发放量大于 0 的条目禁止删除（历史可追溯）"
                :disabled="Number(row.quantity_distributed ?? 0) === 0"
                placement="top"
              >
                <span>
                  <el-button
                    link
                    type="danger"
                    size="small"
                    :disabled="Number(row.quantity_distributed ?? 0) > 0"
                    @click="removePrize(configItems, $index)"
                  >
                    删除
                  </el-button>
                </span>
              </el-tooltip>
            </template>
          </el-table-column>
        </el-table>
        <div class="prize-footer">
          <el-button type="primary" plain size="small" @click="addPrize(configItems)">添加奖品</el-button>
          <div class="prob-summary">
            概率合计
            <b :class="prizeSumOk(configItems) ? 'ok' : 'bad'">{{ prizeSum(configItems).toFixed(4) }}</b>
            <el-tag :type="prizeSumOk(configItems) ? 'success' : 'danger'" size="small">
              {{ prizeSumOk(configItems) ? '合计为 1' : '必须等于 1' }}
            </el-tag>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="configDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="configDialog.submitting" @click="submitConfig">保存奖池配置</el-button>
      </template>
    </el-dialog>

    <!-- 发售配置弹窗 -->
    <el-dialog v-model="releaseDialog.visible" :title="`发售配置 - ${releaseDialog.name}`" width="480px" :close-on-click-modal="false">
      <el-form label-width="96px">
        <el-form-item label="发售状态">
          <el-radio-group v-model="releaseForm.status">
            <el-radio value="onsale">上架发售（onsale）</el-radio>
            <el-radio value="upcoming">待发售（upcoming）</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="开售时间">
          <el-date-picker
            v-model="releaseForm.onsale_at"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="留空则立即开售"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item>
          <el-alert type="info" :closable="false" show-icon title="奖池概率合计必须为 1 才能上架发售" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="releaseDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="releaseDialog.submitting" @click="submitRelease">确认生效</el-button>
      </template>
    </el-dialog>

    <!-- 盲盒审计结果弹窗 -->
    <el-dialog v-model="auditDialog.visible" title="盲盒审计结果" width="760px">
      <template v-if="auditDialog.data">
        <el-alert
          :type="auditDialog.data.allOk ? 'success' : 'error'"
          :closable="false"
          show-icon
          :title="
            auditDialog.data.allOk
              ? `全部 ${auditDialog.data.checked} 个盲盒审计通过（概率合规 / 限量 / 开盒对账）`
              : `共检查 ${auditDialog.data.checked} 个盲盒，发现 ${auditDialog.data.abnormalCount} 个异常`
          "
        />
        <el-table
          v-if="!auditDialog.data.allOk"
          :data="auditDialog.data.abnormal ?? []"
          max-height="380"
          border
          style="margin-top: 12px"
        >
          <el-table-column prop="id" label="盲盒 ID" width="90" />
          <el-table-column prop="name" label="盲盒名称" min-width="150" show-overflow-tooltip />
          <el-table-column label="异常项" min-width="360">
            <template #default="{ row }">
              <div v-for="(issue, i) in row.issues" :key="i" class="audit-issue">{{ issue }}</div>
            </template>
          </el-table-column>
        </el-table>
      </template>
      <template #footer>
        <el-button type="primary" @click="auditDialog.visible = false">知道了</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .toolbar-title {
    font-size: 15px;
    font-weight: 600;
  }
}

.cell-collectible {
  display: flex;
  align-items: center;
  gap: 10px;

  .collectible-info {
    min-width: 0;
    flex: 1;

    .name {
      font-weight: 500;
      color: var(--sn-text);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .sub {
      margin-top: 2px;
      font-size: 12px;
      color: var(--sn-text-secondary);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }
}

.prob-num {
  font-variant-numeric: tabular-nums;
}

.img-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: #9ca3af;
  background: #f3f4f6;
}

.form-tip {
  width: 100%;
  font-size: 12px;
  line-height: 1.6;
  color: var(--sn-text-secondary);
}

.prize-block-title {
  font-size: 13px;
  font-weight: 600;
  margin: 6px 0 10px;
  padding-left: 8px;
  border-left: 3px solid var(--sn-red);
}

.prize-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 12px;

  .prob-summary {
    font-size: 13px;
    color: var(--sn-text-secondary);
    display: flex;
    align-items: center;
    gap: 6px;

    b {
      font-variant-numeric: tabular-nums;
      font-size: 15px;

      &.ok {
        color: #2e7d32;
      }

      &.bad {
        color: #c45656;
      }
    }
  }
}

.audit-issue {
  font-size: 12px;
  line-height: 1.7;
  color: #c45656;
}
</style>
