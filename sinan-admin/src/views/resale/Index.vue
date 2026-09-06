<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getResaleList, resaleAction, getBuyRequests, delistBuyRequest } from '@/api'
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

// ---- 挂单操作 ----
async function onAction(r, action) {
  const map = {
    freeze: { title: '冻结挂单', msg: `确认冻结「${r.collectibleName}」的寄售挂单？冻结期间不可被购买。`, type: 'warning' },
    unfreeze: { title: '解除冻结', msg: '确认恢复该挂单为在售状态？', type: 'info' },
    cancel: { title: '系统强制下架', msg: `确认强制下架该挂单？藏品将退回卖家账户，挂单状态变更为「已取消」。`, type: 'error' }
  }
  const cfg = map[action]
  await ElMessageBox.confirm(cfg.msg, cfg.title, { type: cfg.type })
  const res = await resaleAction(r.id, action)
  if (res.code === 0) {
    r.status = res.data
    ElMessage.success('操作成功，已写入审计日志')
  }
}

// ---- 求购下架 ----
async function onDelistBuy(b) {
  await ElMessageBox.confirm(
    `确认强制下架「${b.userName}」对「${b.collectibleName}」的求购信息？`,
    '强制下架',
    { type: 'warning' }
  )
  const res = await delistBuyRequest(b.id)
  if (res.code === 0) {
    b.status = 'delisted'
    ElMessage.success('已下架')
  }
}
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
        <AdminTablePage :fetch="getBuyRequests" search-placeholder="搜索求购用户 / 藏品">
          <template #default="{ items }">
            <el-table-column label="求购用户" prop="userName" min-width="120" fixed="left" />
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
                >强制下架</el-button>
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
</style>
