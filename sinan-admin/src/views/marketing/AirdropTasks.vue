<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getAirdropTasks, getAirdropTaskRecords } from '@/api'

// ---------- 任务列表 ----------
const loading = ref(false)
const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

const filters = reactive({
  targetType: '',       // '' 全部 / 1 藏品 / 2 盲盒
  keyword: '',          // taskNo 或 targetName
})

onMounted(() => load())

async function load() {
  loading.value = true
  try {
    const params = { page: page.value, pageSize: pageSize.value }
    if (filters.targetType !== '') params.target_type = filters.targetType
    if (filters.keyword.trim()) params.keyword = filters.keyword.trim()
    const res = await getAirdropTasks(params)
    list.value = res.list || []
    total.value = res.total || 0
  } catch (e) {
    ElMessage.error('加载失败')
  } finally {
    loading.value = false
  }
}

function onSearch() {
  page.value = 1
  load()
}
function onReset() {
  filters.targetType = ''
  filters.keyword = ''
  onSearch()
}

// ---------- 明细抽屉 ----------
const drawerVisible = ref(false)
const drawerTitle = ref('')
const records = ref([])
const recordsTotal = ref(0)
const recordsPage = ref(1)
const recordsPageSize = ref(20)
const recordsLoading = ref(false)
const currentTaskId = ref(null)

function openDetail(row) {
  drawerTitle.value = `${row.taskNo} · ${row.targetName}（${row.targetTypeLabel}）`
  currentTaskId.value = row.id
  recordsPage.value = 1
  drawerVisible.value = true
  loadRecords()
}

async function loadRecords() {
  if (!currentTaskId.value) return
  recordsLoading.value = true
  try {
    const res = await getAirdropTaskRecords(currentTaskId.value, {
      page: recordsPage.value,
      pageSize: recordsPageSize.value
    })
    records.value = res.list || []
    recordsTotal.value = res.total || 0
  } catch (e) {
    ElMessage.error('加载明细失败')
  } finally {
    recordsLoading.value = false
  }
}

function statusTag(s) {
  const map = { issued: 'success', failed: 'danger', pending: 'warning' }
  return map[s] || 'info'
}
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <div class="toolbar">
        <div class="toolbar__filters">
          <el-select v-model="filters.targetType" placeholder="全部类型" clearable style="width: 140px" @change="onSearch">
            <el-option label="藏品" :value="1" />
            <el-option label="盲盒" :value="2" />
          </el-select>
          <el-input
            v-model="filters.keyword"
            placeholder="任务编号 / 藏品名"
            clearable
            style="width: 260px"
            @keyup.enter="onSearch"
          />
          <el-button type="primary" @click="onSearch">搜索</el-button>
          <el-button @click="onReset">重置</el-button>
        </div>
      </div>

      <el-table :data="list" v-loading="loading" stripe style="width: 100%">
        <el-table-column prop="taskNo" label="任务编号" width="200" />
        <el-table-column label="类型" width="80">
          <template #default="{ row }">
            <el-tag :type="row.targetType === 1 ? 'primary' : 'success'">{{ row.targetTypeLabel }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="targetName" label="空投对象" min-width="160" />
        <el-table-column label="用户数" width="90" align="right">
          <template #default="{ row }">{{ row.userCount }} 人</template>
        </el-table-column>
        <el-table-column label="数量" width="90" align="right">
          <template #default="{ row }">{{ row.totalQuantity }}</template>
        </el-table-column>
        <el-table-column label="成功" width="80" align="right">
          <template #default="{ row }">
            <span :style="{ color: row.successCount === row.totalQuantity ? '#67c23a' : '' }">{{ row.successCount }}</span>
          </template>
        </el-table-column>
        <el-table-column label="失败" width="80" align="right">
          <template #default="{ row }">
            <span v-if="row.failCount > 0" style="color:#f56c6c">{{ row.failCount }}</span>
            <span v-else style="color:#999">0</span>
          </template>
        </el-table-column>
        <el-table-column prop="adminName" label="发起人" width="110" />
        <el-table-column prop="createdAt" label="时间" width="170" />
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">明细</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="load"
          @current-change="load"
        />
      </div>
    </el-card>

    <!-- 发放明细抽屉 -->
    <el-drawer v-model="drawerVisible" :title="drawerTitle" size="720px">
      <el-table :data="records" v-loading="recordsLoading" stripe>
        <el-table-column label="用户" min-width="180">
          <template #default="{ row }">
            <div>{{ row.username || '-' }} <span style="color:#999;font-size:12px">{{ row.uid }}</span></div>
            <div style="color:#999;font-size:12px">{{ row.phone }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="collectibleName" label="藏品" min-width="140" />
        <el-table-column label="数量" width="80" align="right">
          <template #default="{ row }">×{{ row.quantity }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)">{{ row.status === 'issued' ? '已发放' : row.status === 'failed' ? '失败' : '待发放' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="issuedAt" label="发放时间" width="170" />
      </el-table>
      <div class="pagination" style="margin-top:16px">
        <el-pagination
          v-model:current-page="recordsPage"
          v-model:page-size="recordsPageSize"
          :page-sizes="[10, 20, 50]"
          :total="recordsTotal"
          layout="total, sizes, prev, pager, next"
          @size-change="loadRecords"
          @current-change="loadRecords"
        />
      </div>
    </el-drawer>
  </div>
</template>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.toolbar__filters { display: flex; gap: 10px; }
.pagination { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
