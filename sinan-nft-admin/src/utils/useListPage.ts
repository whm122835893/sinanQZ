/**
 * 列表页通用逻辑：搜索 / 分页 / 加载 / 刷新
 *
 * 用法：
 *   const { list, total, loading, query, page, pageSize, search, reset, refresh } = useListPage(fetcher)
 */
import { reactive, ref, toRefs } from 'vue'
import { ElMessage } from 'element-plus'

export interface PageResult<T = any> {
  list: T[]
  total: number
  page: number
  pageSize: number
  lastPage?: number
}

export function useListPage<T = any>(
  fetcher: (params: Record<string, any>) => Promise<PageResult<T>>,
  defaultParams: Record<string, any> = {},
) {
  const loading = ref(false)
  const list = ref<T[]>([]) as any
  const total = ref(0)
  const page = ref(1)
  const pageSize = ref(20)

  // 搜索条件（页面 reactive 绑定）
  const filters = reactive<Record<string, any>>({ ...defaultParams })

  async function load() {
    loading.value = true
    try {
      const res = await fetcher({
        ...filters,
        page: page.value,
        pageSize: pageSize.value,
      })
      list.value = res.list ?? []
      total.value = res.total ?? 0
      if (res.page) page.value = res.page
    } catch {
      /* 错误已全局提示；保持列表现状 */
    } finally {
      loading.value = false
    }
  }

  /** 点击「查询」：重置到第一页 */
  async function search() {
    page.value = 1
    await load()
  }

  /** 点击「重置」：清空条件并回第一页 */
  async function reset() {
    Object.keys(filters).forEach((k) => {
      filters[k] = defaultParams[k] ?? (Array.isArray(filters[k]) ? [] : '')
    })
    page.value = 1
    await load()
  }

  function refresh() {
    return load()
  }

  /** 删除成功后的语义化提示 */
  function done(msg = '操作成功') {
    ElMessage.success(msg)
    return load()
  }

  return reactive({
    loading,
    list,
    total,
    page,
    pageSize,
    filters,
    search,
    reset,
    refresh,
    done,
    load,
  })
}

/**
 * 简单对象合并到 reactive filters（批量回填详情数据用）
 */
export function fillFilters(filters: Record<string, any>, data: Record<string, any>) {
  Object.keys(data).forEach((k) => {
    if (k in filters) filters[k] = data[k]
  })
}
