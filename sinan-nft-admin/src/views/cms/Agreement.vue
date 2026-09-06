<script setup lang="ts">
/**
 * 协议管理（CMS）
 *
 * - 左侧：协议列表（用户服务协议 / 隐私政策 / 数字藏品购买及持有须知，键白名单由后端下发）
 * - 右侧：编辑区（textarea，支持 HTML 富文本），保存调用 saveAgreement(key, { content })
 * - 服务端保存时会自动去除 script/iframe 等危险标签与 on* 事件属性
 *
 * 接口：GET /admin/cms/agreements、PUT /admin/cms/agreements/:key { content }
 * 权限：cms:agreement
 */
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { fetchAgreements, saveAgreement } from '@/api/cms'
import { datetime } from '@/utils/format'

const loading = ref(false)
const saving = ref(false)

/** 协议列表：[{ key, name, content, updatedAt, exists }] */
const agreements = ref<any[]>([])

/** 当前选中的协议键 */
const activeKey = ref('')

/** 编辑草稿（切换协议时重置为已保存内容） */
const draft = ref('')

const active = computed(() => agreements.value.find((a) => a.key === activeKey.value) || null)

async function load() {
  loading.value = true
  try {
    const rows = await fetchAgreements()
    agreements.value = rows || []
    if (!agreements.value.some((a) => a.key === activeKey.value)) {
      activeKey.value = agreements.value[0]?.key ?? ''
    }
    syncDraft()
  } catch {
    /* 错误已全局提示 */
  } finally {
    loading.value = false
  }
}

/** 草稿回填为当前协议已保存内容 */
function syncDraft() {
  draft.value = active.value?.content ?? ''
}

function selectAgreement(key: string) {
  if (key === activeKey.value) return
  activeKey.value = key
  syncDraft()
}

/** 保存当前协议内容（后端要求内容非空） */
async function save() {
  if (!activeKey.value) {
    ElMessage.warning('请先选择要编辑的协议')
    return
  }
  const content = draft.value.trim()
  if (!content) {
    ElMessage.warning('协议内容不能为空')
    return
  }

  saving.value = true
  try {
    await saveAgreement(activeKey.value, { content })
    ElMessage.success(`「${active.value?.name ?? activeKey.value}」已保存`)
    await load()
  } catch {
    /* 错误已全局提示 */
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page-container" v-loading="loading">
    <div class="agreement-layout">
      <!-- 左侧：协议列表 -->
      <div class="table-card side-card">
        <div class="card-title">协议列表</div>
        <div class="card-sub">共 {{ agreements.length }} 份，点击切换编辑</div>

        <div
          v-for="a in agreements"
          :key="a.key"
          class="agreement-item"
          :class="{ active: a.key === activeKey }"
          @click="selectAgreement(a.key)"
        >
          <div class="item-name">
            {{ a.name }}
            <el-tag v-if="!a.exists" type="info" size="small">未设置</el-tag>
          </div>
          <div class="item-meta">{{ a.key }}</div>
          <div class="item-meta">
            {{ a.exists ? `更新于 ${datetime(a.updatedAt)}` : '尚未配置内容' }}
          </div>
        </div>

        <el-empty v-if="!loading && agreements.length === 0" description="暂无协议配置" :image-size="60" />
      </div>

      <!-- 右侧：编辑区 -->
      <div class="table-card editor-card">
        <div class="card-header">
          <div>
            <div class="card-title">{{ active?.name || '请选择协议' }}</div>
            <div class="card-sub">
              <template v-if="active">
                {{ active.key }} ·
                {{ active.exists ? `最近更新：${datetime(active.updatedAt)}` : '尚未配置内容' }}
              </template>
            </div>
          </div>
          <div>
            <el-button :disabled="!active" @click="syncDraft">重置</el-button>
            <el-button v-permission="'cms:agreement'" type="primary" :loading="saving" :disabled="!active" @click="save">
              保存
            </el-button>
          </div>
        </div>

        <el-input
          v-model="draft"
          type="textarea"
          :rows="20"
          :disabled="!active"
          placeholder="请输入协议内容，支持纯文本或 HTML 富文本（服务端将自动过滤 script/iframe 等危险标签）"
          class="editor-textarea"
        />
        <div class="editor-tip">
          支持纯文本或 HTML 富文本；内容保存后立即对 C 端生效，服务端会自动去除 script/iframe/object/embed
          标签、on* 事件属性及 javascript: 协议链接。
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.agreement-layout {
  display: flex;
  gap: 12px;
  align-items: stretch;
}

.side-card {
  width: 260px;
  flex-shrink: 0;

  .card-title {
    font-size: 15px;
    font-weight: 600;
  }
  .card-sub {
    margin: 4px 0 12px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.agreement-item {
  border: 1px solid var(--sn-border);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 8px;
  cursor: pointer;
  transition: all 0.15s ease;

  &:hover {
    border-color: var(--sn-red);
  }

  &.active {
    border-color: var(--sn-red);
    background: var(--sn-red-bg);
  }

  .item-name {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
  }

  .item-meta {
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
    line-height: 1.6;
  }
}

.editor-card {
  flex: 1;
  min-width: 0;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .card-title {
    font-size: 15px;
    font-weight: 600;
  }
  .card-sub {
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.editor-textarea {
  :deep(textarea) {
    font-family: -apple-system, 'PingFang SC', 'Microsoft YaHei', monospace;
    line-height: 1.8;
  }
}

.editor-tip {
  margin-top: 10px;
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.7;
}
</style>
