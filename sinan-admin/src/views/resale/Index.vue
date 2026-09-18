<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getResaleList, resaleAction, getBuyRequests, delistBuyRequest, getBatchBuyConfig, saveBatchBuyConfig } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { RESALE_STATUS, BUY_REQUEST_STATUS } from '@/utils/maps'
import { fmtMoney } from '@/utils/format'

const activeTab = ref('listings')

const statusFilters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'onsale', label: '挂单中' },
      { value: 'frozen', label: '已冻结' },
      { value: 'sold', label: '已成交' },
      { value: 'cancelled', label: '已取消' },
      { value: 'system_delisted', label: '系统下架' }
    ]
  }
]

// 求购状态筛选：后端存数字（1求购中 2已接单 3已取消 4已成交 5已过期）
const buyStatusFilters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: '1', label: '求购中' },
      { value: '2', label: '已接单' },
      { value: '4', label: '已成交' },
      { value: '3', label: '已取消' },
      { value: '5', label: '已过期' }
    ]
  }
]

// ---- 挂单操作 ----
async function onAction(r, action) {
  const map = {
    freeze: { title: '冻结挂单', msg: `确认冻结「${r.collectibleName}」的寄售挂单？冻结期间不可被购买。`, type: 'warning' },
    unfreeze: { title: '解除冻结', msg: '确认恢复该挂单资产为持有状态？用户可重新上架。', type: 'info' },
    cancel: { title: '系统强制下架', msg: `确认强制下架该挂单？藏品将退回卖家账户，挂单状态变更为「系统下架」。`, type: 'error' }
  }
  const cfg = map[action]
  // 后端要求必填原因（写入审计日志）
  const { value } = await ElMessageBox.prompt(cfg.msg, cfg.title, {
    type: cfg.type,
    confirmButtonText: '确认',
    inputPlaceholder: '操作原因（必填，写入审计日志）',
    inputValidator: (v) => (v && v.trim() ? true : '操作原因必填')
  })
  const res = await resaleAction(r.id, action, value.trim())
  if (res.code === 0) {
    // 后端无状态返回：按操作语义更新（freeze=资产冻结 / cancel=系统下架 / unfreeze=资产回持有）
    r.status = { freeze: 'frozen', unfreeze: 'system_delisted', cancel: 'system_delisted' }[action] || r.status
    ElMessage.success('操作成功，已写入审计日志')
  }
}

// ---- 求购下架 ----
async function onDelistBuy(b) {
  const { value } = await ElMessageBox.prompt(
    `确认强制关闭「${b.userName}」对「${b.collectibleName}」的求购信息？`,
    '强制关闭求购',
    {
      type: 'warning',
      confirmButtonText: '确认关闭',
      inputPlaceholder: '关闭原因（写入审计日志）',
      inputValidator: (v) => (v && v.trim() ? true : '关闭原因必填')
    }
  )
  const res = await delistBuyRequest(b.id, value.trim())
  if (res.code === 0) {
    b.status = 'cancelled'
    ElMessage.success('已关闭')
  }
}

// ---- 批量购买配置 ----
const batchLoading = ref(false)
const batchSaving = ref(false)
const batchForm = reactive({
  enabled: false,
  scope: 'all',       // all=全体用户 specific=指定用户
  limit: 5,
  users: ''           // 指定用户手机号（换行分隔）
})

async function loadBatchConfig() {
  batchLoading.value = true
  const res = await getBatchBuyConfig()
  batchLoading.value = false
  if (res.code === 0 && res.data) {
    batchForm.enabled = !!res.data.enabled
    batchForm.scope = res.data.scope === 'specific' ? 'specific' : 'all'
    batchForm.limit = Number(res.data.limit) || 1
    batchForm.users = res.data.users || ''
  }
}

async function onSaveBatchConfig() {
  if (!Number.isInteger(batchForm.limit) || batchForm.limit < 1 || batchForm.limit > 100) {
    ElMessage.warning('批量限度需为 1~100 之间的整数')
    return
  }
  if (batchForm.scope === 'specific' && !batchForm.users.trim()) {
    ElMessage.warning('指定用户模式需至少填写一个手机号')
    return
  }
  batchSaving.value = true
  const res = await saveBatchBuyConfig({
    enabled: batchForm.enabled,
    scope: batchForm.scope,
    limit: batchForm.limit,
    users: batchForm.users
  })
  batchSaving.value = false
  if (res.code === 0) {
    ElMessage.success('批量购买配置已保存并实时生效')
    loadBatchConfig()
  }
}

onMounted(loadBatchConfig)
</script>

<template>
  <div class="adm-page rs">
    <el-tabs v-model="activeTab" class="rs__tabs">
      <!-- 寄售挂单 -->
      <el-tab-pane label="寄售挂单" name="listings">
        <AdminTablePage
          :fetch="getResaleList"
          :filters="statusFilters"
          search-placeholder="搜索挂单号 / 卖家 / 藏品"
        >
          <template #default="{ items }">
            <el-table-column label="挂单编号" prop="listingNo" width="150" fixed="left" />
            <el-table-column label="藏品" min-width="200">
              <template #default="{ row }">
                <div class="rs__cell">
                  <img class="rs__cover" :src="row.cover" :alt="row.collectibleName" />
                  <div>
                    <div class="rs__name">{{ row.collectibleName }}</div>
                    <div class="t-tertiary" style="font-size: 11px">{{ row.serial }}</div>
                  </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="卖家" min-width="130">
              <template #default="{ row }">
                <div>{{ row.sellerName }}</div>
                <div class="t-tertiary" style="font-size: 11px">{{ row.userPhone }}</div>
              </template>
            </el-table-column>
            <el-table-column label="寄售价" width="110" align="right">
              <template #default="{ row }">
                <span class="price">¥{{ fmtMoney(row.price) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="100">
              <template #default="{ row }">
                <StatusTag :value="row.status" :map="RESALE_STATUS" />
              </template>
            </el-table-column>
            <el-table-column label="挂单时间" prop="createTime" width="150" />
            <el-table-column label="操作" width="150" fixed="right">
              <template #default="{ row }">
                <template v-if="row.status === 'onsale'">
                  <el-button link type="warning" size="small" @click="onAction(row, 'freeze')">冻结</el-button>
                  <el-button link type="danger" size="small" @click="onAction(row, 'cancel')">强制下架</el-button>
                </template>
                <el-button v-else-if="row.status === 'frozen'" link type="primary" size="small" @click="onAction(row, 'unfreeze')">
                  解冻
                </el-button>
                <span v-else class="t-tertiary" style="font-size: 12px">-</span>
              </template>
            </el-table-column>
          </template>
        </AdminTablePage>
      </el-tab-pane>

      <!-- 求购市场 -->
      <el-tab-pane label="求购市场" name="buy" lazy>
        <AdminTablePage :fetch="getBuyRequests" :filters="buyStatusFilters" search-placeholder="搜索求购用户 / 藏品">
          <template #default="{ items }">
            <el-table-column label="求购用户" min-width="130" fixed="left">
              <template #default="{ row }">
                <div>{{ row.userName }}</div>
                <div class="t-tertiary" style="font-size: 11px">{{ row.userPhone }}</div>
              </template>
            </el-table-column>
            <el-table-column label="目标藏品" prop="collectibleName" min-width="180" />
            <el-table-column label="求购价" width="110" align="right">
              <template #default="{ row }">
                <span class="price">¥{{ fmtMoney(row.price) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="数量" width="70" align="center">
              <template #default="{ row }">{{ row.quantity }}</template>
            </el-table-column>
            <el-table-column label="状态" width="100">
              <template #default="{ row }">
                <StatusTag :value="row.status" :map="BUY_REQUEST_STATUS" />
              </template>
            </el-table-column>
            <el-table-column label="发布时间" prop="createTime" width="150" />
            <el-table-column label="操作" width="100" fixed="right">
              <template #default="{ row }">
                <el-button
                  v-if="row.status === 'active'"
                  link type="danger" size="small"
                  @click="onDelistBuy(row)"
                >强制关闭</el-button>
                <span v-else class="t-tertiary" style="font-size: 12px">-</span>
              </template>
            </el-table-column>
          </template>
        </AdminTablePage>
      </el-tab-pane>

      <!-- 成交记录 -->
      <el-tab-pane label="成交记录" name="sold" lazy>
        <AdminTablePage
          :fetch="getResaleList"
          :defaults="{ status: 'sold' }"
          search-placeholder="搜索挂单号 / 卖家 / 藏品"
        >
          <template #default="{ items }">
            <el-table-column label="挂单编号" prop="listingNo" width="150" fixed="left" />
            <el-table-column label="藏品" min-width="200">
              <template #default="{ row }">
                <div class="rs__cell">
                  <img class="rs__cover" :src="row.cover" :alt="row.collectibleName" />
                  <div>
                    <div class="rs__name">{{ row.collectibleName }}</div>
                    <div class="t-tertiary" style="font-size: 11px">{{ row.serial }}</div>
                  </div>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="卖家" prop="sellerName" min-width="110" />
            <el-table-column label="成交价" width="110" align="right">
              <template #default="{ row }">
                <span class="price">¥{{ fmtMoney(row.price) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="100">
              <template #default="{ row }">
                <StatusTag :value="row.status" :map="RESALE_STATUS" />
              </template>
            </el-table-column>
            <el-table-column label="成交时间" prop="createTime" width="150" />
          </template>
        </AdminTablePage>
        <div class="t-tertiary rs__sold-tip">
          历史成交价格走势与手续费统计见「数据统计」模块；平台手续费按比例或固定金额在站点配置中调整
        </div>
      </el-tab-pane>

      <!-- 批量购买设置 -->
      <el-tab-pane label="批量购买" name="batch" lazy>
        <div class="rs__batch" v-loading="batchLoading">
          <el-form label-width="140px" class="rs__batch-form">
            <el-form-item label="批量购买开关">
              <el-switch v-model="batchForm.enabled" active-text="开启" inactive-text="关闭" />
            </el-form-item>
            <el-form-item label="适用范围">
              <el-radio-group v-model="batchForm.scope">
                <el-radio value="all">全体用户</el-radio>
                <el-radio value="specific">指定用户</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="批量限度">
              <el-input-number v-model="batchForm.limit" :min="1" :max="100" :step="1" step-strictly />
              <span class="rs__batch-unit">件 / 次</span>
            </el-form-item>
            <el-form-item v-if="batchForm.scope === 'specific'" label="指定用户手机号">
              <el-input
                v-model="batchForm.users"
                type="textarea"
                :rows="6"
                class="rs__batch-users"
                placeholder="多个用户手机号换行输入，例如：&#10;13800000000&#10;13900000000"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="batchSaving" @click="onSaveBatchConfig">保存配置</el-button>
            </el-form-item>
          </el-form>

          <el-alert
            type="info"
            :closable="false"
            show-icon
            title="批量购买说明：开启后 C 端藏品页显示「批量购买」按钮，按当前地板价（最低价）从低到高批量购买；实际购买数量 = min(批量限度, 市场可购数量)。选择「指定用户」时按手机号精确匹配，多个手机号换行输入。"
          />
        </div>
      </el-tab-pane>
    </el-tabs>

    <el-alert
      type="info"
      :closable="false"
      show-icon
      title="寄售开关联动：单藏品寄售开关关闭后，该藏品所有在售挂单自动「系统下架」，用户无法重新上架（在藏品 / 盲盒详情页操作，需管理员密码验证）"
    />
  </div>
</template>

<style scoped lang="scss">
.rs__tabs {
  :deep(.el-tabs__header) {
    margin-bottom: 14px;
  }
}

.rs__cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.rs__cover {
  width: 40px;
  height: 40px;
  border-radius: 6px;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.rs__name {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
}

.rs__sold-tip {
  font-size: 12px;
  margin-top: 10px;
}

.rs__batch {
  padding: 8px 0;
}

.rs__batch-form {
  max-width: 560px;
  margin-bottom: 16px;
}

.rs__batch-unit {
  margin-left: 10px;
  font-size: 13px;
  color: $color-text-tertiary;
}

.rs__batch-users {
  max-width: 420px;
}
</style>
