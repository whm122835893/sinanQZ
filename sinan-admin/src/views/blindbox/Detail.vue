<template>
  <div v-loading="loading" class="page-container">
    <!-- 顶部：返回 + 盲盒概要 -->
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push('/blindbox')">返回列表</el-button>
        <el-image :src="detail?.image" fit="cover" class="head-cover">
          <template #error>
            <div class="head-cover head-cover--fallback"><el-icon><Box /></el-icon></div>
          </template>
        </el-image>
        <div class="head-meta">
          <div class="head-name">
            <h3>{{ detail?.name || '—' }}</h3>
            <el-tag v-if="detail" :type="STATUS_TAG[detail.status] ?? 'info'" size="small">
              {{ STATUS_MAP[detail.status] ?? detail.status }}
            </el-tag>
          </div>
          <span class="head-sub">{{ detail?.description || '暂无简介' }}</span>
        </div>
      </div>
      <div class="head-actions">
        <el-button v-if="detail && ['draft', 'off'].includes(detail.status)" v-permission="'blindbox:edit'" @click="router.push(`/blindbox/${id}/edit`)">编辑</el-button>
        <el-button v-if="detail && ['draft', 'off'].includes(detail.status)" v-permission="'blindbox:config'" @click="router.push(`/blindbox/${id}/items`)">子藏品配置</el-button>
        <el-button v-if="detail && ['draft', 'upcoming'].includes(detail.status)" v-permission="'blindbox:release'" type="primary" @click="router.push(`/blindbox/${id}/release`)">发售配置</el-button>
        <el-button v-permission="'blindbox:audit'" @click="router.push(`/blindbox/${id}/audit`)">库存审计</el-button>
      </div>
    </div>

    <template v-if="detail">
      <!-- 库存五数（文档 11.2-13 / 4.3.3） -->
      <div class="stock-grid">
        <div class="sn-card stock-card">
          <span class="stock-label">发行总量</span>
          <span class="stock-value din">{{ detail.edition }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">已售出发售</span>
          <span class="stock-value din">{{ detail.sold }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">已配置配额</span>
          <span class="stock-value din">{{ detail.reservedCount }}</span>
        </div>
        <div class="sn-card stock-card" :class="{ 'stock-card--danger': detail.stockPool <= 5 && ['onsale', 'upcoming'].includes(detail.status) }">
          <span class="stock-label">库存池</span>
          <span class="stock-value din">{{ detail.stockPool }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">流通量</span>
          <span class="stock-value din">{{ detail.circulate }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">锁定(待支付)</span>
          <span class="stock-value din">{{ detail.lockedQuantity }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">已空投</span>
          <span class="stock-value din">{{ detail.airdroppedCount }}</span>
        </div>
        <div class="sn-card stock-card">
          <span class="stock-label">已销毁</span>
          <span class="stock-value din">{{ detail.destroyedCount }}</span>
        </div>
      </div>

      <div class="detail-grid">
        <!-- 基础信息 -->
        <div class="sn-card">
          <div class="card-title">基础信息</div>
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="盲盒ID">{{ detail.id }}</el-descriptions-item>
            <el-descriptions-item label="藏品行ID">{{ detail.collectibleId }}</el-descriptions-item>
            <el-descriptions-item label="发售价格">
              <span class="din amount">¥{{ detail.price }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="每人限购">{{ detail.perUserLimit > 0 ? `${detail.perUserLimit} 份` : '不限购' }}</el-descriptions-item>
            <el-descriptions-item label="计划发售数量">{{ detail.releaseQuantity ?? '不限' }}</el-descriptions-item>
            <el-descriptions-item label="实际可卖">{{ detail.saleable }}</el-descriptions-item>
            <el-descriptions-item label="发售时间">{{ detail.onsaleAt || '—' }}</el-descriptions-item>
            <el-descriptions-item label="下架时间">{{ detail.offSaleAt || '—' }}</el-descriptions-item>
            <el-descriptions-item label="可开启">
              <el-tag :type="detail.isOpenable ? 'success' : 'danger'" size="small">{{ detail.isOpenable ? '是' : '否' }}</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="可转赠">
              <el-tag :type="detail.isTransferable ? 'success' : 'danger'" size="small">{{ detail.isTransferable ? '是' : '否' }}</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="可寄售">
              <el-tag :type="detail.isResaleable ? 'success' : 'danger'" size="small">{{ detail.isResaleable ? '是' : '否' }}</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="创建时间">{{ detail.createdAt }}</el-descriptions-item>
            <el-descriptions-item label="更新时间">{{ detail.updatedAt }}</el-descriptions-item>
          </el-descriptions>
        </div>

        <!-- 子藏品与概率（#53 items；可视化进度条，文档 5.4.2） -->
        <div class="sn-card">
          <div class="card-title-row">
            <span class="card-title">子藏品与概率（共 {{ detail.items.length }} 个）</span>
            <el-button
              v-if="['draft', 'off'].includes(detail.status)"
              v-permission="'blindbox:config'"
              text
              type="primary"
              @click="router.push(`/blindbox/${id}/items`)"
            >
              配置
            </el-button>
          </div>
          <el-empty v-if="!detail.items.length" description="尚未配置子藏品" :image-size="60" />
          <template v-else>
            <div class="prob-sum">
              <span class="prob-sum-label">概率总和</span>
              <el-progress
                :percentage="Math.round(detail.probabilitySum * 10000) / 100"
                :status="detail.probabilitySum > 1.0001 ? 'exception' : 'success'"
                :stroke-width="14"
                text-inside
              />
            </div>
            <el-table :data="detail.items" size="small">
              <el-table-column label="子藏品" min-width="180">
                <template #default="{ row }">
                  <div class="cell-item">
                    <el-image :src="row.image" fit="cover" class="item-cover">
                      <template #error>
                        <div class="item-cover item-cover--fallback"><el-icon><Picture /></el-icon></div>
                      </template>
                    </el-image>
                    <div class="item-meta">
                      <span class="item-name">{{ row.name }}</span>
                      <span class="item-sub">#{{ row.collectibleId }} · 流通 {{ row.prizeCirculate }}/{{ row.prizeEdition }}</span>
                    </div>
                  </div>
                </template>
              </el-table-column>
              <el-table-column label="概率" min-width="150">
                <template #default="{ row }">
                  <el-progress
                    :percentage="Math.round(row.probability * 10000) / 100"
                    :stroke-width="12"
                    :color="SN_CHART_COLORS[0]"
                  />
                </template>
              </el-table-column>
              <el-table-column label="计划数量" width="90" align="right">
                <template #default="{ row }">{{ row.plannedQuantity ?? '不限' }}</template>
              </el-table-column>
              <el-table-column label="已发放" prop="distributed" width="80" align="right" />
            </el-table>
          </template>
        </div>
      </div>

      <!-- 开盒记录 / 销毁记录 -->
      <div class="sn-card">
        <el-tabs v-model="recordTab">
          <el-tab-pane label="开盒记录" name="open">
            <el-table v-loading="openLoading" :data="openRecords" size="small" empty-text="暂无开盒记录">
              <el-table-column label="用户" prop="username" min-width="120" />
              <el-table-column label="奖品" min-width="200">
                <template #default="{ row }">
                  <div class="cell-prize">
                    <el-image :src="row.prizeImage" fit="cover" class="prize-cover">
                      <template #error>
                        <div class="prize-cover prize-cover--fallback"><el-icon><Picture /></el-icon></div>
                      </template>
                    </el-image>
                    <span>{{ row.prizeName }}</span>
                  </div>
                </template>
              </el-table-column>
              <el-table-column label="奖品编号" prop="serial" min-width="150" />
              <el-table-column label="开启时间" prop="openedAt" min-width="170" />
            </el-table>
          </el-tab-pane>
          <el-tab-pane label="销毁记录" name="destroy">
            <el-table v-loading="destroyLoading" :data="destroyRecords" size="small" empty-text="暂无销毁记录">
              <el-table-column label="数量" prop="quantity" width="80" align="right" />
              <el-table-column label="原因" prop="reason" min-width="180" show-overflow-tooltip />
              <el-table-column label="操作人" prop="adminName" width="110" />
              <el-table-column label="IP" prop="ip" width="130" />
              <el-table-column label="时间" prop="createdAt" min-width="160" />
            </el-table>
          </el-tab-pane>
        </el-tabs>
      </div>
    </template>

    <div v-else-if="!loading" class="sn-card error-card">
      <el-empty description="盲盒不存在">
        <el-button type="primary" @click="router.push('/blindbox')">返回列表</el-button>
      </el-empty>
    </div>
  </div>
</template>

<script setup lang="ts">
// 盲盒详情（文档 8.7 #53：库存五数 + 子藏品与概率 + 开盒/销毁记录）
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchBlindboxDetail, fetchOpenRecords, fetchBlindboxDestroyRecords } from '@/api/blindbox'
import { SN_CHART_COLORS } from '@/utils/charts'
import type { BlindboxDetail, BlindboxOpenRecordRow, DestroyRecordRow } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const STATUS_MAP: Record<string, string> = {
  draft: '草稿', upcoming: '待发售', onsale: '发售中', soldout: '已售罄', off: '已下架'
}
const STATUS_TAG: Record<string, string> = {
  draft: 'info', upcoming: 'warning', onsale: 'success', soldout: 'danger', off: ''
}

const loading = ref(false)
const detail = ref<BlindboxDetail | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchBlindboxDetail(id)
    await Promise.all([loadOpenRecords(), loadDestroyRecords()])
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 开盒/销毁记录（#65 / #67） ----------------
const recordTab = ref('open')
const openLoading = ref(false)
const openRecords = ref<BlindboxOpenRecordRow[]>([])
const destroyLoading = ref(false)
const destroyRecords = ref<DestroyRecordRow[]>([])

async function loadOpenRecords(): Promise<void> {
  openLoading.value = true
  try {
    const data = await fetchOpenRecords(id, { page: 1, pageSize: 10 })
    openRecords.value = data.list
  } catch {
    // 拦截器已提示
  } finally {
    openLoading.value = false
  }
}

async function loadDestroyRecords(): Promise<void> {
  destroyLoading.value = true
  try {
    const data = await fetchBlindboxDestroyRecords(id, { page: 1, pageSize: 10 })
    destroyRecords.value = data.list
  } catch {
    // 拦截器已提示
  } finally {
    destroyLoading.value = false
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  .head-left {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
  }

  .head-cover {
    width: 64px;
    height: 64px;
    border-radius: 10px;
    flex-shrink: 0;
    background: $sn-surface;

    &--fallback {
      display: flex;
      align-items: center;
      justify-content: center;
      color: $sn-text-muted;
    }
  }

  .head-meta {
    min-width: 0;

    .head-name {
      display: flex;
      align-items: center;
      gap: 8px;

      h3 {
        margin: 0;
        font-size: 17px;
        font-weight: 600;
        color: $sn-text-main;
      }
    }

    .head-sub {
      display: block;
      font-size: 12px;
      color: $sn-text-muted;
      margin-top: 3px;
      max-width: 420px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }
}

.stock-grid {
  display: grid;
  grid-template-columns: repeat(8, 1fr);
  gap: 10px;
}

.stock-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px;

  .stock-label {
    font-size: 12px;
    color: $sn-text-sub;
  }

  .stock-value {
    font-size: 22px;
    font-weight: 600;
    color: $sn-text-main;
  }

  &--danger .stock-value {
    color: $sn-danger;
  }
}

.detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  align-items: start;
}

.card-title {
  font-size: 14px;
  font-weight: 600;
  color: $sn-text-main;
  margin-bottom: 12px;
}

.card-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;

  .card-title {
    margin-bottom: 0;
  }
}

.prob-sum {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;

  .prob-sum-label {
    font-size: 13px;
    color: $sn-text-sub;
    flex-shrink: 0;
  }

  .el-progress {
    flex: 1;
  }
}

.cell-item {
  display: flex;
  align-items: center;
  gap: 10px;

  .item-cover {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    flex-shrink: 0;
    background: $sn-surface;

    &--fallback {
      display: flex;
      align-items: center;
      justify-content: center;
      color: $sn-text-muted;
    }
  }

  .item-meta {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;

    .item-name {
      font-weight: 500;
      color: $sn-text-main;
      @include ellipsis;
    }

    .item-sub {
      font-size: 12px;
      color: $sn-text-muted;
    }
  }
}

.cell-prize {
  display: flex;
  align-items: center;
  gap: 8px;

  .prize-cover {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    flex-shrink: 0;
    background: $sn-surface;

    &--fallback {
      display: flex;
      align-items: center;
      justify-content: center;
      color: $sn-text-muted;
    }
  }
}

.error-card {
  min-height: 240px;
  display: flex;
  align-items: center;
  justify-content: center;
}

@media (max-width: 1365px) {
  .stock-grid {
    grid-template-columns: repeat(4, 1fr);
  }

  .detail-grid {
    grid-template-columns: 1fr;
  }
}
</style>
