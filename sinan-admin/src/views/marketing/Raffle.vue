<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import request from '@/utils/request'

const loading = ref(true)
const list = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const keyword = ref('')
const statusFilter = ref(null)

const COLLECTIBLES = [] // 缓存，选择藏品时用

const STATUS_MAP = {
  0: { text: '草稿', cls: 'info' },
  1: { text: '报名中', cls: 'primary' },
  2: { text: '抽签中', cls: 'warning' },
  3: { text: '已结束', cls: 'success' },
  4: { text: '已取消', cls: 'danger' },
}

onMounted(load)

async function load() {
  loading.value = true
  try {
    const res = await request.get('/raffle', {
      params: { page: page.value, pageSize: pageSize.value, keyword: keyword.value, status: statusFilter.value },
    })
    list.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}

function onSearch() { page.value = 1; load() }
function onReset() { keyword.value = ''; statusFilter.value = null; onSearch() }
function onChangePage(p) { page.value = p; load() }
function goEdit(id) {
  // 直接跳到 URL，后端 API 已就绪
  const base = window.location.hash.split('#')[1] || '/'
  window.location.hash = '#/marketing/raffle/edit/' + (id || '')
}

async function doStart(row) {
  await ElMessageBox.confirm(`确认开启「${row.name}」的报名？开启后用户可以在 C 端报名抽签。`, '开启报名', { type: 'warning' })
  await request.post(`/raffle/${row.id}/start`)
  ElMessage.success('已开启报名')
  load()
}
async function doDraw(row) {
  await ElMessageBox.confirm(`确认立即对「${row.name}」执行抽签？`, '立即抽签', { type: 'warning' })
  const res = await request.post(`/raffle/${row.id}/draw`)
  ElMessage.success(`抽签完成！中签 ${res.count || 0} 人`)
  load()
}
async function doCancel(row) {
  await ElMessageBox.confirm(`确认取消「${row.name}」？取消后不可恢复。`, '取消活动', { type: 'warning' })
  await request.post(`/raffle/${row.id}/cancel`)
  ElMessage.success('已取消')
  load()
}
async function doDelete(row) {
  await ElMessageBox.confirm(`确认删除「${row.name}」？（软删除）`, '删除', { type: 'warning' })
  await request.delete(`/raffle/${row.id}`)
  ElMessage.success('已删除')
  load()
}
</script>

<template>
  <div class="page">
    <div class="page__head">
      <h2>抽签购管理</h2>
      <p class="page__tip">用户报名 → 截止后系统自动抽签 → 中签者限时购买</p>
    </div>

    <!-- 筛选 -->
    <div class="filter">
      <el-input v-model="keyword" placeholder="活动名称/藏品名称" clearable style="width: 220px" @keyup.enter="onSearch" />
      <el-select v-model="statusFilter" placeholder="状态" clearable style="width: 140px">
        <el-option v-for="(v, k) in STATUS_MAP" :key="k" :value="Number(k)" :label="v.text" />
      </el-select>
      <el-button type="primary" @click="onSearch">搜索</el-button>
      <el-button @click="onReset">重置</el-button>
      <div style="flex: 1" />
      <el-button type="success" @click="goEdit(0)">+ 新建抽签活动</el-button>
    </div>

    <!-- 列表 -->
    <el-table :data="list" v-loading="loading" stripe style="width: 100%">
      <el-table-column label="封面" width="80">
        <template #default="{ row }">
          <el-image :src="row.collectibleImage" fit="cover" style="width:56px;height:56px;border-radius:6px" />
        </template>
      </el-table-column>
      <el-table-column prop="name" label="活动名称" min-width="180" show-overflow-tooltip />
      <el-table-column prop="collectibleName" label="藏品" min-width="140" show-overflow-tooltip />
      <el-table-column label="名额" width="220">
        <template #default="{ row }">
          中签 <b>{{ row.winnerCount }}</b> 人
          <br /><span style="color:#999">每人限购 {{ row.saleQuantity }}</span>
        </template>
      </el-table-column>
      <el-table-column label="价格" width="100">
        <template #default="{ row }">¥{{ row.salePrice }}</template>
      </el-table-column>
      <el-table-column label="时间" min-width="220">
        <template #default="{ row }">
          <div style="font-size:12px;color:#999">报名: {{ row.registrationStart?.substring(0, 16) }}</div>
          <div style="font-size:12px;color:#999">至 {{ row.registrationEnd?.substring(0, 16) }}</div>
          <div style="font-size:12px;color:#999">抽签: {{ row.drawTime?.substring(0, 16) }}</div>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="STATUS_MAP[row.status]?.cls || 'info'">{{ STATUS_MAP[row.status]?.text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="数据" width="140">
        <template #default="{ row }">
          已报名 <b>{{ row.registrationCount || 0 }}</b>
          <br /><span style="color:#22c55e">已中 {{ row.winnerCountDone || 0 }}</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="260" fixed="right">
        <template #default="{ row }">
          <el-button size="small" link type="primary" @click="goEdit(row.id)">编辑</el-button>
          <el-button v-if="row.status === 0" size="small" link type="success" @click="doStart(row)">开启报名</el-button>
          <el-button v-if="row.status === 1" size="small" link type="warning" @click="doDraw(row)">立即抽签</el-button>
          <el-button v-if="row.status === 1 || row.status === 2" size="small" link type="danger" @click="doCancel(row)">取消</el-button>
          <el-button size="small" link type="danger" @click="doDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div class="page__foot">
      <el-pagination background layout="total, prev, pager, next, sizes" :total="total" :page-sizes="[10, 20, 50]" v-model:current-page="page" v-model:page-size="pageSize" @current-change="onChangePage" />
    </div>
  </div>
</template>

<style scoped>
.page { padding: 16px; }
.page__head { margin-bottom: 16px; }
.page__head h2 { margin: 0 0 4px; font-size: 18px; }
.page__tip { margin: 0; font-size: 13px; color: #999; }
.filter { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; flex-wrap: wrap; }
.page__foot { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
