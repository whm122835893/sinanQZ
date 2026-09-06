<script setup lang="ts">
/**
 * 盲盒详情
 *
 * - 基本信息：盲盒（父藏品）名称 / 类目 / 价格 / 可开盒状态等
 * - 奖品配置列表：blind_box_items（奖品藏品 / 概率 / 限量 / 已发放 / 发放进度）
 * - 开盒记录统计：openedCount 与奖池发放总数对账、库存池分布
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft } from '@element-plus/icons-vue'
import { fetchBlindBoxDetail } from '@/api/collectible'
import { COLLECTIBLE_STATUS, boolTag, datetime, money } from '@/utils/format'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const detail = ref<any>({})

async function load() {
  loading.value = true
  try {
    detail.value = (await fetchBlindBoxDetail(Number(route.params.id))) || {}
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** 盲盒状态沿用父藏品状态；后端已下架存储值为 off，format.ts 字典键为 delisted */
function statusInfo(status?: string) {
  const key = status === 'off' ? 'delisted' : status
  return COLLECTIBLE_STATUS[key as string] ?? { text: status || '-', type: 'info' }
}

/** 概率（0~1）转百分比展示 */
function percent(v: any): string {
  const n = Number(v ?? 0)
  return `${(n * 100).toFixed(2)}%`
}

/** 奖品配置（blind_box_items，已驼峰化） */
const items = computed<any[]>(() => detail.value.items ?? [])

/** 开盒对账统计（后端 audit 字段：pool / issuedTotal / openedCount / issuedMatchesOpened） */
const audit = computed(() => detail.value.audit || {})

function goBack() {
  // 有浏览历史时返回上一页，否则回到盲盒列表
  if (window.history.state && window.history.state.back) {
    router.back()
  } else {
    router.push('/blindbox')
  }
}
</script>

<template>
  <div v-loading="loading" class="page-container">
    <!-- 头部：返回 + 标题 -->
    <div class="detail-section">
      <div class="detail-header">
        <el-button :icon="ArrowLeft" @click="goBack">返回</el-button>
        <div class="detail-title">
          <span class="title-text">{{ detail.name || '盲盒详情' }}</span>
          <el-tag :type="statusInfo(detail.status).type" size="small">{{ statusInfo(detail.status).text }}</el-tag>
          <el-tag :type="detail.probabilityOk ? 'success' : 'danger'" size="small">
            概率合计 {{ Number(detail.probabilitySum ?? 0).toFixed(4) }}
          </el-tag>
        </div>
      </div>
    </div>

    <!-- 基本信息 -->
    <div class="detail-section">
      <div class="section-title">基本信息</div>
      <div class="base-info">
        <el-image
          class="base-img"
          :src="detail.image"
          fit="cover"
          :preview-src-list="detail.image ? [detail.image] : []"
          preview-teleported
          hide-on-click-modal
        >
          <template #error>
            <div class="img-fallback">暂无图片</div>
          </template>
        </el-image>
        <el-descriptions :column="3" border class="base-desc">
          <el-descriptions-item label="盲盒 ID">{{ detail.id ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="关联藏品 ID">{{ detail.collectibleId ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="盲盒名称">{{ detail.name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="所属类目">{{ detail.categoryName || '-' }}</el-descriptions-item>
          <el-descriptions-item label="单价（元）">{{ money(detail.price) }}</el-descriptions-item>
          <el-descriptions-item label="发行总量">{{ detail.edition ?? '-' }} 份</el-descriptions-item>
          <el-descriptions-item label="已售数量">{{ detail.sold ?? 0 }} 份</el-descriptions-item>
          <el-descriptions-item label="可开盒">{{ boolTag(detail.isOpenable) }}</el-descriptions-item>
          <el-descriptions-item label="已开盒数">{{ detail.openedCount ?? 0 }} 次</el-descriptions-item>
          <el-descriptions-item label="开售时间">{{ datetime(detail.onsaleAt) }}</el-descriptions-item>
          <el-descriptions-item label="下架时间">{{ datetime(detail.offSaleAt) }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ datetime(detail.createdAt) }}</el-descriptions-item>
          <el-descriptions-item label="描述" :span="3">{{ detail.description || '-' }}</el-descriptions-item>
        </el-descriptions>
      </div>
    </div>

    <!-- 奖品配置列表 -->
    <div class="detail-section">
      <div class="section-title">奖品配置（blind_box_items）</div>
      <div class="prize-summary">
        <span>共 {{ items.length }} 个奖品</span>
        <span>
          概率合计
          <b :class="detail.probabilityOk ? 'ok' : 'bad'">{{ Number(detail.probabilitySum ?? 0).toFixed(4) }}</b>
          <el-tag :type="detail.probabilityOk ? 'success' : 'danger'" size="small" style="margin-left: 4px">
            {{ detail.probabilityOk ? '合规（= 1）' : '不合规（须为 1）' }}
          </el-tag>
        </span>
      </div>
      <el-table :data="items" border stripe>
        <el-table-column label="奖品藏品" min-width="230">
          <template #default="{ row }">
            <div class="cell-collectible">
              <el-image
                class="table-img"
                :src="row.prizeImage"
                fit="cover"
                :preview-src-list="row.prizeImage ? [row.prizeImage] : []"
                preview-teleported
                hide-on-click-modal
              >
                <template #error>
                  <div class="img-fallback">暂无图片</div>
                </template>
              </el-image>
              <div class="collectible-info">
                <div class="name">{{ row.prizeName || '-' }}</div>
                <div class="sub">奖品 ID：{{ row.prizeCollectibleId ?? '-' }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="概率" width="100" align="center">
          <template #default="{ row }">{{ percent(row.probability) }}</template>
        </el-table-column>
        <el-table-column label="限量" width="90" align="right">
          <template #default="{ row }">{{ row.quantityLimit != null ? row.quantityLimit : '不限' }}</template>
        </el-table-column>
        <el-table-column prop="quantityDistributed" label="已发放" width="80" align="right" />
        <el-table-column label="发放进度" width="150">
          <template #default="{ row }">
            <el-progress
              v-if="row.quantityLimit"
              :percentage="Math.min(100, Math.round((Number(row.quantityDistributed ?? 0) / Number(row.quantityLimit)) * 100))"
              :stroke-width="8"
            />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="奖品单价（元）" width="110" align="right">
          <template #default="{ row }">{{ money(row.prizePrice) }}</template>
        </el-table-column>
      </el-table>
    </div>

    <!-- 开盒记录统计 -->
    <div class="detail-section">
      <div class="section-title">开盒记录统计</div>
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">已开盒数（openedCount）</div>
            <div class="stat-value">{{ audit.openedCount ?? 0 }}</div>
            <div class="stat-sub">盲盒开启次数</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">奖池累计发放</div>
            <div class="stat-value">{{ audit.issuedTotal ?? 0 }}</div>
            <div class="stat-sub">各奖品已发放之和</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">库存池（pool）</div>
            <div class="stat-value">{{ audit.pool ?? 0 }}</div>
            <div class="stat-sub">发行 - 已售 - 锁定 - 空投 - 销毁</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">已售（sold）</div>
            <div class="stat-value">{{ detail.sold ?? 0 }}</div>
            <div class="stat-sub">盲盒售出数量</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">已空投（airdropped）</div>
            <div class="stat-value">{{ detail.airdroppedCount ?? 0 }}</div>
            <div class="stat-sub">独立空投发放</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">已销毁（destroyed）</div>
            <div class="stat-value">{{ detail.destroyedCount ?? 0 }}</div>
            <div class="stat-sub">销毁台账累计</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">待支付锁定（locked）</div>
            <div class="stat-value">{{ detail.lockedQuantity ?? 0 }}</div>
            <div class="stat-sub">未支付订单占用</div>
          </div>
        </div>
      </div>
      <el-alert
        :type="audit.issuedMatchesOpened ? 'success' : 'error'"
        :closable="false"
        show-icon
        :title="
          audit.issuedMatchesOpened
            ? `开盒对账一致：已开盒 ${audit.openedCount ?? 0} 次 = 奖池累计发放 ${audit.issuedTotal ?? 0} 份`
            : `开盒对账异常：已开盒 ${audit.openedCount ?? 0} 次 ≠ 奖池累计发放 ${audit.issuedTotal ?? 0} 份`
        "
        style="margin-top: 12px"
      />
    </div>
  </div>
</template>

<style scoped lang="scss">
.detail-header {
  display: flex;
  align-items: center;
  gap: 14px;

  .detail-title {
    display: flex;
    align-items: center;
    gap: 8px;

    .title-text {
      font-size: 16px;
      font-weight: 600;
    }
  }
}

.base-info {
  display: flex;
  align-items: flex-start;
  gap: 16px;

  .base-img {
    width: 120px;
    height: 120px;
    flex-shrink: 0;
    border-radius: 8px;
    background: #f3f4f6;
  }

  .base-desc {
    flex: 1;
    min-width: 0;
  }
}

.prize-summary {
  display: flex;
  align-items: center;
  gap: 24px;
  margin-bottom: 12px;
  font-size: 13px;
  color: var(--sn-text-secondary);

  b {
    font-variant-numeric: tabular-nums;
    font-size: 15px;
    margin-left: 4px;

    &.ok {
      color: #2e7d32;
    }

    &.bad {
      color: #c45656;
    }
  }
}

.cell-collectible {
  display: flex;
  align-items: center;
  gap: 10px;

  .collectible-info {
    min-width: 0;
    flex: 1;

    .name {
      font-weight: 500;
      color: var(--sn-text);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .sub {
      margin-top: 2px;
      font-size: 12px;
      color: var(--sn-text-secondary);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }
}

.img-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: #9ca3af;
  background: #f3f4f6;
}
</style>
