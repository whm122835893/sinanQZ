<script setup>
import { ref } from 'vue'
import { showSuccessToast, showConfirmDialog } from 'vant'
import { getOrderList, orderAction } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
import DetailSheet from '@/components/DetailSheet.vue'
import StatusTag from '@/components/StatusTag.vue'
import { ORDER_STATUS } from '@/utils/maps'
import { fmtMoney } from '@/utils/format'

const sheetShow = ref(false)
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
  }
]

const actionMap = {
  markPaid: { title: '标记已支付', msg: '补单确认：标记后订单进入已支付状态并发放藏品。', type: 'primary' },
  complete: { title: '完成订单', msg: '确认将订单标记为已完成？', type: 'primary' },
  cancel: { title: '取消订单', msg: '确认取消该订单？取消后库存回滚。', type: 'danger' },
  applyRefund: { title: '转退款', msg: '确认将该订单转入退款流程？', type: 'warning' }
}

function openDetail(o) {
  detail.value = o
  sheetShow.value = true
}

async function onAction(action) {
  const cfg = actionMap[action]
  await showConfirmDialog({ title: cfg.title, message: cfg.msg })
  const res = await orderAction(detail.value.id, action)
  if (res.code === 0) {
    detail.value.status = res.data
    showSuccessToast('操作成功')
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminListPage :fetch="getOrderList" :filters="filters" search-placeholder="搜索订单号 / 用户 / 藏品">
      <template #default="{ items }">
        <div
          v-for="o in items"
          :key="o.id"
          class="adm-card ord"
          @click="openDetail(o)"
        >
          <div class="adm-item" style="padding: 0; border-bottom: 1px solid var(--color-border)">
            <img class="adm-item__thumb" style="width: 46px; height: 46px" :src="o.cover" :alt="o.collectibleName" />
            <div class="adm-item__body">
              <div class="adm-item__title">{{ o.collectibleName }} ×{{ o.quantity }}</div>
              <div class="adm-item__desc">{{ o.userName }} · {{ o.userPhone }}</div>
            </div>
            <div class="adm-item__side">
              <div class="price" style="font-size: 15px">¥{{ fmtMoney(o.amount) }}</div>
              <div style="margin-top: 4px"><StatusTag :value="o.status" :map="ORDER_STATUS" /></div>
            </div>
          </div>
          <div class="ord__foot">
            <span class="ord__no t-tertiary">{{ o.orderNo }}</span>
            <span class="t-tertiary">{{ o.createTime }}</span>
          </div>
        </div>
      </template>
    </AdminListPage>

    <DetailSheet v-model:show="sheetShow" :title="detail ? `订单 ${detail.orderNo}` : ''">
      <template v-if="detail">
        <div class="adm-card" style="margin-bottom: 10px">
          <div class="adm-card__title">
            订单信息
            <StatusTag :value="detail.status" :map="ORDER_STATUS" />
          </div>
          <div class="adm-kv"><span class="k">订单号</span><span class="v">{{ detail.orderNo }}</span></div>
          <div class="adm-kv"><span class="k">下单用户</span><span class="v">{{ detail.userName }}（{{ detail.userPhone }}）</span></div>
          <div class="adm-kv"><span class="k">藏品</span><span class="v">{{ detail.collectibleName }}</span></div>
          <div class="adm-kv"><span class="k">单价 / 数量</span><span class="v">¥{{ fmtMoney(detail.unitPrice) }} × {{ detail.quantity }}</span></div>
          <div class="adm-kv"><span class="k">实付金额</span><span class="v price">¥{{ fmtMoney(detail.amount) }}</span></div>
          <div class="adm-kv"><span class="k">支付方式</span><span class="v">余额支付（司南币）</span></div>
          <div class="adm-kv"><span class="k">创建时间</span><span class="v">{{ detail.createTime }}</span></div>
          <div class="adm-kv"><span class="k">支付时间</span><span class="v">{{ detail.payTime || '-' }}</span></div>
        </div>
      </template>

      <template #actions v-if="detail">
        <template v-if="detail.status === 'pending'">
          <van-button block round plain type="danger" @click="onAction('cancel')">取消订单</van-button>
          <van-button block round type="primary" @click="onAction('markPaid')">标记已支付</van-button>
        </template>
        <template v-else-if="detail.status === 'paid'">
          <van-button block round plain type="warning" @click="onAction('applyRefund')">转退款</van-button>
          <van-button block round type="primary" @click="onAction('complete')">完成订单</van-button>
        </template>
        <template v-else-if="detail.status === 'abnormal'">
          <van-button block round plain type="danger" @click="onAction('cancel')">取消订单</van-button>
          <van-button block round type="primary" @click="onAction('markPaid')">补单（标记已支付）</van-button>
        </template>
        <van-button v-else block round plain @click="sheetShow = false">关闭</van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.ord__foot {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  margin-top: 10px;
}

.ord__no { font-family: $font-price; }
</style>
