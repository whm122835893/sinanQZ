<script setup>
import { ref, onMounted } from 'vue'
import { showSuccessToast, showToast } from 'vant'
import { getPrioritySales, addWhitelist } from '@/api'
import DetailSheet from '@/components/DetailSheet.vue'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'

const loading = ref(true)
const sales = ref([])

const addShow = ref(false)
const currentSale = ref(null)
const form = ref({ phone: '', quantity: 1, expiresAt: '' })

onMounted(load)

async function load() {
  loading.value = true
  const res = await getPrioritySales()
  sales.value = res.data
  loading.value = false
}

function openAdd(s) {
  currentSale.value = s
  form.value = { phone: '', quantity: 1, expiresAt: s.endTime }
  addShow.value = true
}

async function onAdd() {
  if (!/^1\d{10}$/.test(form.value.phone)) return showToast('请输入正确的手机号')
  if (!Number.isInteger(form.value.quantity) || form.value.quantity < 1) return showToast('请输入有效份数')
  const res = await addWhitelist({
    saleId: currentSale.value.id,
    phone: form.value.phone,
    quantity: form.value.quantity,
    expiresAt: form.value.expiresAt
  })
  if (res.code === 0) {
    showSuccessToast('已加入白名单')
    addShow.value = false
    load()
  }
}
</script>

<template>
  <div class="adm-page pr">
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else>
      <div v-for="s in sales" :key="s.id" class="adm-card">
        <div class="pr__head">
          <img class="adm-item__thumb" style="width: 46px; height: 46px" :src="s.cover" :alt="s.name" />
          <div class="adm-item__body">
            <div class="adm-item__title">
              {{ s.name }}
              <StatusTag :value="s.status" :map="ACTIVITY_STATUS" />
            </div>
            <div class="adm-item__desc">
              {{ s.type === 'priority' ? '优先购' : '资格购' }} · {{ s.collectibleName }}
            </div>
            <div class="adm-item__desc">{{ s.startTime }} ~ {{ s.endTime }}</div>
          </div>
          <van-button size="small" round type="primary" icon="plus" @click="openAdd(s)">加白名单</van-button>
        </div>

        <div class="pr__whitelist">
          <div class="pr__wl-title">
            白名单（{{ s.whitelistCount }} 人）
            <span class="t-tertiary" style="font-size: 11px; font-weight: 400">限额 / 已购</span>
          </div>
          <div v-for="w in s.whitelists" :key="w.id" class="adm-item">
            <div class="adm-item__body">
              <div class="adm-item__title">{{ w.nickname }}</div>
              <div class="adm-item__desc">{{ w.phone }} · 有效期至 {{ w.expiresAt }}</div>
            </div>
            <div class="adm-item__side">
              <span class="price">{{ w.maxQuantity }}</span>
              <span class="t-tertiary" style="font-size: 12px"> / {{ w.usedQuantity }}</span>
            </div>
          </div>
          <div v-if="s.whitelistCount > s.whitelists.length" class="t-tertiary" style="font-size: 11px; text-align: center; padding: 6px 0">
            其余 {{ s.whitelistCount - s.whitelists.length }} 人已省略（联调后分页加载）
          </div>
        </div>
      </div>
    </template>

    <DetailSheet v-model:show="addShow" :title="currentSale ? `加入白名单 · ${currentSale.name}` : ''">
      <template v-if="currentSale">
        <van-field v-model="form.phone" type="tel" label="手机号" placeholder="被邀请用户手机号" required />
        <van-field v-model="form.quantity" type="digit" label="限购份数" placeholder="该用户最大可购份数" required />
        <van-field v-model="form.expiresAt" label="失效时间" placeholder="YYYY-MM-DD HH:mm" />
        <van-notice-bar left-icon="info-o" text="加入白名单的用户在活动期间享有优先购买资格，超出限购份数后回落普通购买。" style="margin-top: 10px" />
      </template>
      <template #actions>
        <van-button block round type="primary" @click="onAdd">确认添加</van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.pr__head {
  display: flex;
  gap: 10px;
  align-items: flex-start;
}

.pr__whitelist {
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px dashed $color-border;
}

.pr__wl-title {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 4px;
}
</style>
