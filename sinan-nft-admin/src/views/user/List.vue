<script setup lang="ts">
/**
 * 用户管理 - 用户列表
 *
 * - 搜索：关键词（手机号/UID/用户名）、账号状态、注册时间区间
 * - 行点击进入用户详情 /user/:id
 * - 操作（均需填写原因，后端写审计日志）：冻结/解冻、重置交易密码、强制登出、拉黑/移出黑名单
 */
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { User } from '@element-plus/icons-vue'
import { useListPage } from '@/utils/useListPage'
import {
  fetchUsers,
  freezeUser,
  unfreezeUser,
  resetUserTransactionPassword,
  forceLogout,
  blacklistUser,
  removeBlacklist,
} from '@/api/user'
import { USER_STATUS, maskPhone, datetime } from '@/utils/format'

const router = useRouter()

/**
 * 列表逻辑：useListPage 返回 reactive 聚合对象，
 * 状态（list/total/loading/page/pageSize/filters）须经 pager.xxx 访问以保持响应式
 */
const pager = useListPage((params: Record<string, any>) => {
  // dateRange 拆为后端约定的 startDate / endDate（后端自动将结束日期补全到当天 23:59:59）
  const { dateRange, ...rest } = params
  const [startDate = '', endDate = ''] = Array.isArray(dateRange) ? dateRange : []
  return fetchUsers({ ...rest, startDate, endDate })
})

const statusOptions = [
  { label: '正常', value: 1 },
  { label: '已冻结', value: 0 },
]

onMounted(() => pager.load())

function displayName(row: any): string {
  return String(row.username || row.uid || row.phone || `用户#${row.id}`)
}

/** 行点击跳转用户详情 */
function goDetail(row: any) {
  router.push(`/user/${row.id}`)
}

/** 弹窗填写操作原因（点取消时 Promise 拒绝，由调用方吞掉） */
function askReason(message: string, title: string): Promise<string> {
  return ElMessageBox.prompt(message, title, {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请输入操作原因（将记入操作日志）',
    inputValidator: (v: string) => (v && v.trim() ? true : '请填写操作原因'),
    type: 'warning',
  }).then(({ value }) => (value ?? '').trim())
}

/** 冻结 / 解冻（status：1 正常 → 冻结；0 冻结 → 解冻） */
async function onToggleFreeze(row: any) {
  const freezing = Number(row.status) === 1
  let reason: string
  try {
    reason = await askReason(
      freezing
        ? `确认冻结用户「${displayName(row)}」？冻结后该用户将被强制下线`
        : `确认解冻用户「${displayName(row)}」？解冻后该用户可正常登录`,
      freezing ? '冻结用户' : '解冻用户',
    )
  } catch {
    return
  }
  try {
    if (freezing) {
      await freezeUser(row.id, reason)
      pager.done('用户已冻结并强制下线')
    } else {
      await unfreezeUser(row.id, reason)
      pager.done('用户已解冻')
    }
  } catch {
    /* 业务错误已全局提示 */
  }
}

/** 重置交易密码 */
async function onResetPassword(row: any) {
  let reason: string
  try {
    reason = await askReason(
      `确认重置用户「${displayName(row)}」的交易密码？重置后用户需在 APP 重新设置`,
      '重置交易密码',
    )
  } catch {
    return
  }
  try {
    await resetUserTransactionPassword(row.id, reason)
    pager.done('交易密码已重置，用户需在 APP 重新设置')
  } catch {
    /* 业务错误已全局提示 */
  }
}

/** 强制登出 */
async function onForceLogout(row: any) {
  let reason: string
  try {
    reason = await askReason(
      `确认强制登出用户「${displayName(row)}」？该用户全部登录态将失效`,
      '强制登出',
    )
  } catch {
    return
  }
  try {
    await forceLogout(row.id, reason)
    pager.done('该用户全部登录态已失效')
  } catch {
    /* 业务错误已全局提示 */
  }
}

/** 加入黑名单 */
async function onBlacklist(row: any) {
  let reason: string
  try {
    reason = await askReason(
      `确认将用户「${displayName(row)}」加入黑名单？加入后该用户即刻被禁止访问并强制下线`,
      '加入黑名单',
    )
  } catch {
    return
  }
  try {
    await blacklistUser(row.id, reason)
    pager.done('已加入黑名单并强制下线')
  } catch {
    /* 业务错误已全局提示 */
  }
}

/** 移出黑名单（后端接口无原因参数，confirm 确认即可） */
async function onRemoveBlacklist(row: any) {
  try {
    await ElMessageBox.confirm(
      `确认将用户「${displayName(row)}」移出黑名单？`,
      '移出黑名单',
      { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await removeBlacklist(row.id)
    pager.done('已移出黑名单')
  } catch {
    /* 业务错误已全局提示 */
  }
}

/** 重置搜索（dateRange 被 reset 归一为 ''/null 时恢复为空数组，匹配 el-date-picker） */
function onReset() {
  pager.reset()
  if (!Array.isArray(pager.filters.dateRange)) pager.filters.dateRange = []
}

function onPageChange(p: number) {
  pager.page = p
  pager.load()
}

function onSizeChange(s: number) {
  pager.pageSize = s
  pager.page = 1
  pager.load()
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <el-form class="search-bar" inline @submit.prevent>
      <el-form-item label="关键词">
        <el-input
          v-model="pager.filters.keyword"
          placeholder="手机号 / UID / 用户名"
          clearable
          style="width: 220px"
          @keyup.enter="pager.search"
          @clear="pager.search"
        />
      </el-form-item>
      <el-form-item label="状态">
        <el-select
          v-model="pager.filters.status"
          placeholder="全部状态"
          clearable
          style="width: 140px"
          @change="pager.search"
        >
          <el-option v-for="opt in statusOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
        </el-select>
      </el-form-item>
      <el-form-item label="注册时间">
        <el-date-picker
          v-model="pager.filters.dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          range-separator="至"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 260px"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="pager.search">查询</el-button>
        <el-button @click="onReset">重置</el-button>
      </el-form-item>
    </el-form>

    <!-- 用户表格 -->
    <div class="table-card">
      <el-table
        v-loading="pager.loading"
        :data="pager.list"
        row-key="id"
        highlight-current-row
        class="row-clickable"
        @row-click="goDetail"
      >
        <el-table-column prop="uid" label="UID" width="130" show-overflow-tooltip />
        <el-table-column label="用户名" min-width="160">
          <template #default="{ row }">
            <div class="user-cell">
              <el-avatar :size="28" :src="row.avatar">
                <el-icon><User /></el-icon>
              </el-avatar>
              <span class="user-name">{{ row.username || '-' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="手机号" width="150">
          <template #default="{ row }">{{ maskPhone(row.phone) }}</template>
        </el-table-column>
        <el-table-column label="实名状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="Number(row.isRealname) === 1 ? 'success' : 'info'" disable-transitions>
              {{ Number(row.isRealname) === 1 ? '已实名' : '未实名' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="160" align="center">
          <template #default="{ row }">
            <el-tag :type="USER_STATUS[row.status]?.type ?? 'info'" disable-transitions>
              {{ USER_STATUS[row.status]?.text ?? '未知' }}
            </el-tag>
            <el-tag
              v-if="Number(row.isBlacklisted) === 1"
              type="danger"
              effect="plain"
              class="ml-tag"
              disable-transitions
            >
              黑名单
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最后登录时间" width="180">
          <template #default="{ row }">{{ datetime(row.lastLoginAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="330" fixed="right">
          <template #default="{ row }">
            <el-button
              v-permission="'user:freeze'"
              link
              :type="Number(row.status) === 1 ? 'danger' : 'success'"
              size="small"
              @click.stop="onToggleFreeze(row)"
            >
              {{ Number(row.status) === 1 ? '冻结' : '解冻' }}
            </el-button>
            <el-button
              v-permission="'user:manage'"
              link
              type="primary"
              size="small"
              @click.stop="onResetPassword(row)"
            >
              重置交易密码
            </el-button>
            <el-button
              v-permission="'user:manage'"
              link
              type="warning"
              size="small"
              @click.stop="onForceLogout(row)"
            >
              强制登出
            </el-button>
            <el-button
              v-if="Number(row.isBlacklisted) === 1"
              v-permission="'user:blacklist'"
              link
              type="success"
              size="small"
              @click.stop="onRemoveBlacklist(row)"
            >
              移出黑名单
            </el-button>
            <el-button
              v-else
              v-permission="'user:blacklist'"
              link
              type="danger"
              size="small"
              @click.stop="onBlacklist(row)"
            >
              拉黑
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          :current-page="pager.page"
          :page-size="pager.pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :total="pager.total"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="onPageChange"
          @size-change="onSizeChange"
        />
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
/* 行可点击：整行跳详情 */
.row-clickable :deep(.el-table__row) {
  cursor: pointer;
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;

  .user-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.ml-tag {
  margin-left: 6px;
}
</style>
