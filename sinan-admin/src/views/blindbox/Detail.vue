<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { showSuccessToast, showToast } from 'vant'
import { getBlindBoxDetail, saveBlindBoxPrize } from '@/api'
import DetailSheet from '@/components/DetailSheet.vue'
import { fmtMoney } from '@/utils/format'

const route = useRoute()
const id = Number(route.params.id)
const loading = ref(true)
const detail = ref(null)

const editShow = ref(false)
const editingPrize = ref(null)
const form = ref({ probability: '', quantityLimit: '' })

async function load() {
  loading.value = true
  const res = await getBlindBoxDetail(id)
  detail.value = res.data
  loading.value = false
}
onMounted(load)

function openEdit(prize) {
  editingPrize.value = prize
  form.value.probability = String(prize.probability)
  form.value.quantityLimit = prize.quantityLimit === null ? '' : String(prize.quantityLimit)
  editShow.value = true
}

async function onSavePrize() {
  const prob = Number(form.value.probability)
  if (!(prob > 0 && prob <= 1)) return showToast('概率需为 0~1 之间的小数')
  const limit = form.value.quantityLimit === '' ? null : Number(form.value.quantityLimit)
  const res = await saveBlindBoxPrize({
    boxId: id,
    prizeCollectibleId: editingPrize.value.prizeCollectibleId,
    probability: prob,
    quantityLimit: limit
  })
  if (res.code === 0) {
    showSuccessToast('奖池已更新')
    editShow.value = false
    load()
  }
}

const probSumOk = () => Math.abs((detail.value?.probabilitySum ?? 0) - 1) < 0.0001
</script>

<template>
  <div class="adm-page bd">
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else-if="detail">
      <div class="adm-card">
        <div class="bd__head">
          <img class="bd__cover" :src="detail.cover" :alt="detail.name" />
          <div>
            <div class="bd__name">{{ detail.name }}</div>
            <div class="t-tertiary" style="font-size: 12px">发行 {{ detail.edition }} · 已售 {{ detail.sold }} · 已开启 {{ detail.openedCount }}</div>
            <div class="price" style="font-size: 20px; margin-top: 6px">¥{{ fmtMoney(detail.price) }}</div>
          </div>
        </div>
      </div>

      <div class="adm-card">
        <div class="adm-card__title">
          奖池配置
          <van-tag :type="probSumOk() ? 'success' : 'danger'" plain round size="medium">
            概率合计 {{ (detail.probabilitySum * 100).toFixed(1) }}%
          </van-tag>
        </div>

        <div
          v-for="p in detail.items"
          :key="p.id"
          class="adm-item"
          @click="openEdit(p)"
        >
          <img class="adm-item__thumb" style="width: 46px; height: 46px" :src="p.cover" :alt="p.prizeName" />
          <div class="adm-item__body">
            <div class="adm-item__title">{{ p.prizeName }}</div>
            <div class="adm-item__desc">
              已发放 {{ p.quantityDistributed }}{{ p.quantityLimit !== null ? ` / 限量 ${p.quantityLimit}` : ' / 不限量' }}
            </div>
          </div>
          <div class="adm-item__side">
            <div class="price" style="font-size: 16px">{{ (p.probability * 100).toFixed(1) }}%</div>
            <div class="t-tertiary" style="font-size: 11px; margin-top: 2px">点击调整</div>
          </div>
        </div>

        <van-notice-bar
          left-icon="warning-o"
          text="概率合计必须为 100%；限量奖品发完后自动落入次级奖池（联调后由后端强校验）。"
          style="margin-top: 10px"
        />
      </div>
    </template>

    <DetailSheet v-model:show="editShow" :title="editingPrize ? `调整 · ${editingPrize.prizeName}` : ''">
      <template v-if="editingPrize">
        <van-field
          v-model="form.probability"
          type="number"
          label="中奖概率"
          placeholder="0 ~ 1 之间小数，如 0.05 表示 5%"
        />
        <van-field
          v-model="form.quantityLimit"
          type="digit"
          label="限量份数"
          placeholder="留空表示不限量"
        />
        <div class="bd__preview t-secondary" style="padding: 10px 4px; font-size: 12px">
          预览：中奖概率 = {{ (Number(form.probability || 0) * 100).toFixed(1) }}%
        </div>
      </template>
      <template #actions>
        <van-button block round type="primary" @click="onSavePrize">保存调整</van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.bd__head {
  display: flex;
  gap: 14px;
  align-items: center;
}

.bd__cover {
  width: 84px;
  height: 84px;
  border-radius: $radius-md;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.bd__name {
  font-size: 16px;
  font-weight: 700;
}
</style>
