<template>
  <div v-loading="loading" class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push(`/blindbox/${id}`)">返回详情</el-button>
        <div>
          <h3 class="head-title">盲盒库存审计</h3>
          <span class="head-sub">{{ detail?.name || '—' }} · 守恒校验（文档 4.3.3 / 8.7 #66）</span>
        </div>
      </div>
      <el-button :loading="loading" @click="load">重新校验</el-button>
    </div>

    <template v-if="audit">
      <!-- 总体结论 -->
      <el-alert
        :type="audit.ok ? 'success' : 'error'"
        :closable="false"
        show-icon
        :title="audit.ok ? '审计通过：库存守恒与持有校验全部一致' : '审计未通过：存在不一致项，请核查下方明细'"
        style="margin-bottom: 12px"
      />

      <!-- 守恒校验 -->
      <div class="sn-card">
        <div class="card-title-row">
          <span class="card-title">① 盲盒库存守恒校验</span>
          <el-tag :type="audit.conservation.ok ? 'success' : 'danger'" size="small">
            {{ audit.conservation.ok ? '一致' : '不一致' }}
          </el-tag>
        </div>
        <div class="formula">{{ audit.conservation.formula }}</div>
        <div class="counter-grid">
          <div class="counter-item">
            <span class="counter-label">库存池</span>
            <span class="counter-value din">{{ audit.counters.stockPool }}</span>
          </div>
          <div class="counter-operator">+</div>
          <div class="counter-item">
            <span class="counter-label">锁定(待支付)</span>
            <span class="counter-value din">{{ audit.counters.lockedQuantity }}</span>
          </div>
          <div class="counter-operator">+</div>
          <div class="counter-item">
            <span class="counter-label">配额预留</span>
            <span class="counter-value din">{{ audit.counters.reservedCount }}</span>
          </div>
          <div class="counter-operator">+</div>
          <div class="counter-item">
            <span class="counter-label">已售出</span>
            <span class="counter-value din">{{ audit.counters.sold }}</span>
          </div>
          <div class="counter-operator">+</div>
          <div class="counter-item">
            <span class="counter-label">已空投</span>
            <span class="counter-value din">{{ audit.counters.airdroppedCount }}</span>
          </div>
          <div class="counter-operator">+</div>
          <div class="counter-item">
            <span class="counter-label">已销毁</span>
            <span class="counter-value din">{{ audit.counters.destroyedCount }}</span>
          </div>
          <div class="counter-operator">=</div>
          <div class="counter-item counter-item--result" :class="{ 'counter-item--bad': !audit.conservation.ok }">
            <span class="counter-label">实际合计</span>
            <span class="counter-value din">{{ audit.conservation.actual }}</span>
          </div>
          <div class="counter-item counter-item--result">
            <span class="counter-label">发行总量(期望)</span>
            <span class="counter-value din">{{ audit.conservation.expected }}</span>
          </div>
        </div>
      </div>

      <!-- 持有校验 -->
      <div class="sn-card">
        <div class="card-title-row">
          <span class="card-title">② 资产持有校验</span>
          <el-tag :type="audit.holding.ok ? 'success' : 'danger'" size="small">
            {{ audit.holding.ok ? '一致' : '不一致' }}
          </el-tag>
        </div>
        <div class="formula">{{ audit.holding.formula }}</div>
        <el-descriptions :column="3" border size="small">
          <el-descriptions-item label="持有中 held">{{ audit.holding.byStatus.held }}</el-descriptions-item>
          <el-descriptions-item label="寄售中 consigned">{{ audit.holding.byStatus.consigned }}</el-descriptions-item>
          <el-descriptions-item label="冻结中 frozen">{{ audit.holding.byStatus.frozen }}</el-descriptions-item>
          <el-descriptions-item label="已转赠 transferred">{{ audit.holding.byStatus.transferred }}</el-descriptions-item>
          <el-descriptions-item label="已消耗 consumed">{{ audit.holding.byStatus.consumed }}</el-descriptions-item>
          <el-descriptions-item label="流通量(期望)">{{ audit.holding.expected }}</el-descriptions-item>
          <el-descriptions-item label="资产总数(实际)">
            <span :class="{ 'bad-value': !audit.holding.ok }">{{ audit.holding.actual }}</span>
          </el-descriptions-item>
        </el-descriptions>
        <div v-if="sourceEntries.length" class="source-tags">
          <span class="source-label">按来源分布：</span>
          <el-tag v-for="[source, cnt] in sourceEntries" :key="source" size="small" effect="plain">
            {{ source }} × {{ cnt }}
          </el-tag>
        </div>
      </div>

      <!-- 配额一览 -->
      <div class="sn-card">
        <div class="card-title-row">
          <span class="card-title">③ 配额一览</span>
          <el-tag :type="audit.quotas.reservedMatch ? 'success' : 'danger'" size="small">
            {{ audit.quotas.reservedMatch ? '启用配额合计 = reserved_count' : '配额合计与预留数不一致' }}
          </el-tag>
        </div>
        <el-descriptions :column="3" border size="small" style="margin-bottom: 12px">
          <el-descriptions-item label="启用配额计划合计">{{ audit.quotas.activePlanned }}</el-descriptions-item>
          <el-descriptions-item label="全部配额已使用合计">{{ audit.quotas.usedTotal }}</el-descriptions-item>
          <el-descriptions-item label="盲盒 reserved_count">{{ audit.counters.reservedCount }}</el-descriptions-item>
        </el-descriptions>
        <el-table :data="audit.quotas.list" size="small" empty-text="暂无配额">
          <el-table-column label="ID" prop="id" width="70" />
          <el-table-column label="配额名称" prop="quotaName" min-width="150" show-overflow-tooltip />
          <el-table-column label="类型" width="100">
            <template #default="{ row }">{{ QUOTA_TYPE_MAP[row.quotaType] ?? row.quotaType }}</template>
          </el-table-column>
          <el-table-column label="计划数量" prop="plannedQuantity" width="100" align="right" />
          <el-table-column label="已使用" prop="usedQuantity" width="90" align="right" />
          <el-table-column label="状态" width="80">
            <template #default="{ row }">
              <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="活动类型" prop="activityType" min-width="110">
            <template #default="{ row }">{{ row.activityType || '—' }}</template>
          </el-table-column>
        </el-table>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
// 盲盒库存审计（文档 8.7 #66：守恒校验，4.3.3 公式，映射到藏品行 D-3）
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchBlindboxDetail, fetchBlindboxAudit } from '@/api/blindbox'
import type { BlindboxDetail, BlindboxAuditResult } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const QUOTA_TYPE_MAP: Record<number, string> = {
  1: '优先购', 2: '活动空投', 3: '签到', 4: '注册', 5: '邀请', 6: '抽奖', 7: '其他'
}

const loading = ref(false)
const detail = ref<BlindboxDetail | null>(null)
const audit = ref<BlindboxAuditResult | null>(null)

const sourceEntries = computed<Array<[string, number]>>(() => {
  const bySource = audit.value?.holding.bySource ?? {}
  return Object.entries(bySource).sort((a, b) => b[1] - a[1])
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const [d, a] = await Promise.all([fetchBlindboxDetail(id), fetchBlindboxAudit(id)])
    detail.value = d
    audit.value = a
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

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

.formula {
  font-size: 12px;
  color: $sn-text-muted;
  background: $sn-bg;
  border-radius: 8px;
  padding: 8px 12px;
  margin-bottom: 14px;
}

.counter-grid {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;

  .counter-operator {
    font-size: 16px;
    color: $sn-text-muted;
    font-weight: 600;
  }

  .counter-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 16px;
    background: $sn-bg;
    border-radius: 10px;
    min-width: 96px;

    .counter-label {
      font-size: 12px;
      color: $sn-text-sub;
    }

    .counter-value {
      font-size: 20px;
      font-weight: 600;
      color: $sn-text-main;
    }

    &--result {
      background: rgba(192, 21, 44, 0.06);

      .counter-value {
        color: $sn-primary;
      }
    }

    &--bad .counter-value {
      color: $sn-danger;
    }
  }
}

.bad-value {
  color: $sn-danger;
  font-weight: 600;
}

.source-tags {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;

  .source-label {
    font-size: 13px;
    color: $sn-text-sub;
  }
}
</style>
