<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import { getOrderList, orderAction } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { ORDER_STATUS, ORDER_SOURCE } from '@/utils/maps'
import { fmtMoney } from '@/utils/format'

const drawerShow = ref(false)
const detail = ref(null)

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'pending', label: '待支付' },
      { value: 'paid', label: '已支付' },
      { value: 'completed', label: '已完成' },
      { value: 'refunding', label: '退款中' },
      { value: 'refunded', label: '已退款' },
      { value: 'cancelled', label: '已取消' },
      { value: 'abnormal', label: '异常' }
    ]
  },
  {
    field: 'source',
    label: '来源',
    options: [
      { value: 'release', label: '公售' },
      { value: 'priority', label: '优先购' },
      { value: 'eligibility', label: '资格购' },
      { value: 'market', label: '市场' },
      { value: 'blindbox', label: '盲盒' }
    ]
  }
]

const actionMap = {
  markPaid: { title: '标记已支付', msg: '补单确认：标记后订单进入已支付状态并发放藏品。', type: 'warning' },
  complete: { title: '完成订单', msg: '确认将订单标记为已完成？', type: 'warning' },
  cancel: { title: '取消订单', msg: '确认取消该订单？取消后库存回滚。', type: 'error' },
  applyRefund: { title: '转退款', msg: '确认将该订单转入退款流程？', type: 'warning' }
}

function openDetail(o) {
  detail.value = o
  drawerShow.value = true
}

async function onAction(action) {
  const cfg = actionMap[action]
  await ElMessageBox.confirm(cfg.msg, cfg.title, { type: cfg.type })
  const res = await orderAction(detail.value.id, action)
  if (res.code === 0) {
    detail.value.status = res.data
    ElMessage.success('操作成功')
  }
}

function onExport() {
  ElMessage.info('导出任务已生成（联调后下载 Excel/CSV）')
}
</script>

<template>
  <div class="adm-page">
    <AdminTablePage :fetch="getOrderList" :filters="filters" search-placeholder="搜索订单号 / 用户 / 藏品">
      <template #extra>
        <el-button :icon="Download" @click="onExport">导出报表</el-button>
      </template>

      <template #default="{ items }">
        <el-table-column label="订单号" width="180" fixed="left">
          <template #default="{ row }">
            <span class="ord-no" @click="openDetail(row)">{{ row.orderNo }}</span>
          </template>
        </el-table-column>

        <el-table-column label="藏品" min-width="200">
          <template #default="{ row }">
            <div class="ord-item">
              <img class="adm-thumb" :src="row.cover" :alt="row.collectibleName" />
              <div>
                <div class="ord-name">{{ row.collectibleName }} ×{{ row.quantity }}</div>
                <div class="ord-sub">¥{{ fmtMoney(row.unitPrice) }}/份</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="买家" width="160">
          <template #default="{ row }">
            <div>{{ row.userName }}</div>
            <div class="t-tertiary" style="font-size: 12px">{{ row.userPhone }}</div>
          </template>
        </el-table-column>

        <el-table-column label="来源" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.source" :map="ORDER_SOURCE" />
          </template>
        </el-table-column>

        <el-table-column label="实付金额" width="110" align="right">
          <template #default="{ row }">
            <span class="price">¥{{ fmtMoney(row.amount) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="ORDER_STATUS" />
          </template>
        </el-table-column>

        <el-table-column label="下单时间" width="150" prop="createTime" />

        <el-table-column label="操作" width="80" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 订单详情抽屉 -->
    <el-drawer v-model="drawerShow" :title="detail ? `订单 ${detail.orderNo}` : '订单详情'" size="420px">
      <template v-if="detail">
        <div class="adm-card" style="box-shadow: none">
          <div class="adm-card__title">
            订单信息
            <StatusTag :value="detail.status" :map="ORDER_STATUS" />
          </div>
          <div class="adm-kv"><span class="k">订单号</span><span class="v">{{ detail.orderNo }}</span></div>
          <div class="adm-kv"><span class="k">订单来源</span><span class="v"><StatusTag :value="detail.source" :map="ORDER_SOURCE" /></span></div>
          <div class="adm-kv"><span class="k">下单用户</span><span class="v">{{ detail.userName }}（{{ detail.userPhone }}）</span></div>
          <div class="adm-kv"><span class="k">藏品</span><span class="v">{{ detail.collectibleName }}</span></div>
          <div class="adm-kv"><span class="k">单价 / 数量</span><span class="v">¥{{ fmtMoney(detail.unitPrice) }} × {{ detail.quantity }}</span></div>
          <div class="adm-kv"><span class="k">实付金额</span><span class="v price">¥{{ fmtMoney(detail.amount) }}</span></div>
          <div class="adm-kv"><span class="k">支付方式</span><span class="v">余额支付（司南币）</span></div>
          <div class="adm-kv"><span class="k">创建时间</span><span class="v">{{ detail.createTime }}</span></div>
          <div class="adm-kv"><span class="k">支付时间</span><span class="v">{{ detail.payTime || '-' }}</span></div>
        </div>

        <div class="ord-actions">
          <template v-if="detail.status === 'pending'">
            <el-button type="danger" plain @click="onAction('cancel')">取消订单</el-button>
            <el-button type="primary" @click="onAction('markPaid')">标记已支付</el-button>
          </template>
          <template v-else-if="detail.status === 'paid'">
            <el-button type="warning" plain @click="onAction('applyRefund')">转退款</el-button>
            <el-button type="primary" @click="onAction('complete')">完成订单</el-button>
          </template>
          <template v-else-if="detail.status === 'abnormal'">
            <el-button type="danger" plain @click="onAction('cancel')">取消订单</el-button>
            <el-button type="primary" @click="onAction('markPaid')">补单（标记已支付）</el-button>
          </template>
          <el-button v-else @click="drawerShow = false">关闭</el-button>
        </div>
      </template>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.ord-no {
  font-family: $font-price;
  color: $color-primary;
  cursor: pointer;

  &:hover { text-decoration: underline; }
}

.ord-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ord-name { font-size: 13px; font-weight: 600; color: $color-text-primary; }
.ord-sub { font-size: 12px; color: $color-text-tertiary; margin-top: 2px; }

.ord-actions {
  display: flex;
  gap: 10px;
  margin-top: 16px;

  .el-button { flex: 1; }
}
</style>
