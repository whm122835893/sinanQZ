<script setup>
import { useRouter } from 'vue-router'
import { showSuccessToast, showConfirmDialog } from 'vant'
import { getCollectibleList, toggleCollectibleStatus } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { COLLECTIBLE_STATUS } from '@/utils/maps'
import { fmtMoney, stockPool } from '@/utils/format'

const router = useRouter()

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'onsale', label: '发售中' },
      { value: 'upcoming', label: '待发售' },
      { value: 'soldout', label: '已售罄' },
      { value: 'offline', label: '已下架' }
    ]
  },
  {
    field: 'category',
    label: '分类',
    options: [
      { value: '青铜', label: '青铜' },
      { value: '水墨', label: '水墨' },
      { value: '国潮', label: '国潮' },
      { value: '限定', label: '限定' },
      { value: '盲盒', label: '盲盒' }
    ]
  }
]

async function onAction(c, action) {
  const map = {
    online: { title: '上架藏品', msg: `确认上架「${c.name}」上架后 C 端立即可见。` },
    offline: { title: '下架藏品', msg: `确认下架「${c.name}」下架后 C 端不再展示。` },
    forceSoldout: { title: '强制售罄', msg: `确认将「${c.name}」标记为已售罄？剩余 ${stockPool(c)} 份将进入锁定池。` }
  }
  const cfg = map[action]
  await showConfirmDialog({ title: cfg.title, message: cfg.msg })
  const res = await toggleCollectibleStatus(c.id, action)
  if (res.code === 0) {
    c.status = res.data
    showSuccessToast('操作成功')
  }
}
</script>

<template>
  <div class="adm-page">
    <div class="adm-toolbar">
      <div class="t-secondary" style="font-size: 12px">藏品全生命周期：发售 / 售罄 / 下架 / 空投 / 销毁</div>
      <van-button size="small" round type="primary" icon="plus" to="/collectible/edit">新建藏品</van-button>
    </div>

    <AdminListPage :fetch="getCollectibleList" :filters="filters" search-placeholder="搜索藏品名称 / 系列">
      <template #default="{ items }">
        <div
          v-for="c in items"
          :key="c.id"
          class="adm-card col-card"
        >
          <div class="col-card__main" @click="router.push(`/collectible/detail/${c.id}`)">
            <img class="adm-item__thumb" :src="c.cover" :alt="c.name" />
            <div class="adm-item__body">
              <div class="adm-item__title">
                {{ c.name }}
                <van-tag v-if="c.tag" plain type="primary" size="small">{{ c.tag }}</van-tag>
              </div>
              <div class="adm-item__desc">{{ c.subtitle }} · {{ c.category }}</div>
              <div class="adm-item__desc">
                <span class="price" style="font-size: 14px">¥{{ fmtMoney(c.price) }}</span>
                · 发行 {{ c.edition }} · 已售 {{ c.sold }}
              </div>
              <div class="col-card__progress">
                <div class="col-card__progress-bar">
                  <div class="col-card__progress-inner" :style="{ width: (c.sold / c.edition * 100).toFixed(1) + '%' }" />
                </div>
                <span class="col-card__progress-text">{{ (c.sold / c.edition * 100).toFixed(1) }}%</span>
              </div>
            </div>
            <div class="adm-item__side">
              <StatusTag :value="c.status" :map="COLLECTIBLE_STATUS" />
              <div class="t-tertiary" style="font-size: 11px; margin-top: 6px">库存 {{ stockPool(c) }}</div>
            </div>
          </div>
          <div class="col-card__actions">
            <van-button
              size="small" round plain type="primary"
              @click.stop="router.push(`/collectible/detail/${c.id}`)"
            >详情</van-button>
            <van-button
              v-if="c.status === 'offline' || c.status === 'soldout'"
              size="small" round plain type="success"
              @click.stop="onAction(c, 'online')"
            >重新上架</van-button>
            <van-button
              v-if="c.status === 'onsale'"
              size="small" round plain type="warning"
              @click.stop="onAction(c, 'forceSoldout')"
            >强制售罄</van-button>
            <van-button
              v-if="c.status === 'onsale'"
              size="small" round plain type="default"
              @click.stop="onAction(c, 'offline')"
            >下架</van-button>
          </div>
        </div>
      </template>
    </AdminListPage>
  </div>
</template>

<style scoped lang="scss">
.col-card__main {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  cursor: pointer;
}

.col-card__actions {
  display: flex;
  gap: 8px;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px dashed $color-border;
}

.col-card__progress {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
}

.col-card__progress-bar {
  flex: 1;
  height: 4px;
  border-radius: 2px;
  background: $color-surface;
  overflow: hidden;
}

.col-card__progress-inner {
  height: 100%;
  border-radius: 2px;
  background: linear-gradient(90deg, var(--color-primary), var(--color-gold));
}

.col-card__progress-text {
  font-size: 10px;
  color: $color-text-tertiary;
  font-family: $font-price;
}
</style>
