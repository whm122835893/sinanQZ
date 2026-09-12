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

const STATUS_MAP = {
  0: { text: '草稿(停用)', cls: 'info' },
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
  window.location.hash = '#/marketing/raffle/edit/' + (id || '')
}
function goRegistrations(row) {
  window.location.hash = `#/marketing/raffle/registrations?activityId=${row.id}`
}
function goCodes(row) {
  window.location.hash = `#/marketing/raffle/codes?activityId=${row.id}`
}

// 单用户码总量 = 基础 1 + 邀请上限（开时）+ 购买上限（开时）
function codeLimitText(row) {
  const invite = row.inviteEnabled ? (row.inviteCodeLimit || 0) : 0
  const buy = row.drawCodeEnabled ? (row.buyCodeLimit || 0) : 0
  const total = 1 + invite + buy
  const parts = ['基础1']
  if (invite) parts.push(`邀${invite}`)
  if (buy) parts.push(`购${buy}`)
  return `${total}（${parts.join('+')}）`
}

async function doStart(row) {
  await ElMessageBox.confirm(`确认开启「${row.name}」的报名？开启后用户可以在 C 端报名抽签。`, '开启报名', { type: 'warning' })
  await request.post(`/raffle/${row.id}/start`)
  ElMessage.success('已开启报名')
  load()
}
async function doPause(row) {
  await ElMessageBox.confirm(`确认停用「${row.name}」？活动将回到草稿状态，暂停报名。`, '停用活动', { type: 'warning' })
  await request.post(`/raffle/${row.id}/pause`)
  ElMessage.success('已停用')
  load()
}
async function doDraw(row) {
  const forced = row.forceWinCount || 0
  await ElMessageBox.confirm(
    `确认对「${row.name}」执行开奖？\n${forced} 名强制必中用户直接各得 1 签，剩余名额（签）在全部抽签码中随机抽取：每个码最多中 1 次，同一用户中签数达到上限后其余码不再参与。\n一次开奖产出完整中签名单，开奖后本次中签名额将锁定。`,
    '开奖',
    { type: 'warning' }
  )
  const res = await request.post(`/raffle/${row.id}/draw`)
  ElMessage.success(`开奖完成！共中签 ${res.count || 0} 人 / ${res.totalWins || 0} 签（必中 ${res.forcedCount || 0} 人）`)
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
      <h2>抽签活动管理</h2>
      <p class="page__tip">报名 → 开奖（必中直接中签 + 剩余名额随机，一次完成）→ 中签者限时购买；开奖后名额锁定</p>
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
      <el-table-column prop="name" label="活动名称" min-width="160" show-overflow-tooltip />
      <el-table-column prop="collectibleName" label="藏品" min-width="120" show-overflow-tooltip />
      <el-table-column label="发行量/名额" width="170">
        <template #default="{ row }">
          总发行 <b>{{ row.totalSupply || row.collectibleEdition || '-' }}</b>
          <br />抽签名额 <b class="hl">{{ row.drawWinCount || row.winnerCount }}</b>
          <el-tag v-if="row.winLocked" size="small" type="danger" style="margin-left:4px">已锁定</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="单用户码上限" width="140">
        <template #default="{ row }">
          {{ codeLimitText(row) }}
        </template>
      </el-table-column>
      <el-table-column label="价格" width="90">
        <template #default="{ row }">¥{{ row.salePrice }}</template>
      </el-table-column>
      <el-table-column label="时间" min-width="200">
        <template #default="{ row }">
          <div style="font-size:12px;color:#999">报名: {{ row.registrationStart?.substring(0, 16) }}</div>
          <div style="font-size:12px;color:#999">至 {{ row.registrationEnd?.substring(0, 16) }}</div>
          <div style="font-size:12px;color:#999">开奖: {{ row.drawTime?.substring(0, 16) }}</div>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="96">
        <template #default="{ row }">
          <el-tag :type="STATUS_MAP[row.status]?.cls || 'info'">{{ STATUS_MAP[row.status]?.text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="数据" width="150">
        <template #default="{ row }">
          报名 <b>{{ row.registrationCount || 0 }}</b>｜码 <b>{{ row.codeCount || 0 }}</b>
          <br />
          <span style="color:#e6a23c">必中 {{ row.forceWinCount || 0 }}</span>
          ｜<span style="color:#22c55e">中签 {{ row.winnerCountDone || 0 }}</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="280" fixed="right">
        <template #default="{ row }">
          <el-button size="small" link type="primary" @click="goEdit(row.id)">编辑</el-button>
          <el-button size="small" link type="primary" @click="goRegistrations(row)">报名/中签</el-button>
          <el-button size="small" link type="primary" @click="goCodes(row)">抽签码</el-button>
          <br />
          <el-button v-if="row.status === 0" size="small" link type="success" @click="doStart(row)">开启报名</el-button>
          <el-button v-if="row.status === 1" size="small" link type="info" @click="doPause(row)">停用</el-button>
          <el-button v-if="row.status === 1" size="small" link type="warning" @click="doDraw(row)">立即开奖</el-button>
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
.hl { color: #e6a23c; }
</style>
