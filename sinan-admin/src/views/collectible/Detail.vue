<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showSuccessToast, showConfirmDialog, showToast } from 'vant'
import { getCollectibleDetail, airdropCollectible, destroyCollectible, addQuota, toggleQuota } from '@/api'
import DetailSheet from '@/components/DetailSheet.vue'
import StatusTag from '@/components/StatusTag.vue'
import { COLLECTIBLE_STATUS, QUOTA_TYPES } from '@/utils/maps'
import { fmtMoney, stockPool } from '@/utils/format'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)
const loading = ref(true)
const detail = ref(null)

// ---- 空投 ----
const airShow = ref(false)
const airForm = ref({ phones: '', quantity: 1 })

// ---- 销毁 ----
const destroyShow = ref(false)
const destroyQty = ref(1)

// ---- 配额 ----
const quotaShow = ref(false)
const quotaForm = ref({ quotaType: 1, quotaName: '', quantity: 10 })

async function load() {
  loading.value = true
  const res = await getCollectibleDetail(id)
  detail.value = res.data
  loading.value = false
}
onMounted(load)

async function onAirdrop() {
  const phones = airForm.value.phones.split(/[\n,，\s]+/).filter(Boolean)
  if (!phones.length) return showToast('请输入至少一个手机号')
  if (!Number.isInteger(airForm.value.quantity) || airForm.value.quantity < 1) return showToast('请输入有效数量')
  const res = await airdropCollectible({ id, phones, quantity: airForm.value.quantity })
  if (res.code === 0) {
    showSuccessToast(`已空投给 ${res.data.users} 位用户`)
    airShow.value = false
    load()
  }
}

async function onDestroy() {
  if (!Number.isInteger(destroyQty.value) || destroyQty.value.quantity < 1) return showToast('请输入有效数量')
  await showConfirmDialog({
    title: '确认销毁',
    message: `确认从库存池销毁 ${destroyQty.value} 份？销毁后不可恢复，总发行量守恒。`
  })
  const res = await destroyCollectible({ id, quantity: destroyQty.value })
  if (res.code === 0) {
    showSuccessToast(`已销毁 ${res.data.destroyed} 份`)
    destroyShow.value = false
    load()
  }
}

async function onAddQuota() {
  if (!quotaForm.value.quotaName.trim()) return showToast('请输入配额名称')
  const res = await addQuota({
    collectibleId: id,
    quotaType: quotaForm.value.quotaType,
    quotaName: quotaForm.value.quotaName,
    quantity: quotaForm.value.quantity
  })
  if (res.code === 0) {
    showSuccessToast('配额已添加')
    quotaShow.value = false
    load()
  }
}

async function onToggleQuota(q) {
  const res = await toggleQuota(q.id)
  if (res.code === 0) {
    q.status = res.data
    showSuccessToast(res.data === 1 ? '已启用' : '已停用')
  }
}
</script>

<template>
  <div class="adm-page cd">
    <van-skeleton v-if="loading" title :row="8" style="padding: 16px" />
    <template v-else-if="detail">
      <!-- 头图 -->
      <div class="cd__hero adm-card">
        <img class="cd__cover" :src="detail.cover" :alt="detail.name" />
        <div class="cd__hero-info">
          <div class="cd__name">
            {{ detail.name }}
            <StatusTag :value="detail.status" :map="COLLECTIBLE_STATUS" />
          </div>
          <div class="t-tertiary" style="font-size: 12px">{{ detail.subtitle }} · {{ detail.category }} · {{ detail.issuer }}</div>
          <div class="cd__price price">¥{{ fmtMoney(detail.price) }}</div>
        </div>
      </div>

      <!-- 库存守恒审计 -->
      <div class="adm-card">
        <div class="adm-card__title">
          库存守恒审计
          <van-tag v-if="detail.audit.ok" type="success" size="medium" plain round>守恒正常</van-tag>
          <van-tag v-else type="danger" size="medium" plain round>数据异常</van-tag>
        </div>
        <div class="cd__audit">
          <div class="cd__audit-item">
            <div class="cd__audit-v price">{{ detail.edition }}</div>
            <div class="cd__audit-l">总发行</div>
          </div>
          <div class="cd__audit-item">
            <div class="cd__audit-v price">{{ detail.sold }}</div>
            <div class="cd__audit-l">已售</div>
          </div>
          <div class="cd__audit-item">
            <div class="cd__audit-v price">{{ detail.audit.pool }}</div>
            <div class="cd__audit-l">库存池</div>
          </div>
          <div class="cd__audit-item">
            <div class="cd__audit-v price">{{ detail.lockedQuantity }}</div>
            <div class="cd__audit-l">锁定</div>
          </div>
          <div class="cd__audit-item">
            <div class="cd__audit-v price">{{ detail.reservedCount }}</div>
            <div class="cd__audit-l">配额预留</div>
          </div>
          <div class="cd__audit-item">
            <div class="cd__audit-v price">{{ detail.airdroppedCount }}</div>
            <div class="cd__audit-l">空投</div>
          </div>
          <div class="cd__audit-item">
            <div class="cd__audit-v price">{{ detail.destroyedCount }}</div>
            <div class="cd__audit-l">销毁</div>
          </div>
        </div>
        <div class="cd__audit-formula">
          发行 {{ detail.edition }} = 已售 {{ detail.sold }} + 库存 {{ detail.audit.pool }} + 锁定 {{ detail.lockedQuantity }} + 预留 {{ detail.reservedCount }} + 空投 {{ detail.airdroppedCount }} + 销毁 {{ detail.destroyedCount }}
        </div>
      </div>

      <!-- 基本信息 -->
      <div class="adm-card">
        <div class="adm-card__title">基本信息</div>
        <div class="adm-kv"><span class="k">发售时间</span><span class="v">{{ detail.saleTime }}</span></div>
        <div class="adm-kv"><span class="k">流通量</span><span class="v">{{ detail.circulate }}</span></div>
        <div class="adm-kv"><span class="k">首发推荐</span><span class="v">{{ detail.featured ? '是' : '否' }}</span></div>
        <div class="adm-kv"><span class="k">藏品描述</span><span class="v">{{ detail.description }}</span></div>
      </div>

      <!-- 配额 -->
      <div class="adm-card">
        <div class="adm-card__title">
          配额管理
          <span class="adm-card__more" @click="quotaShow = true"><van-icon name="plus" />新增配额</span>
        </div>
        <div v-for="q in detail.quotas" :key="q.id" class="adm-item">
          <div class="adm-item__body">
            <div class="adm-item__title">{{ q.quotaName }}</div>
            <div class="adm-item__desc">{{ QUOTA_TYPES[q.quotaType] }} · 计划 {{ q.plannedQuantity }} / 已用 {{ q.usedQuantity }}</div>
          </div>
          <van-switch :model-value="q.status === 1" size="20px" @click="onToggleQuota(q)" />
        </div>
        <van-empty v-if="!detail.quotas.length" description="暂无配额" image-size="60" />
      </div>

      <!-- 持有人 TOP -->
      <div class="adm-card">
        <div class="adm-card__title">持有人 TOP5</div>
        <div v-for="(h, i) in detail.holders" :key="h.serial" class="adm-item">
          <div class="cd__rank">{{ i + 1 }}</div>
          <div class="adm-item__body">
            <div class="adm-item__title">{{ h.nickname }}</div>
            <div class="adm-item__desc">{{ h.serial }}</div>
          </div>
          <div class="adm-item__side"><span class="price">×{{ h.quantity }}</span></div>
        </div>
      </div>

      <!-- 操作 -->
      <div class="cd__ops">
        <van-button block round plain type="primary" icon="gift-o" @click="airShow = true">独立空投</van-button>
        <van-button block round plain type="danger" icon="fire-o" @click="destroyShow = true">销毁库存</van-button>
        <van-button block round type="primary" icon="edit" :to="`/collectible/edit/${id}`">编辑藏品</van-button>
      </div>

      <!-- 空投弹层 -->
      <DetailSheet v-model:show="airShow" title="独立空投">
        <van-field
          v-model="airForm.phones"
          type="textarea"
          rows="3"
          label="手机号"
          placeholder="多个手机号用换行或逗号分隔"
        />
        <van-field v-model="airForm.quantity" type="digit" label="每人份数" placeholder="每用户空投份数" />
        <van-notice-bar left-icon="info-o" text="空投从库存池扣减，直接进入用户账户，记入空投统计。" style="margin-top: 10px" />
        <template #actions>
          <van-button block round type="primary" @click="onAirdrop">确认空投</van-button>
        </template>
      </DetailSheet>

      <!-- 销毁弹层 -->
      <DetailSheet v-model:show="destroyShow" title="销毁库存">
        <van-field v-model="destroyQty" type="digit" label="销毁份数" :placeholder="`最多可销毁 ${stockPool(detail)} 份`" />
        <van-notice-bar left-icon="warning-o" text="销毁从库存池扣减且不可恢复，销毁后总发行量守恒（已售+库存+锁定+预留+空投+销毁=发行）。" style="margin-top: 10px" />
        <template #actions>
          <van-button block round type="danger" @click="onDestroy">确认销毁</van-button>
        </template>
      </DetailSheet>

      <!-- 配额弹层 -->
      <DetailSheet v-model:show="quotaShow" title="新增配额">
        <van-field v-model="quotaForm.quotaName" label="配额名称" placeholder="如：优先购预留 / 活动空投" />
        <van-field v-model="quotaForm.quantity" type="digit" label="预留数量" placeholder="从库存池预留的份数" />
        <van-field name="quotaType" label="配额类型">
          <template #input>
            <van-radio-group v-model="quotaForm.quotaType" direction="horizontal" style="flex-wrap: wrap; gap: 8px">
              <van-radio v-for="(label, value) in QUOTA_TYPES" :key="value" :name="Number(value)">{{ label }}</van-radio>
            </van-radio-group>
          </template>
        </van-field>
        <template #actions>
          <van-button block round type="primary" @click="onAddQuota">确认添加</van-button>
        </template>
      </DetailSheet>
    </template>
  </div>
</template>

<style scoped lang="scss">
.cd__hero {
  display: flex;
  gap: 14px;
}

.cd__cover {
  width: 110px;
  height: 110px;
  border-radius: $radius-md;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.cd__hero-info { flex: 1; min-width: 0; padding-top: 4px; }

.cd__name {
  font-size: 16px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
  @include ellipsis;
}

.cd__price { font-size: 20px; margin-top: 8px; }

.cd__audit {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px 4px;
  text-align: center;
}

.cd__audit-v { font-size: 16px; }
.cd__audit-l { font-size: 10px; color: $color-text-tertiary; margin-top: 2px; }

.cd__audit-formula {
  margin-top: 12px;
  padding: 8px 10px;
  border-radius: $radius-md;
  background: $color-surface;
  font-size: 11px;
  color: $color-text-secondary;
  line-height: 1.7;
}

.cd__rank {
  width: 18px;
  height: 18px;
  border-radius: 5px;
  background: $color-surface;
  color: $color-text-tertiary;
  font-size: 10px;
  font-weight: 700;
  @include flex-center;
  flex-shrink: 0;
}

.cd__ops {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  padding-bottom: 6px;
}

@media (min-width: 769px) {
  .cd__audit { grid-template-columns: repeat(7, 1fr); }
}
</style>
