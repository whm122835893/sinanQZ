import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import request from '@/utils/request'

// 公告状态：列表 / 分类 / 搜索
export const useNoticeStore = defineStore('notice', () => {
  const categories = ref([
    { key: 'all', label: '全部' },
    { key: 'activity', label: '活动公告' },
    { key: 'compose', label: '合成公告' },
    { key: 'operation', label: '运营公告' }
  ])

  // 子分类 → 展示标签
  const categoryLabels = {
    activity: '活动公告',
    compose: '合成公告',
    operation: '运营公告'
  }

  // 公告列表（真实接口：GET /api/announcements）
  const notices = ref([])
  const loaded = ref(false)

  async function fetchNotices() {
    const res = await request.get('/announcements', { params: { page: 1, pageSize: 50 } })
    notices.value = (res.list || []).map((a) => ({
      id: a.id,
      title: a.title,
      summary: a.summary || '',
      category: a.subtype || 'operation',
      categoryLabel: categoryLabels[a.subtype] || '公告',
      time: (a.createdAt || '').slice(0, 19).replace('T', ' ')
    }))
    loaded.value = true
    return notices.value
  }

  // 公告详情（真实接口：GET /api/announcements/:id）
  async function fetchNotice(id) {
    const a = await request.get(`/announcements/${id}`)
    const content = a.content || ''
    const isHtml = /<[a-z][\s\S]*>/i.test(content)
    const detail = {
      id: a.id,
      title: a.title,
      summary: a.summary || '',
      category: a.subtype || 'operation',
      categoryLabel: categoryLabels[a.subtype] || '公告',
      time: (a.createdAt || '').slice(0, 19).replace('T', ' '),
      coverImage: a.coverImage || '',
      // 平台风险提示（后端 AnnouncementDisclaimer 生成的 HTML 片段，空串 = 不显示）。
      // 正文与提示分两个字段下发，不拼进 content：拼进去会让纯文本公告被认成富文本，
      // 段落换行就得在接口里重做一遍（见 RUNBOOK §7.32 的口径说明）
      disclaimer: a.disclaimer || ''
    }
    if (isHtml) {
      // 富文本内容存为一个 text 块（模板 v-html 渲染，保留加粗/高亮等标签）
      detail.richContent = [{ type: 'text', value: content }]
    } else {
      // 纯文本：按空行拆分段落
      detail.content = String(content).split(/\n+/).filter(Boolean)
    }
    // 同步回列表（供详情页返回时展示最新标题）
    const idx = notices.value.findIndex((n) => n.id === Number(id))
    if (idx >= 0) notices.value[idx] = { ...notices.value[idx], ...detail }
    else notices.value.unshift({ ...detail, summary: detail.summary || detail.title })
    return detail
  }

  const searchKeyword = ref('')
  const activeCategory = ref('all')

  const filteredNotices = computed(() => {
    let list = notices.value
    if (activeCategory.value !== 'all') {
      list = list.filter((n) => n.category === activeCategory.value)
    }
    if (searchKeyword.value.trim()) {
      const k = searchKeyword.value.trim()
      list = list.filter((n) => n.title.includes(k) || (n.summary || '').includes(k))
    }
    return list
  })

  function search(keyword) {
    searchKeyword.value = keyword
    if (!loaded.value) return fetchNotices()
    return Promise.resolve(filteredNotices.value)
  }

  return {
    categories,
    notices,
    searchKeyword,
    activeCategory,
    filteredNotices,
    fetchNotices,
    fetchNotice,
    search
  }
})
