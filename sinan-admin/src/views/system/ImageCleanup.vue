<script setup>
import { ref, onMounted, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getUnreferencedImages,
  trashImages,
  getImageTrash,
  restoreImages,
  purgeImages,
} from '@/api'

const tab = ref('unreferenced')
const BIZ_OPTIONS = ['collection', 'blindbox', 'marketing', 'content', 'misc', 'custom']

function fmtSize(bytes) {
  if (bytes == null) return '-'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

// ---------- 零引用图 ----------
const loading = ref(false)
const items = ref([])
const total = ref(0)
const summary = ref({ totalSize: 0, byBiz: {}, scannedAt: '', protected: {} })
const page = ref(1)
const pageSize = ref(30)
const bizFilter = ref('')
const selection = ref([])

/**
 * 后端受保护文件的口径（宽限期小时数 + 不参与清理的业务目录）
 * 数字与名单都由接口下发，前端不再抄一份 —— 后端把 24 小时改成 48 小时时，这句话不会变成假话。
 */
const protectedInfo = computed(() => {
  const p = summary.value?.protected || {}
  return {
    graceHours: p.graceHours ?? 24,
    excludedBiz: Array.isArray(p.excludedBiz) ? p.excludedBiz : ['announcement'],
    count: p.count ?? 0,
    size: p.size ?? 0,
  }
})

async function loadUnreferenced() {
  loading.value = true
  try {
    const res = await getUnreferencedImages({
      page: page.value,
      pageSize: pageSize.value,
      ...(bizFilter.value ? { biz: bizFilter.value } : {}),
    })
    if (res.code === 0 && res.data) {
      items.value = res.data.items || []
      total.value = res.data.total || 0
      summary.value = res.data.summary || summary.value
    } else {
      // 这个列表接口是 silent 的：请求层不弹全局错误，失败必须在这里说清楚，
      // 否则表格空着，运营会以为「真的没有未引用图片」，下一步就该误删了。
      ElMessage.error(res?.message || '未引用图片列表加载失败')
    }
  } finally {
    loading.value = false
  }
}

async function doTrash(rows) {
  const urls = rows.map((r) => r.url)
  await ElMessageBox.confirm(
    `将 ${urls.length} 张图片移入回收站？移入后网站将立即无法显示这些图片，可从回收站恢复。`,
    '移入回收站',
    { type: 'warning' }
  )
  const res = await trashImages(urls)
  if (res.code === 0) {
    ElMessage.success(res.message || '已移入回收站')
    selection.value = []
    loadUnreferenced()
  }
}

function onBizChange() {
  page.value = 1
  loadUnreferenced()
}

function onPageChange(p) {
  page.value = p
  loadUnreferenced()
}

// ---------- 回收站 ----------
const trashLoading = ref(false)
const trashItems = ref([])
const trashTotal = ref(0)
const trashPage = ref(1)
const trashSelection = ref([])

async function loadTrash() {
  trashLoading.value = true
  try {
    const res = await getImageTrash({ page: trashPage.value, pageSize: 30 })
    if (res.code === 0 && res.data) {
      trashItems.value = res.data.items || []
      trashTotal.value = res.data.total || 0
    } else {
      // 同上：silent 请求不弹全局提示，回收站打不开时必须说清楚是哪种错
      ElMessage.error(res?.message || '回收站列表加载失败')
    }
  } finally {
    trashLoading.value = false
  }
}

async function doRestore(rows) {
  const urls = rows.map((r) => r.url)
  const res = await restoreImages(urls)
  if (res.code === 0) {
    ElMessage.success(res.message || '已恢复')
    trashSelection.value = []
    loadTrash()
    loadUnreferenced()
  }
}

async function doPurge(rows) {
  const urls = rows.map((r) => r.url)
  await ElMessageBox.confirm(
    `彻底删除 ${urls.length} 张图片？此操作不可恢复！`,
    '彻底删除',
    { type: 'error' }
  )
  const res = await purgeImages({ urls })
  if (res.code === 0) {
    ElMessage.success(res.message || '已彻底删除')
    trashSelection.value = []
    loadTrash()
  }
}

async function doPurgeAll() {
  await ElMessageBox.confirm(
    `清空回收站全部 ${trashTotal.value} 张图片？此操作不可恢复！`,
    '清空回收站',
    { type: 'error' }
  )
  const res = await purgeImages({ all: 1 })
  if (res.code === 0) {
    ElMessage.success(res.message || '已清空')
    loadTrash()
  }
}

const selectedUrls = computed(() => selection.value.map((r) => r.url))

function onTabChange(name) {
  if (name === 'trash' && !trashItems.value.length) loadTrash()
}

onMounted(loadUnreferenced)
</script>

<template>
  <div class="page">
    <div class="page__head">
      <h2>图片清理</h2>
      <p class="page__tip">
        扫描后端 uploads 目录中<strong>未被任何数据引用</strong>的上传图，勾选后移入回收站；
        回收站支持恢复与彻底删除。藏品换图/记录物理删除时旧图也会自动进入回收站。
      </p>
      <p class="page__tip">
        两类图<strong>不参与这里的清理</strong>（不是没扫到，是刻意留着）：上传不足 {{ protectedInfo.graceHours }} 小时的
        ——「先选图、还没点保存」的图此刻确实没被引用，怕误清；{{ protectedInfo.excludedBiz.join('/') || '（无）' }} 目录的
        ——富文本正文里的插图没法保证真的没人用。宁可留几张孤儿图，也不删错一张在用的。
      </p>
    </div>

    <el-tabs v-model="tab" @tab-change="onTabChange">
      <!-- ================= 零引用图 ================= -->
      <el-tab-pane label="零引用图片" name="unreferenced">
        <div class="toolbar">
          <el-select v-model="bizFilter" placeholder="全部业务目录" clearable style="width: 160px" @change="onBizChange">
            <el-option v-for="b in BIZ_OPTIONS" :key="b" :label="b" :value="b" />
          </el-select>
          <el-button :loading="loading" @click="loadUnreferenced">重新扫描</el-button>
          <span class="tip-text">
            共 {{ total }} 张未引用 · 约 {{ fmtSize(summary.totalSize) }}
            <template v-if="protectedInfo.count"> · 另有 {{ protectedInfo.count }} 张受保护未列出（约 {{ fmtSize(protectedInfo.size) }}）</template>
            <template v-if="summary.scannedAt"> · 扫描于 {{ summary.scannedAt }}</template>
          </span>
          <el-button
            type="danger"
            plain
            :disabled="!selectedUrls.length"
            @click="doTrash(selection)"
          >
            移入回收站（已选 {{ selectedUrls.length }}）
          </el-button>
        </div>

        <el-table
          :data="items"
          v-loading="loading"
          stripe
          style="width: 100%"
          row-key="url"
          @selection-change="(rows) => (selection = rows)"
        >
          <el-table-column type="selection" width="44" />
          <el-table-column label="预览" width="80">
            <template #default="{ row }">
              <el-image :src="row.url" fit="cover" style="width: 48px; height: 48px; border-radius: 4px" lazy>
                <template #error><div class="img-broken">失效</div></template>
              </el-image>
            </template>
          </el-table-column>
          <el-table-column label="路径" prop="url" min-width="320" show-overflow-tooltip />
          <el-table-column label="业务目录" prop="biz" width="120" />
          <el-table-column label="大小" width="100">
            <template #default="{ row }">{{ fmtSize(row.size) }}</template>
          </el-table-column>
          <el-table-column label="上传时间" prop="mtime" width="170" />
          <el-table-column label="操作" width="120" fixed="right">
            <template #default="{ row }">
              <el-button link type="danger" @click="doTrash([row])">移入回收站</el-button>
            </template>
          </el-table-column>
        </el-table>

        <el-pagination
          v-if="total > pageSize"
          class="pager"
          layout="prev, pager, next"
          :total="total"
          :page-size="pageSize"
          :current-page="page"
          @current-change="onPageChange"
        />
      </el-tab-pane>

      <!-- ================= 回收站 ================= -->
      <el-tab-pane :label="`回收站（${trashTotal}）`" name="trash">
        <div class="toolbar">
          <el-button :loading="trashLoading" @click="loadTrash">刷新</el-button>
          <span class="tip-text">回收站文件不占网站展示位，可恢复或彻底删除</span>
          <el-button
            type="primary"
            plain
            :disabled="!trashSelection.length"
            @click="doRestore(trashSelection)"
          >
            恢复所选（{{ trashSelection.length }}）
          </el-button>
          <el-button
            type="danger"
            plain
            :disabled="!trashSelection.length"
            @click="doPurge(trashSelection)"
          >
            彻底删除所选
          </el-button>
          <el-button type="danger" :disabled="!trashTotal" @click="doPurgeAll">清空回收站</el-button>
        </div>

        <el-table
          :data="trashItems"
          v-loading="trashLoading"
          stripe
          style="width: 100%"
          row-key="url"
          @selection-change="(rows) => (trashSelection = rows)"
        >
          <el-table-column type="selection" width="44" />
          <el-table-column label="原路径" prop="url" min-width="360" show-overflow-tooltip />
          <el-table-column label="大小" width="100">
            <template #default="{ row }">{{ fmtSize(row.size) }}</template>
          </el-table-column>
          <el-table-column label="移入时间" prop="deletedAt" width="170" />
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="doRestore([row])">恢复</el-button>
              <el-button link type="danger" @click="doPurge([row])">彻底删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <el-pagination
          v-if="trashTotal > 30"
          class="pager"
          layout="prev, pager, next"
          :total="trashTotal"
          :page-size="30"
          :current-page="trashPage"
          @current-change="(p) => { trashPage = p; loadTrash() }"
        />
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.tip-text {
  color: #999;
  font-size: 13px;
  flex: 1;
}
.pager {
  margin-top: 14px;
  justify-content: flex-end;
}
.img-broken {
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f5f5f5;
  color: #bbb;
  font-size: 12px;
  border-radius: 4px;
}
</style>
