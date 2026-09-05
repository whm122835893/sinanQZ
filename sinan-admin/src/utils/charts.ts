// ============================================================================
// ECharts 司南主题（文档 3.3 图表配色规范）
// ============================================================================
import type { EChartsOption } from 'echarts'

/** 司南图表配色序列 */
export const SN_CHART_COLORS = [
  '#C00000', '#D4A574', '#8B0000', '#E8B873',
  '#5C5C5C', '#DF6666', '#B08D55', '#333333'
]

/** 折线图基础 option 模板：统一配色 + areaStyle 20% 透明渐变填充 */
export function lineChartOption(params: {
  categories: string[]
  series: Array<{ name: string; data: number[] }>
  yAxisFormatter?: (value: number) => string
}): EChartsOption {
  return {
    color: SN_CHART_COLORS,
    tooltip: {
      trigger: 'axis',
      backgroundColor: 'rgba(255,255,255,0.96)',
      borderColor: '#EBEDF0',
      textStyle: { color: '#1A1A1A', fontSize: 12 }
    },
    legend: {
      top: 0,
      right: 0,
      itemWidth: 14,
      itemHeight: 4,
      textStyle: { color: '#6B7280', fontSize: 12 }
    },
    grid: { left: 8, right: 12, top: 32, bottom: 0, containLabel: true },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: params.categories,
      axisLine: { lineStyle: { color: '#EBEDF0' } },
      axisLabel: { color: '#9CA3AF', fontSize: 11 }
    },
    yAxis: {
      type: 'value',
      splitLine: { lineStyle: { color: '#F2F3F5' } },
      axisLabel: {
        color: '#9CA3AF',
        fontSize: 11,
        formatter: params.yAxisFormatter as never
      }
    },
    series: params.series.map((s) => ({
      name: s.name,
      type: 'line' as const,
      smooth: true,
      symbol: 'circle',
      symbolSize: 6,
      showSymbol: false,
      lineStyle: { width: 2.5 },
      areaStyle: { opacity: 0.2 },
      emphasis: { focus: 'series' as const },
      data: s.data
    }))
  }
}

/** 柱状图基础 option 模板 */
export function barChartOption(params: {
  categories: string[]
  series: Array<{ name: string; data: number[] }>
  yAxisFormatter?: (value: number) => string
}): EChartsOption {
  return {
    color: SN_CHART_COLORS,
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      backgroundColor: 'rgba(255,255,255,0.96)',
      borderColor: '#EBEDF0',
      textStyle: { color: '#1A1A1A', fontSize: 12 }
    },
    legend: {
      top: 0,
      right: 0,
      itemWidth: 14,
      itemHeight: 4,
      textStyle: { color: '#6B7280', fontSize: 12 }
    },
    grid: { left: 8, right: 12, top: 32, bottom: 0, containLabel: true },
    xAxis: {
      type: 'category',
      data: params.categories,
      axisLine: { lineStyle: { color: '#EBEDF0' } },
      axisLabel: { color: '#9CA3AF', fontSize: 11 }
    },
    yAxis: {
      type: 'value',
      splitLine: { lineStyle: { color: '#F2F3F5' } },
      axisLabel: {
        color: '#9CA3AF',
        fontSize: 11,
        formatter: params.yAxisFormatter as never
      }
    },
    series: params.series.map((s) => ({
      name: s.name,
      type: 'bar' as const,
      barMaxWidth: 26,
      itemStyle: { borderRadius: [4, 4, 0, 0] },
      emphasis: { focus: 'series' as const },
      data: s.data
    }))
  }
}
