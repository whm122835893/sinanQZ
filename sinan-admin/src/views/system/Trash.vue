<script setup>
import { ref, onMounted, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import request from '@/utils/request'

const TABLES = [
  { key: 'collectibles', label: '藏品' },
  { key: 'orders', label: '订单' },
  { key: 'users', label: '用户' },
  { key: 'banners', label: '轮播图' },
  { key: 'announcements', label: '公告' },
]

const tab = ref('collectibles')
const loading = ref(true)
const list = ref([])
const total = ref(0)

onMounted(load)
watch(tab, load)

async function load() {
  loading.value = true
  try {
    const res = await request.get('/admin/trash/' + tab.value)
    list.value = res.list || []
    total.value = res.total || 0
  } finally { loading.value = false }
}

async function doRecover(row) {
  await ElMessageBox.confirm(`恢复该${TABLES.find(t=>t.key===tab.value)?.label}？`, '恢复', { type: 'info' })
  await request.post(`/admin/trash/${tab.value}/${row.id}/recover`)
  ElMessage.success('已恢复'); load()
}
async function doPurge(row) {
  await ElMessageBox.confirm(`物理删除该${TABLES.find(t=>t.key===tab.value)?.label}？此操作不可恢复！`, '物理删除', { type: 'error' })
  await request.delete(`/admin/trash/${tab.value}/${row.id}/purge`)
  ElMessage.success('已物理删除'); load()
}
async function doPurgeAll() {
  await ElMessageBox.confirm(`确认清空「${TABLES.find(t=>t.key===tab.value)?.label}」回收站？全部物理删除！`, '清空回收站', { type: 'error' })
  await request.post(`/admin/trash/${tab.value}/purge-all`)
  ElMessage.success('已清空'); load()
}
</script>

<template>
  <div class="page">
    <div class="page__head">
      <h2>回收站</h2>
      <p class="page__tip">所有软删除的业务数据，可一键恢复或物理删除</p>
    </div>

    <el-tabs v-model="tab" class="trash-tabs">
      <el-tab-pane v-for="t in TABLES" :key="t.key" :label="`${t.label}回收站`" :name="t.key" />
    </el-tabs>

    <div class="trash-toolbar">
      <span style="color:#999;font-size:13px">共 {{ total }} 条已软删除数据</span>
      <div style="flex:1" />
      <el-button type="danger" plain size="small" :disabled="!total" @click="doPurgeAll">🗑 清空当前回收站</el-button>
    </div>

    <el-table :data="list" v-loading="loading" stripe style="width:100%">
      <el-table-column label="记录" min-width="300">
        <template #default="{ row }">
          <template v-if="tab==='collectibles'">
            <b>{{ row.name }}</b> <span style="color:#999;font-size:12px">¥{{ row.price }} / {{ row.edition }}份</span>
          </template>
          <template v-else-if="tab==='orders'">
            <b>{{ row.orderNo }}</b> <span style="color:#999;font-size:12px">¥{{ row.totalPrice }} / {{ row.quantity }}件</span>
          </template>
          <template v-else-if="tab==='users'">
            <b>{{ row.nickname || row.uid }}</b> <span style="color:#999;font-size:12px">/{{ row.phone }}</span>
          </template>
          <template v-else-if="tab==='banners'">
            <b>{{ row.title }}</b> <span style="color:#999;font-size:12px">/{{ row.position }}</span>
          </template>
          <template v-else-if="tab==='announcements'">
            <b>{{ row.title }}</b> <span style="color:#999;font-size:12px">/{{ row.type }}</span>
          </template>
          <template v-else>{{ row.id }}</template>
        </template>
      </el-table-column>
      <el-table-column label="原状态" width="100">
        <template #default="{ row }">
          <el-tag size="small">状态 {{ row.status }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="删除时间" width="180">
        <template #default="{ row }">
          <span style="color:#f56c6c">{{ row.deletedAt }}</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="200" fixed="right">
        <template #default="{ row }">
          <el-button size="small" link type="success" @click="doRecover(row)">↩ 恢复</el-button>
          <el-button size="small" link type="danger" @click="doPurge(row)">💥 物理删除</el-button>
        </template>
      </el-table-column>
    </el-table>
  </div>
</template>
<style scoped>
.page { padding: 16px; }
.page__head { margin-bottom: 8px; }
.page__head h2 { margin: 0 0 4px; font-size: 18px; }
.page__tip { margin: 0; font-size: 13px; color: #999; }
.trash-toolbar { display:flex; align-items:center; gap:8px; padding:8px 0; }
</style>
