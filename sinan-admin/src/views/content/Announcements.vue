<script setup>
import { ref } from 'vue'
import { showSuccessToast, showConfirmDialog } from 'vant'
import { getAnnouncements, saveAnnouncement, removeAnnouncement } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
import DetailSheet from '@/components/DetailSheet.vue'
import StatusTag from '@/components/StatusTag.vue'
import { NOTICE_TYPE, CONTENT_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const filters = [
  {
    field: 'type',
    label: '类型',
    options: [
      { value: 'system', label: '系统公告' },
      { value: 'activity', label: '活动公告' },
      { value: 'maintenance', label: '维护公告' }
    ]
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
const form = ref({ title: '', type: 'system', content: '' })

function openCreate() {
  editing.value = null
  form.value = { title: '', type: 'system', content: '' }
  editShow.value = true
}

function openEdit(a) {
  editing.value = a
  form.value = { title: a.title, type: a.type, content: a.content || '' }
  editShow.value = true
}

async function onSave() {
  if (!form.value.title.trim()) return
  const res = await saveAnnouncement({
    id: editing.value?.id,
    title: form.value.title.trim(),
    type: form.value.type,
    content: form.value.content,
    status: 'published'
  })
  if (res.code === 0) {
    showSuccessToast(editing.value ? '已更新' : '已发布')
    editShow.value = false
    listRef.value?.reset()
  }
}

async function onRemove(a) {
  await showConfirmDialog({ title: '删除公告', message: `确认删除「${a.title}」？删除后不可恢复。` })
  const res = await removeAnnouncement(a.id)
  if (res.code === 0) {
    showSuccessToast('已删除')
    listRef.value?.reset()
  }
}
</script>

<template>
  <div class="adm-page an">
    <div class="adm-toolbar">
      <div class="t-secondary" style="font-size: 12px">公告在 C 端首页公告栏与消息中心展示</div>
      <van-button size="small" round type="primary" icon="plus" @click="openCreate">发布公告</van-button>
    </div>

    <AdminListPage ref="listRef" :fetch="getAnnouncements" :filters="filters" search-placeholder="搜索公告标题">
      <template #default="{ items }">
        <div v-for="a in items" :key="a.id" class="adm-card">
          <div class="adm-item" style="padding: 0; border-bottom: 1px solid var(--color-border)" @click="openEdit(a)">
            <div class="adm-item__body">
              <div class="adm-item__title">
                {{ a.title }}
                <StatusTag :value="a.type" :map="NOTICE_TYPE" />
                <StatusTag :value="a.status" :map="CONTENT_STATUS" />
              </div>
              <div class="adm-item__desc">{{ a.publishTime || '未发布' }} · {{ fmtNumber(a.views) }} 次浏览</div>
            </div>
            <van-icon name="arrow" color="#999" />
          </div>
          <div class="an__ops">
            <van-button size="small" round plain type="danger" @click="onRemove(a)">删除</van-button>
            <van-button size="small" round plain type="primary" @click="openEdit(a)">编辑</van-button>
          </div>
        </div>
      </template>
    </AdminListPage>

    <DetailSheet v-model:show="editShow" :title="editing ? '编辑公告' : '发布公告'">
      <van-field v-model="form.title" label="公告标题" placeholder="请输入标题" required />
      <van-field name="type" label="公告类型">
        <template #input>
          <van-radio-group v-model="form.type" direction="horizontal">
            <van-radio name="system">系统</van-radio>
            <van-radio name="activity">活动</van-radio>
            <van-radio name="maintenance">维护</van-radio>
          </van-radio-group>
        </template>
      </van-field>
      <van-field
        v-model="form.content"
        type="textarea"
        rows="4"
        maxlength="500"
        show-word-limit
        label="公告内容"
        placeholder="公告正文（C 端公告详情展示）"
      />
      <template #actions>
        <van-button block round type="primary" @click="onSave">{{ editing ? '保存修改' : '立即发布' }}</van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.an__ops {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}
</style>
