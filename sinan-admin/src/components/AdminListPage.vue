<script setup>
import { ref, onMounted } from 'vue'

// ============================================================
// 通用列表页：搜索 + 下拉筛选 + 下拉刷新 + 触底加载
// 用法：<AdminListPage :fetch="api" :filters="[...]">
//         <template #default="{ items }"> ... </template>
//       </AdminListPage>
// ============================================================

const props = defineProps({
  fetch: { type: Function, required: true },   // (params) => Promise<{code, data:{list,total}}>
  filters: { type: Array, default: () => [] }, // [{field, label, options:[{value,label}]}]
  searchPlaceholder: { type: String, default: '搜索关键词' },
  size: { type: Number, default: 10 },
  defaults: { type: Object, default: () => ({}) } // {field: value} 初始筛选值
})

const items = ref([])
const loading = ref(false)
const finished = ref(false)
const refreshing = ref(false)
const error = ref(false)
const page = ref(0)
const total = ref(0)
const keyword = ref('')
const filterValues = ref(
  Object.fromEntries(props.filters.map((f) => [f.field, props.defaults[f.field] ?? 'all']))
)

async function load() {
  if (refreshing.value) {
    items.value = []
    page.value = 0
    refreshing.value = false
  }
  loading.value = true
  try {
    const res = await props.fetch({
      page: page.value + 1,
      size: props.size,
      keyword: keyword.value,
      ...filterValues.value
    })
    const data = res.data || {}
    items.value.push(...(data.list || []))
    total.value = data.total || 0
    page.value += 1
    finished.value = items.value.length >= total.value
    error.value = false
  } catch (e) {
    error.value = true
  } finally {
    loading.value = false
  }
}

function onRefresh() {
  finished.value = false
  loading.value = true
  load()
}

function reset() {
  items.value = []
  page.value = 0
  finished.value = false
  error.value = false
  loading.value = true
  load()
}

function filterTitle(f) {
  if (!props.filters.length) return ''
  const v = filterValues.value[f.field]
  if (v === 'all' || v === undefined) return f.label
  return f.options.find((o) => o.value === v)?.label || f.label
}

defineExpose({ reset })
</script>

<template>
  <div class="alp">
    <div class="alp__tools">
      <van-search
        v-model="keyword"
        :placeholder="searchPlaceholder"
        shape="round"
        background="transparent"
        @search="reset"
        @clear="reset"
      />
      <van-dropdown-menu v-if="filters.length" class="alp__filters">
        <van-dropdown-item
          v-for="f in filters"
          :key="f.field"
          v-model="filterValues[f.field]"
          :title="filterTitle(f)"
          :options="[{ value: 'all', text: `全部${f.label}` }, ...f.options.map((o) => ({ value: o.value, text: o.label }))]"
          @change="reset"
        />
      </van-dropdown-menu>
    </div>

    <van-pull-refresh v-model="refreshing" @refresh="onRefresh">
      <van-list
        v-model:loading="loading"
        v-model:error="error"
        error-text="加载失败，点击重试"
        :finished="finished"
        finished-text="没有更多了"
        @load="load"
      >
        <slot :items="items" />
      </van-list>
      <van-empty v-if="finished && !items.length" description="暂无数据" />
    </van-pull-refresh>

    <div v-if="items.length" class="alp__total">共 {{ total }} 条记录</div>
  </div>
</template>

<style scoped lang="scss">
.alp__tools {
  background: $color-card;
  border-radius: $radius-lg;
  overflow: hidden;
  margin-bottom: 12px;
  box-shadow: 0 1px 6px rgba(26, 26, 26, 0.04);
}

.alp__filters {
  border-top: 1px solid $color-border;
}

.alp__total {
  text-align: center;
  font-size: 11px;
  color: $color-text-tertiary;
  padding: 8px 0 2px;
}
</style>
