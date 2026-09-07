<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import request from '@/utils/request'

const loading = ref(true)
const list = ref([])
const statusFilter = ref(null)
const STATUS_MAP = { 1: '求购中', 2: '已接单', 3: '已取消', 4: '已成交', 5: '已过期' }

onMounted(load)
async function load() {
  loading.value = true
  try {
    const res = await request.get('/admin/buy-request', { params: { status: statusFilter.value } })
    list.value = res.list || []
  } finally { loading.value = false }
}
async function doClose(row) {
  await ElMessageBox.confirm(`关闭该求购挂单？`, '关闭', { type: 'warning' })
  await request.post(`/admin/buy-request/${row.id}/close`)
  ElMessage.success('已关闭'); load()
}
async function doDelete(row) {
  await ElMessageBox.confirm(`软删除该求购挂单？`, '删除', { type: 'warning' })
  await request.delete(`/admin/buy-request/${row.id}`)
  ElMessage.success('已删除'); load()
}
</script>

<template>
  <div class="page">
    <div class="page__head"><h2>求购挂单</h2><p class="page__tip">用户主动挂价求购，卖家可接单</p></div>
    <div class="filter">
      <el-select v-model="statusFilter" placeholder="状态" clearable style="width:140px" @change="load">
        <el-option v-for="(v, k) in STATUS_MAP" :key="k" :value="Number(k)" :label="v" />
      </el-select>
      <el-button @click="statusFilter=null;load()">重置</el-button>
    </div>
    <el-table :data="list" v-loading="loading" stripe style="width:100%">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="求购方" min-width="140">
        <template #default="{ row }">
          <div>{{ row.nickname }}</div>
          <div style="color:#999;font-size:12px">{{ row.userPhone }}</div>
        </template>
      </el-table-column>
      <el-table-column label="目标藏品" min-width="180">
        <template #default="{ row }">
          <el-image :src="row.collectibleImage" fit="cover" style="width:40px;height:40px;border-radius:4px;vertical-align:middle;margin-right:6px" />
          {{ row.collectibleName }}
        </template>
      </el-table-column>
      <el-table-column prop="price" label="单价" width="100"><template #default="{ row }">¥{{ row.price }}</template></el-table-column>
      <el-table-column prop="quantity" label="数量" width="80" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status===1?'primary':row.status===4?'success':'info'">{{ STATUS_MAP[row.status] }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="createdAt" label="发布时间" width="180" />
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <el-button size="small" link type="warning" :disabled="row.status!==1" @click="doClose(row)">关闭</el-button>
          <el-button size="small" link type="danger" @click="doDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>
  </div>
</template>
<style scoped>
.page { padding: 16px; }
.page__head { margin-bottom: 12px; }
.page__head h2 { margin: 0 0 4px; font-size: 18px; }
.page__tip { margin: 0; font-size: 13px; color: #999; }
.filter { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; }
</style>
