<script setup>
/**
 * 参与资格编辑器（五大活动统一）
 * v-model: { type: 'all'|'realname'|'checkin'|'invite'|'hold'|'checkin_rank', config: {...} }
 * 与后端 ActivityRewardService::validateEligibility 对齐：
 *   checkin      { days: N }
 *   invite       { count: N }
 *   hold         { collectibleIds: [], match: 'any'|'all' }
 *   checkin_rank { rank: N }
 */
import { watch } from 'vue'
import { Plus, Delete } from '@element-plus/icons-vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({ type: 'all', config: {} }) },
  collectibles: { type: Array, default: () => [] }
})
const emit = defineEmits(['update:modelValue'])

const TYPES = [
  { value: 'all', label: '所有人' },
  { value: 'realname', label: '已实名用户' },
  { value: 'checkin', label: '累计签到 N 天' },
  { value: 'invite', label: '累计邀请 N 人' },
  { value: 'hold', label: '持有指定藏品' },
  { value: 'checkin_rank', label: '签到前 N 名' }
]

const el = props.modelValue || { type: 'all', config: {} }
watch(
  () => props.modelValue,
  (v) => {
    const cur = v || { type: 'all', config: {} }
    el.type = cur.type || 'all'
    el.config = cur.config || {}
    if (el.type === 'hold' && !Array.isArray(el.config.collectibleIds)) el.config.collectibleIds = []
  },
  { immediate: true, deep: true }
)

function emitUp() {
  emit('update:modelValue', { type: el.type, config: { ...el.config } })
}

function onTypeChange() {
  const cfg = {}
  if (el.type === 'checkin') cfg.days = 1
  else if (el.type === 'invite') cfg.count = 1
  else if (el.type === 'hold') { cfg.collectibleIds = []; cfg.match = 'any' }
  else if (el.type === 'checkin_rank') cfg.rank = 100
  el.config = cfg
  emitUp()
}

function addHold() {
  if (!Array.isArray(el.config.collectibleIds)) el.config.collectibleIds = []
  if (el.config.collectibleIds.length >= 50) return
  el.config.collectibleIds.push(null)
  emitUp()
}
function removeHold(idx) {
  el.config.collectibleIds.splice(idx, 1)
  emitUp()
}
</script>

<template>
  <div class="ele">
    <el-select :model-value="el.type" style="width: 200px" @change="(v) => { el.type = v; onTypeChange() }">
      <el-option v-for="t in TYPES" :key="t.value" :label="t.label" :value="t.value" />
    </el-select>

    <!-- checkin: 累计 N 天 -->
    <template v-if="el.type === 'checkin'">
      <el-input-number
        :model-value="el.config.days ?? 1"
        :min="1" :max="3650" style="width: 140px; margin-left: 8px"
        @update:model-value="(v) => { el.config.days = v; emitUp() }"
      />
      <span class="ele__unit">天</span>
    </template>

    <!-- invite: 累计邀请 N 人 -->
    <template v-else-if="el.type === 'invite'">
      <el-input-number
        :model-value="el.config.count ?? 1"
        :min="1" :max="10000" style="width: 140px; margin-left: 8px"
        @update:model-value="(v) => { el.config.count = v; emitUp() }"
      />
      <span class="ele__unit">人</span>
    </template>

    <!-- checkin_rank: 签到前 N 名 -->
    <template v-else-if="el.type === 'checkin_rank'">
      <el-input-number
        :model-value="el.config.rank ?? 100"
        :min="1" :max="1000000" :step="100" style="width: 150px; margin-left: 8px"
        @update:model-value="(v) => { el.config.rank = v; emitUp() }"
      />
      <span class="ele__unit">名内</span>
    </template>

    <!-- hold: 持有藏品 -->
    <template v-else-if="el.type === 'hold'">
      <div class="ele__hold">
        <div class="ele__hold-row">
          <el-radio-group
            :model-value="el.config.match ?? 'any'"
            size="small"
            @update:model-value="(v) => { el.config.match = v; emitUp() }"
          >
            <el-radio value="any">任一藏品</el-radio>
            <el-radio value="all">全部藏品</el-radio>
          </el-radio-group>
        </div>
        <div v-for="(_, idx) in el.config.collectibleIds || []" :key="idx" class="ele__hold-row">
          <el-select
            :model-value="el.config.collectibleIds[idx]"
            filterable clearable placeholder="选择藏品" style="flex: 1"
            @update:model-value="(v) => { el.config.collectibleIds[idx] = v; emitUp() }"
          >
            <el-option v-for="c in collectibles" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
          <el-button link type="danger" :icon="Delete" @click="removeHold(idx)" />
        </div>
        <el-button link type="primary" size="small" :icon="Plus" @click="addHold">添加藏品</el-button>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.ele { width: 100%; display: flex; align-items: center; flex-wrap: wrap; }

.ele__unit {
  font-size: 12px;
  color: $color-text-tertiary;
  margin-left: 6px;
}

.ele__hold { width: 100%; margin-top: 8px; }

.ele__hold-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-bottom: 8px;
}
</style>
