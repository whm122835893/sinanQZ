<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { showSuccessToast, showConfirmDialog } from 'vant'
import { getUserList, getUserDetail, freezeUser, resetTradePwd } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
import DetailSheet from '@/components/DetailSheet.vue'
import StatusTag from '@/components/StatusTag.vue'
import { USER_STATUS, REALNAME_STATUS } from '@/utils/maps'
import { fmtMoney, fmtDateTime } from '@/utils/format'

const router = useRouter()
const sheetShow = ref(false)
const detail = ref(null)

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'normal', label: '正常' },
      { value: 'frozen', label: '已冻结' }
    ]
  },
  {
    field: 'realnameStatus',
    label: '实名',
    options: [
      { value: 'approved', label: '已实名' },
      { value: 'pending', label: '待审核' },
      { value: 'rejected', label: '已驳回' },
      { value: 'none', label: '未实名' }
    ]
  }
]

async function openDetail(u) {
  const res = await getUserDetail(u.id)
  detail.value = res.data
  sheetShow.value = true
}

async function onFreeze() {
  const u = detail.value
  const freezing = u.status === 'normal'
  await showConfirmDialog({
    title: freezing ? '冻结账号' : '解冻账号',
    message: freezing
      ? `确认冻结「${u.nickname}」？冻结后该用户无法登录与交易。`
      : `确认解冻「${u.nickname}」？`
  })
  const res = await freezeUser(u.id)
  if (res.code === 0) {
    u.status = res.data
    showSuccessToast(res.data === 'normal' ? '已解冻' : '已冻结')
  }
}

async function onResetPwd() {
  await showConfirmDialog({
    title: '重置交易密码',
    message: `确认重置「${detail.value.nickname}」的交易密码？重置后用户可重新设置。`
  })
  const res = await resetTradePwd(detail.value.id)
  if (res.code === 0) showSuccessToast('已重置')
}
</script>

<template>
  <div class="adm-page">
    <AdminListPage :fetch="getUserList" :filters="filters" search-placeholder="搜索昵称 / 手机号">
      <template #default="{ items }">
        <div class="adm-card" style="padding: 0">
          <div
            v-for="u in items"
            :key="u.id"
            class="adm-item"
            style="padding: 12px 14px"
            @click="openDetail(u)"
          >
            <img class="user__avatar" :src="u.avatar" :alt="u.nickname" />
            <div class="adm-item__body">
              <div class="adm-item__title">
                {{ u.nickname }}
                <StatusTag :value="u.status" :map="USER_STATUS" />
              </div>
              <div class="adm-item__desc">{{ u.phone }} · 注册于 {{ u.registerTime.slice(0, 10) }}</div>
              <div class="adm-item__desc">
                余额 <span class="price">{{ fmtMoney(u.balance) }}</span> · {{ u.collectibleCount }} 件藏品 · {{ u.orderCount }} 笔订单
              </div>
            </div>
            <div class="adm-item__side">
              <StatusTag :value="u.realnameStatus" :map="REALNAME_STATUS" />
              <div class="t-tertiary" style="font-size: 11px; margin-top: 6px">{{ u.lastLoginTime }}</div>
            </div>
          </div>
        </div>
      </template>
    </AdminListPage>

    <!-- 用户详情抽屉 -->
    <DetailSheet v-model:show="sheetShow" :title="detail?.nickname">
      <template v-if="detail">
        <div class="adm-card" style="margin-bottom: 10px">
          <div class="adm-card__title">基础信息</div>
          <div class="adm-kv"><span class="k">用户 ID</span><span class="v">{{ detail.id }}</span></div>
          <div class="adm-kv"><span class="k">手机号</span><span class="v">{{ detail.phone }}</span></div>
          <div class="adm-kv"><span class="k">注册时间</span><span class="v">{{ detail.registerTime }}</span></div>
          <div class="adm-kv"><span class="k">最近登录</span><span class="v">{{ detail.lastLoginTime }}</span></div>
          <div class="adm-kv"><span class="k">实名状态</span><span class="v"><StatusTag :value="detail.realnameStatus" :map="REALNAME_STATUS" /></span></div>
          <div class="adm-kv" v-if="detail.realnameName"><span class="k">实名信息</span><span class="v">{{ detail.realnameName }}（{{ detail.realnameIdNo }}）</span></div>
          <div class="adm-kv"><span class="k">账号状态</span><span class="v"><StatusTag :value="detail.status" :map="USER_STATUS" /></span></div>
        </div>

        <div class="adm-card" style="margin-bottom: 10px">
          <div class="adm-card__title">资产概况</div>
          <div class="user__assets">
            <div class="user__asset">
              <div class="price">{{ fmtMoney(detail.balance) }}</div>
              <div class="t-tertiary">余额（元）</div>
            </div>
            <div class="user__asset">
              <div class="price">{{ detail.points }}</div>
              <div class="t-tertiary">司南币</div>
            </div>
            <div class="user__asset">
              <div class="price">{{ detail.collectibleCount }}</div>
              <div class="t-tertiary">藏品数</div>
            </div>
          </div>
        </div>

        <div class="adm-card" style="margin-bottom: 10px">
          <div class="adm-card__title">最近订单</div>
          <div v-for="o in detail.orders" :key="o.id" class="adm-kv">
            <span class="k" style="max-width: 60%">{{ o.collectibleName }} ×{{ o.quantity }}</span>
            <span class="v">¥{{ o.amount }} · {{ o.createTime.slice(5, 16) }}</span>
          </div>
          <van-empty v-if="!detail.orders.length" description="暂无订单" image-size="60" />
        </div>

        <div class="adm-card">
          <div class="adm-card__title">最近钱包流水</div>
          <div v-for="t in detail.transfers" :key="t.id" class="adm-kv">
            <span class="k" style="max-width: 60%">{{ t.title }}</span>
            <span class="v" :class="t.direction > 0 ? 't-success' : 't-primary'">
              {{ t.direction > 0 ? '+' : '-' }}{{ t.amount }}
            </span>
          </div>
          <van-empty v-if="!detail.transfers.length" description="暂无流水" image-size="60" />
        </div>
      </template>

      <template #actions>
        <van-button block round plain type="warning" @click="onResetPwd">重置交易密码</van-button>
        <van-button block round :type="detail?.status === 'normal' ? 'danger' : 'primary'" @click="onFreeze">
          {{ detail?.status === 'normal' ? '冻结账号' : '解冻账号' }}
        </van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.user__avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid $color-border;
}

.user__assets {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  text-align: center;
}

.user__asset .price { font-size: 17px; }
.user__asset .t-tertiary { font-size: 11px; margin-top: 3px; }
</style>
