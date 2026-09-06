<script setup lang="ts">
/**
 * 轮播图管理（CMS）
 *
 * - 列表：图片预览（点击放大）/ 描述 / 排序 / 启用状态（el-switch 直调启停）/ 创建时间
 * - 新增 / 编辑弹窗：图片地址（http(s):// 或以 / 开头）、描述、排序、启用开关
 * - 删除需二次确认（后端软删除，前台不再展示）
 *
 * 接口：GET/POST /admin/cms/banners、PUT/DELETE /admin/cms/banners/:id、
 *      POST /admin/cms/banners/:id/toggle
 * 权限：cms:banner
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { createBanner, deleteBanner, fetchBanners, toggleBanner, updateBanner } from '@/api/cms'
import { useListPage } from '@/utils/useListPage'
import { datetime } from '@/utils/format'

// ==================== 列表 ====================
const listPage = useListPage(fetchBanners, { status: '' })

function onPageChange(page: number) {
  listPage.page = page
  listPage.load()
}

function onSizeChange(size: number) {
  listPage.pageSize = size
  listPage.page = 1
  listPage.load()
}

// ==================== 启用 / 停用（表格内 el-switch 直调） ====================
const togglingId = ref<number | null>(null)

async function onToggle(row: any) {
  togglingId.value = row.id
  try {
    await toggleBanner(row.id)
    await listPage.refresh()
  } catch {
    /* 错误已全局提示；未刷新则开关保持原状态 */
  } finally {
    togglingId.value = null
  }
}

// ==================== 新增 / 编辑弹窗 ====================
const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()

const form = reactive({
  id: undefined as number | undefined,
  image: '',
  description: '',
  sortOrder: 0,
  isActive: true,
})

/** 图片地址格式校验（与后端一致）：http(s):// 或以 / 开头的相对路径 */
function validateImage(_rule: any, value: string, callback: (err?: Error) => void) {
  const v = (value || '').trim()
  if (!v) {
    callback(new Error('请输入图片地址'))
    return
  }
  if (!/^https?:\/\//i.test(v) && !v.startsWith('/')) {
    callback(new Error('图片地址需为 http(s):// 或以 / 开头的相对路径'))
    return
  }
  callback()
}

const rules: FormRules = {
  image: [{ required: true, validator: validateImage, trigger: 'blur' }],
}

function openCreate() {
  form.id = undefined
  form.image = ''
  form.description = ''
  form.sortOrder = 0
  form.isActive = true
  dialogVisible.value = true
}

function openEdit(row: any) {
  form.id = row.id
  form.image = row.image || ''
  form.description = row.description || ''
  form.sortOrder = Number(row.sortOrder ?? 0) || 0
  form.isActive = Number(row.isActive) === 1
  dialogVisible.value = true
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  saving.value = true
  try {
    // 后端入参为 snake_case：image / description / sort_order / is_active
    const payload = {
      image: form.image.trim(),
      description: form.description.trim(),
      sort_order: form.sortOrder,
      is_active: form.isActive ? 1 : 0,
    }
    if (form.id) {
      await updateBanner(form.id, payload)
      ElMessage.success('轮播图已更新')
    } else {
      await createBanner(payload)
      ElMessage.success('轮播图已创建')
    }
    dialogVisible.value = false
    await listPage.refresh()
  } catch {
    /* 错误已全局提示 */
  } finally {
    saving.value = false
  }
}

// ==================== 删除（二次确认） ====================
async function remove(row: any) {
  try {
    await ElMessageBox.confirm(
      `确定删除该轮播图吗？${row.description ? `（${row.description}）` : ''}删除后前台将不再展示。`,
      '删除确认',
      { type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    await deleteBanner(row.id)
    await listPage.done('轮播图已删除')
  } catch {
    /* 错误已全局提示 */
  }
}

onMounted(() => listPage.load())
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent="listPage.search">
        <el-form-item label="启用状态">
          <el-select
            v-model="listPage.filters.status"
            placeholder="全部"
            clearable
            style="width: 140px"
            @change="listPage.search"
          >
            <el-option label="启用" :value="1" />
            <el-option label="停用" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="listPage.search">查询</el-button>
          <el-button @click="listPage.reset">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 轮播图列表 -->
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">轮播图管理</div>
          <div class="card-sub">C 端首页轮播图，按排序值升序展示，仅启用的图片对外可见</div>
        </div>
        <el-button v-permission="'cms:banner'" type="primary" @click="openCreate">新增轮播图</el-button>
      </div>

      <el-table v-loading="listPage.loading" :data="listPage.list">
        <el-table-column label="图片" width="140">
          <template #default="{ row }">
            <el-image
              class="table-img banner-img"
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
          </template>
        </el-table-column>
        <el-table-column label="描述" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
        <el-table-column label="排序" width="80" align="center">
          <template #default="{ row }">{{ row.sortOrder ?? 0 }}</template>
        </el-table-column>
        <el-table-column label="启用状态" width="100" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="Number(row.isActive) === 1"
              :loading="togglingId === row.id"
              @change="onToggle(row)"
            />
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'cms:banner'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'cms:banner'" link type="danger" @click="remove(row)">删除</el-button>
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
      :title="form.id ? '编辑轮播图' : '新增轮播图'"
      width="560px"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="图片地址" prop="image">
          <el-input v-model="form.image" maxlength="255" placeholder="http(s):// 或以 / 开头的相对路径" />
          <div class="form-tip">建议上传至 CDN / OSS 后粘贴地址，或使用站点内相对路径</div>
        </el-form-item>
        <el-form-item label="描述" prop="description">
          <el-input
            v-model="form.description"
            maxlength="100"
            show-word-limit
            placeholder="轮播图描述（选填）"
          />
        </el-form-item>
        <el-form-item label="排序" prop="sortOrder">
          <el-input-number v-model="form.sortOrder" :min="0" :max="9999" />
          <span class="form-tip inline">数值越小越靠前</span>
        </el-form-item>
        <el-form-item label="启用" prop="isActive">
          <el-switch v-model="form.isActive" />
          <span class="form-tip inline">{{ form.isActive ? '启用：前台展示' : '停用：前台隐藏' }}</span>
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

.banner-img {
  width: 96px;
}

.img-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: var(--sn-text-secondary);
  background: #f3f4f6;
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
</style>
