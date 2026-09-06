<script setup lang="ts">
/**
 * 藏品详情
 *
 * - 基本信息：图片 / 类目 / 发行方 / 链上信息 / 转赠寄售配置等
 * - 发售信息：状态 / 上下架时间 / 价格 / 销量统计
 * - 库存恒等式：edition = pool + locked + reserved + sold + airdropped + destroyed（按后端 inventoryAudit 返回展示）
 * - 持仓分布：流通量与实际持仓对账
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft } from '@element-plus/icons-vue'
import { fetchCollectibleDetail } from '@/api/collectible'
import { COLLECTIBLE_STATUS, boolTag, datetime, money } from '@/utils/format'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const detail = ref<any>({})

async function load() {
  loading.value = true
  try {
    detail.value = (await fetchCollectibleDetail(Number(route.params.id))) || {}
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** 后端已下架存储值为 off，format.ts 字典键为 delisted */
function statusInfo(status?: string) {
  const key = status === 'off' ? 'delisted' : status
  return COLLECTIBLE_STATUS[key as string] ?? { text: status || '-', type: 'info' }
}

/** 寄售限价模式文案：0 不限价 / 1 固定价 / 2 区间价 */
function resaleModeText(mode: any, min: any, max: any): string {
  const m = Number(mode ?? 0)
  if (m === 1) return `固定价 ¥${money(min)}`
  if (m === 2) return `区间 ¥${money(min)} ~ ¥${money(max)}`
  return '不限价'
}

/** 库存审计结果（恒等式 + 持仓对账），字段与后端 inventoryAudit 一致 */
const audit = computed(() => detail.value.inventoryAudit || {})

function goBack() {
  // 有浏览历史时返回上一页，否则回到藏品列表
  if (window.history.state && window.history.state.back) {
    router.back()
  } else {
    router.push('/collectible')
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
          <span class="title-text">{{ detail.name || '藏品详情' }}</span>
          <el-tag v-if="detail.blindBox" type="warning" size="small">盲盒资产</el-tag>
          <el-tag :type="statusInfo(detail.status).type" size="small">{{ statusInfo(detail.status).text }}</el-tag>
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
          <el-descriptions-item label="藏品 ID">{{ detail.id ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="藏品名称">{{ detail.name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="副标题">{{ detail.subtitle || '-' }}</el-descriptions-item>
          <el-descriptions-item label="所属类目">{{ detail.categoryName || '-' }}</el-descriptions-item>
          <el-descriptions-item label="标签">{{ detail.tag || '-' }}</el-descriptions-item>
          <el-descriptions-item label="首页推荐">{{ boolTag(detail.featured) }}</el-descriptions-item>
          <el-descriptions-item label="可转赠">{{ boolTag(detail.isTransferable) }}</el-descriptions-item>
          <el-descriptions-item label="可寄售">{{ boolTag(detail.isResaleable) }}</el-descriptions-item>
          <el-descriptions-item label="寄售限价">
            {{ resaleModeText(detail.resalePriceMode, detail.resalePriceMin, detail.resalePriceMax) }}
          </el-descriptions-item>
          <el-descriptions-item label="发行方">{{ detail.issuer || '-' }}</el-descriptions-item>
          <el-descriptions-item label="创作者">{{ detail.creator || '-' }}</el-descriptions-item>
          <el-descriptions-item label="品牌">{{ detail.brand || '-' }}</el-descriptions-item>
          <el-descriptions-item label="链类型">{{ detail.chainType || '-' }}</el-descriptions-item>
          <el-descriptions-item label="通证标准">{{ detail.tokenStandard || '-' }}</el-descriptions-item>
          <el-descriptions-item label="合约地址">{{ detail.contract || '-' }}</el-descriptions-item>
          <el-descriptions-item label="简介" :span="3">{{ detail.description || '-' }}</el-descriptions-item>
        </el-descriptions>
      </div>
    </div>

    <!-- 发售信息 -->
    <div class="detail-section">
      <div class="section-title">发售信息</div>
      <el-descriptions :column="3" border>
        <el-descriptions-item label="发售状态">
          <el-tag :type="statusInfo(detail.status).type" size="small">{{ statusInfo(detail.status).text }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="是否上架">{{ boolTag(detail.isRelease) }}</el-descriptions-item>
        <el-descriptions-item label="资格购">{{ boolTag(detail.isQualificationEnabled) }}</el-descriptions-item>
        <el-descriptions-item label="单价（元）">{{ money(detail.price) }}</el-descriptions-item>
        <el-descriptions-item label="每人限购">
          {{ Number(detail.perUserLimit ?? 0) > 0 ? `${detail.perUserLimit} 份` : '不限购' }}
        </el-descriptions-item>
        <el-descriptions-item label="发行日期">{{ datetime(detail.releaseDate) }}</el-descriptions-item>
        <el-descriptions-item label="开售时间">{{ datetime(detail.onsaleAt) }}</el-descriptions-item>
        <el-descriptions-item label="下架时间">{{ datetime(detail.offSaleAt) }}</el-descriptions-item>
        <el-descriptions-item label="创建时间">{{ datetime(detail.createdAt) }}</el-descriptions-item>
        <el-descriptions-item label="已售数量">{{ detail.sold ?? 0 }} 份</el-descriptions-item>
        <el-descriptions-item label="已空投数量">{{ detail.airdroppedCount ?? 0 }} 份</el-descriptions-item>
        <el-descriptions-item label="流通量">{{ detail.circulate ?? 0 }} 份</el-descriptions-item>
      </el-descriptions>
    </div>

    <!-- 库存恒等式 -->
    <div class="detail-section">
      <div class="section-title">库存恒等式</div>
      <el-alert
        :type="audit.identityOk ? 'success' : 'error'"
        :closable="false"
        show-icon
        :title="audit.identityOk ? '库存恒等式成立' : '库存恒等式异常'"
        :description="audit.identityDesc"
      />
      <div class="identity-formula">
        发行总量 <b>{{ audit.edition ?? '-' }}</b> = 库存池 <b>{{ audit.pool ?? '-' }}</b> + 待支付锁定
        <b>{{ audit.locked ?? '-' }}</b> + 已配置配额 <b>{{ audit.reserved ?? '-' }}</b> + 已售出
        <b>{{ audit.sold ?? '-' }}</b> + 已空投 <b>{{ audit.airdropped ?? '-' }}</b> + 已销毁
        <b>{{ audit.destroyed ?? '-' }}</b>
      </div>
      <el-descriptions :column="4" border style="margin-top: 12px">
        <el-descriptions-item label="发行总量（edition）">{{ audit.edition ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="库存池（pool）">{{ audit.pool ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="待支付锁定（locked）">{{ audit.locked ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="已配置配额（reserved）">{{ audit.reserved ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="已售出（sold）">{{ audit.sold ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="已空投（airdropped）">{{ audit.airdropped ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="已销毁（destroyed）">{{ audit.destroyed ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="流通对账">
          <el-tag :type="audit.circulateOk ? 'success' : 'danger'" size="small">
            {{ audit.circulateOk ? '流通量一致' : '流通量异常' }}
          </el-tag>
        </el-descriptions-item>
      </el-descriptions>
    </div>

    <!-- 持仓分布 -->
    <div class="detail-section">
      <div class="section-title">持仓分布</div>
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">流通量（circulate）</div>
            <div class="stat-value">{{ audit.circulate ?? '-' }}</div>
            <div class="stat-sub">台账口径</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">活跃持仓</div>
            <div class="stat-value">{{ audit.activeHold ?? '-' }}</div>
            <div class="stat-sub">持有中 / 寄售中 / 冻结合计</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">已消耗</div>
            <div class="stat-value">{{ audit.consumed ?? '-' }}</div>
            <div class="stat-sub">开盒消耗（consumed）</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">已回收</div>
            <div class="stat-value">{{ audit.recovered ?? '-' }}</div>
            <div class="stat-sub">资产回收（recovered）</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">期望流通量</div>
            <div class="stat-value">{{ audit.expectedCirculate ?? '-' }}</div>
            <div class="stat-sub">已售 + 已空投 - 已回收</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-info">
            <div class="stat-label">持仓差异</div>
            <div class="stat-value" :class="{ 'gap-bad': Number(audit.holdingGap ?? 0) !== 0 }">
              {{ audit.holdingGap ?? '-' }}
            </div>
            <div class="stat-sub">流通 - 活跃持仓 - 已消耗</div>
          </div>
        </div>
      </div>
      <el-alert
        :type="audit.holdingOk ? 'success' : 'error'"
        :closable="false"
        show-icon
        :title="audit.holdingOk ? '流通量与实际持仓一致' : '流通量与实际持仓存在差异'"
        :description="audit.holdingDesc"
        style="margin-top: 12px"
      />
      <el-descriptions :column="3" border style="margin-top: 12px">
        <el-descriptions-item label="流通量（circulate）">{{ audit.circulate ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="期望流通量">{{ audit.expectedCirculate ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="持仓差异（holdingGap）">{{ audit.holdingGap ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="活跃持仓">{{ audit.activeHold ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="已消耗">{{ audit.consumed ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="已回收">{{ audit.recovered ?? '-' }}</el-descriptions-item>
      </el-descriptions>
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

.identity-formula {
  margin-top: 12px;
  padding: 10px 14px;
  border-radius: 6px;
  background: var(--sn-red-bg);
  font-size: 13px;
  line-height: 1.8;
  color: var(--sn-text);

  b {
    color: var(--sn-red);
    font-variant-numeric: tabular-nums;
  }
}

.stat-value.gap-bad {
  color: #c45656;
}

.img-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: #9ca3af;
}
</style>
