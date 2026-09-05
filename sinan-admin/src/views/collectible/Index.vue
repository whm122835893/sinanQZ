<template>
  <div class="page-container">
    <!-- 检索区 -->
    <div class="sn-card">
      <el-form :model="query" inline class="query-form" @submit.prevent="handleSearch">
        <el-form-item label="关键词">
          <el-input v-model="query.name" placeholder="藏品名称模糊匹配" clearable style="width: 200px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部状态" clearable style="width: 130px">
            <el-option v-for="(label, key) in STATUS_MAP" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">查 询</el-button>
          <el-button @click="resetSearch">重 置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 列表 -->
    <div class="sn-card">
      <div class="table-toolbar">
        <span class="toolbar-title">藏品列表</span>
        <el-button v-permission="'collectible:create'" type="primary" :icon="'Plus'" @click="router.push('/collectible/create')">
          新建藏品
        </el-button>
      </div>

      <el-table v-loading="loading" :data="list" row-key="id">
        <el-table-column label="藏品" min-width="220" fixed="left">
          <template #default="{ row }">
            <div class="cell-collectible">
              <el-image :src="row.image" fit="cover" class="col-cover" :preview-src-list="[row.image]" preview-teleported>
                <template #error>
                  <div class="col-cover col-cover--fallback"><el-icon><Picture /></el-icon></div>
                </template>
              </el-image>
              <div class="col-meta">
                <span class="col-name">{{ row.name }}</span>
                <span class="col-sub">{{ row.subtitle || '—' }}</span>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="分类" prop="category" width="90" />
        <el-table-column label="价格" width="110" align="right">
          <template #default="{ row }">
            <span class="din amount">¥{{ row.price }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] ?? 'info'" size="small" effect="light">
              {{ STATUS_MAP[row.status] ?? row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="发行量" prop="edition" width="90" align="right" />
        <el-table-column label="已售/锁定" width="110" align="center">
          <template #default="{ row }">
            <span class="din">{{ row.sold }}</span> / <span class="din">{{ row.lockedQuantity }}</span>
          </template>
        </el-table-column>
        <el-table-column label="库存池" width="90" align="right">
          <template #default="{ row }">
            <span class="din" :class="{ 'pool-danger': row.stockPool <= 5 && ['onsale', 'upcoming'].includes(row.status) }">
              {{ row.stockPool }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="流通/空投/销毁" width="130" align="center">
          <template #default="{ row }">
            <span class="din">{{ row.circulate }}</span> / <span class="din">{{ row.airdroppedCount }}</span> / <span class="din">{{ row.destroyedCount }}</span>
          </template>
        </el-table-column>
        <el-table-column label="发售时间" width="170">
          <template #default="{ row }">{{ row.onsaleAt || '—' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="330" fixed="right">
          <template #default="{ row }">
            <el-button text type="primary" @click="router.push(`/collectible/${row.id}`)">详情</el-button>
            <el-button v-if="row.status === 'draft'" v-permission="'collectible:edit'" text type="primary" @click="router.push(`/collectible/${row.id}/edit`)">编辑</el-button>
            <el-button v-if="row.status === 'draft'" v-permission="'collectible:release'" text type="primary" @click="router.push(`/collectible/${row.id}/release`)">发售配置</el-button>
            <el-button v-permission="'collectible:quota'" text type="primary" @click="router.push(`/collectible/${row.id}/quota`)">配额</el-button>
            <el-button v-if="['onsale', 'soldout', 'upcoming'].includes(row.status)" v-permission="'collectible:airdrop'" text type="primary" @click="router.push(`/collectible/${row.id}/airdrop`)">空投</el-button>
            <el-button v-if="['onsale', 'soldout'].includes(row.status)" v-permission="'collectible:destroy'" text type="warning" @click="router.push(`/collectible/${row.id}/destroy`)">销毁</el-button>
            <el-button v-if="row.status === 'onsale'" v-permission="'collectible:manage'" text type="danger" @click="openForceSoldout(row)">强制售罄</el-button>
            <el-button v-if="['soldout', 'off'].includes(row.status)" v-permission="'collectible:relist'" text type="success" @click="openRelist(row)">重新上架</el-button>
            <el-button v-if="row.status === 'draft'" v-permission="'collectible:delete'" text type="danger" @click="openDelete(row)">删除</el-button>
            <el-button v-permission="'collectible:audit'" text @click="router.push(`/collectible/${row.id}/audit`)">审计</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="table-pagination">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="load"
          @size-change="handleSearch"
        />
      </div>
    </div>

    <!-- 高风险：强制售罄 -->
    <PasswordVerify ref="soldoutRef" title="强制售罄" hint="强制售罄不清零任何计数器，仅停止发售，剩余额度保留在库存池中。" />
    <!-- 高风险：重新上架 -->
    <el-dialog v-model="relistVisible" title="重新上架" width="420px" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent>
        <el-form-item label="上架数量（≤ 当前库存池）" required>
          <el-input-number v-model="relistForm.releaseQuantity" :min="1" :max="relistTarget?.stockPool ?? 1" style="width: 100%" />
          <div class="form-hint">当前库存池：<b class="din">{{ relistTarget?.stockPool ?? '—' }}</b></div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="relistVisible = false">取 消</el-button>
        <el-button type="primary" :loading="relistSubmitting" @click="submitRelistStep1">下一步（验证密码）</el-button>
      </template>
    </el-dialog>
    <PasswordVerify ref="relistPwdRef" title="重新上架确认" :require-reason="false" hint="重新上架后藏品将恢复 C 端可见与可购买。" />
    <!-- 高风险：删除 -->
    <PasswordVerify ref="deleteRef" title="删除藏品" :require-reason="false" hint="仅草稿且无任何关联（订单/资产/空投/销毁记录）的藏品可删除，删除为软删除。" />
  </div>
</template>

<script setup lang="ts">
// 藏品列表（文档 8.5 / 6.1 状态机；高风险操作走 PasswordVerify）
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import PasswordVerify from '@/components/PasswordVerify.vue'
import {
  fetchCollectibles,
  forceSoldoutCollectible,
  relistCollectible,
  deleteCollectible
} from '@/api/collectible'
import type { CollectibleRow, PageData } from '@/types/api'

const router = useRouter()

/** 藏品状态机（文档 6.1） */
const STATUS_MAP: Record<string, string> = {
  draft: '草稿',
  upcoming: '待发售',
  onsale: '发售中',
  soldout: '已售罄',
  off: '已下架'
}
const STATUS_TAG: Record<string, string> = {
  draft: 'info',
  upcoming: 'warning',
  onsale: 'success',
  soldout: 'danger',
  off: ''
}

// ---------------- 检索 ----------------
const query = reactive({ name: '', status: '' })

function handleSearch(): void {
  page.value = 1
  load()
}

function resetSearch(): void {
  query.name = ''
  query.status = ''
  handleSearch()
}

// ---------------- 列表 ----------------
const loading = ref(false)
const list = ref<CollectibleRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

async function load(): Promise<void> {
  loading.value = true
  try {
    const params: Record<string, unknown> = { page: page.value, pageSize: pageSize.value }
    if (query.name.trim()) params.name = query.name.trim()
    if (query.status) params.status = query.status
    const data = await fetchCollectibles(params)
    const pageData = data as PageData<CollectibleRow>
    list.value = pageData.list
    total.value = pageData.total
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 强制售罄（reason + password） ----------------
const soldoutRef = ref<InstanceType<typeof PasswordVerify>>()
let soldoutTarget: CollectibleRow | null = null

async function openForceSoldout(row: CollectibleRow): Promise<void> {
  soldoutTarget = row
  const ok = await soldoutRef.value?.open({ title: '强制售罄', reasonLabel: '售罄原因' })
  if (!ok || !soldoutTarget) return
  try {
    await forceSoldoutCollectible(soldoutTarget.id, { reason: ok.reason, password: ok.password })
    ElMessage.success(`「${soldoutTarget.name}」已强制售罄`)
    load()
  } catch {
    // 拦截器已提示
  }
}

// ---------------- 重新上架（releaseQuantity ≤ 库存池 + password） ----------------
const relistVisible = ref(false)
const relistSubmitting = ref(false)
const relistTarget = ref<CollectibleRow | null>(null)
const relistForm = reactive({ releaseQuantity: 1 })
const relistPwdRef = ref<InstanceType<typeof PasswordVerify>>()

function openRelist(row: CollectibleRow): void {
  relistTarget.value = row
  relistForm.releaseQuantity = row.stockPool
  relistVisible.value = true
}

async function submitRelistStep1(): Promise<void> {
  const target = relistTarget.value
  if (!target || relistForm.releaseQuantity < 1) {
    ElMessage.warning('请输入有效的上架数量')
    return
  }
  relistSubmitting.value = true
  try {
    const ok = await relistPwdRef.value?.open({ title: '重新上架确认', requireReason: false })
    if (!ok) return
    await relistCollectible(target.id, { releaseQuantity: relistForm.releaseQuantity, password: ok.password })
    ElMessage.success(`「${target.name}」已重新上架`)
    relistVisible.value = false
    load()
  } catch {
    // 拦截器已提示
  } finally {
    relistSubmitting.value = false
  }
}

// ---------------- 删除（仅草稿无关联 + password） ----------------
const deleteRef = ref<InstanceType<typeof PasswordVerify>>()
let deleteTarget: CollectibleRow | null = null

async function openDelete(row: CollectibleRow): Promise<void> {
  deleteTarget = row
  const ok = await deleteRef.value?.open({
    title: '删除藏品',
    requireReason: false,
    hint: `仅草稿且无关联可删除。「${row.name}」删除后将不再出现在管理端与 C 端。`
  })
  if (!ok || !deleteTarget) return
  try {
    await deleteCollectible(deleteTarget.id, ok.password)
    ElMessage.success(`「${deleteTarget.name}」已删除`)
    load()
  } catch {
    // 拦截器已提示
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.cell-collectible {
  display: flex;
  align-items: center;
  gap: 10px;

  .col-cover {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    flex-shrink: 0;
    background: $sn-surface;

    &--fallback {
      display: flex;
      align-items: center;
      justify-content: center;
      color: $sn-text-muted;
    }
  }

  .col-meta {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;

    .col-name {
      font-weight: 500;
      color: $sn-text-main;
      @include ellipsis;
    }

    .col-sub {
      font-size: 12px;
      color: $sn-text-muted;
      @include ellipsis;
    }
  }
}

.pool-danger {
  color: $sn-danger;
  font-weight: 600;
}

.form-hint {
  font-size: 12px;
  color: $sn-text-muted;
  margin-top: 4px;

  b {
    color: $sn-primary;
  }
}
</style>
