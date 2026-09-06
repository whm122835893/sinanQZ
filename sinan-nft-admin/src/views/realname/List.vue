<script setup lang="ts">
/**
 * 实名认证 - 实名用户列表
 *
 * - 顶部统计卡：注册总数 / 已实名 / 实名率 / 今日实名（fetchRealnameStats）
 * - 列表默认脱敏摘要（后端仅返回「已实名」占位与脱敏手机号）
 * - 持 realname:full 权限可查看完整信息（后端每次查看均写审计日志）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useListPage } from '@/utils/useListPage'
import { fetchRealnameUsers, fetchRealnameDetail, fetchRealnameStats } from '@/api/user'
import { maskPhone, maskIdCard, datetime } from '@/utils/format'

// ===== 实名统计卡 =====
const stats = ref<any>({})
const statsLoading = ref(false)

async function loadStats() {
  statsLoading.value = true
  try {
    stats.value = (await fetchRealnameStats()) || {}
  } catch {
    /* 错误已全局提示 */
  } finally {
    statsLoading.value = false
  }
}

const statCards = computed(() => {
  const s = stats.value || {}
  return [
    { label: '注册用户总数', value: s.total ?? 0, sub: '平台全部注册用户', icon: 'User', color: '' },
    { label: '已实名用户', value: s.realnamed ?? 0, sub: `占比 ${s.rate ?? 0}%`, icon: 'Postcard', color: 'green' },
    { label: '实名率', value: `${s.rate ?? 0}%`, sub: '已实名用户 / 注册用户', icon: 'DataAnalysis', color: 'blue' },
    { label: '今日新增实名', value: s.today ?? 0, sub: '今日完成实名认证', icon: 'Bell', color: 'orange' },
  ]
})

// ===== 列表 =====
/**
 * 列表逻辑：useListPage 返回 reactive 聚合对象，
 * 状态（list/total/loading/page/pageSize/filters）须经 pager.xxx 访问以保持响应式
 */
const pager = useListPage((params: Record<string, any>) => {
  // dateRange 拆为后端约定的 startDate / endDate
  const { dateRange, ...rest } = params
  const [startDate = '', endDate = ''] = Array.isArray(dateRange) ? dateRange : []
  return fetchRealnameUsers({ ...rest, startDate, endDate })
})

onMounted(() => {
  loadStats()
  pager.load()
})

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

// ===== 完整信息查看（realname:full） =====
// 已查看过完整信息的行（用户 id → 完整数据），用于回填表格脱敏列
const fullMap = reactive<Record<number, any>>({})

const fullVisible = ref(false)
const fullDetail = ref<any>(null)

/** 查看完整实名信息（需 realname:full 权限；后端每次查看均写审计日志） */
async function openFull(row: any) {
  try {
    const data = await fetchRealnameDetail(row.id)
    fullDetail.value = data
    fullMap[row.id] = data
    fullVisible.value = true
  } catch {
    /* 全局已提示（含无权限 4003） */
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 实名统计卡 -->
    <div v-loading="statsLoading" class="stat-grid">
      <div v-for="card in statCards" :key="card.label" class="stat-card">
        <div class="stat-info">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ card.value }}</div>
          <div class="stat-sub">{{ card.sub }}</div>
        </div>
        <div class="stat-icon" :class="card.color">
          <el-icon :size="22"><component :is="card.icon" /></el-icon>
        </div>
      </div>
    </div>

    <!-- 搜索栏 -->
    <el-form class="search-bar" inline @submit.prevent>
      <el-form-item label="关键词">
        <el-input
          v-model="pager.filters.keyword"
          placeholder="手机号 / UID"
          clearable
          style="width: 220px"
          @keyup.enter="pager.search"
          @clear="pager.search"
        />
      </el-form-item>
      <el-form-item label="实名时间">
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

    <!-- 实名用户表格 -->
    <div class="table-card">
      <el-table v-loading="pager.loading" :data="pager.list" row-key="id">
        <el-table-column prop="uid" label="UID" width="140" show-overflow-tooltip />
        <el-table-column label="用户名" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.username || '-' }}</template>
        </el-table-column>
        <el-table-column label="手机号" width="150">
          <template #default="{ row }">{{ maskPhone(row.phone) }}</template>
        </el-table-column>
        <el-table-column label="真实姓名" min-width="130">
          <template #default="{ row }">
            {{ fullMap[row.id]?.realName || row.realNameMasked || '已实名' }}
          </template>
        </el-table-column>
        <el-table-column label="身份证号" width="200">
          <template #default="{ row }">
            {{ fullMap[row.id] ? maskIdCard(fullMap[row.id].idCard) : '-' }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="Number(row.isRealname) === 1 ? 'success' : 'info'" disable-transitions>
              {{ Number(row.isRealname) === 1 ? '已实名' : '未实名' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="实名时间" width="180">
          <template #default="{ row }">{{ datetime(row.realnameTime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right" align="center">
          <template #default="{ row }">
            <el-button
              v-permission="'realname:full'"
              link
              type="primary"
              size="small"
              @click.stop="openFull(row)"
            >
              查看完整信息
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

    <!-- 完整实名信息弹窗（每次查看后端均写审计日志） -->
    <el-dialog v-model="fullVisible" title="完整实名信息" width="520px">
      <div class="full-tip">本次查看已记录审计日志，请勿泄露用户隐私信息</div>
      <el-descriptions v-if="fullDetail" :column="1" border>
        <el-descriptions-item label="UID">{{ fullDetail.uid }}</el-descriptions-item>
        <el-descriptions-item label="用户名">{{ fullDetail.username || '-' }}</el-descriptions-item>
        <el-descriptions-item label="手机号">{{ fullDetail.phone || '-' }}</el-descriptions-item>
        <el-descriptions-item label="真实姓名">{{ fullDetail.realName || '-' }}</el-descriptions-item>
        <el-descriptions-item label="身份证号">{{ fullDetail.idCard || '-' }}</el-descriptions-item>
        <el-descriptions-item label="实名时间">{{ datetime(fullDetail.realnameTime) }}</el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button type="primary" @click="fullVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.full-tip {
  font-size: 12px;
  color: var(--sn-text-secondary);
  margin-bottom: 12px;
}
</style>
