<script setup>
import { ref, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Picture } from '@element-plus/icons-vue'
import { uploadImage } from '@/api'

// ============================================================
// 轻量富文本编辑器（contenteditable + document.execCommand，无外部依赖）
// - v-model 为 HTML 字符串（后端 sanitizeRichText 消毒后落库）
// - 工具栏：段落/标题、加粗/斜体/下划线/删除线、列表、引用、
//   对齐、文字颜色、超链接、图片上传（复用统一上传接口）、清除格式
// - 粘贴一律转为纯文本，避免外部样式污染
// ============================================================

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '请输入内容…' },
  height: { type: Number, default: 260 },
  biz: { type: String, default: 'announcement' }
})

const emit = defineEmits(['update:modelValue'])

const editor = ref(null)
const uploading = ref(false)
const colorShow = ref(false)

const COLORS = ['#1f2329', '#D00000', '#E89121', '#07c160', '#337cff', '#8c5a00']

const HEADINGS = [
  { value: 'P', label: '正文' },
  { value: 'H2', label: '标题' },
  { value: 'H3', label: '小标题' }
]

onMounted(() => {
  if (props.modelValue && editor.value) {
    editor.value.innerHTML = props.modelValue
  }
})

// 外部赋值（如编辑回填）同步到编辑区
watch(
  () => props.modelValue,
  (v) => {
    if (editor.value && v !== editor.value.innerHTML) {
      editor.value.innerHTML = v || ''
    }
  }
)

function exec(cmd, val = null) {
  editor.value?.focus()
  document.execCommand(cmd, false, val)
  sync()
}

function onHeading(h) {
  exec('formatBlock', `<${h}>`)
}

function onInput() {
  sync()
}

function sync() {
  emit('update:modelValue', editor.value?.innerHTML || '')
}

/** 粘贴转纯文本 */
function onPaste(e) {
  e.preventDefault()
  const text = (e.clipboardData || window.clipboardData).getData('text/plain')
  document.execCommand('insertText', false, text)
  sync()
}

/** 文字颜色 */
function onColor(c) {
  exec('foreColor', c)
  colorShow.value = false
}

/** 超链接 */
function onLink() {
  const sel = window.getSelection()?.toString() || ''
  const url = window.prompt('请输入链接地址（以 https:// 开头）', 'https://')
  if (!url || url === 'https://') return
  if (!/^https?:\/\//i.test(url)) return ElMessage.warning('链接需以 http(s):// 开头')
  if (sel) {
    exec('createLink', url)
  } else {
    document.execCommand('insertHTML', false, `<a href="${url}" target="_blank" rel="noopener">${url}</a>`)
    sync()
  }
}

/** 图片上传 */
const fileInput = ref(null)
function pickImage() {
  fileInput.value?.click()
}
async function onFileChange(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  if (file.size > 5 * 1024 * 1024) return ElMessage.warning('图片不能超过 5MB')
  uploading.value = true
  try {
    const res = await uploadImage(file, props.biz)
    if (res.code === 0 && res.data?.url) {
      document.execCommand('insertHTML', false, `<img src="${res.data.url}" alt="" />`)
      sync()
    } else {
      ElMessage.error(res.message || '图片上传失败')
    }
  } finally {
    uploading.value = false
  }
}

/** 内容是否为空（占位提示用） */
function isEmpty() {
  const v = props.modelValue || ''
  return !v.replace(/<[^>]*>/g, '').trim() && !v.includes('<img')
}
</script>

<template>
  <div class="rte">
    <!-- 工具栏 -->
    <div class="rte__bar">
      <template v-for="h in HEADINGS" :key="h.value">
        <button type="button" class="rte__btn rte__btn--text" @click="onHeading(h.value)">{{ h.label }}</button>
      </template>
      <span class="rte__sep" />
      <button type="button" class="rte__btn" title="加粗" @click="exec('bold')"><b>B</b></button>
      <button type="button" class="rte__btn" title="斜体" @click="exec('italic')"><i>I</i></button>
      <button type="button" class="rte__btn" title="下划线" @click="exec('underline')"><u>U</u></button>
      <button type="button" class="rte__btn" title="删除线" @click="exec('strikeThrough')"><s>S</s></button>
      <span class="rte__sep" />
      <button type="button" class="rte__btn" title="无序列表" @click="exec('insertUnorderedList')">•列表</button>
      <button type="button" class="rte__btn" title="有序列表" @click="exec('insertOrderedList')">1.列表</button>
      <button type="button" class="rte__btn" title="引用" @click="exec('formatBlock', '<blockquote>')">引用</button>
      <span class="rte__sep" />
      <button type="button" class="rte__btn" title="左对齐" @click="exec('justifyLeft')">⇤</button>
      <button type="button" class="rte__btn" title="居中" @click="exec('justifyCenter')">≡</button>
      <button type="button" class="rte__btn" title="右对齐" @click="exec('justifyRight')">⇥</button>
      <span class="rte__sep" />
      <div class="rte__color">
        <button type="button" class="rte__btn rte__btn--color" title="文字颜色" @click="colorShow = !colorShow">A</button>
        <div v-if="colorShow" class="rte__colors">
          <span
            v-for="c in COLORS"
            :key="c"
            class="rte__color-item"
            :style="{ background: c }"
            @click="onColor(c)"
          />
        </div>
      </div>
      <button type="button" class="rte__btn" title="插入链接" @click="onLink">链接</button>
      <button
        type="button"
        class="rte__btn"
        title="插入图片"
        :disabled="uploading"
        @click="pickImage"
      >
        <el-icon v-if="uploading" class="is-loading"><Picture /></el-icon>
        <template v-else>图片</template>
      </button>
      <button type="button" class="rte__btn" title="清除格式" @click="exec('removeFormat')">清除</button>
    </div>

    <!-- 编辑区 -->
    <div
      ref="editor"
      class="rte__body"
      :style="{ height: height + 'px' }"
      contenteditable="true"
      @input="onInput"
      @paste="onPaste"
    />
    <div v-if="isEmpty()" class="rte__placeholder">{{ placeholder }}</div>

    <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden @change="onFileChange" />
  </div>
</template>

<style scoped lang="scss">
.rte {
  position: relative;
  width: 100%;
  border: 1px solid var(--el-border-color, #dcdfe6);
  border-radius: 6px;
  overflow: visible;

  &:focus-within { border-color: var(--el-color-primary); }
}

.rte__bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 2px;
  padding: 6px 8px;
  border-bottom: 1px solid var(--el-border-color-lighter, #ebeef5);
  background: var(--el-fill-color-light, #f5f7fa);
  border-radius: 6px 6px 0 0;
}

.rte__btn {
  min-width: 28px;
  height: 26px;
  padding: 0 6px;
  border: none;
  border-radius: 4px;
  background: transparent;
  color: var(--el-text-color-regular, #606266);
  font-size: 13px;
  cursor: pointer;

  &:hover { background: var(--el-fill-color, #ecf0f5); }
  &:disabled { opacity: 0.5; cursor: not-allowed; }
}

.rte__btn--text { font-size: 12px; }
.rte__btn--color {
  font-weight: 700;
  color: #D00000;
}

.rte__sep {
  width: 1px;
  height: 16px;
  margin: 0 4px;
  background: var(--el-border-color, #dcdfe6);
}

.rte__color { position: relative; }

.rte__colors {
  position: absolute;
  top: 30px;
  left: 0;
  z-index: 10;
  display: flex;
  gap: 6px;
  padding: 8px;
  background: #fff;
  border: 1px solid var(--el-border-color-lighter, #ebeef5);
  border-radius: 6px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.rte__color-item {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  cursor: pointer;
  border: 1px solid rgba(0, 0, 0, 0.1);

  &:hover { transform: scale(1.15); }
}

.rte__body {
  padding: 10px 12px;
  font-size: 14px;
  line-height: 1.7;
  color: var(--el-text-color-primary, #303133);
  overflow-y: auto;
  outline: none;

  :deep(h2) { font-size: 18px; font-weight: 700; margin: 10px 0 6px; }
  :deep(h3) { font-size: 16px; font-weight: 600; margin: 8px 0 4px; }
  :deep(blockquote) {
    margin: 8px 0;
    padding: 6px 12px;
    border-left: 3px solid #D00000;
    background: rgba(0, 0, 0, 0.03);
    color: var(--el-text-color-secondary, #909399);
  }
  :deep(ul), :deep(ol) { padding-left: 22px; margin: 6px 0; }
  :deep(img) { max-width: 100%; border-radius: 6px; margin: 6px 0; }
  :deep(a) { color: var(--el-color-primary, #409eff); }
}

.rte__placeholder {
  position: absolute;
  top: 46px;
  left: 14px;
  font-size: 14px;
  color: var(--el-text-color-placeholder, #a8abb2);
  pointer-events: none;
}
</style>
