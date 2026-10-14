<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getAnnouncements, saveAnnouncement, removeAnnouncement, uploadImage } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import RichTextEditor from '@/components/RichTextEditor.vue'
import { NOTICE_TYPE } from '@/utils/maps'

// 分类选项由 NOTICE_TYPE 一处派生（不再各处抄一遍四类清单）：
// 加一类只需改 @/utils/maps.js，真源在后端 AnnouncementCover::SUBTYPES，防漂移测试盯这两处
const filters = [
  {
    field: 'type',
    label: '类型',
    options: Object.entries(NOTICE_TYPE).map(([value, m]) => ({ value, label: m.label }))
  },
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'published', label: '已发布' },
      { value: 'draft', label: '草稿' }
    ]
  }
]

const listRef = ref(null)
const editShow = ref(false)
const editing = ref(null)
const submitting = ref(false)
const uploading = ref(false)
const form = ref(emptyForm())

// 四个子分类的默认头图（来自列表接口的 data.covers，键即 activity/compose/consignment/operation）
// 走列表接口而不是 /system/configs：那个接口要 system:config 权限，只给运营开的内容管理员会 403
const covers = ref({})

onMounted(loadCovers)

async function loadCovers() {
  // 只要 covers，所以 size 取 1；失败也不影响发布流程，弹窗里少一张预览图而已
  const res = await getAnnouncements({ page: 1, size: 1 })
  if (res.code === 0) covers.value = res.data?.covers || {}
}

// 当前所选类型的默认头图（后台「全局参数 → 公告头图」里配的）
const typeDefault = computed(() => covers.value[form.value.type] || '')
// C 端详情页真正会显示的那张：自己上传的 > 该类型默认
const activeCover = computed(() => form.value.coverImage || typeDefault.value)

function emptyForm() {
  return {
    title: '',
    type: 'operation',
    summary: '',
    content: '',
    // 公告图：留空 = 跟随该类型默认头图，不是「不显示图」
    coverImage: '',
    // 发布方式：now 立即发布 / schedule 定时发布 / draft 存草稿
    mode: 'now',
    publishTime: ''
  }
}

// 展示状态：草稿 / 定时中（未到时间）/ 已发布
function displayStatus(a) {
  if (a.status === 'draft') return 'draft'
  if (a.publishTime && new Date(a.publishTime.replace(/-/g, '/')).getTime() > Date.now()) return 'scheduled'
  return 'published'
}

const STATUS_MAP = {
  draft: { label: '草稿', type: 'info' },
  scheduled: { label: '定时中', type: 'warning' },
  published: { label: '已发布', type: 'success' }
}

// 纯文本内容（校验非空用）
function plainContent(html) {
  return String(html || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim()
}

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  editShow.value = true
}

function openEdit(a) {
  editing.value = a
  const scheduled = a.status === 'published' && a.publishTime
    && new Date(a.publishTime.replace(/-/g, '/')).getTime() > Date.now()
  form.value = {
    title: a.title,
    type: a.type,
    summary: a.summary || '',
    content: a.content || '',
    coverImage: a.coverImage || '',
    mode: a.status === 'draft' ? 'draft' : scheduled ? 'schedule' : 'now',
    publishTime: scheduled ? a.publishTime : ''
  }
  editShow.value = true
}

// ---- 公告图上传（≤5MB，存 /uploads/announcement/年月/随机名；HEIC 由 api/index.js 总闸转 JPEG）----
async function onUploadCover({ file }) {
  if (!file) return
  if (file.size > 5 * 1024 * 1024) {
    return ElMessage.warning('图片大小不能超过 5MB')
  }
  uploading.value = true
  const res = await uploadImage(file, 'announcement')
  uploading.value = false
  if (res.code === 0 && res.data?.url) {
    form.value.coverImage = res.data.url
    ElMessage.success('公告图已上传，保存后 C 端详情页即换成这张')
  } else {
    ElMessage.error(res.message || '上传失败，请重试')
  }
}

async function onSave() {
  const f = form.value
  if (!f.title.trim()) return ElMessage.warning('请输入公告标题')
  if (!plainContent(f.content) && !f.content.includes('<img')) {
    return ElMessage.warning('请输入公告内容')
  }
  if (f.mode === 'schedule' && !f.publishTime) {
    return ElMessage.warning('请选择定时发布时间')
  }

  // 摘要为空时取正文前 100 字
  const summary = f.summary.trim() || plainContent(f.content).slice(0, 100)

  submitting.value = true
  const res = await saveAnnouncement({
    id: editing.value?.id,
    title: f.title.trim(),
    type: f.type,
    summary,
    content: f.content,
    coverImage: f.coverImage,
    status: f.mode === 'draft' ? 'draft' : 'published',
    publishTime: f.mode === 'schedule' ? f.publishTime : ''
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(
      editing.value
        ? '已更新'
        : f.mode === 'draft' ? '已存草稿' : f.mode === 'schedule' ? '已设置定时发布' : '已发布'
    )
    editShow.value = false
    listRef.value?.refresh()
  }
}

async function onRemove(a) {
  await ElMessageBox.confirm(`确认删除「${a.title}」？删除后不可恢复。`, '删除公告', { type: 'warning' })
  const res = await removeAnnouncement(a.id)
  if (res.code === 0) {
    ElMessage.success('已删除')
    listRef.value?.refresh()
  }
}
</script>

<template>
  <div class="adm-page an">
    <AdminTablePage ref="listRef" :fetch="getAnnouncements" :filters="filters" search-placeholder="搜索公告标题">
      <template #extra>
        <el-button type="primary" :icon="Plus" @click="openCreate">发布公告</el-button>
      </template>

      <template #default="{ items }">
        <el-table-column label="公告标题" min-width="240" fixed="left" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title }}</template>
        </el-table-column>
        <el-table-column label="类型" width="110">
          <template #default="{ row }">
            <StatusTag :value="row.type" :map="NOTICE_TYPE" />
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <StatusTag :value="displayStatus(row)" :map="STATUS_MAP" />
          </template>
        </el-table-column>
        <el-table-column label="发布时间" width="170">
          <template #default="{ row }">
            <template v-if="displayStatus(row) === 'scheduled'">
              <div>{{ row.publishTime }}</div>
              <div class="t-tertiary" style="font-size: 12px">定时发布</div>
            </template>
            <template v-else>{{ row.publishTime || row.createdAt || '—' }}</template>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="onRemove(row)">删除</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 编辑弹窗 -->
    <el-dialog
      v-model="editShow"
      :title="editing ? '编辑公告' : '发布公告'"
      width="760px"
      :close-on-click-modal="false"
      top="6vh"
    >
      <el-form label-width="90px">
        <el-form-item label="公告标题">
          <el-input v-model="form.title" placeholder="请输入标题" maxlength="60" show-word-limit />
        </el-form-item>
        <el-form-item label="公告类型">
          <el-radio-group v-model="form.type">
            <el-radio v-for="(m, k) in NOTICE_TYPE" :key="k" :value="k">{{ m.label }}</el-radio>
          </el-radio-group>
          <div class="t-tertiary" style="font-size: 12px; width: 100%">C 端公告列表按此分类展示（各分类 Tab），同时决定下面「公告图」用哪张默认头图</div>
        </el-form-item>
        <el-form-item label="公告图">
          <div class="an__cover">
            <div class="an__cover-row">
              <div class="an__cover-cell">
                <div class="an__cover-cap">
                  详情页实际显示
                  <span v-if="!form.coverImage" class="t-tertiary">（跟随类型默认）</span>
                </div>
                <div class="an__cover-frame" :class="{ 'is-empty': !activeCover }">
                  <img v-if="activeCover" :src="activeCover" alt="公告图预览" />
                  <div v-else class="an__cover-none">该类型暂未设置默认头图</div>
                </div>
                <div class="an__cover-ops">
                  <el-upload :show-file-list="false" :http-request="onUploadCover" accept="image/*">
                    <el-button link type="primary" size="small" :loading="uploading">
                      {{ form.coverImage ? '重新上传' : '上传自定义公告图' }}
                    </el-button>
                  </el-upload>
                  <el-button v-if="form.coverImage" link type="warning" size="small" @click="form.coverImage = ''">
                    恢复跟随类型默认
                  </el-button>
                </div>
              </div>
              <div class="an__cover-cell">
                <div class="an__cover-cap">{{ NOTICE_TYPE[form.type]?.label || '该类型' }}默认头图</div>
                <div class="an__cover-frame" :class="{ 'is-empty': !typeDefault }">
                  <img v-if="typeDefault" :src="typeDefault" alt="该类型默认头图" />
                  <div v-else class="an__cover-none">未设置</div>
                </div>
                <div class="an__cover-ops">
                  <span class="t-tertiary" style="font-size: 11px">想换这张：系统设置 → 全局参数 → 公告头图（改完实时生效，不用发版）</span>
                </div>
              </div>
            </div>
            <div class="t-tertiary" style="font-size: 12px; margin-top: 8px; width: 100%">
              公告图显示在 C 端公告详情页正文上方的横幅条。不上传就用该类型的默认头图（右边那张）；
              上传了就以这条公告自己的为准。建议 1280×495（约 2.59:1），不超过 5MB。
            </div>
          </div>
        </el-form-item>
        <el-form-item label="摘要">
          <el-input
            v-model="form.summary"
            type="textarea"
            :rows="2"
            maxlength="200"
            show-word-limit
            placeholder="列表页展示的摘要（留空自动截取正文前 100 字）"
          />
        </el-form-item>
        <el-form-item label="公告内容">
          <RichTextEditor
            v-model="form.content"
            placeholder="公告正文，支持标题/加粗/颜色/列表/图片等（C 端公告详情展示）"
            :height="280"
          />
          <div class="t-tertiary" style="font-size: 12px; width: 100%">
            C 端详情页会在正文底部自动附上平台《风险提示》（公告与新闻都带，正文里不必再自己写一遍）；
            文案与开关在「系统 → 全局参数 → 内容与公告」，改完实时生效、不用发版
          </div>
        </el-form-item>
        <el-form-item label="发布方式">
          <el-radio-group v-model="form.mode">
            <el-radio value="now">立即发布</el-radio>
            <el-radio value="schedule">定时发布</el-radio>
            <el-radio value="draft">存草稿</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.mode === 'schedule'" label="发布时间">
          <el-date-picker
            v-model="form.publishTime"
            type="datetime"
            placeholder="选择定时发布时间"
            format="YYYY-MM-DD HH:mm:ss"
            value-format="YYYY-MM-DD HH:mm:ss"
            :disabled-date="(d) => d.getTime() < Date.now() - 86400000"
            style="width: 240px"
          />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            到达该时间后公告自动在 C 端可见，无需再次操作
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onSave">
          {{ form.mode === 'draft' ? '存草稿' : form.mode === 'schedule' ? '设置定时发布' : '立即发布' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
// 公告图：左边是这条公告最终生效的图（自己传的，没传就是该类型默认），
// 右边是「该类型默认头图」本体——两张同框，运营一眼能看出留空会显示哪张。
// 1280×495 的横幅比例按 260px 宽铺出来约 100px 高，两格并排不撑破 760px 弹窗。
.an__cover {
  width: 100%;
}

.an__cover-row {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
}

.an__cover-cell {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.an__cover-cap {
  font-size: 12px;
  color: $color-text-secondary;
}

.an__cover-frame {
  width: 260px;
  aspect-ratio: 1280 / 495;
  border-radius: 8px;
  overflow: hidden;
  background: $color-surface;
  border: 1px solid $color-border;
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  &.is-empty {
    background: $color-bg;
  }
}

.an__cover-none {
  font-size: 12px;
  color: $color-text-tertiary;
  padding: 0 10px;
  text-align: center;
}

.an__cover-ops {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
}
</style>
