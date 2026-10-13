import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * 公告/新闻详情页底部的平台《风险提示》（2026-10-14，RUNBOOK §7.32）
 *
 * 口径：
 *   1. 文案由后端 app/service/AnnouncementDisclaimer.php 生成，走 GET /api/announcements/:id 的
 *      disclaimer 字段单独下发 —— 不拼进 content（拼进去会让纯文本正文被 isHtml 判成富文本，
 *      段落换行就得在接口里重做一遍）；
 *   2. C 端只负责渲染：有值才出现，空串连灰块都不留；
 *   3. 富文本与纯文本两种正文都要在最底部出现，所以模板块放在两个分支之外；
 *   4. 样式复用早就写好的 .notice-detail__warning（灰底小字 + .warn-title 加粗），不新画一套。
 *
 * 这里盯的是 C 端这半边；后端转义/分段/开关的口径由
 * sinan-nft-backend/tests/unit/AnnouncementDisclaimerTest.php 钉。
 */

const shared = vi.hoisted(() => ({ detail: {} }))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' } }),
  useRouter: () => ({
    push: vi.fn(),
    back: vi.fn(),
    options: { history: { state: { back: null } } }
  }),
  RouterLink: { template: '<a><slot /></a>' }
}))
vi.mock('@/utils/request', () => ({
  default: { get: (...a) => Promise.resolve(shared.detail), post: async () => ({}) }
}))

import NoticeDetail from '@/views/NoticeDetail.vue'

const RICH = '<h2>活动说明</h2><p>正文第一段</p>'
const PLAIN = '第一行正文\n第二行正文'

function mountDetail() {
  const wrapper = mount(NoticeDetail, { attachTo: document.body })
  return flushPromises().then(() => wrapper)
}

describe('NoticeDetail · 正文底部平台风险提示', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('富文本正文：提示块出现在正文卡片最底部，标题与段落都在', async () => {
    shared.detail = {
      id: 1,
      title: '盲盒玩法上线',
      content: RICH,
      subtype: 'activity',
      createdAt: '2026-10-14 00:00:00',
      disclaimer: '<p class="warn-title">司南风险提示</p><p>第一段</p><p>第二段</p>'
    }
    const wrapper = await mountDetail()
    const box = wrapper.find('.notice-detail__warning')
    expect(box.exists()).toBe(true)
    expect(box.find('.warn-title').text()).toBe('司南风险提示')
    expect(box.findAll('p')).toHaveLength(3)
    // 顺序：正文在前、提示在后
    const children = wrapper.find('.notice-detail__content').element.children
    expect(children[children.length - 1].className).toContain('notice-detail__warning')
  })

  it('纯文本正文：同样在落款之后补上提示块', async () => {
    shared.detail = {
      id: 2,
      title: '系统维护通知',
      content: PLAIN,
      createdAt: '2026-10-14 00:00:00',
      disclaimer: '<p class="warn-title">司南风险提示</p><p>只有一段</p>'
    }
    const wrapper = await mountDetail()
    // 纯文本分支：段落 + 落款 + 提示
    expect(wrapper.findAll('.notice-detail__p')).toHaveLength(2)
    expect(wrapper.find('.notice-detail__foot').exists()).toBe(true)
    const children = wrapper.find('.notice-detail__content').element.children
    expect(children[children.length - 1].className).toContain('notice-detail__warning')
    expect(children[children.length - 2].className).toContain('notice-detail__foot')
  })

  it('接口没给 disclaimer（旧后端或开关关闭）时不留空灰块', async () => {
    for (const value of [undefined, '', null]) {
      shared.detail = { id: 3, title: '甲', content: RICH, createdAt: '', disclaimer: value }
      const wrapper = await mountDetail()
      expect(wrapper.find('.notice-detail__warning').exists()).toBe(false)
    }
  })

  it('store 只透传字段，不自己拼文案', async () => {
    const text = '<p class="warn-title">司南风险提示</p><p>乙</p>'
    shared.detail = { id: 4, title: '丙', content: RICH, createdAt: '', disclaimer: text }
    const { useNoticeStore } = await import('@/stores/notice')
    const store = useNoticeStore()
    const detail = await store.fetchNotice(4)
    expect(detail.disclaimer).toBe(text)

    shared.detail = { id: 5, title: '丁', content: RICH, createdAt: '' }
    expect((await store.fetchNotice(5)).disclaimer).toBe('')
  })

  it('提示块写在两个正文分支之外，保证两种模式都有', () => {
    const src = readFileSync(resolve(process.cwd(), 'src/views/NoticeDetail.vue'), 'utf-8').replace(/\r\n/g, '\n')
    const block = src.match(/<div v-if="notice\.disclaimer"[^\n]*\n?/)
    expect(block, '详情页找不到风险提示块').not.toBeNull()
    const before = src.slice(0, block.index)
    // 它必须出现在「普通文本模式」那个 template 分支结束之后
    expect(before).toContain('notice-detail__foot')
    expect((before.match(/<\/template>/g) || []).length).toBeGreaterThanOrEqual(2)
  })
})
