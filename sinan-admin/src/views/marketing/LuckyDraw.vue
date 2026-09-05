<script setup>
import { ref, onMounted } from 'vue'
import { showSuccessToast, showToast } from 'vant'
import { getLuckyDraws, toggleLuckyDraw, saveLuckyDrawPrize } from '@/api'
import DetailSheet from '@/components/DetailSheet.vue'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const activities = ref([])

const editShow = ref(false)
const editing = ref(null) // { activity, prize }
const form = ref({ probability: '', total: '' })

onMounted(load)

async function load() {
  loading.value = true
  const res = await getLuckyDraws()
  activities.value = res.data
  loading.value = false
}

async function onToggle(a) {
  const res = await toggleLuckyDraw(a.id)
  if (res.code === 0) {
    a.status = res.data
    showSuccessToast(res.data === 'enabled' ? '已开启' : '已停用')
  }
}

function openEdit(a, p) {
  editing.value = { activity: a, prize: p }
  form.value.probability = String(p.probability)
  form.value.total = String(p.total)
  editShow.value = true
}

async function onSavePrize() {
  const prob = Number(form.value.probability)
  const total = Number(form.value.total)
  if (!(prob > 0 && prob <= 1)) return showToast('概率需为 0~1 之间的小数')
  if (!Number.isInteger(total) || total < 1) return showToast('奖品总量需为正整数')
  const res = await saveLuckyDrawPrize({
    activityId: editing.value.activity.id,
    prizeId: editing.value.prize.id,
    probability: prob,
    total
  })
  if (res.code === 0) {
    showSuccessToast('奖品已更新')
    editShow.value = false
    load()
  }
}

const sumProb = (a) => a.prizes.reduce((s, p) => s + p.probability, 0)
</script>

<template>
  <div class="adm-page ld">
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else>
      <div v-for="a in activities" :key="a.id" class="adm-card">
        <div class="ld__head">
          <div class="adm-item__title">
            {{ a.name }}
            <StatusTag :value="a.status" :map="ACTIVITY_STATUS" />
          </div>
          <van-switch :model-value="a.status === 'enabled'" size="22px" @click="onToggle(a)" />
        </div>
        <div class="adm-item__desc">{{ a.startTime }} ~ {{ a.endTime }}</div>

        <div class="ld__stats">
          <div class="ld__stat"><div class="price">{{ fmtNumber(a.chancesIssued) }}</div><div class="t-tertiary">发放次数</div></div>
          <div class="ld__stat"><div class="price">{{ fmtNumber(a.chancesUsed) }}</div><div class="t-tertiary">已消耗</div></div>
          <div class="ld__stat">
            <div class="price" :class="Math.abs(sumProb(a) - 1) < 0.0001 ? '' : 't-warning'">{{ (sumProb(a) * 100).toFixed(1) }}%</div>
            <div class="t-tertiary">概率合计</div>
          </div>
        </div>

        <div class="ld__prizes">
          <div v-for="p in a.prizes" :key="p.id" class="ld__prize" @click="openEdit(a, p)">
            <img v-if="p.cover" class="ld__prize-img" :src="p.cover" :alt="p.tier" />
            <div v-else class="ld__prize-img ld__prize-img--none"><van-icon name="smile-comment-o" size="18" /></div>
            <div class="ld__prize-tier">{{ p.tier }}</div>
            <div class="price" style="font-size: 13px">{{ (p.probability * 100).toFixed(1) }}%</div>
            <div class="t-tertiary" style="font-size: 10px">{{ p.won }}/{{ p.total }}</div>
          </div>
        </div>
      </div>
    </template>

    <DetailSheet v-model:show="editShow" :title="editing ? `调整奖品 · ${editing.prize.tier}` : ''">
      <template v-if="editing">
        <van-field v-model="form.probability" type="number" label="中奖概率" placeholder="0~1 小数，如 0.02 = 2%" />
        <van-field v-model="form.total" type="digit" label="奖品总量" placeholder="该奖品的总份数" />
        <div class="t-tertiary" style="padding: 8px 4px; font-size: 12px">
          当前已发出 {{ editing.prize.won }} 份，调整总量不能低于已发出数量。
        </div>
      </template>
      <template #actions>
        <van-button block round type="primary" @click="onSavePrize">保存调整</van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.ld__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.ld__stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin: 12px 0;
  text-align: center;
}

.ld__stat .price { font-size: 17px; }
.ld__stat .t-tertiary { font-size: 11px; margin-top: 2px; }

.ld__prizes {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.ld__prize {
  border: 1px solid $color-border;
  border-radius: $radius-md;
  padding: 10px 6px;
  text-align: center;
  cursor: pointer;
}

.ld__prize-img {
  width: 52px;
  height: 52px;
  border-radius: 8px;
  object-fit: cover;
  margin: 0 auto;
  background: $color-surface;

  &--none {
    @include flex-center;
    color: $color-gold;
  }
}

.ld__prize-tier {
  font-size: 11px;
  color: $color-text-secondary;
  margin-top: 6px;
  @include ellipsis;
}
</style>
