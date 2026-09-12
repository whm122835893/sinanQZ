<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import request from '@/utils/request'

const route = useRoute()
const router = useRouter()

// ---------- 活动选择 ----------
const activities = ref([])
const activityId = ref(Number(route.query.activityId) || null)

// ---------- 列表 ----------
const loading = ref(false)
const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const keyword = ref('')

onMounted(async () => {
  const res = await request.get('/raffle', { params: { page: 1, pageSize: 200 } })
  activities.value = res.list || []
  load()
})

function onSearch() {
  page.value = 1
  load()
}

async function load() {
  loading.value = true
  try {
    const params = { page: page.value, pageSize: pageSize.value }
    if (activityId.value) params.activityId = activityId.value
    if (keyword.value.trim()) params.keyword = keyword.value.trim()
    const res = await request.get('/raffle/logs', { params })
    list.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}

function actionTag(action) {
  const map = {
    save: 'primary',
    change_quota: 'warning',
    change_buy_limit: 'warning',
    change_total_supply: 'warning',
    change_invite: 'warning',
    set_force_win: 'danger',
    cancel_force_win: 'danger',
    draw: 'success',
    start: 'success',
    pause: 'info',
    cancel: 'danger',
    code_create: 'primary',
    code_import: 'primary',
    code_invalidate: 'warning',
    code_delete: 'danger',
    mark_paid: 'success',
    verify: 'success',
  }
  return map[action] || 'info'
}
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <div class="toolbar">
        <el-button link @click="router.push('/marketing/raffle')">← 返回活动列表</el-button>
        <div class="toolbar__filters">
          <el-select v-model="activityId" placeholder="全部活动" clearable style="width: 220px" @change="onSearch">
            <el-option v-for="a in activities" :key="a.id" :label="`#${a.id} ${a.name}`" :value="a.id" />
          </el-select>
          <el-input
            v-model="keyword"
            placeholder="管理员 / 操作内容"
            clearable
            style="width: 220px"
            @keyup.enter="onSearch"
            @clear="onSearch"
          />
          <el-button type="primary" @click="onSearch">查询</el-button>
        </div>
      </div>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        style="margin-bottom: 12px"
        title="抽签操作日志为只读审计记录（名额/上限/必中/开奖/码管理等关键操作），不可删除"
      />

      <el-table v-loading="loading" :data="list" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="操作" width="130">
          <template #default="{ row }">
            <el-tag :type="actionTag(row.action)" size="small">{{ row.action }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="actionDesc" label="操作内容" min-width="260" show-overflow-tooltip />
        <el-table-column label="关联活动" width="110">
          <template #default="{ row }">
            <span v-if="row.activityId">#{{ row.activityId }}</span>
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="adminName" label="操作管理员" width="120" />
        <el-table-column prop="ip" label="IP" width="130">
          <template #default="{ row }">
            <span class="mono">{{ row.ip || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="createdAt" label="时间" width="170" />
        <el-table-column label="明细" width="80">
          <template #default="{ row }">
            <el-popover v-if="row.detail" placement="left" :width="420" trigger="click">
              <pre class="detail-json">{{ typeof row.detail === 'string' ? row.detail : JSON.stringify(row.detail, null, 2) }}</pre>
              <template #reference>
                <el-button link type="primary">查看</el-button>
              </template>
            </el-popover>
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="page"
        :page-size="pageSize"
        :total="total"
        layout="total, prev, pager, next"
        style="margin-top: 14px; justify-content: flex-end"
        @current-change="load"
      />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
  margin-bottom: 12px;
}
.toolbar__filters { display: flex; gap: 10px; flex-wrap: wrap; }
.muted { color: #999; }
.mono { font-family: ui-monospace, monospace; font-size: 12px; }
.detail-json {
  margin: 0; max-height: 320px; overflow: auto;
  font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-all;
}
</style>
