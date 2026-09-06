<script setup lang="ts">
/**
 * 站点装修（CMS）
 *
 * - 分组键值配置：basic 基础 / theme 主题配色 / button 按钮样式 / seo SEO 优化（el-tabs）
 * - 每组内按白名单键渲染表单（文本 / 颜色 / 数字），保存时统一提交
 *   saveDecoration({ settings: { key: value, ... } })，仅白名单键会被后端落库
 *
 * 接口：GET /admin/cms/decoration、POST /admin/cms/decoration { settings }
 * 权限：cms:decoration
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { fetchDecoration, saveDecoration } from '@/api/cms'

/** 字段控件类型 */
type FieldType = 'input' | 'color' | 'number' | 'textarea'

interface DecoField {
  key: string
  name: string
  type?: FieldType
  placeholder?: string
  tip?: string
}

interface DecoGroup {
  key: string
  label: string
  desc: string
  fields: DecoField[]
}

/** 配置分组与字段（键名与后端 DECORATION_KEYS 白名单严格一致） */
const GROUPS: DecoGroup[] = [
  {
    key: 'basic',
    label: '基础配置',
    desc: '站点基础信息，站点名称与 Logo 展示于 C 端导航栏 / 分享卡片',
    fields: [
      { key: 'site_name', name: '站点名称', type: 'input', placeholder: '如：司南数藏' },
      { key: 'site_logo', name: '站点 Logo', type: 'input', placeholder: 'http(s):// 或以 / 开头的相对路径' },
    ],
  },
  {
    key: 'theme',
    label: '主题配色',
    desc: 'C 端主题色与页面背景色，支持 HEX 色值（如 #B00000）',
    fields: [
      { key: 'theme_color', name: '主题色', type: 'color' },
      { key: 'bg_color', name: '背景色', type: 'color' },
    ],
  },
  {
    key: 'button',
    label: '按钮样式',
    desc: 'C 端主按钮的颜色与圆角',
    fields: [
      { key: 'button_color', name: '按钮色', type: 'color' },
      { key: 'button_radius', name: '按钮圆角', type: 'number', tip: '单位 px（0~200，0 为直角）' },
    ],
  },
  {
    key: 'seo',
    label: 'SEO 优化',
    desc: '搜索引擎收录信息，作用于 C 端首页 title / description / keywords',
    fields: [
      { key: 'seo_title', name: 'SEO 标题', type: 'input', placeholder: '不超过 60 字为宜' },
      { key: 'seo_description', name: 'SEO 描述', type: 'textarea', placeholder: '不超过 160 字为宜' },
      { key: 'seo_keywords', name: 'SEO 关键词', type: 'input', placeholder: '多个关键词用英文逗号分隔' },
    ],
  },
]

const loading = ref(false)
const saving = ref(false)
const activeTab = ref('basic')

/** 键值表单模型（与后端 setting_key 一一对应） */
const form = reactive<Record<string, string>>({})

async function load() {
  loading.value = true
  try {
    // 先按白名单初始化为空串，避免未配置的键在表单中 undefined
    GROUPS.forEach((g) => g.fields.forEach((f) => (form[f.key] = '')))
    const rows = await fetchDecoration()
    ;(rows || []).forEach((r: any) => {
      if (r && r.key in form) {
        form[r.key] = String(r.value ?? '')
      }
    })
  } catch {
    /* 错误已全局提示 */
  } finally {
    loading.value = false
  }
}

/** 批量保存全部白名单键（后端静默跳过非白名单键） */
async function save() {
  saving.value = true
  try {
    const settings: Record<string, string> = {}
    GROUPS.forEach((g) =>
      g.fields.forEach((f) => {
        settings[f.key] = String(form[f.key] ?? '').trim()
      }),
    )
    await saveDecoration({ settings })
    ElMessage.success('站点配置已保存')
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
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">站点装修</div>
          <div class="card-sub">分组键值配置：基础信息 / 主题配色 / 按钮样式 / SEO 优化，保存后对 C 端生效</div>
        </div>
        <div>
          <el-button :disabled="loading" @click="load">重置</el-button>
          <el-button v-permission="'cms:decoration'" type="primary" :loading="saving" @click="save">
            保存配置
          </el-button>
        </div>
      </div>

      <el-tabs v-model="activeTab">
        <el-tab-pane v-for="g in GROUPS" :key="g.key" :name="g.key" :label="g.label">
          <div class="group-desc">{{ g.desc }}</div>
          <el-form label-width="110px" class="deco-form">
            <el-form-item v-for="f in g.fields" :key="f.key" :label="f.name">
              <!-- 颜色：取色器 + 文本输入双向同步 -->
              <div v-if="f.type === 'color'" class="color-row">
                <el-color-picker v-model="form[f.key]" />
                <el-input v-model="form[f.key]" placeholder="#B00000" class="color-input" />
                <div class="color-preview" :style="{ background: form[f.key] || 'transparent' }" />
              </div>

              <!-- 数字 -->
              <el-input-number
                v-else-if="f.type === 'number'"
                :model-value="form[f.key] === '' || form[f.key] == null ? undefined : Number(form[f.key])"
                :min="0"
                :max="200"
                @update:model-value="form[f.key] = $event == null ? '' : String($event)"
              />

              <!-- 多行文本 -->
              <el-input
                v-else-if="f.type === 'textarea'"
                v-model="form[f.key]"
                type="textarea"
                :rows="3"
                maxlength="500"
                show-word-limit
                :placeholder="f.placeholder"
              />

              <!-- 单行文本 -->
              <el-input v-else v-model="form[f.key]" maxlength="255" :placeholder="f.placeholder || `请输入${f.name}`" />

              <div class="kv-key">配置键：{{ f.key }}</div>
              <div v-if="f.tip" class="form-tip">{{ f.tip }}</div>
            </el-form-item>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </div>
  </div>
</template>

<style scoped lang="scss">
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;

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

.group-desc {
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.7;
  margin-bottom: 16px;
  padding: 8px 12px;
  background: var(--sn-bg);
  border-radius: 6px;
}

.deco-form {
  max-width: 560px;
}

.color-row {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;

  .color-input {
    flex: 1;
  }

  .color-preview {
    width: 32px;
    height: 24px;
    border-radius: 4px;
    border: 1px solid var(--sn-border);
    flex-shrink: 0;
  }
}

.kv-key {
  width: 100%;
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.7;
  margin-top: 2px;
}

.form-tip {
  width: 100%;
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.7;
  margin-top: 2px;
}
</style>
