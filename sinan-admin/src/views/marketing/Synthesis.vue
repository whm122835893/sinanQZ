<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getSynthesisList, toggleSynthesis, saveSynthesis } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const list = ref([])

// ---- 限次编辑 ----
const editShow = ref(false)
const editing = ref(null)
const form = ref({ perUserLimit: 1, totalLimit: null })

onMounted(load)

async function load() {
  loading.value = true
  const res = await getSynthesisList()
  list.value = res.data
  loading.value = false
}

// ---- 活动启停 ----
async function onToggle(a) {
  const enabling = a.status !== 'enabled'
  await ElMessageBox.confirm(
    enabling ? `确认开启合成活动「${a.title}」？C 端将展示合成入口。` : `确认停用合成活动「${a.title}」？停用后 C 端入口隐藏，已合成的资产不受影响。`,
    '活动启停',
    { type: 'warning' }
  )
  const res = await toggleSynthesis(a.id)
  if (res.code === 0) {
    a.status = res.data
    ElMessage.success(res.data === 'enabled' ? '已开启' : '已停用')
  }
}

// ---- 限次编辑 ----
function openEdit(a) {
  editing.value = a
  form.value = { perUserLimit: a.perUserLimit, totalLimit: a.totalLimit }
  editShow.value = true
}

async function onSaveLimit() {
  const f = form.value
  if (!Number.isInteger(f.perUserLimit) || f.perUserLimit < 1) return ElMessage.warning('每人限次需为正整数')
  if (f.totalLimit !== null && (!Number.isInteger(f.totalLimit) || f.totalLimit < editing.value.usedCount)) {
    return ElMessage.warning(`总限次不可低于已合成数量（已合成 ${fmtNumber(editing.value.usedCount)} 份）`)
  }
  const res = await saveSynthesis({
    id: editing.value.id,
    perUserLimit: f.perUserLimit,
    totalLimit: f.totalLimit
  })
  if (res.code === 0) {
    ElMessage.success('限次配置已保存')
    editShow.value = false
    load()
  }
}
</script>

<template>
  <div class="adm-page sy">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div v-for="a in list" :key="a.id" class="adm-card sy__card">
        <div class="sy__head">
          <div class="sy__title">
            <span class="sy__name">{{ a.title }}</span>
            <el-tag :type="a.type === 'limited' ? 'warning' : 'primary'" effect="plain" size="small">
              {{ a.type === 'limited' ? '限时' : '常驻' }}
            </el-tag>
            <StatusTag :value="a.status" :map="ACTIVITY_STATUS" />
          </div>
          <div class="sy__head-ops">
            <el-button link type="primary" size="small" @click="openEdit(a)">限次配置</el-button>
            <el-switch :model-value="a.status === 'enabled'" @change="onToggle(a)" />
          </div>
        </div>
        <div class="sy__desc">{{ a.rules }}</div>

        <!-- 合成公式：材料 M:N → 产物 -->
        <div class="sy__formula">
          <div class="sy__mats">
            <div v-for="m in a.materials" :key="m.collectibleId" class="sy__mat">
              <img :src="m.cover" :alt="m.name" />
              <div class="sy__mat-name">{{ m.name }}</div>
              <div class="sy__mat-count price">×{{ m.count }}</div>
            </div>
          </div>
          <el-icon class="sy__plus"><Plus /></el-icon>
          <div class="sy__result">
            <img :src="a.result.cover" :alt="a.result.name" />
            <div class="sy__mat-name">{{ a.result.name }}</div>
            <div class="t-tertiary" style="font-size: 11px">合成产物</div>
          </div>
        </div>

        <div class="sy__meta">
          <span>每人限合成 <b class="price">{{ a.perUserLimit }}</b> 次</span>
          <span>总量 <b class="price">{{ a.totalLimit === null ? '不限' : fmtNumber(a.totalLimit) }}</b></span>
          <span>已合成 <b class="price">{{ fmtNumber(a.usedCount) }}</b></span>
          <span v-if="a.type === 'limited' && a.endTime" class="t-tertiary">截止 {{ a.endTime }}</span>
        </div>
      </div>

      <!-- 限次编辑弹窗 -->
      <el-dialog v-model="editShow" :title="`限次配置 · ${editing?.title || ''}`" width="440px" :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="每人限次">
            <el-input-number v-model="form.perUserLimit" :min="1" style="width: 180px" />
          </el-form-item>
          <el-form-item label="总限次">
            <el-input-number
              v-model="form.totalLimit"
              :min="editing ? editing.usedCount : 1"
              :step="100"
              style="width: 180px"
              placeholder="留空表示不限"
            />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              清空 / null 表示不限总量；已合成 {{ editing ? fmtNumber(editing.usedCount) : 0 }} 份，总限次不可低于该值
            </div>
          </el-form-item>
        </el-form>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="合成消耗时实时校验产物库存池 / 配额预留，不足则拦截；材料从用户仓库扣除并记录合成流水"
        />
        <template #footer>
          <el-button @click="editShow = false">取消</el-button>
          <el-button type="primary" @click="onSaveLimit">保存</el-button>
        </template>
      </el-dialog>
    </template>
  </div>
</template>

<style scoped lang="scss">
.sy__card + .sy__card { margin-top: 14px; }

.sy__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.sy__title { display: flex; align-items: center; gap: 8px; min-width: 0; }
.sy__name { font-size: 15px; font-weight: 600; color: $color-text-primary; }

.sy__head-ops { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.sy__desc {
  font-size: 12px;
  color: $color-text-secondary;
  margin-top: 6px;
}

.sy__formula {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 14px 0;
  overflow-x: auto;
  padding-bottom: 2px;
}

.sy__mats { display: flex; gap: 10px; }

.sy__mat, .sy__result {
  flex-shrink: 0;
  width: 80px;
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
  margin-top: 5px;
  @include ellipsis;
}

.sy__mat-count { font-size: 12px; }
.sy__plus { color: $color-text-tertiary; flex-shrink: 0; font-size: 18px; }

.sy__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 18px;
  font-size: 12px;
  color: $color-text-secondary;
  padding-top: 12px;
  border-top: 1px dashed $color-border;

  b { font-size: 13px; }
}
</style>
