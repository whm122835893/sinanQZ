<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Delete } from '@element-plus/icons-vue'
import { getPrioritySales, addWhitelist, cleanExpiredPriority } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'

const loading = ref(true)
const sales = ref([])

// ---- 加白名单 ----
const addShow = ref(false)
const currentSale = ref(null)
const form = ref({ phone: '', quantity: 1, expiresAt: '' })

onMounted(load)

async function load() {
  loading.value = true
  const res = await getPrioritySales()
  sales.value = res.data
  loading.value = false
}

function openAdd(s) {
  currentSale.value = s
  form.value = { phone: '', quantity: 1, expiresAt: s.endTime }
  addShow.value = true
}

async function onAdd() {
  const f = form.value
  if (!/^1\d{10}$/.test(f.phone)) return ElMessage.warning('请输入正确的手机号')
  if (!Number.isInteger(f.quantity) || f.quantity < 1) return ElMessage.warning('请输入有效份数')
  const res = await addWhitelist({
    saleId: currentSale.value.id,
    phone: f.phone,
    quantity: f.quantity,
    expiresAt: f.expiresAt
  })
  if (res.code === 0) {
    ElMessage.success('已加入白名单并写入审计日志')
    addShow.value = false
    load()
  } else {
    ElMessage.error(res.message)
  }
}

// ---- 批量清理过期资格（二次确认） ----
async function onClean(s) {
  await ElMessageBox.confirm(
    `确认清理「${s.name}」的过期优先购资格？过期资格（有效期早于当前时间）将被批量移除，未过期的白名单不受影响。`,
    '清理过期资格',
    { type: 'warning' }
  )
  const res = await cleanExpiredPriority(s.id)
  if (res.code === 0) {
    ElMessage.success(res.data.cleaned > 0 ? `已清理 ${res.data.cleaned} 条过期资格` : '暂无过期资格')
    load()
  }
}

// 是否已过期
const isExpired = (t) => new Date(t).getTime() < Date.now()
</script>

<template>
  <div class="adm-page pr">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div v-for="s in sales" :key="s.id" class="adm-card pr__card">
        <div class="pr__head">
          <img class="pr__cover" :src="s.cover" :alt="s.collectibleName" />
          <div class="pr__head-body">
            <div class="pr__title">
              {{ s.name }}
              <el-tag type="warning" effect="plain" size="small">优先购</el-tag>
              <StatusTag :value="s.status" :map="ACTIVITY_STATUS" />
            </div>
            <div class="pr__desc">目标藏品：{{ s.collectibleName }} · 白名单 {{ s.whitelistCount }} 人</div>
            <div class="pr__desc t-tertiary">{{ s.startTime }} ~ {{ s.endTime }}</div>
          </div>
          <div class="pr__head-ops">
            <el-button type="primary" size="small" :icon="Plus" @click="openAdd(s)">加白名单</el-button>
            <el-button size="small" :icon="Delete" @click="onClean(s)">清理过期</el-button>
          </div>
        </div>

        <el-table :data="s.whitelists" class="pr__table">
          <el-table-column label="用户" min-width="140">
            <template #default="{ row }">{{ row.nickname }}</template>
          </el-table-column>
          <el-table-column label="手机号" prop="phone" width="130" />
          <el-table-column label="最大购买量" width="100" align="center">
            <template #default="{ row }">
              <span class="price">{{ row.maxQuantity }}</span>
            </template>
          </el-table-column>
          <el-table-column label="已用配额" width="90" align="center">
            <template #default="{ row }">{{ row.usedQuantity }}</template>
          </el-table-column>
          <el-table-column label="有效期" min-width="150">
            <template #default="{ row }">
              <span :class="{ 'pr__expired': isExpired(row.expiresAt) }">{{ row.expiresAt }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <el-tag :type="isExpired(row.expiresAt) ? 'info' : 'success'" effect="plain" size="small">
                {{ isExpired(row.expiresAt) ? '已过期' : '生效中' }}
              </el-tag>
            </template>
          </el-table-column>
        </el-table>
        <div v-if="s.whitelistCount > s.whitelists.length" class="t-tertiary pr__more">
          其余 {{ s.whitelistCount - s.whitelists.length }} 人已省略（联调后分页加载）
        </div>

        <el-alert
          type="info"
          :closable="false"
          show-icon
          class="pr__tip"
          title="优先购与资格购完全独立：白名单用户在公售时间前可提前购买（优先购权限覆盖资格购限制），购买成功后 used_quantity +1、订单 source = priority"
        />
      </div>

      <!-- 加白名单弹窗 -->
      <el-dialog v-model="addShow" :title="`加入白名单 · ${currentSale?.name || ''}`" width="440px" :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="手机号">
            <el-input v-model="form.phone" placeholder="平台已注册用户手机号" maxlength="11" />
          </el-form-item>
          <el-form-item label="最大购买量">
            <el-input-number v-model="form.quantity" :min="1" style="width: 180px" />
          </el-form-item>
          <el-form-item label="有效期至">
            <el-input v-model="form.expiresAt" placeholder="YYYY-MM-DD HH:mm（精确到时分秒）" />
          </el-form-item>
        </el-form>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="加入白名单的用户在活动期间享有优先购买资格（expires_at > 当前时间 且 used < max 时可购买），超出限购份数后回落普通购买"
        />
        <template #footer>
          <el-button @click="addShow = false">取消</el-button>
          <el-button type="primary" @click="onAdd">确认添加</el-button>
        </template>
      </el-dialog>
    </template>
  </div>
</template>

<style scoped lang="scss">
.pr__card + .pr__card { margin-top: 14px; }

.pr__head {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-bottom: 12px;
}

.pr__cover {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.pr__head-body { flex: 1; min-width: 0; }

.pr__title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 600;
  color: $color-text-primary;
}

.pr__desc { font-size: 12px; color: $color-text-secondary; margin-top: 3px; }

.pr__head-ops { display: flex; gap: 8px; flex-shrink: 0; }

.pr__expired {
  color: $color-text-tertiary;
  text-decoration: line-through;
}

.pr__more {
  font-size: 11px;
  text-align: center;
  padding: 6px 0;
}

.pr__tip { margin-top: 10px; }
</style>
