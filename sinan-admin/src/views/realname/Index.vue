<script setup lang="ts">
// ============================================================================
// 实名认证管理（文档 8.5，#29~#32，只读模块）
// - 列表/详情默认脱敏（姓名「张*」、身份证前3后1）
// - 完整查看：密码二次验证 + 审计日志（文档 5.6 / 11.1）
// - 无任何修改/删除入口（模块只读红线）
// ============================================================================
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import PasswordVerify from '@/components/PasswordVerify.vue'
import {
  fetchRealnames,
  fetchRealnameDetail,
  fetchRealnameFull,
  fetchRealnameAuditLogs
} from '@/api/realname'
import type { PageData, RealnameAuditLog, RealnameDetail, RealnameRow } from '@/types/api'

// ---------------- 检索 ----------------
const query = reactive({ name: '', phone: '' })
const loading = ref(false)
const list = ref<RealnameRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

async function load(): Promise<void> {
  loading.value = true
  try {
    const params: Record<string, unknown> = { page: page.value, pageSize: pageSize.value }
    if (query.name.trim()) params.name = query.name.trim()
    if (query.phone.trim()) params.phone = query.phone.trim()
    const data = await fetchRealnames(params as { name?: string; phone?: string })
    const pageData = data as PageData<RealnameRow>
    list.value = pageData.list
    total.value = pageData.total
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

function handleSearch(): void {
  page.value = 1
  load()
}

function resetSearch(): void {
  query.name = ''
  query.phone = ''
  handleSearch()
}

// ---------------- 详情（脱敏） ----------------
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<RealnameDetail | null>(null)
/** 完整信息（本次会话内展示，关闭即清空） */
const fullInfo = ref<{ realName: string; idCard: string } | null>(null)

async function openDetail(row: RealnameRow): Promise<void> {
  detailVisible.value = true
  detailLoading.value = true
  fullInfo.value = null
  auditLogs.value = []
  try {
    detail.value = await fetchRealnameDetail(row.id)
  } catch {
    // 拦截器已提示
  } finally {
    detailLoading.value = false
  }
}

// ---------------- 完整查看（密码二次验证，#31） ----------------
const pwdRef = ref<InstanceType<typeof PasswordVerify>>()
const fullLoading = ref(false)

async function viewFull(): Promise<void> {
  const target = detail.value
  if (!target) return
  const ok = await pwdRef.value?.open({
    title: '查看完整实名信息',
    hint: '查看该用户的完整姓名与身份证号属高风险操作，本次查看将写入审计日志',
    requireReason: false
  })
  if (!ok) return

  fullLoading.value = true
  try {
    const full = await fetchRealnameFull(target.id, ok.password)
    fullInfo.value = { realName: full.realName, idCard: full.idCard }
    ElMessage.warning('已展示明文信息，请勿截图外传；本次查看已写入审计日志')
    loadAuditLogs()
  } catch {
    // 拦截器已提示（密码错误 403 / 无权限 401）
  } finally {
    fullLoading.value = false
  }
}

// ---------------- 查看审计日志（#32） ----------------
const auditLogs = ref<RealnameAuditLog[]>([])
const auditTotal = ref(0)
const auditPage = ref(1)
const auditLoading = ref(false)

async function loadAuditLogs(): Promise<void> {
  const target = detail.value
  if (!target) return
  auditLoading.value = true
  try {
    const data = await fetchRealnameAuditLogs(target.id, {
      page: auditPage.value,
      pageSize: 10
    })
    auditLogs.value = data.list
    auditTotal.value = data.total
  } catch {
    // 拦截器已提示
  } finally {
    auditLoading.value = false
  }
}

function onAuditPageChange(p: number): void {
  auditPage.value = p
  loadAuditLogs()
}

function onDetailClosed(): void {
  detail.value = null
  fullInfo.value = null
  auditLogs.value = []
  auditTotal.value = 0
  auditPage.value = 1
}

onMounted(load)
</script>

<template>
  <div class="page-container">
    <!-- 只读警示 -->
    <el-alert
      type="info"
      :closable="false"
      show-icon
      title="实名认证模块为只读：不提供修改与删除；完整查看需密码二次验证并写入审计日志"
      style="margin-bottom: 12px"
    />

    <!-- 检索区 -->
    <div class="sn-card">
      <el-form :model="query" inline class="query-form" @submit.prevent="handleSearch">
        <el-form-item label="姓名">
          <el-input
            v-model="query.name"
            placeholder="脱敏前匹配（后端解密比对）"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input
            v-model="query.phone"
            placeholder="模糊匹配"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :icon="'Search'" @click="handleSearch">查询</el-button>
          <el-button :icon="'RefreshLeft'" @click="resetSearch">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 列表区 -->
    <div class="sn-card">
      <div class="table-toolbar">
        <span class="toolbar-title">实名认证记录</span>
        <el-button :icon="'Refresh'" :loading="loading" @click="load">刷新</el-button>
      </div>

      <el-table v-loading="loading" :data="list" stripe>
        <el-table-column prop="uid" label="UID" width="170" show-overflow-tooltip />
        <el-table-column label="用户" min-width="150">
          <template #default="{ row }">
            <div class="user-cell">
              <el-avatar :size="28" :src="row.avatar || undefined">
                {{ row.username.slice(0, 1).toUpperCase() }}
              </el-avatar>
              <span>{{ row.username }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="phone" label="手机号（脱敏）" width="130" />
        <el-table-column prop="realName" label="姓名（脱敏）" width="110" />
        <el-table-column prop="idCard" label="身份证（脱敏）" min-width="190" show-overflow-tooltip />
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag type="success" size="small" effect="light">{{ row.status || '已认证' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="createdAt" label="注册时间" width="165" />
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="table-footer">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="load"
          @size-change="handleSearch"
        />
      </div>
    </div>

    <!-- 详情抽屉 -->
    <el-drawer
      v-model="detailVisible"
      :title="`实名详情 · ${detail?.username ?? ''}`"
      size="560px"
      @closed="onDetailClosed"
    >
      <div v-loading="detailLoading" class="rn-detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="UID">{{ detail?.uid ?? '—' }}</el-descriptions-item>
          <el-descriptions-item label="用户名">{{ detail?.username ?? '—' }}</el-descriptions-item>
          <el-descriptions-item label="手机号（脱敏）">{{ detail?.phone ?? '—' }}</el-descriptions-item>
          <el-descriptions-item label="账号状态">{{ detail?.accountStatus ?? '—' }}</el-descriptions-item>
          <el-descriptions-item label="姓名（脱敏）">{{ detail?.realName ?? '—' }}</el-descriptions-item>
          <el-descriptions-item label="身份证（脱敏）">{{ detail?.idCard ?? '—' }}</el-descriptions-item>
          <el-descriptions-item label="实名时间" :span="2">
            {{ detail?.realnamedAt ?? '—' }}
          </el-descriptions-item>
        </el-descriptions>

        <!-- 完整查看结果 -->
        <template v-if="fullInfo">
          <div class="full-box">
            <div class="full-box-title">
              <el-icon color="#C00000"><WarningFilled /></el-icon>
              完整信息（明文，本次查看已写审计）
            </div>
            <el-descriptions :column="1" border class="full-desc">
              <el-descriptions-item label="姓名（明文）">{{ fullInfo.realName }}</el-descriptions-item>
              <el-descriptions-item label="身份证号（明文）">{{ fullInfo.idCard }}</el-descriptions-item>
            </el-descriptions>
          </div>
        </template>

        <div class="rn-actions">
          <el-button
            v-permission="'realname:full'"
            type="primary"
            :loading="fullLoading"
            @click="viewFull"
          >
            查看完整信息（需密码）
          </el-button>
        </div>

        <!-- 审计日志 -->
        <div class="audit-section">
          <div class="audit-title">查看审计日志</div>
          <el-table v-loading="auditLoading" :data="auditLogs" size="small">
            <el-table-column prop="adminName" label="管理员" width="110" show-overflow-tooltip />
            <el-table-column prop="ip" label="IP" width="120" />
            <el-table-column prop="createdAt" label="查看时间" width="160" />
            <el-table-column prop="targetDesc" label="目标" min-width="140" show-overflow-tooltip>
              <template #default="{ row }">{{ row.targetDesc || '—' }}</template>
            </el-table-column>
          </el-table>
          <el-pagination
            v-if="auditTotal > 10"
            layout="total, prev, pager, next"
            :total="auditTotal"
            :page-size="10"
            :current-page="auditPage"
            style="margin-top: 10px; justify-content: flex-end"
            @current-change="onAuditPageChange"
          />
        </div>
      </div>
    </el-drawer>

    <!-- 密码二次验证 -->
    <PasswordVerify ref="pwdRef" />
  </div>
</template>

<style scoped lang="scss">
.query-form {
  :deep(.el-form-item) {
    margin-bottom: 4px;
    margin-right: 16px;
  }
}

.table-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;

  .toolbar-title {
    font-size: 15px;
    font-weight: 600;
  }
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 8px;

  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.table-footer {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.rn-detail {
  .full-box {
    margin-top: 16px;
    border: 1px solid #ffd6d6;
    border-radius: 8px;
    background: #fff9f9;
    padding: 12px;

    .full-box-title {
      display: flex;
      align-items: center;
      gap: 6px;
      color: $sn-primary;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 10px;
    }
  }
}

.rn-actions {
  margin-top: 16px;
  display: flex;
  justify-content: center;
}

.audit-section {
  margin-top: 24px;

  .audit-title {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 10px;
    padding-left: 10px;
    border-left: 3px solid $sn-primary;
    line-height: 1.2;
  }
}
</style>
