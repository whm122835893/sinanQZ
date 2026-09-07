<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox, ElTabs, ElTabPane } from 'element-plus'
import request from '@/utils/request'

const loading = ref(true)
const list = ref([])
const records = ref([])
const tab = ref('offers')
const STATUS_MAP = { 1: '挂单中', 2: '已接受', 3: '已拒绝', 4: '已撤销', 5: '已完成', 6: '已过期' }

onMounted(load)
async function load() {
  loading.value = true
  try {
    if (tab.value === 'offers') {
      const res = await request.get('/admin/swap')
      list.value = res.list || []
    } else {
      const res = await request.get('/admin/swap/records')
      records.value = res.list || []
    }
  } finally { loading.value = false }
}
async function doClose(row) {
  await ElMessageBox.confirm(`强制关闭该置换挂单？`, '关闭', { type: 'warning' })
  await request.post(`/admin/swap/${row.id}/close`)
  ElMessage.success('已关闭'); load()
}
async function doDelete(row) {
  await ElMessageBox.confirm(`软删除？`, '删除', { type: 'warning' })
  await request.delete(`/admin/swap/${row.id}`)
  ElMessage.success('已删除'); load()
}
</script>

<template>
  <div class="page">
    <div class="page__head"><h2>置换管理</h2><p class="page__tip">藏品 ⇄ 藏品 交换，可加差价</p></div>

    <el-tabs v-model="tab" @tab-change="load">
      <el-tab-pane label="置换挂单" name="offers">
        <el-table :data="list" v-loading="loading" stripe style="width:100%;margin-top:12px">
          <el-table-column label="发起方" width="140">
            <template #default="{ row }">{{ row.offerUserName }}</template>
          </el-table-column>
          <el-table-column label="我出" min-width="200">
            <template #default="{ row }">
              <el-image :src="row.offerCollectibleImage" fit="cover" style="width:40px;height:40px;border-radius:4px;vertical-align:middle;margin-right:6px" />
              {{ row.offerCollectibleName }}
            </template>
          </el-table-column>
          <el-table-column label="换得" min-width="200">
            <template #default="{ row }">
              <el-image :src="row.targetCollectibleImage" fit="cover" style="width:40px;height:40px;border-radius:4px;vertical-align:middle;margin-right:6px" />
              {{ row.targetCollectibleName }}
            </template>
          </el-table-column>
          <el-table-column label="差价" width="100">
            <template #default="{ row }">
              <span v-if="row.cashDiff > 0" style="color:#22c55e">+¥{{ row.cashDiff }}</span>
              <span v-else-if="row.cashDiff < 0" style="color:#f56c6c">需补¥{{ Math.abs(row.cashDiff) }}</span>
              <span v-else>—</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template #default="{ row }"><el-tag size="small">{{ STATUS_MAP[row.status] }}</el-tag></template>
          </el-table-column>
          <el-table-column label="操作" width="140">
            <template #default="{ row }">
              <el-button size="small" link type="warning" :disabled="row.status!==1" @click="doClose(row)">关闭</el-button>
              <el-button size="small" link type="danger" @click="doDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
      <el-tab-pane label="完成流水" name="records">
        <el-table :data="records" v-loading="loading" stripe style="width:100%;margin-top:12px">
          <el-table-column prop="id" label="流水ID" width="80" />
          <el-table-column prop="offerId" label="原挂单" width="80" />
          <el-table-column prop="offerUserId" label="发起方" width="120" />
          <el-table-column prop="acceptUserId" label="接受方" width="120" />
          <el-table-column label="差价" width="100"><template #default="{ row }">¥{{ row.cashDiff }}</template></el-table-column>
          <el-table-column prop="createdAt" label="完成时间" width="180" />
        </el-table>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>
<style scoped>
.page { padding: 16px; }
.page__head { margin-bottom: 12px; }
.page__head h2 { margin: 0 0 4px; font-size: 18px; }
.page__tip { margin: 0; font-size: 13px; color: #999; }
</style>
