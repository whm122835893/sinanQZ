<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppIcon from '@/components/AppIcon.vue'
import { showToast } from 'vant'

const router = useRouter()

const balance = ref('12,860.00')
const coin = ref('1,280.00')
const available = ref('11,580.00')
const visible = ref(true)

function toggleVisible() { visible.value = !visible.value }

const records = [
  { type: 'recharge', title: '账户充值', time: '2026-08-20 14:22', amount: '+2,000.00', done: true },
  { type: 'buy', title: '购买《司南·青铜纹样》', time: '2026-08-18 21:05', amount: '-399.00', done: true },
  { type: 'withdraw', title: '提现到银行卡', time: '2026-08-15 10:48', amount: '-1,500.00', done: true },
  { type: 'buy', title: '购买《司南·鎏金面具》', time: '2026-08-12 19:33', amount: '-880.00', done: true },
  { type: 'recharge', title: '账户充值', time: '2026-08-10 09:11', amount: '+1,000.00', done: true }
]

const icons = { recharge: 'wallet', buy: 'cube', withdraw: 'horn' }
const titles = { recharge: '充值', buy: '消费', withdraw: '提现' }

function action(name) { showToast(name + '功能开发中') }
</script>

<template>
  <div class="wallet page--no-tabbar">
    <AppNavBar title="我的钱包" @click-left="$router.back()" />

    <!-- 资产卡片 -->
    <div class="wallet-card">
      <span class="wallet-card__brand">汇付</span>
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
        <div class="wallet-card__balance">{{ visible ? '¥ ' + balance : '¥ ****' }}</div>
        <div class="wallet-card__sub">
          <span>司南币 {{ visible ? coin : '****' }}</span>
          <span class="dot">·</span>
          <span>可用 {{ visible ? '¥ ' + available : '****' }}</span>
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
      <div class="wallet-records__list">
        <div v-for="(r, i) in records" :key="i" class="wallet-records__item">
          <div class="wallet-records__icon" :class="'is-' + r.type">
            <AppIcon :name="icons[r.type]" :size="20" color="#fff" />
          </div>
          <div class="wallet-records__info">
            <p class="wallet-records__name">{{ r.title }}</p>
            <p class="wallet-records__time">{{ r.time }}</p>
          </div>
          <span class="wallet-records__amount" :class="{ minus: r.amount.startsWith('-') }">
            {{ r.amount }}
          </span>
        </div>
      </div>
    </div>
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
}
.wallet-records__info { flex: 1; min-width: 0; }
.wallet-records__name { margin: 0 0 4px; font-size: 14px; color: $color-text-primary; @include ellipsis; }
.wallet-records__time { margin: 0; font-size: 12px; color: $color-text-tertiary; }
.wallet-records__amount {
  font-size: 15px; font-weight: 700; font-family: $font-price; color: #07c160;
  &.minus { color: $color-text-primary; }
}
</style>
