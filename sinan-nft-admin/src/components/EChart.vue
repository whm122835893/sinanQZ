<script setup lang="ts">
/**
 * ECharts 通用封装：自动初始化 / 响应式 / 销毁
 */
import { onMounted, onBeforeUnmount, ref, watch, nextTick } from 'vue'
import * as echarts from 'echarts'

const props = defineProps<{
  option: echarts.EChartsOption
  height?: string
}>()

const el = ref<HTMLDivElement>()
let chart: echarts.ECharts | null = null
let resizeObserver: ResizeObserver | null = null

function render() {
  if (!el.value) return
  if (!chart) {
    chart = echarts.init(el.value)
  }
  chart.setOption(props.option, true)
}

function resize() {
  chart?.resize()
}

onMounted(async () => {
  await nextTick()
  render()
  resizeObserver = new ResizeObserver(resize)
  if (el.value) resizeObserver.observe(el.value)
})

watch(
  () => props.option,
  () => render(),
  { deep: true },
)

onBeforeUnmount(() => {
  resizeObserver?.disconnect()
  chart?.dispose()
  chart = null
})
</script>

<template>
  <div ref="el" class="echart" :style="{ height: height || '320px' }" />
</template>

<style scoped>
.echart {
  width: 100%;
}
</style>
