<template>
  <div ref="chartRef" class="echarts-wrapper" :style="{ height: typeof height === 'number' ? `${height}px` : height }" />
</template>

<script setup lang="ts">
// ============================================================================
// ECharts 容器（文档 9.1 / 3.3 图表配色规范）
// - 统一配色序列（司南红/金/墨体系）
// - 自适应容器尺寸（ResizeObserver）
// - 销毁时自动 dispose
// ============================================================================
import * as echarts from 'echarts'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { EChartsOption } from 'echarts'
import { SN_CHART_COLORS } from '@/utils/charts'

const props = withDefaults(
  defineProps<{
    option: EChartsOption
    height?: number | string
    /** 空数据时显示占位 */
    empty?: boolean
  }>(),
  { height: 300, empty: false }
)

const chartRef = ref<HTMLDivElement>()
let chart: echarts.ECharts | null = null
let observer: ResizeObserver | null = null

function render(): void {
  if (!chartRef.value) return
  if (!chart) {
    chart = echarts.init(chartRef.value)
  }
  if (props.empty) {
    chart.clear()
    chart.showLoading('default', {
      text: '暂无数据',
      color: SN_CHART_COLORS[0],
      textColor: '#9CA3AF',
      maskColor: 'rgba(255, 255, 255, 0.8)',
      showSpinner: false
    })
    return
  }
  chart.hideLoading()
  chart.setOption(
    { color: SN_CHART_COLORS, ...props.option },
    { notMerge: true }
  )
}

onMounted(() => {
  render()
  if (typeof ResizeObserver !== 'undefined' && chartRef.value) {
    observer = new ResizeObserver(() => chart?.resize())
    observer.observe(chartRef.value)
  } else {
    window.addEventListener('resize', () => chart?.resize())
  }
})

watch(() => props.option, render, { deep: true })
watch(() => props.empty, render)

onBeforeUnmount(() => {
  observer?.disconnect()
  chart?.dispose()
  chart = null
})

defineExpose({ getChart: () => chart })
</script>

<style scoped lang="scss">
.echarts-wrapper {
  width: 100%;
  min-height: 200px;
}
</style>
