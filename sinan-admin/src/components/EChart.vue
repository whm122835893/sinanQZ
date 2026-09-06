<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import * as echarts from 'echarts'

// ============================================================
// ECharts 通用容器：传入 option 自动渲染，窗口自适应
// 用法：<EChart :option="lineOption" :height="320" />
// ============================================================

const props = defineProps({
  option: { type: Object, required: true },
  height: { type: Number, default: 300 }
})

const el = ref(null)
let chart = null

function render() {
  if (!el.value) return
  if (!chart) chart = echarts.init(el.value)
  chart.setOption(props.option, true)
}

function resize() {
  chart && chart.resize()
}

watch(() => props.option, render, { deep: true })

onMounted(() => {
  render()
  window.addEventListener('resize', resize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', resize)
  chart && chart.dispose()
  chart = null
})
</script>

<template>
  <div ref="el" class="echart" :style="{ height: height + 'px' }" />
</template>

<style scoped>
.echart { width: 100%; }
</style>
