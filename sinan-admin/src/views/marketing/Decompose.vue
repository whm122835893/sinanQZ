<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Minus } from '@element-plus/icons-vue'
import {
  getDecomposeRules,
  saveDecomposeRule,
  toggleDecomposeRule,
  deleteDecomposeRule,
  getCollectibleList
} from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'

// ---- 下拉数据源（非盲盒藏品） ----
const collectibles = ref([])

onMounted(async () => {
  const col = await getCollectibleList({ page: 1, pageSize: 200 })
  if (col.code === 0) collectibles.value = col.data.list || []
})

const cname = (id) => collectibles.value.find((c) => c.id === id)?.name || `藏品 #${id}`

// ---- 规则开关 ----
async function onToggle(row) {
  const target = row.enabled === 1 ? 0 : 1
  await ElMessageBox.confirm(
    target === 1
      ? `确认启用分解规则「${row.name}」？用户可按规则分解源藏品。`
      : `确认停用分解规则「${row.name}」？用户将无法继续分解（已完成的分解不受影响）。`,
    '规则开关',
    { type: 'warning' }
  )
  const res = await toggleDecomposeRule(row.id)
  if (res.code === 0) {
    row.enabled = target
    ElMessage.success(target === 1 ? '已启用' : '已停用')
  }
}

async function onDelete(row) {
  await ElMessageBox.confirm(`确认删除分解规则「${row.name}」？删除后不可恢复。`, '删除规则', { type: 'error' })
  const res = await deleteDecomposeRule(row.id)
  if (res.code === 0) {
    ElMessage.success('已删除')
    listRef.value?.refresh()
  }
}

// ---- 新建/编辑 ----
const listRef = ref(null)
const editShow = ref(false)
const editing = ref(null)
const submitting = ref(false)
const form = ref(emptyForm())

function emptyForm() {
  return {
    name: '',
    sourceCollectibleId: null,
    enabled: 1,
    perUserLimit: 0,
    dailyLimit: 0,
    startTime: '',
    endTime: '',
    items: [{ collectibleId: null, quantityPer: 1 }]
  }
}

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  editShow.value = true
}

function openEdit(row) {
  editing.value = row
  form.value = {
    id: row.id,
    name: row.name,
    sourceCollectibleId: row.sourceCollectibleId,
    enabled: row.enabled,
    perUserLimit: row.perUserLimit,
    dailyLimit: row.dailyLimit,
    startTime: row.startTime ? row.startTime.substring(0, 16) : '',
    endTime: row.endTime ? row.endTime.substring(0, 16) : '',
    items: (row.items || []).map((i) => ({ collectibleId: i.collectibleId, quantityPer: i.quantityPer }))
  }
  editShow.value = true
}

function addItem() {
  form.value.items.push({ collectibleId: null, quantityPer: 1 })
}
function removeItem(i) {
  form.value.items.splice(i, 1)
}

async function onSave() {
  const f = form.value
  if (!f.name.trim()) return ElMessage.warning('请输入规则名称')
  if (!f.sourceCollectibleId) return ElMessage.warning('请选择分解源藏品')
  if (!f.items.length || !f.items[0].collectibleId) return ElMessage.warning('请至少配置一条产出明细')
  if (f.items.some((i) => !i.collectibleId)) return ElMessage.warning('产出明细存在未选择藏品的行')
  if (f.items.some((i) => i.collectibleId === f.sourceCollectibleId)) {
    return ElMessage.warning('产出藏品不能与源藏品相同')
  }
  if (f.startTime && f.endTime && f.startTime >= f.endTime) {
    return ElMessage.warning('结束时间需晚于开始时间')
  }

  submitting.value = true
  const res = await saveDecomposeRule(f)
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success('保存成功')
    editShow.value = false
    listRef.value?.refresh()
  }
}
</script>

<template>
  <div class="adm-page dc">
    <AdminTablePage ref="listRef" :fetch="getDecomposeRules" search-placeholder="搜索规则名称">
      <template #extra>
        <el-button type="primary" :icon="Plus" @click="openCreate">新建分解规则</el-button>
      </template>

      <template #default="{ items }">
        <el-table-column label="规则" min-width="220" fixed="left">
          <template #default="{ row }">
            <div class="dc__rule">
              <div class="dc__rule-name">{{ row.name || `规则 #${row.id}` }}</div>
              <div class="dc__rule-sub">源藏品：{{ row.sourceName || `#${row.sourceCollectibleId}` }}</div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="分解公式" min-width="260">
          <template #default="{ row }">
            <div class="dc__formula">
              <img v-if="row.sourceImage" class="dc__thumb" :src="row.sourceImage" :alt="row.sourceName" />
              <div class="dc__arrow">→</div>
              <div class="dc__outputs">
                <div v-for="(it, idx) in row.items" :key="idx" class="dc__output">
                  <img v-if="it.cover" class="dc__thumb dc__thumb--sm" :src="it.cover" :alt="it.name" />
                  <span>{{ it.name }} ×{{ it.quantityPer }}</span>
                </div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="限制" width="160">
          <template #default="{ row }">
            <div>每人 {{ row.perUserLimit ? `${row.perUserLimit} 次` : '不限' }}</div>
            <div>每日 {{ row.dailyLimit ? `${row.dailyLimit} 次` : '不限' }}</div>
          </template>
        </el-table-column>

        <el-table-column label="时间窗口" width="200">
          <template #default="{ row }">
            <div v-if="row.startTime || row.endTime" class="dc__time">
              <div>{{ row.startTime || '不限' }}</div>
              <div>~ {{ row.endTime || '不限' }}</div>
            </div>
            <span v-else class="t-tertiary">长期有效</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-switch :model-value="row.enabled === 1" @change="onToggle(row)" />
          </template>
        </el-table-column>

        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 新建/编辑弹窗 -->
    <el-dialog v-model="editShow" :title="editing ? '编辑分解规则' : '新建分解规则'" width="640px" :close-on-click-modal="false">
      <el-form :model="form" label-width="110px">
        <el-form-item label="规则名称" required>
          <el-input v-model="form.name" placeholder="例：青铜器分解" maxlength="50" show-word-limit />
        </el-form-item>
        <el-form-item label="分解源藏品" required>
          <el-select v-model="form.sourceCollectibleId" filterable placeholder="选择要被分解的藏品" style="width: 100%">
            <el-option v-for="c in collectibles" :key="c.id" :value="c.id" :label="c.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="产出明细" required>
          <div v-for="(it, i) in form.items" :key="i" class="dc__item-row">
            <el-select v-model="it.collectibleId" filterable placeholder="产出藏品" style="flex: 1">
              <el-option v-for="c in collectibles" :key="c.id" :value="c.id" :label="c.name" />
            </el-select>
            <el-input-number v-model="it.quantityPer" :min="1" :max="999" />
            <el-button :icon="Plus" @click="addItem" />
            <el-button :icon="Minus" :disabled="form.items.length <= 1" @click="removeItem(i)" />
          </div>
        </el-form-item>
        <el-form-item label="每人限额">
          <el-input-number v-model="form.perUserLimit" :min="0" :max="99999" />
          <span class="dc__hint">0 = 不限</span>
        </el-form-item>
        <el-form-item label="日限额">
          <el-input-number v-model="form.dailyLimit" :min="0" :max="99999" />
          <span class="dc__hint">0 = 不限</span>
        </el-form-item>
        <el-form-item label="时间窗口">
          <el-date-picker v-model="form.startTime" type="datetime" placeholder="开始（空=立即生效）" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" />
          <span style="margin: 0 6px">~</span>
          <el-date-picker v-model="form.endTime" type="datetime" placeholder="结束（空=长期有效）" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" />
        </el-form-item>
        <el-form-item label="启用状态">
          <el-switch v-model="form.enabled" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.dc__rule-name {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
}

.dc__rule-sub {
  font-size: 12px;
  color: $color-text-tertiary;
  margin-top: 3px;
}

.dc__formula {
  display: flex;
  align-items: center;
  gap: 10px;
}

.dc__thumb {
  width: 40px;
  height: 40px;
  border-radius: 6px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid $color-border;

  &--sm {
    width: 24px;
    height: 24px;
    border-radius: 4px;
  }
}

.dc__arrow {
  color: $color-text-tertiary;
  flex-shrink: 0;
}

.dc__outputs {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
}

.dc__output {
  display: flex;
  align-items: center;
  gap: 6px;
}

.dc__time {
  font-size: 12px;
  color: $color-text-secondary;
}

.dc__item-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
  width: 100%;
}

.dc__hint {
  margin-left: 8px;
  font-size: 12px;
  color: $color-text-tertiary;
}
</style>
