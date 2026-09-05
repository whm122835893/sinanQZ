<script setup>
import { ref, onMounted } from 'vue'
import { Search, Refresh } from '@element-plus/icons-vue'

// ============================================================
// 通用表格列表页（Element Plus 桌面端）
// 用法：
// <AdminTablePage :fetch="api" :filters="filters" search-placeholder="搜索">
//   <template #default="{ items }">
//     <el-table-column ... />   // 直接写 el-table-column
//   </template>
// </AdminTablePage>
// filters: [{ field, label, options: [{value,label}], placeholder }]
// ============================================================

const props = defineProps({
  fetch: { type: Function, required: true },      // (params) => Promise<{code, data:{list,total}}>
  filters: { type: Array, default: () => [] },    // 筛选项
  searchPlaceholder: { type: String, default: '搜索关键词' },
  size: { type: Number, default: 10 },
  defaults: { type: Object, default: () => ({}) }, // 初始筛选值
  hideSearch: { type: Boolean, default: false }
})

const items = ref([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const keyword = ref('')
const filterValues = ref(Object.fromEntries(props.filters.map((f) => [f.field, props.defaults[f.field] ?? ''])))

async function load() {
  loading.value = true
  try {
    const res = await props.fetch({
      page: page.value,
      size: props.size,
      keyword: keyword.value,
      ...props.defaults,
      ...filterValues.value
    })
    const data = res.data || {}
    items.value = data.list || []
    total.value = data.total || 0
  } finally {
    loading.value = false
  }
}

function search() {
  page.value = 1
  load()
}

function reset() {
  keyword.value = ''
  filterValues.value = Object.fromEntries(props.filters.map((f) => [f.field, '']))
  page.value = 1
  load()
}

onMounted(load)
defineExpose({ refresh: load })
</script>

<template>
  <div class="atp">
    <!-- 搜索区 -->
    <div v-if="!hideSearch" class="atp__search">
      <el-input
        v-model="keyword"
        :placeholder="searchPlaceholder"
        clearable
        class="atp__kw"
        @keyup.enter="search"
        @clear="search"
      >
        <template #prefix><el-icon><Search /></el-icon></template>
      </el-input>

      <el-select
        v-for="f in filters"
        :key="f.field"
        v-model="filterValues[f.field]"
        :placeholder="f.placeholder || `全部${f.label}`"
        clearable
        class="atp__filter"
        @change="search"
      >
        <el-option
          v-for="o in f.options"
          :key="o.value"
          :label="o.label"
          :value="o.value"
        />
      </el-select>

      <el-button type="primary" @click="search">
        <el-icon style="margin-right: 4px"><Search /></el-icon>查询
      </el-button>
      <el-button @click="reset">
        <el-icon style="margin-right: 4px"><Refresh /></el-icon>重置
      </el-button>

      <div class="atp__extra"><slot name="extra" /></div>
    </div>

    <!-- 表格 -->
    <el-table
      :data="items"
      v-loading="loading"
      class="atp__table"
      :header-cell-style="{ background: '#F5F7FA', color: '#333', fontWeight: 600 }"
    >
      <slot :items="items" />
      <template #empty>
        <el-empty description="暂无数据" :image-size="72" />
      </template>
    </el-table>

    <!-- 分页 -->
    <div v-if="total" class="atp__pager">
      <span class="atp__total">共 {{ total }} 条</span>
      <el-pagination
        v-model:current-page="page"
        :page-size="size"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next, jumper"
        background
        @current-change="load"
      />
    </div>
  </div>
</template>

<style scoped lang="scss">
.atp__search {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}

.atp__kw { width: 240px; }
.atp__filter { width: 150px; }
.atp__extra { margin-left: auto; display: flex; gap: 8px; }

.atp__table {
  width: 100%;

  :deep(.el-table__row) { cursor: default; }
}

.atp__pager {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding-top: 14px;
  gap: 10px;
}

.atp__total {
  font-size: 12px;
  color: $color-text-tertiary;
  margin-right: auto;
}

@media (max-width: 768px) {
  .atp__kw { width: 100%; }
  .atp__filter { width: calc(50% - 5px); }
  .atp__extra { margin-left: 0; width: 100%; }
}
</style>
