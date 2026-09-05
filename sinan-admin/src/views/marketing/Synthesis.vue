<script setup>
import { ref, onMounted } from 'vue'
import { showSuccessToast } from 'vant'
import { getSynthesisList, toggleSynthesis } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const list = ref([])

onMounted(load)

async function load() {
  loading.value = true
  const res = await getSynthesisList()
  list.value = res.data
  loading.value = false
}

async function onToggle(a) {
  const res = await toggleSynthesis(a.id)
  if (res.code === 0) {
    a.status = res.data
    showSuccessToast(res.data === 'enabled' ? '已开启' : '已停用')
  }
}
</script>

<template>
  <div class="adm-page sy">
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else>
      <div v-for="a in list" :key="a.id" class="adm-card">
        <div class="sy__head">
          <div class="adm-item__title">
            {{ a.title }}
            <van-tag plain round size="medium" :type="a.type === 'limited' ? 'warning' : 'primary'">
              {{ a.type === 'limited' ? '限时' : '常驻' }}
            </van-tag>
            <StatusTag :value="a.status" :map="ACTIVITY_STATUS" />
          </div>
          <van-switch :model-value="a.status === 'enabled'" size="22px" @click="onToggle(a)" />
        </div>
        <div class="adm-item__desc">{{ a.rules }}</div>

        <!-- 合成公式 -->
        <div class="sy__formula">
          <div class="sy__mats">
            <div v-for="m in a.materials" :key="m.collectibleId" class="sy__mat">
              <img :src="m.cover" :alt="m.name" />
              <div class="sy__mat-name">{{ m.name }}</div>
              <div class="sy__mat-count price">×{{ m.count }}</div>
            </div>
          </div>
          <div class="sy__plus"><van-icon name="plus" /></div>
          <div class="sy__result">
            <img :src="a.result.cover" :alt="a.result.name" />
            <div class="sy__mat-name">{{ a.result.name }}</div>
            <div class="t-tertiary" style="font-size: 10px">合成产物</div>
          </div>
        </div>

        <div class="sy__meta">
          <span>每人限合成 <b class="price">{{ a.perUserLimit }}</b> 次</span>
          <span>总量 <b class="price">{{ a.totalLimit === null ? '不限' : fmtNumber(a.totalLimit) }}</b></span>
          <span>已合成 <b class="price">{{ fmtNumber(a.usedCount) }}</b></span>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.sy__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.sy__formula {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 12px 0;
  overflow-x: auto;
}

.sy__mats { display: flex; gap: 8px; }

.sy__mat, .sy__result {
  flex-shrink: 0;
  width: 76px;
  text-align: center;

  img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 8px;
    background: $color-surface;
  }
}

.sy__result img { border: 2px solid var(--color-gold); }

.sy__mat-name {
  font-size: 11px;
  color: $color-text-secondary;
  margin-top: 4px;
  @include ellipsis;
}

.sy__mat-count { font-size: 12px; }

.sy__plus {
  color: $color-text-tertiary;
  flex-shrink: 0;
}

.sy__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  font-size: 12px;
  color: $color-text-secondary;
  padding-top: 10px;
  border-top: 1px dashed $color-border;

  b { font-size: 13px; }
}
</style>
