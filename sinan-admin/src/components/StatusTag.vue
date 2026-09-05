<script setup>
import { computed } from 'vue'
import { ElTag } from 'element-plus'

// ============================================================
// 状态标签：传入业务状态字典 + 值，渲染 el-tag
// 用法：<StatusTag :value="row.status" :map="ORDER_STATUS" />
// map 结构：{ key: { label, type } }
// ============================================================

const props = defineProps({
  value: { type: [String, Number], default: '' },
  map: { type: Object, required: true }
})

const cfg = computed(() => props.map[props.value] || { label: String(props.value), type: 'info' })
</script>

<template>
  <el-tag :type="cfg.type" effect="light" size="small" disable-transitions>
    {{ cfg.label }}
  </el-tag>
</template>
