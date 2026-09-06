<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import request from '@/utils/request'
import AppNavBar from '@/components/AppNavBar.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import AppModal from '@/components/AppModal.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import { showToast } from 'vant'
import { useUserStore } from '@/stores/user'
import { useLoginGate } from '@/utils/loginGate'

const route = useRoute()
const user = useUserStore()
const { requireLogin } = useLoginGate()

const visible = ref(true)
const wallet = ref({ balance: 0, available: 0, frozen: 0, points: 0, brand: '汇付' })
const records = ref([])
const loading = ref(false)

// 千分位格式化金额
const fmt = (n) => Number(n || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// MOCK_REPLACED: 原为内联 mock 余额（12,860.00 等）与流水数组，
// 现从后端拉取：GET /api/wallet（余额/可用/司南币）、GET /api/wallet/transactions（流水）
async function fetchWallet() {
  wallet.value = await request.get('/wallet')
}

async function fetchRecords() {
  const res = await request.get('/wallet/transactions', { params: { page: 1, pageSize: 20 } })
  records.value = (res.list || []).map((t) => ({
    id: t.id,
    type: t.transType, // recharge充值 / buy消费 / withdraw提现 / reward奖励
    title: t.title,
    time: String(t.createdAt || '').slice(0, 16),
    amount: (t.direction === 'in' ? '+' : '-') + fmt(t.amount),
    income: t.direction === 'in'
  }))
}

async function refresh() {
  loading.value = true
  try {
    await Promise.all([fetchWallet(), fetchRecords()])
  } catch (e) {
    showToast(e.message || '加载失败')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (!requireLogin(route.fullPath)) return
  refresh()
})

function toggleVisible() { visible.value = !visible.value }

const icons = { recharge: 'wallet', buy: 'cube', withdraw: 'horn', reward: 'gift' }

// ---- 充值（真实接口：POST /api/wallet/recharge，模拟入账）----
const showRecharge = ref(false)
const amount = ref('')
const quickAmounts = [100, 500, 1000, 2000]
const rechargeError = ref('')
const recharging = ref(false)

function openRecharge() {
  if (!user.isLoggedIn) { requireLogin(route.fullPath); return }
  amount.value = ''
  rechargeError.value = ''
  showRecharge.value = true
}

function pickQuick(v) {
  amount.value = String(v)
  rechargeError.value = ''
}

async function submitRecharge() {
  const n = Number(amount.value)
  if (!n || n < 1) { rechargeError.value = '请输入大于 0 的充值金额'; return }
  if (recharging.value) return
  recharging.value = true
  try {
    await request.post('/wallet/recharge', { amount: n })
    showRecharge.value = false
    showToast('充值成功')
    await refresh()
  } catch (e) {
    rechargeError.value = e.message || '充值失败'
  } finally {
    recharging.value = false
  }
}

function action(name) {
  if (name === '充值') { openRecharge(); return }
  if (name === '提现') { showToast('提现功能开发中'); return }
  // 明细：滚动到流水区域
  document.querySelector('.wallet-records')?.scrollIntoView({ behavior: 'smooth' })
}
</script>

<template>
  <div class="wallet page--no-tabbar">
    <AppNavBar title="我的钱包" @click-left="$router.back()" />

    <!-- 资产卡片 -->
    <div class="wallet-card">
      <span class="wallet-card__brand">{{ wallet.brand || '汇付' }}</span>
      <svg class="wallet-card__logo" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg">
        <circle cx="30" cy="30" r="22" fill="none" stroke="#444" stroke-width="1"/>
        <circle cx="30" cy="12" r="4" fill="#E84B4B"/>
        <circle cx="44" cy="20" r="4" fill="#F0A93B"/>
        <circle cx="47" cy="36" r="4" fill="#3BB273"/>
        <circle cx="36" cy="48" r="4" fill="#3B8DF0"/>
        <circle cx="22" cy="48" r="4" fill="#9B59F0"/>
        <circle cx="13" cy="36" r="4" fill="#E84B9B"/>
        <circle cx="16" cy="20" r="4" fill="#3BC9F0"/>
        <circle cx="30" cy="30" r="5" fill="#fff"/>
      </svg>

      <div class="wallet-card__body">
        <div class="wallet-card__label">
          <span>总资产（元）</span>
          <span class="wallet-card__eye" @click="toggleVisible">
            <AppIcon :name="visible ? 'search' : 'close'" :size="16" color="#fff" />
          </span>
        </div>
        <div class="wallet-card__balance">{{ visible ? '¥ ' + fmt(wallet.balance) : '¥ ****' }}</div>
        <div class="wallet-card__sub">
          <span>司南币 {{ visible ? fmt(wallet.points) : '****' }}</span>
          <span class="dot">·</span>
          <span>可用 {{ visible ? '¥ ' + fmt(wallet.available) : '****' }}</span>
        </div>
      </div>

      <div class="wallet-card__actions">
        <button class="wallet-card__action" @click="action('充值')">
          <AppIcon name="wallet" :size="22" color="#fff" />
          <span>充值</span>
        </button>
        <button class="wallet-card__action" @click="action('提现')">
          <AppIcon name="horn" :size="22" color="#fff" />
          <span>提现</span>
        </button>
        <button class="wallet-card__action" @click="action('明细')">
          <AppIcon name="list" :size="22" color="#fff" />
          <span>明细</span>
        </button>
      </div>
    </div>

    <!-- 交易明细 -->
    <div class="wallet-records">
      <h3 class="wallet-records__title">交易明细</h3>
      <div v-if="records.length" class="wallet-records__list">
        <div v-for="r in records" :key="r.id" class="wallet-records__item">
          <div class="wallet-records__icon" :class="'is-' + r.type">
            <AppIcon :name="icons[r.type] || 'wallet'" :size="20" color="#fff" />
          </div>
          <div class="wallet-records__info">
            <p class="wallet-records__name">{{ r.title }}</p>
            <p class="wallet-records__time">{{ r.time }}</p>
          </div>
          <span class="wallet-records__amount" :class="{ minus: !r.income }">
            {{ r.amount }}
          </span>
        </div>
      </div>
      <div v-else-if="loading" class="wallet-records__loading">加载中...</div>
      <AppEmpty v-else description="暂无交易明细" />
    </div>

    <!-- 充值弹窗 -->
    <AppModal v-model:show="showRecharge" title="账户充值" :closable="!recharging">
      <div class="recharge">
        <div class="recharge__balance">
          当前可用余额：<b>¥ {{ fmt(wallet.available) }}</b>
        </div>
        <AppInput
          v-model="amount"
          type="number"
          label="充值金额（元）"
          placeholder="请输入充值金额"
          :error="rechargeError"
        />
        <div class="recharge__quick">
          <button
            v-for="q in quickAmounts"
            :key="q"
            class="recharge__quick-btn"
            :class="{ active: String(q) === String(amount) }"
            @click="pickQuick(q)"
          >¥{{ q }}</button>
        </div>
        <AppButton :disabled="recharging" @click="submitRecharge">
          {{ recharging ? '充值中...' : '确认充值' }}
        </AppButton>
        <p class="recharge__tip">当前为联调环境，充值即时模拟到账</p>
      </div>
    </AppModal>
  </div>
</template>

<style scoped lang="scss">
.wallet-card {
  position: relative; margin: 16px; border-radius: $radius-lg; padding: 20px;
  background: linear-gradient(135deg, #2a2f3a, #1A1A1A);
  box-shadow: 0 10px 24px rgba(0,0,0,0.18); color: #fff;
  &__brand { font-size: 26px; font-weight: 700; letter-spacing: 2px; }
  &__logo { position: absolute; top: 18px; right: 18px; width: 48px; height: 48px; }
  &__body { margin-top: 14px; }
  &__label {
    display: flex; align-items: center; gap: 8px; font-size: 12px; color: #bbb;
  }
  &__eye { cursor: pointer; display: inline-flex; }
  &__balance { margin: 8px 0 10px; font-size: 30px; font-weight: 800; font-family: $font-price; }
  &__sub { font-size: 12px; color: #bbb; display: flex; align-items: center; gap: 8px; .dot { opacity: .5; } }
  &__actions {
    display: flex; margin-top: 18px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,.12);
  }
  &__action {
    flex: 1; border: none; background: transparent; color: #fff; cursor: pointer;
    display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: 13px;
  }
}

.wallet-records { margin: 8px 16px 16px; }
.wallet-records__title { margin: 4px 0 12px; font-size: 16px; font-weight: 700; color: $color-text-primary; }
.wallet-records__list { background: $color-card; border-radius: $radius-lg; padding: 0 14px; }
.wallet-records__loading { padding: 32px 0; text-align: center; font-size: 13px; color: $color-text-tertiary; }
.wallet-records__item {
  display: flex; align-items: center; gap: 12px; min-height: 64px;
  &:not(:last-child) { border-bottom: 1px solid $color-border; }
}
.wallet-records__icon {
  width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  &.is-recharge { background: #07c160; }
  &.is-buy { background: $color-primary; }
  &.is-withdraw { background: #E8A33D; }
  &.is-reward { background: #9B59F0; }
}
.wallet-records__info { flex: 1; min-width: 0; }
.wallet-records__name { margin: 0 0 4px; font-size: 14px; color: $color-text-primary; @include ellipsis; }
.wallet-records__time { margin: 0; font-size: 12px; color: $color-text-tertiary; }
.wallet-records__amount {
  font-size: 15px; font-weight: 700; font-family: $font-price; color: #07c160;
  &.minus { color: $color-text-primary; }
}

.recharge {
  &__balance {
    margin-bottom: 14px; font-size: 13px; color: $color-text-secondary;
    b { color: $color-text-primary; font-family: $font-price; }
  }
  &__quick { display: flex; gap: 8px; margin: 12px 0 16px; }
  &__quick-btn {
    flex: 1; height: 34px; border: 1px solid $color-border; border-radius: $radius-md;
    background: $color-surface; color: $color-text-primary; font-size: 13px; cursor: pointer;
    &.active { border-color: $color-primary; color: $color-primary; font-weight: 700; }
  }
  &__tip { margin: 12px 0 0; font-size: 12px; color: $color-text-tertiary; text-align: center; }
}
</style>
