<template>
  <div v-loading="loading" class="page-container">
    <!-- 顶部：返回 + 藏品概要 -->
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push('/collectible')">返回列表</el-button>
        <el-image :src="detail?.image" fit="cover" class="head-cover">
          <template #error>
            <div class="head-cover head-cover--fallback"><el-icon><Picture /></el-icon></div>
          </template>
        </el-image>
        <div class="head-meta">
          <div class="head-name">
            <h3>{{ detail?.name || '—' }}</h3>
            <el-tag v-if="detail" :type="STATUS_TAG[detail.status] ?? 'info'" size="small">
              {{ STATUS_MAP[detail.status] ?? detail.status }}
            </el-tag>
            <el-tag v-if="detail?.isBlindbox" type="warning" size="small" effect="plain">盲盒藏品</el-tag>
          </div>
          <span class="head-sub">{{ detail?.subtitle || '暂无简介' }}</span>
        </div>
      </div>
      <div class="head-actions">
        <el-button v-if="detail?.status === 'draft'" v-permission="'collectible:edit'" @click="router.push(`/collectible/${id}/edit`)">编辑</el-button>
        <el-button v-if="detail && ['draft', 'upcoming'].includes(detail.status)" v-permission="'collectible:release'" type="primary" @click="router.push(`/collectible/${id}/release`)">发售配置</el-button>
        <el-button v-permission="'collectible:audit'" @click="router.push(`/collectible/${id}/audit`)">库存审计</el-button>
      </div>
    </div>

    <template v-if="detail">
      <!-- 库存五数（文档 11.2-13） -->
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
            <el-descriptions-item label="藏品ID">{{ detail.id }}</el-descriptions-item>
            <el-descriptions-item label="分类">{{ detail.category }}</el-descriptions-item>
            <el-descriptions-item label="发售价格">
              <span class="din amount">¥{{ detail.price }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="每人限购">{{ detail.perUserLimit > 0 ? `${detail.perUserLimit} 份` : '不限购' }}</el-descriptions-item>
            <el-descriptions-item label="计划发售数量">{{ detail.releaseQuantity ?? '不限' }}</el-descriptions-item>
            <el-descriptions-item label="实际可卖">{{ detail.saleable }}</el-descriptions-item>
            <el-descriptions-item label="发售时间">{{ detail.onsaleAt || '—' }}</el-descriptions-item>
            <el-descriptions-item label="下架时间">{{ detail.offSaleAt || '—' }}</el-descriptions-item>
            <el-descriptions-item label="寄售开关">
              <el-tag :type="detail.isResaleable ? 'success' : 'danger'" size="small">{{ detail.isResaleable ? '开启' : '关闭' }}</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="转赠开关">
              <el-tag :type="detail.isTransferable ? 'success' : 'danger'" size="small">{{ detail.isTransferable ? '开启' : '关闭' }}</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="寄售限价">
              {{ detail.resalePriceMode === 1 ? `¥${detail.resalePriceMin ?? '—'} ~ ¥${detail.resalePriceMax ?? '—'}` : '不限价' }}
            </el-descriptions-item>
            <el-descriptions-item label="标签">{{ detail.tag || '—' }}</el-descriptions-item>
            <el-descriptions-item label="发行方">{{ detail.issuer || '—' }}</el-descriptions-item>
            <el-descriptions-item label="创作者">{{ detail.creator || '—' }}</el-descriptions-item>
            <el-descriptions-item label="创建时间">{{ detail.createdAt }}</el-descriptions-item>
            <el-descriptions-item label="更新时间">{{ detail.updatedAt }}</el-descriptions-item>
          </el-descriptions>
          <div v-if="detail.description" class="story-block">
            <div class="card-title" style="margin-top: 14px">创作故事</div>
            <p class="story-text">{{ detail.description }}</p>
          </div>
        </div>

        <!-- 资格购配置（文档 5.1：发售配置内 Switch 开启；6.1：仅草稿可配置） -->
        <div class="sn-card">
          <div class="card-title-row">
            <span class="card-title">资格购配置（与优先购完全独立，文档 5.1）</span>
            <el-button
              v-if="detail.status === 'draft'"
              v-permission="'collectible:qualification'"
              text
              type="primary"
              @click="router.push(`/collectible/${id}/release`)"
            >
              配置
            </el-button>
          </div>
          <template v-if="detail.qualification">
            <el-descriptions :column="1" border size="small">
              <el-descriptions-item label="开关状态">
                <el-tag :type="detail.qualification.isEnabled ? 'success' : 'info'" size="small">{{ detail.qualification.isEnabled ? '已开启' : '已关闭' }}</el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="条件组合">
                {{ detail.qualification.conditionType === 2 ? '满足全部条件' : '满足任一条件' }}
              </el-descriptions-item>
              <el-descriptions-item label="持有指定藏品">
                {{ detail.qualification.requiredCollectibleIds.length ? detail.qualification.requiredCollectibleIds.map((v) => `#${v}`).join('、') : '不限' }}
              </el-descriptions-item>
              <el-descriptions-item label="累计签到">{{ detail.qualification.requiredCheckinDays }} 天</el-descriptions-item>
              <el-descriptions-item label="邀请人数">{{ detail.qualification.requiredInviteCount }} 人</el-descriptions-item>
              <el-descriptions-item label="资格有效期">
                {{ detail.qualification.validStartAt || '—' }} ~ {{ detail.qualification.validEndAt || '—' }}
              </el-descriptions-item>
              <el-descriptions-item label="白名单">
                {{ detail.qualification.whitelist.length ? `${detail.qualification.whitelist.length} 人` : '未配置' }}
              </el-descriptions-item>
            </el-descriptions>
          </template>
          <el-empty v-else description="未配置资格购" :image-size="60" />
        </div>
      </div>

      <!-- 配额 -->
      <div class="sn-card">
        <div class="card-title-row">
          <span class="card-title">配额列表（文档 4.3.2）</span>
          <el-button v-permission="'collectible:quota'" text type="primary" @click="router.push(`/collectible/${id}/quota`)">配额管理</el-button>
        </div>
        <el-table :data="detail.quotas" size="small" empty-text="暂无配额">
          <el-table-column label="配额名称" prop="quotaName" min-width="140" />
          <el-table-column label="类型" width="110">
            <template #default="{ row }">{{ QUOTA_TYPE_MAP[row.quotaType] ?? row.quotaType }}</template>
          </el-table-column>
          <el-table-column label="计划数量" prop="plannedQuantity" width="100" align="right" />
          <el-table-column label="已使用" prop="usedQuantity" width="90" align="right" />
          <el-table-column label="剩余" width="90" align="right">
            <template #default="{ row }">
              <span class="din">{{ row.plannedQuantity - row.usedQuantity }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="创建时间" prop="createdAt" min-width="160" />
        </el-table>
      </div>

      <!-- 空投 / 销毁记录 -->
      <div class="sn-card">
        <el-tabs v-model="recordTab">
          <el-tab-pane label="空投记录" name="airdrop">
            <el-table v-loading="airdropLoading" :data="airdropRecords" size="small" empty-text="暂无空投记录">
              <el-table-column label="用户" prop="username" min-width="120" />
              <el-table-column label="手机号" prop="phone" width="130" />
              <el-table-column label="数量" prop="quantity" width="70" align="right" />
              <el-table-column label="来源" prop="source" min-width="120" />
              <el-table-column label="发放时间" prop="issuedAt" min-width="160" />
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
      <el-empty description="藏品不存在">
        <el-button type="primary" @click="router.push('/collectible')">返回列表</el-button>
      </el-empty>
    </div>
  </div>
</template>

<script setup lang="ts">
// 藏品详情（文档 8.6 #35：库存五数 + 全部开关 + 配额 + 空投/销毁记录）
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchCollectibleDetail, fetchAirdropRecords, fetchDestroyRecords } from '@/api/collectible'
import type { AirdropRecordRow, CollectibleDetail, DestroyRecordRow } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const STATUS_MAP: Record<string, string> = {
  draft: '草稿', upcoming: '待发售', onsale: '发售中', soldout: '已售罄', off: '已下架'
}
const STATUS_TAG: Record<string, string> = {
  draft: 'info', upcoming: 'warning', onsale: 'success', soldout: 'danger', off: ''
}
/** 配额类型（文档 4.3.2） */
const QUOTA_TYPE_MAP: Record<number, string> = {
  1: '优先购', 2: '活动空投', 3: '签到', 4: '注册', 5: '邀请', 6: '抽奖', 7: '其他'
}

const loading = ref(false)
const detail = ref<CollectibleDetail | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchCollectibleDetail(id)
    await Promise.all([loadAirdropRecords(), loadDestroyRecords()])
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 空投/销毁记录（#49 / #50） ----------------
const recordTab = ref('airdrop')
const airdropLoading = ref(false)
const airdropRecords = ref<AirdropRecordRow[]>([])
const destroyLoading = ref(false)
const destroyRecords = ref<DestroyRecordRow[]>([])

async function loadAirdropRecords(): Promise<void> {
  airdropLoading.value = true
  try {
    const data = await fetchAirdropRecords(id, { page: 1, pageSize: 10 })
    airdropRecords.value = data.list
  } catch {
    // 拦截器已提示
  } finally {
    airdropLoading.value = false
  }
}

async function loadDestroyRecords(): Promise<void> {
  destroyLoading.value = true
  try {
    const data = await fetchDestroyRecords(id, { page: 1, pageSize: 10 })
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
  grid-template-columns: 3fr 2fr;
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

.story-block {
  .story-text {
    margin: 0;
    font-size: 13px;
    line-height: 1.8;
    color: $sn-text-sub;
    background: $sn-bg;
    border-radius: 8px;
    padding: 12px;
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
