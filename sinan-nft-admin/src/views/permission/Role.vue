<script setup lang="ts">
/**
 * 权限管理 - 角色管理
 *
 * - 表格：角色名、编码、描述、管理员数、权限数、状态、操作（编辑权限 / 删除）
 * - 新增/编辑弹窗：基本信息（名称/编码/描述/状态）+ 权限树（fetchPermissionTree，
 *   el-tree show-checkbox node-key=id，编辑时 default-checked 为角色已有权限的叶子节点）
 * - 保存把「选中 + 半选（父级菜单）」id 数组提交 createRole / updateRole
 * - 内置角色不可删除；超级管理员角色权限不可裁剪、不可停用
 *
 * 接口：GET/POST /admin/permission/roles、GET/PUT/DELETE /admin/permission/roles/:id、
 *       GET /admin/permission/tree
 * 权限：permission:role
 */
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules, ElTree } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { useListPage } from '@/utils/useListPage'
import {
  fetchRoles,
  fetchRoleDetail,
  fetchPermissionTree,
  createRole,
  updateRole,
  deleteRole,
} from '@/api/permission'
import { datetime } from '@/utils/format'

/** 后端角色列表直接返回 DB 行（snake_case 键），统一转 camelCase 视图模型使用 */
function camelizeKeys<T = any>(data: any): T {
  if (Array.isArray(data)) return data.map(camelizeKeys) as any
  if (data && typeof data === 'object') {
    const out: Record<string, any> = {}
    Object.keys(data).forEach((k) => {
      const nk = k.replace(/_([a-z0-9])/g, (_, c: string) => c.toUpperCase())
      out[nk] = camelizeKeys(data[k])
    })
    return out as any
  }
  return data
}

/** 角色列表为全量返回（无分页），包装成 PageResult 以复用 useListPage 的加载/刷新能力 */
const pager = useListPage(async () => {
  const rows = ((await fetchRoles()) ?? []).map(camelizeKeys)
  return { list: rows, total: rows.length, page: 1, pageSize: Math.max(rows.length, 1) }
})

onMounted(() => {
  pager.load()
  loadPermissionTree()
})

// ===== 权限树 =====

const treeRef = ref<InstanceType<typeof ElTree>>()
const permissionTree = ref<any[]>([])
const treeLoading = ref(false)

async function loadPermissionTree() {
  treeLoading.value = true
  try {
    permissionTree.value = (await fetchPermissionTree()) ?? []
  } catch {
    /* 错误已全局提示 */
  } finally {
    treeLoading.value = false
  }
}

/** 树的全部叶子节点 id 集合（回显时仅勾选叶子，父级由 el-tree 推导半选/全选） */
const leafIds = computed(() => {
  const ids = new Set<number>()
  const walk = (nodes: any[]) => {
    nodes.forEach((n) => {
      if (n.children && n.children.length > 0) walk(n.children)
      else ids.add(Number(n.id))
    })
  }
  walk(permissionTree.value)
  return ids
})

/** 提交时收集「勾选 + 半选（父级菜单）」权限 id */
function collectPermissionIds(): number[] {
  const tree = treeRef.value
  if (!tree) return []
  const checked = (tree.getCheckedKeys() as any[]).map(Number)
  const half = (tree.getHalfCheckedKeys() as any[]).map(Number)
  return [...new Set([...checked, ...half])]
}

function resetTreeChecked() {
  nextTick(() => treeRef.value?.setCheckedKeys([]))
}

// ===== 新增 / 编辑弹窗 =====

const dialogVisible = ref(false)
const isCreate = ref(true)
const submitting = ref(false)
const detailLoading = ref(false)
const formRef = ref<FormInstance>()

const RESERVED_CODES = ['super_admin', 'operator', 'finance', 'risk', 'support']

const form = reactive({
  id: 0,
  name: '',
  code: '',
  description: '',
  status: 1,
  isBuiltin: 0,
})

/** 超级管理员角色：权限不可裁剪、不可停用，仅可改名称与描述 */
const isSuperAdmin = computed(() => !isCreate.value && form.code === 'super_admin')

const rules: FormRules = {
  name: [
    { required: true, message: '请输入角色名称', trigger: 'blur' },
    { min: 2, max: 50, message: '角色名称需为 2~50 字', trigger: 'blur' },
  ],
  code: [
    { required: true, message: '请输入角色标识', trigger: 'blur' },
    { pattern: /^[a-z][a-z0-9_]{1,49}$/, message: '小写字母开头的 2~50 位字母/数字/下划线', trigger: 'blur' },
  ],
}

function openCreate() {
  isCreate.value = true
  form.id = 0
  form.name = ''
  form.code = ''
  form.description = ''
  form.status = 1
  form.isBuiltin = 0
  dialogVisible.value = true
  resetTreeChecked()
}

async function openEdit(row: any) {
  isCreate.value = false
  form.id = Number(row.id)
  form.name = String(row.name ?? '')
  form.code = String(row.code ?? '')
  form.description = String(row.description ?? '')
  form.status = Number(row.status) === 1 ? 1 : 0
  form.isBuiltin = Number(row.isBuiltin ?? 0)
  dialogVisible.value = true

  // 拉取角色详情（含已有权限 id 集合），回显到权限树（仅勾选叶子节点）
  detailLoading.value = true
  try {
    const detail = camelizeKeys(await fetchRoleDetail(form.id))
    const permissionIds: number[] = (detail?.permissionIds ?? []).map(Number)
    const leafChecked = permissionIds.filter((id) => leafIds.value.has(id))
    await nextTick()
    treeRef.value?.setCheckedKeys(leafChecked)
  } catch {
    /* 错误已全局提示 */
  } finally {
    detailLoading.value = false
  }
}

async function submitForm() {
  const valid = await formRef.value?.validate().then(() => true).catch(() => false)
  if (formRef.value && !valid) return

  if (isCreate.value && RESERVED_CODES.includes(form.code.trim())) {
    ElMessage.warning('该角色标识为内置保留，请更换')
    return
  }

  submitting.value = true
  try {
    if (isCreate.value) {
      await createRole({
        name: form.name.trim(),
        code: form.code.trim(),
        description: form.description.trim(),
        permission_ids: collectPermissionIds(),
      })
      ElMessage.success('角色已创建')
    } else {
      const payload: Record<string, any> = {
        name: form.name.trim(),
        description: form.description.trim(),
      }
      // 超级管理员角色：后端拒绝停用与权限调整，不提交 status / permission_ids
      if (!isSuperAdmin.value) {
        payload.status = form.status
        payload.permission_ids = collectPermissionIds()
      }
      await updateRole(form.id, payload)
      ElMessage.success('角色已更新')
    }
    dialogVisible.value = false
    pager.load()
  } catch {
    /* 业务错误已全局提示（内置角色保护 / 标识重复等） */
  } finally {
    submitting.value = false
  }
}

// ===== 删除角色 =====

async function onDelete(row: any) {
  try {
    await ElMessageBox.confirm(
      `确认删除角色「${row.name}」（${row.code}）？删除将同时解除该角色的全部权限映射，操作不可恢复`,
      '删除角色',
      { confirmButtonText: '确认删除', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await deleteRole(row.id)
    await pager.done('角色已删除')
  } catch {
    /* 业务错误已全局提示（内置角色不可删 / 仍有管理员引用等） */
  }
}

/** 删除按钮可用性：内置角色不可删；仍有管理员引用时禁用并提示 */
function deleteDisabled(row: any) {
  return Number(row.isBuiltin) === 1 || Number(row.adminCount ?? 0) > 0
}

function deleteTooltip(row: any) {
  if (Number(row.isBuiltin) === 1) return '内置角色不可删除'
  if (Number(row.adminCount ?? 0) > 0) return `该角色下仍有 ${row.adminCount} 名管理员，请先转移后再删除`
  return ''
}
</script>

<template>
  <div class="page-container">
    <div class="table-card">
      <div class="table-header">
        <div>
          <span class="table-title">角色列表</span>
          <span class="table-tip">
            共 {{ pager.total }} 个角色 · 角色权限基于权限树勾选（勾选动作权限时将自动带上父级菜单）；内置角色与超级管理员受后端保护
          </span>
        </div>
        <el-button v-permission="'permission:role'" type="primary" :icon="Plus" @click="openCreate">新增角色</el-button>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe row-key="id">
        <el-table-column prop="name" label="角色名" min-width="160" fixed="left">
          <template #default="{ row }">
            <span class="role-name">{{ row.name }}</span>
            <el-tag v-if="Number(row.isBuiltin) === 1" size="small" type="warning" class="builtin-tag">内置</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="code" label="编码" width="150">
          <template #default="{ row }">
            <span class="mono">{{ row.code }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="description" label="描述" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
        <el-table-column label="管理员数" width="100" align="center">
          <template #default="{ row }">{{ row.adminCount ?? 0 }}</template>
        </el-table-column>
        <el-table-column label="权限数" width="90" align="center">
          <template #default="{ row }">{{ row.permissionCount ?? 0 }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'" size="small" disable-transitions>
              {{ Number(row.status) === 1 ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'permission:role'" link type="primary" size="small" @click="openEdit(row)">
              编辑权限
            </el-button>
            <el-tooltip
              v-if="deleteDisabled(row)"
              :content="deleteTooltip(row)"
              placement="top"
            >
              <span class="disabled-wrap">
                <el-button v-permission="'permission:role'" link type="danger" size="small" disabled>删除</el-button>
              </span>
            </el-tooltip>
            <el-button
              v-else
              v-permission="'permission:role'"
              link
              type="danger"
              size="small"
              @click="onDelete(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <!-- 新增 / 编辑弹窗：基本信息 + 权限树 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isCreate ? '新增角色' : `编辑角色 · ${form.name}`"
      width="640px"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" @submit.prevent>
        <el-form-item label="角色名称" prop="name">
          <el-input v-model="form.name" maxlength="50" placeholder="2~50 字" />
        </el-form-item>
        <el-form-item label="角色标识" prop="code">
          <el-input v-model="form.code" :disabled="!isCreate" maxlength="50" placeholder="小写字母开头，如 content_ops" />
          <div v-if="!isCreate" class="form-tip">标识创建后不可修改</div>
          <div v-else class="form-tip">内置保留标识（super_admin / operator / finance / risk / support）不可使用</div>
        </el-form-item>
        <el-form-item label="描述">
          <el-input v-model="form.description" type="textarea" :rows="2" maxlength="255" show-word-limit placeholder="选填" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
            :disabled="isCreate || isSuperAdmin"
            active-text="启用"
            inactive-text="停用"
          />
          <div v-if="isSuperAdmin" class="form-tip">超级管理员角色不可停用</div>
          <div v-else-if="!isCreate" class="form-tip">停用前需保证该角色下无在职管理员</div>
        </el-form-item>

        <el-divider content-position="left">权限分配</el-divider>

        <el-alert
          v-if="isSuperAdmin"
          type="warning"
          :closable="false"
          show-icon
          title="超级管理员拥有全部权限，权限不可裁剪"
          style="margin-bottom: 12px"
        />
        <div v-loading="treeLoading || detailLoading" class="tree-wrap" :class="{ 'tree-disabled': isSuperAdmin }">
          <el-tree
            ref="treeRef"
            :data="permissionTree"
            :props="{ label: 'name', children: 'children' }"
            node-key="id"
            show-checkbox
            default-expand-all
            class="permission-tree"
          >
            <template #default="{ data }">
              <span class="tree-node">
                <span>{{ data.name }}</span>
                <span class="tree-code">{{ data.code }}</span>
                <el-tag v-if="Number(data.type) === 1" size="small" type="info" class="tree-tag">菜单</el-tag>
                <el-tag v-else-if="Number(data.type) === 2" size="small" type="success" class="tree-tag">按钮</el-tag>
              </span>
            </template>
          </el-tree>
        </div>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitForm">{{ isCreate ? '确认新增' : '保存修改' }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.table-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .table-title {
    font-size: 15px;
    font-weight: 600;
  }

  .table-tip {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.role-name {
  font-weight: 600;
}

.builtin-tag {
  margin-left: 6px;
}

.mono {
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  font-size: 13px;
}

.disabled-wrap {
  margin-left: 12px;
}

.form-tip {
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.4;
  margin-top: 2px;
}

.tree-wrap {
  border: 1px solid var(--sn-border);
  border-radius: 6px;
  padding: 8px 12px;
  max-height: 320px;
  overflow: auto;

  &.tree-disabled {
    pointer-events: none;
    opacity: 0.7;
    background: #fafafa;
  }
}

.permission-tree {
  --el-tree-node-content-height: 30px;
  background: transparent;

  .tree-node {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    .tree-code {
      font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
      font-size: 12px;
      color: var(--sn-text-secondary);
    }

    .tree-tag {
      flex-shrink: 0;
    }
  }
}
</style>
