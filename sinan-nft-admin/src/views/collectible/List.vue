<script setup lang="ts">
/**
 * 藏品列表
 *
 * - 搜索：名称关键词 / 状态
 * - 顶部操作：新增藏品（collectible:create）、库存审计（collectible:audit）
 * - 行操作：详情 / 编辑 / 发售配置 / 强制售罄·强制下架 / 销毁库存 / 删除
 */
import { computed, nextTick, onMounted, reactive, ref, toRefs } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useListPage } from '@/utils/useListPage'
import {
  fetchCollectibleAudit,
  fetchCollectibleDetail,
  fetchCollectibles,
  createCollectible,
  updateCollectible,
  releaseCollectible,
  manageCollectible,
  destroyCollectibleStock,
  deleteCollectible,
} from '@/api/collectible'
import { COLLECTIBLE_STATUS, datetime, money } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

// ===== 列表（useListPage 返回 reactive 包装，内部为 ref，需 toRefs 解构保持响应式） =====
const pager = useListPage(fetchCollectibles, { keyword: '', status: '' })
const { list, total, loading, page, pageSize } = toRefs(pager)
const { filters, search, reset, load, done } = pager

onMounted(() => load())

/** 后端已下架存储值为 off，format.ts 字典键为 delisted，统一归一化后取字典 */
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
  router.push(`/collectible/${row.id}`)
}

/** 「更多」下拉：任一危险操作权限即可见，具体菜单项仍由 v-permission 控制 */
const showMore = computed(
  () =>
    auth.hasPermission('collectible:manage')
    || auth.hasPermission('collectible:destroy')
    || auth.hasPermission('collectible:delete'),
)

// ===== 新增 / 编辑弹窗 =====
const formDialog = reactive({
  visible: false,
  mode: 'create' as 'create' | 'edit',
  submitting: false,
  lockedPrice: false, // 已有售出记录的藏品禁止改价
})

const formRef = ref<FormInstance>()
const form = reactive({
  id: 0,
  name: '',
  subtitle: '',
  category_id: undefined as number | undefined,
  image: '',
  price: undefined as number | undefined,
  edition: undefined as number | undefined,
  per_user_limit: 0,
  is_transferable: 1,
  is_resaleable: 1,
})

const formRules: FormRules = {
  name: [
    { required: true, message: '请输入藏品名称', trigger: 'blur' },
    { max: 100, message: '名称不能超过 100 字', trigger: 'blur' },
  ],
  category_id: [{ required: true, message: '请输入所属类目 ID', trigger: 'blur' }],
  image: [{ required: true, message: '请输入图片 URL', trigger: 'blur' }],
  price: [{ required: true, message: '请输入单价（元）', trigger: 'blur' }],
  edition: [{ required: true, message: '请输入发行总量', trigger: 'blur' }],
}

function openCreate() {
  formDialog.mode = 'create'
  formDialog.lockedPrice = false
  Object.assign(form, {
    id: 0,
    name: '',
    subtitle: '',
    category_id: undefined,
    image: '',
    price: undefined,
    edition: undefined,
    per_user_limit: 0,
    is_transferable: 1,
    is_resaleable: 1,
  })
  formDialog.visible = true
  nextTick(() => formRef.value?.clearValidate())
}

async function openEdit(row: any) {
  try {
    // 列表字段不全（无限购/转赠/寄售等），编辑前先拉详情回填
    const d = await fetchCollectibleDetail(row.id)
    formDialog.mode = 'edit'
    formDialog.lockedPrice = Number(d.sold ?? 0) > 0
    Object.assign(form, {
      id: d.id,
      name: d.name ?? '',
      subtitle: d.subtitle ?? '',
      category_id: d.categoryId != null ? Number(d.categoryId) : undefined,
      image: d.image ?? '',
      price: d.price != null ? Number(d.price) : undefined,
      edition: d.edition != null ? Number(d.edition) : undefined,
      per_user_limit: Number(d.perUserLimit ?? 0),
      is_transferable: Number(d.isTransferable ?? 1),
      is_resaleable: Number(d.isResaleable ?? 1),
    })
    formDialog.visible = true
    nextTick(() => formRef.value?.clearValidate())
  } catch {
    /* 全局已提示 */
  }
}

async function submitForm() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  formDialog.submitting = true
  try {
    if (formDialog.mode === 'create') {
      await createCollectible({
        name: form.name.trim(),
        subtitle: form.subtitle.trim(),
        category_id: form.category_id,
        image: form.image.trim(),
        price: form.price,
        edition: form.edition,
        per_user_limit: form.per_user_limit,
        is_transferable: form.is_transferable,
        is_resaleable: form.is_resaleable,
      })
      ElMessage.success('藏品创建成功（当前为待发售状态）')
    } else {
      await updateCollectible(form.id, {
        name: form.name.trim(),
        subtitle: form.subtitle.trim(),
        category_id: form.category_id,
        image: form.image.trim(),
        price: form.price,
        per_user_limit: form.per_user_limit,
        is_transferable: form.is_transferable,
        is_resaleable: form.is_resaleable,
      })
      ElMessage.success('藏品信息已更新')
    }
    formDialog.visible = false
    await load()
  } catch {
    /* 全局已提示 */
  } finally {
    formDialog.submitting = false
  }
}

// ===== 发售配置弹窗 =====
const releaseDialog = reactive({ visible: false, submitting: false, id: 0, name: '' })
const releaseForm = reactive({
  status: 'onsale',
  onsale_at: null as string | null,
  off_sale_at: null as string | null,
})

function openRelease(row: any) {
  releaseDialog.id = row.id
  releaseDialog.name = row.name
  releaseForm.status = 'onsale'
  releaseForm.onsale_at = null
  releaseForm.off_sale_at = null
  releaseDialog.visible = true
}

async function submitRelease() {
  releaseDialog.submitting = true
  try {
    await releaseCollectible(releaseDialog.id, {
      status: releaseForm.status,
      onsale_at: releaseForm.onsale_at || undefined,
      off_sale_at: releaseForm.off_sale_at || undefined,
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

// ===== 强制售罄 / 强制下架 =====
async function handleManage(row: any, action: 'soldout' | 'off') {
  const isSoldout = action === 'soldout'
  const tip = isSoldout
    ? `确认将藏品「${row.name}」强制标记为已售罄？前台将立即展示售罄状态。`
    : `确认将藏品「${row.name}」强制下架？下架后用户将无法购买该藏品。`
  try {
    await ElMessageBox.confirm(tip, isSoldout ? '强制售罄' : '强制下架', {
      confirmButtonText: isSoldout ? '确认售罄' : '确认下架',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await manageCollectible(row.id, { action })
    await done(isSoldout ? '已标记为售罄' : '已强制下架')
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
      `仅可销毁库存池中的份额（不可触碰已售/锁定/配额），当前可销毁 ${pool} 份`,
      `销毁库存「${row.name}」`,
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
    await destroyCollectibleStock(row.id, { quantity, reason })
    await done('库存已销毁')
  } catch {
    /* 全局已提示 */
  }
}

// ===== 删除（软删除） =====
async function handleDelete(row: any) {
  try {
    await ElMessageBox.confirm(
      `确认删除藏品「${row.name}」？仅允许删除无售出、无空投、无持仓的藏品；删除为软删除，数据保留可追溯。`,
      '删除藏品',
      { confirmButtonText: '确认删除', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await deleteCollectible(row.id)
    await done('藏品已删除')
  } catch {
    /* 全局已提示 */
  }
}

function handleCommand(cmd: string, row: any) {
  if (cmd === 'soldout' || cmd === 'off') handleManage(row, cmd)
  else if (cmd === 'destroy') handleDestroy(row)
  else if (cmd === 'delete') handleDelete(row)
}

// ===== 库存审计 =====
const auditDialog = reactive({ visible: false, loading: false, data: null as any })

async function handleAudit() {
  auditDialog.loading = true
  try {
    auditDialog.data = await fetchCollectibleAudit()
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
            placeholder="藏品名称"
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
        <div class="toolbar-title">藏品列表</div>
        <div>
          <el-button v-permission="'collectible:create'" type="primary" @click="openCreate">新增藏品</el-button>
          <el-button
            v-permission="'collectible:audit'"
            type="warning"
            plain
            :loading="auditDialog.loading"
            @click="handleAudit"
          >
            库存审计
          </el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column label="藏品" min-width="240">
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
                <div class="sub">{{ row.subtitle || `ID：${row.id}` }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="类目" width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.categoryName || '-' }}</template>
        </el-table-column>
        <el-table-column label="单价（元）" width="100" align="right">
          <template #default="{ row }">{{ money(row.price) }}</template>
        </el-table-column>
        <el-table-column prop="edition" label="发行量" width="90" align="right" />
        <el-table-column prop="sold" label="已售" width="80" align="right" />
        <el-table-column prop="circulate" label="流通" width="80" align="right" />
        <el-table-column label="库存池" width="90" align="right">
          <template #default="{ row }">
            <el-tag :type="Number(row.availablePool) > 0 ? 'success' : 'danger'" size="small">
              {{ row.availablePool }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="96" align="center">
          <template #default="{ row }">
            <el-tag :type="statusInfo(row.status).type" size="small">{{ statusInfo(row.status).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" v-permission="'collectible:detail'" @click="goDetail(row)">
              详情
            </el-button>
            <el-button link type="primary" size="small" v-permission="'collectible:edit'" @click="openEdit(row)">
              编辑
            </el-button>
            <el-button link type="primary" size="small" v-permission="'collectible:release'" @click="openRelease(row)">
              发售配置
            </el-button>
            <el-dropdown v-if="showMore" trigger="click" @command="(cmd: string) => handleCommand(cmd, row)">
              <el-button link type="primary" size="small">
                更多<el-icon class="el-icon--right"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="soldout" v-permission="'collectible:manage'">强制售罄</el-dropdown-item>
                  <el-dropdown-item command="off" v-permission="'collectible:manage'">强制下架</el-dropdown-item>
                  <el-dropdown-item command="destroy" divided v-permission="'collectible:destroy'">销毁库存</el-dropdown-item>
                  <el-dropdown-item command="delete" v-permission="'collectible:delete'">删除藏品</el-dropdown-item>
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

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog
      v-model="formDialog.visible"
      :title="formDialog.mode === 'create' ? '新增藏品' : `编辑藏品（ID ${form.id}）`"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="96px">
        <el-form-item label="藏品名称" prop="name">
          <el-input v-model="form.name" maxlength="100" show-word-limit placeholder="不超过 100 字" />
        </el-form-item>
        <el-form-item label="副标题" prop="subtitle">
          <el-input v-model="form.subtitle" maxlength="100" placeholder="选填" />
        </el-form-item>
        <el-form-item label="类目 ID" prop="category_id">
          <el-input-number
            v-model="form.category_id"
            :min="1"
            :step="1"
            step-strictly
            controls-position="right"
            placeholder="藏品所属类目 ID"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="图片 URL" prop="image">
          <el-input v-model="form.image" placeholder="藏品主图地址（https://...）" />
        </el-form-item>
        <el-form-item label="单价（元）" prop="price">
          <el-input-number
            v-model="form.price"
            :min="0"
            :max="9999999.99"
            :precision="2"
            :step="1"
            controls-position="right"
            :disabled="formDialog.mode === 'edit' && formDialog.lockedPrice"
            style="width: 100%"
          />
          <div v-if="formDialog.mode === 'edit' && formDialog.lockedPrice" class="form-tip">
            该藏品已有售出记录，禁止修改价格
          </div>
        </el-form-item>
        <el-form-item label="发行总量" prop="edition">
          <el-input-number
            v-model="form.edition"
            :min="1"
            :max="100000"
            :step="1"
            step-strictly
            controls-position="right"
            :disabled="formDialog.mode === 'edit'"
            style="width: 100%"
          />
          <div v-if="formDialog.mode === 'edit'" class="form-tip">发行总量创建后不可修改（保护库存恒等式）</div>
        </el-form-item>
        <el-form-item label="每人限购" prop="per_user_limit">
          <el-input-number
            v-model="form.per_user_limit"
            :min="0"
            :step="1"
            step-strictly
            controls-position="right"
            style="width: 100%"
          />
          <div class="form-tip">0 表示不限制</div>
        </el-form-item>
        <el-form-item label="可转赠" prop="is_transferable">
          <el-switch v-model="form.is_transferable" :active-value="1" :inactive-value="0" active-text="允许" inactive-text="禁止" />
        </el-form-item>
        <el-form-item label="可寄售" prop="is_resaleable">
          <el-switch v-model="form.is_resaleable" :active-value="1" :inactive-value="0" active-text="允许" inactive-text="禁止" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="formDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="formDialog.submitting" @click="submitForm">
          {{ formDialog.mode === 'create' ? '确认创建' : '保存修改' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 发售配置弹窗 -->
    <el-dialog v-model="releaseDialog.visible" :title="`发售配置 - ${releaseDialog.name}`" width="500px" :close-on-click-modal="false">
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
        <el-form-item label="下架时间">
          <el-date-picker
            v-model="releaseForm.off_sale_at"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="选填"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item>
          <el-alert type="info" :closable="false" show-icon title="上架发售要求库存池大于 0；库存池为空需先调整库存" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="releaseDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="releaseDialog.submitting" @click="submitRelease">确认生效</el-button>
      </template>
    </el-dialog>

    <!-- 库存审计结果弹窗 -->
    <el-dialog v-model="auditDialog.visible" title="库存审计结果" width="760px">
      <template v-if="auditDialog.data">
        <el-alert
          :type="auditDialog.data.allOk ? 'success' : 'error'"
          :closable="false"
          show-icon
          :title="
            auditDialog.data.allOk
              ? `全部 ${auditDialog.data.checked} 个藏品库存恒等式校验通过`
              : `共检查 ${auditDialog.data.checked} 个藏品，发现 ${auditDialog.data.abnormalCount} 个异常`
          "
        />
        <el-table
          v-if="!auditDialog.data.allOk"
          :data="auditDialog.data.abnormal ?? []"
          max-height="380"
          border
          style="margin-top: 12px"
        >
          <el-table-column prop="id" label="藏品 ID" width="90" />
          <el-table-column prop="name" label="藏品名称" min-width="150" show-overflow-tooltip />
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

.audit-issue {
  font-size: 12px;
  line-height: 1.7;
  color: #c45656;
}
</style>
