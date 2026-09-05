<script setup>
import { computed } from 'vue'

const props = defineProps({
  value: { type: [String, Number], required: true },
  map: { type: Object, required: true }
})

const cfg = computed(() => props.map[props.value] || { label: String(props.value), type: 'default' })
</script>

<template>
  <van-tag plain round :type="cfg.type">{{ cfg.label }}</van-tag>
</template>
