<script setup>
import { useRouter } from 'vue-router'
import { showSuccessToast } from 'vant'
import { getBlindBoxList, toggleBlindBoxOpenable } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { COLLECTIBLE_STATUS } from '@/utils/maps'
import { fmtMoney } from '@/utils/format'

const router = useRouter()

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'onsale', label: '发售中' },
      { value: 'upcoming', label: '待发售' }
    ]
  }
]

async function onToggleOpen(b) {
  const res = await toggleBlindBoxOpenable(b.id)
  if (res.code === 0) {
    b.isOpenable = res.data
    showSuccessToast(res.data === 1 ? '已允许开启' : '已暂停开启')
  }
}
</script>

<template>
  <div class="adm-page">
    <van-notice-bar left-icon="info-o" text="盲盒奖池概率必须合计 100%，可点击盲盒卡片进入配置页调整。" />

    <AdminListPage :fetch="getBlindBoxList" :filters="filters" search-placeholder="搜索盲盒名称">
      <template #default="{ items }">
        <div
          v-for="b in items"
          :key="b.id"
          class="adm-card bb-card"
          @click="router.push(`/blindbox/detail/${b.id}`)"
        >
          <div class="bb-card__main">
            <img class="adm-item__thumb" :src="b.cover" :alt="b.name" />
            <div class="adm-item__body">
              <div class="adm-item__title">
                {{ b.name }}
                <StatusTag :value="b.status" :map="COLLECTIBLE_STATUS" />
              </div>
              <div class="adm-item__desc">
                <span class="price" style="font-size: 14px">¥{{ fmtMoney(b.price) }}</span>
                · 发行 {{ b.edition }} · 已售 {{ b.sold }}
              </div>
              <div class="adm-item__desc">已开启 {{ b.openedCount }} 份</div>
            </div>
            <div class="adm-item__side">
              <div class="t-tertiary" style="font-size: 11px; margin-bottom: 6px">允许开启</div>
              <van-switch :model-value="b.isOpenable === 1" size="22px" @click.stop="onToggleOpen(b)" />
            </div>
          </div>
          <div class="bb-card__pool">
            <div v-for="it in b.items" :key="it.id" class="bb-card__pool-item">
              <img :src="it.cover" :alt="it.prizeName" />
              <div class="bb-card__pool-name">{{ it.prizeName }}</div>
              <div class="bb-card__pool-prob price">{{ (it.probability * 100).toFixed(1) }}%</div>
            </div>
          </div>
        </div>
      </template>
    </AdminListPage>
  </div>
</template>

<style scoped lang="scss">
.bb-card__main {
  display: flex;
  gap: 10px;
  align-items: flex-start;
}

.bb-card__pool {
  display: flex;
  gap: 8px;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px dashed $color-border;
  overflow-x: auto;
}

.bb-card__pool-item {
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

.bb-card__pool-name {
  font-size: 11px;
  color: $color-text-secondary;
  margin-top: 4px;
  @include ellipsis;
}

.bb-card__pool-prob { font-size: 12px; }
</style>
