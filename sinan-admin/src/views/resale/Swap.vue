<script setup>
import { ref, onMounted } from 'vue'
import request from '@/utils/request'

const loading = ref(true)

onMounted(loadPlans)

// ---- 统一置换回收记录（计划列表 + 名单/明细） ----
const plans = ref([])
const plansTotal = ref(0)
const plansPage = ref(1)
const plansSize = ref(20)

async function loadPlans() {
  loading.value = true
  try {
    const res = await request.get('/swap/plans', { params: { page: plansPage.value, pageSize: plansSize.value } })
    plans.value = res.list || []
    plansTotal.value = res.total || 0
  } finally { loading.value = false }
}
function onPlansPageChange(p) {
  plansPage.value = p
  loadPlans()
}

// 详情（名单/明细分页）
const detailShow = ref(false)
const detailLoading = ref(false)
const detail = ref(null)
const detailUsersPage = ref(1)
const detailDetailsPage = ref(1)
const detailAction = ref('') // ''全部 1回收 2空投

async function openPlanDetail(row) {
  detailShow.value = true
  detailUsersPage.value = 1
  detailDetailsPage.value = 1
  detailAction.value = ''
  await loadPlanDetail(row.id)
}

async function loadPlanDetail(id) {
  detailLoading.value = true
  try {
    const params = {
      page: detailUsersPage.value,
      pageSize: 20,
      // 后端名单/明细独立分页：dPage/dPageSize 控制资产明细页码
      dPage: detailDetailsPage.value,
      dPageSize: 20
    }
    if (detailAction.value) params.action = detailAction.value
    const res = await request.get(`/swap/plans/${id}`, { params })
    detail.value = res
  } finally { detailLoading.value = false }
}

function onUsersPageChange(p) {
  detailUsersPage.value = p
  loadPlanDetail(detail.value.id)
}
function onDetailsPageChange(p) {
  if (typeof p === 'number') detailDetailsPage.value = p
  loadPlanDetail(detail.value.id)
}
</script>

<template>
  <div class="page">
    <div class="page__head"><h2>置换管理</h2><p class="page__tip">管理员统一回收源藏品并按比例向持有人空投新藏品；执行入口在「藏品管理 → 藏品详情 → 统一置换」</p></div>

    <el-table :data="plans" v-loading="loading" stripe style="width:100%;margin-top:12px">
      <el-table-column prop="planNo" label="计划号" width="170" />
      <el-table-column label="回收源藏品（比例）" min-width="280">
        <template #default="{ row }">
          <span v-for="(it, i) in row.items" :key="it.collectibleId">
            <template v-if="i > 0">、</template>{{ it.collectibleName }} 1:{{ it.ratio }}（回收 {{ it.recoveredCount }} 份）
          </span>
        </template>
      </el-table-column>
      <el-table-column label="空投新藏品" min-width="160">
        <template #default="{ row }">{{ row.newCollectibleName }}（#{{ row.newCollectibleId }}）</template>
      </el-table-column>
      <el-table-column prop="userCount" label="受影响用户" width="100" />
      <el-table-column prop="totalRecovered" label="回收份数" width="90" />
      <el-table-column prop="totalAirdropped" label="空投份数" width="90" />
      <el-table-column prop="reason" label="原因" min-width="120" show-overflow-tooltip />
      <el-table-column prop="adminName" label="操作人" width="100" />
      <el-table-column prop="createdAt" label="执行时间" width="180" />
      <el-table-column label="操作" width="100" fixed="right">
        <template #default="{ row }">
          <el-button size="small" link type="primary" @click="openPlanDetail(row)">名单/明细</el-button>
        </template>
      </el-table-column>
    </el-table>
    <div style="margin-top:12px;display:flex;justify-content:flex-end">
      <el-pagination
        v-model:current-page="plansPage"
        layout="total, prev, pager, next"
        :total="plansTotal"
        :page-size="plansSize"
        @current-change="onPlansPageChange"
      />
    </div>

    <!-- 置换计划详情：源配置 + 用户名单 + 资产明细 -->
    <el-dialog v-model="detailShow" :title="`置换计划 ${detail?.planNo || ''} · 名单与明细`" width="980px" append-to-body>
      <div v-loading="detailLoading">
        <template v-if="detail">
          <div class="plan__summary">
            <div class="plan__stat">
              <div class="plan__v">{{ detail.userCount }}</div>
              <div class="plan__l">受影响用户</div>
            </div>
            <div class="plan__stat">
              <div class="plan__v">{{ detail.totalRecovered }}</div>
              <div class="plan__l">回收总份数</div>
            </div>
            <div class="plan__stat">
              <div class="plan__v">{{ detail.totalAirdropped }}</div>
              <div class="plan__l">空投总份数</div>
            </div>
            <div class="plan__stat">
              <div class="plan__v" style="font-size:14px">{{ detail.newCollectibleName }}</div>
              <div class="plan__l">目标新藏品（#{{ detail.newCollectibleId }}）</div>
            </div>
          </div>
          <div class="plan__meta">
            <span>原因：{{ detail.reason || '—' }}</span>
            <span>操作人：{{ detail.adminName }}</span>
            <span>执行时间：{{ detail.createdAt }}</span>
          </div>

          <div class="t-title">源藏品配置</div>
          <el-table :data="detail.items" size="small" border>
            <el-table-column label="藏品" min-width="200">
              <template #default="{ row }">{{ row.collectibleName }}（#{{ row.collectibleId }}）</template>
            </el-table-column>
            <el-table-column label="比例（每份→新藏品）" width="150">
              <template #default="{ row }">1 : {{ row.ratio }}</template>
            </el-table-column>
            <el-table-column prop="recoveredCount" label="实际回收份数" width="120" />
          </el-table>

          <div class="t-title">用户名单（共 {{ detail.users.total }} 人）</div>
          <el-table :data="detail.users.list" size="small" border>
            <el-table-column prop="userId" label="用户ID" width="90" />
            <el-table-column prop="phone" label="手机号" width="130" />
            <el-table-column label="持有明细（数量 × 比例 → 空投）" min-width="320">
              <template #default="{ row }">
                <span v-for="(h, i) in row.holdings" :key="h.collectibleId">
                  <template v-if="i > 0"> + </template>{{ h.name }} {{ h.quantity }}×{{ h.ratio }}（{{ h.newQuantity }}）
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="recoveredTotal" label="回收合计" width="90" />
            <el-table-column label="空投新藏品" width="100">
              <template #default="{ row }">
                <el-tag type="warning" effect="plain" size="small">{{ row.airdropQuantity }} 份</el-tag>
              </template>
            </el-table-column>
          </el-table>
          <div class="plan__pager">
            <el-pagination
              v-model:current-page="detailUsersPage"
              layout="total, prev, pager, next"
              :total="detail.users.total"
              :page-size="detail.users.pageSize"
              @current-change="onUsersPageChange"
            />
          </div>

          <div class="t-title" style="display:flex;align-items:center;gap:12px">
            资产明细
            <el-radio-group v-model="detailAction" size="small" @change="onDetailsPageChange(1)">
              <el-radio-button value="">全部</el-radio-button>
              <el-radio-button value="1">回收</el-radio-button>
              <el-radio-button value="2">空投</el-radio-button>
            </el-radio-group>
            （共 {{ detail.details.total }} 条）
          </div>
          <el-table :data="detail.details.list" size="small" border max-height="320">
            <el-table-column prop="userId" label="用户ID" width="90" />
            <el-table-column label="动作" width="80">
              <template #default="{ row }">
                <el-tag :type="row.action === 1 ? 'info' : 'success'" effect="plain" size="small">
                  {{ row.action === 1 ? '回收' : '空投' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="藏品" min-width="180">
              <template #default="{ row }">{{ row.collectibleName }}（#{{ row.collectibleId }}）</template>
            </el-table-column>
            <el-table-column prop="serial" label="资产编号" width="140" />
            <el-table-column label="比例" width="80">
              <template #default="{ row }">{{ row.ratio ? '1:' + row.ratio : '—' }}</template>
            </el-table-column>
            <el-table-column prop="userCollectibleId" label="资产行ID" width="90" />
            <el-table-column prop="createdAt" label="时间" width="180" />
          </el-table>
          <div class="plan__pager">
            <el-pagination
              v-model:current-page="detailDetailsPage"
              layout="total, prev, pager, next"
              :total="detail.details.total"
              :page-size="detail.details.pageSize"
              @current-change="onDetailsPageChange"
            />
          </div>
        </template>
      </div>
      <template #footer>
        <el-button @click="detailShow = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>
<style scoped>
.page { padding: 16px; }
.page__head { margin-bottom: 12px; }
.page__head h2 { margin: 0 0 4px; font-size: 18px; }
.page__tip { margin: 0; font-size: 13px; color: #999; }
.t-title { font-size: 13px; color: #666; margin: 14px 0 6px; font-weight: 600; }
.plan__summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 10px; }
.plan__stat { text-align: center; padding: 10px 0; border-radius: 8px; background: var(--color-surface, #f7f8fa); }
.plan__v { font-size: 20px; font-weight: 700; }
.plan__l { font-size: 12px; color: #999; margin-top: 2px; }
.plan__meta { display: flex; gap: 18px; flex-wrap: wrap; font-size: 13px; color: #666; margin-bottom: 4px; }
.plan__pager { margin-top: 10px; display: flex; justify-content: flex-end; }
</style>
