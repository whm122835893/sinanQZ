<script setup>
/**
 * 奖励列表编辑器（五大活动统一奖励配置）
 * v-model: rewards 数组，元素结构（与后端 RewardGrantService::validate 对齐）：
 *   { type: 'collectible', collectibleId, quantity }
 *   { type: 'points', amount }
 *   { type: 'draw_chance', quantity }
 *   { type: 'priority_qualification', prioritySaleId, quantity, expiresAt? }
 *   { type: 'eligibility_qualification', collectibleId, quantity, expiresAt? }
 *   { type: 'blindbox', quantity }
 *   { type: 'none' }
 */
import { ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Delete } from '@element-plus/icons-vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  collectibles: { type: Array, default: () => [] },
  prioritySales: { type: Array, default: () => [] },
  /** 允许的奖励类型（缺省全部） */
  types: { type: Array, default: null },
  /** 最多奖励项数 */
  max: { type: Number, default: 10 }
})
const emit = defineEmits(['update:modelValue'])

const REWARD_TYPES = {
  collectible: { label: '藏品空投', tag: 'primary' },
  points: { label: '司南币', tag: 'warning' },
  draw_chance: { label: '抽奖次数', tag: 'success' },
  priority_qualification: { label: '优先购资格', tag: 'info' },
  eligibility_qualification: { label: '资格购白名单', tag: 'info' },
  blindbox: { label: '盲盒', tag: 'danger' },
  none: { label: '无奖励', tag: 'info' }
}
const typeOptions = props.types ? props.types.filter((t) => REWARD_TYPES[t]) : Object.keys(REWARD_TYPES)

const list = ref([])
watch(
  () => props.modelValue,
  (v) => { list.value = Array.isArray(v) ? v.map((x) => ({ ...x })) : [] },
  { immediate: true, deep: true }
)

function add() {
  if (list.value.length >= props.max) return ElMessage.warning(`最多配置 ${props.max} 项奖励`)
  list.value.push({ type: 'points', amount: 10 })
  sync()
}
function remove(idx) {
  list.value.splice(idx, 1)
  sync()
}
/** 切换类型时重置为该类型的默认结构 */
function onTypeChange(item) {
  const t = item.type
  const base = { type: t }
  if (t === 'points') base.amount = 10
  else if (t === 'collectible') Object.assign(base, { collectibleId: null, quantity: 1 })
  else if (t === 'eligibility_qualification') Object.assign(base, { collectibleId: null, quantity: 1 })
  else if (t === 'priority_qualification') Object.assign(base, { prioritySaleId: null, quantity: 1 })
  else if (t === 'draw_chance' || t === 'blindbox') base.quantity = 1
  Object.keys(item).forEach((k) => delete item[k])
  Object.assign(item, base)
  sync()
}
function sync() {
  emit('update:modelValue', list.value.map((x) => ({ ...x })))
}

const cname = (id) => props.collectibles.find((c) => c.id === id)?.name || `藏品 #${id}`
const pname = (id) => props.prioritySales.find((p) => p.id === id)?.name || `优先购 #${id}`

/** 奖励摘要文案（列表展示用） */
function summary(r) {
  const t = REWARD_TYPES[r.type]?.label || r.type
  if (r.type === 'collectible') return `${t}「${cname(r.collectibleId)}」×${r.quantity ?? 1}`
  if (r.type === 'points') return `${t} ${r.amount ?? 0}`
  if (r.type === 'priority_qualification') return `${t}「${pname(r.prioritySaleId)}」可购 ${r.quantity ?? 1} 份`
  if (r.type === 'eligibility_qualification') return `${t}「${cname(r.collectibleId)}」×${r.quantity ?? 1}`
  if (r.type === 'draw_chance' || r.type === 'blindbox') return `${t} ×${r.quantity ?? 1}`
  return t
}
defineExpose({ summary })
</script>

<template>
  <div class="rle">
    <div v-for="(r, idx) in list" :key="idx" class="rle__row">
      <el-select :model-value="r.type" style="width: 150px" placeholder="奖励类型" @change="(v) => { r.type = v; onTypeChange(r) }">
        <el-option v-for="t in typeOptions" :key="t" :label="REWARD_TYPES[t].label" :value="t" />
      </el-select>

      <!-- 按类型动态字段 -->
      <template v-if="r.type === 'points'">
        <el-input-number v-model="r.amount" :min="0.01" :max="1000000" :precision="2" :step="10" style="width: 150px" @change="sync" />
        <span class="rle__unit">司南币</span>
      </template>
      <template v-else-if="r.type === 'collectible'">
        <el-select v-model="r.collectibleId" filterable placeholder="选择藏品" style="flex: 1" @change="sync">
          <el-option v-for="c in collectibles" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
        <el-input-number v-model="r.quantity" :min="1" :max="9999" style="width: 120px" @change="sync" />
        <span class="rle__unit">份</span>
      </template>
      <template v-else-if="r.type === 'eligibility_qualification'">
        <el-select v-model="r.collectibleId" filterable placeholder="选择目标藏品" style="flex: 1" @change="sync">
          <el-option v-for="c in collectibles" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
        <el-input-number v-model="r.quantity" :min="1" :max="99" style="width: 120px" @change="sync" />
        <span class="rle__unit">份</span>
      </template>
      <template v-else-if="r.type === 'priority_qualification'">
        <el-select v-model="r.prioritySaleId" filterable placeholder="选择优先购活动" style="flex: 1" @change="sync">
          <el-option v-for="p in prioritySales" :key="p.id" :label="p.name" :value="p.id" />
        </el-select>
        <el-input-number v-model="r.quantity" :min="1" :max="99" style="width: 120px" @change="sync" />
        <span class="rle__unit">份</span>
      </template>
      <template v-else-if="r.type === 'draw_chance' || r.type === 'blindbox'">
        <el-input-number v-model="r.quantity" :min="1" :max="9999" style="width: 150px" @change="sync" />
        <span class="rle__unit">{{ r.type === 'draw_chance' ? '次' : '个' }}</span>
      </template>

      <el-button link type="danger" :icon="Delete" @click="remove(idx)" />
    </div>
    <el-button link type="primary" size="small" :icon="Plus" @click="add">添加奖励</el-button>
  </div>
</template>

<style scoped lang="scss">
.rle { width: 100%; }

.rle__row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-bottom: 8px;
}

.rle__unit {
  font-size: 12px;
  color: $color-text-tertiary;
  flex-shrink: 0;
}
</style>
