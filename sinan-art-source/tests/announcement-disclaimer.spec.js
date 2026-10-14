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
 *   4. 样式复用早就写好的 .notice-detail__warning（居中标题 + 灰底小字），不新画一套；
 *      盒子从 2026-10-15 起不再是独立白卡片 —— 你要求「公告正文和风险提示共用一个边框」，
 *      它搬进正文那张卡片的最底下，中间只用一条虚线隔开（RUNBOOK §7.39，
 *      推翻 §7.32/§7.36 的「分开才像平台在说话」口径）。
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

/**
 * a 是否排在 b 之前（按文档顺序）。
 * compareDocumentPosition 的 BITTING_POSITION 位表示「b 在 a 之前」，
 * 所以 a.contains(b) 为 false 时，`(a.compareDocumentPosition(b) & 4) === 0` 就是 a 在前。
 * 用 DOM 顺序而不是「第几个 children」：卡片外面还包了导航栏和返回按钮，位置数会变，
 * 而「正文在前、声明在后」这条口径不会变。
 */
function afterInDocumentOrder(a, b) {
  if (a.contains(b) || b.contains(a)) return false
  return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_PRECEDING) === 0
}

describe('NoticeDetail · 正文底部平台风险提示', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('富文本正文：提示嵌在正文卡片最底部，标题与段落都在', async () => {
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
    // 2026-10-15 §7.39：提示从「自己的白卡片」搬回正文那张卡片里面，和正文共用一个边框。
    // 所以它必须是 .notice-detail__main 的后代，而且排在正文卡片内的最后一位。
    const main = wrapper.find('.notice-detail__main')
    expect(main.exists()).toBe(true)
    expect(main.element.contains(box.element)).toBe(true)
    expect(main.element.lastElementChild).toBe(box.element)
  })

  // 防漂移：「共用一个边框」这个决定的全部技术含义就在样式上 —— 它只能靠一条虚线和正文分隔，
  // 不能再自带一张白卡（否则视觉上又变回两个框，而 DOM 结构是对的，只看渲染结果看不出来）。
  it('卡内那条提示不再自己画白卡（2026-10-15 你的要求：和正文共用一个边框）', () => {
    const src = readFileSync(resolve(process.cwd(), 'src/views/NoticeDetail.vue'), 'utf-8').replace(/\r\n/g, '\n')
    const rule = src.match(/&__main > \.notice-detail__warning \{([^}]*)\}/)
    expect(rule, '找不到正文卡片内风险提示的容器样式').not.toBeNull()
    expect(rule[1]).toMatch(/border-top:\s*1px dashed/)
    // 故意留着的历史分支：富文本正文里夹的 warning 块仍是卡内灰底小块，那条规则不归这次改
    expect(rule[1]).not.toMatch(/background|border-radius|\$page-padding/)
  })

  it('纯文本正文：落款之后同样接上提示（和正文同一个边框内）', async () => {
    shared.detail = {
      id: 2,
      title: '系统维护通知',
      content: PLAIN,
      createdAt: '2026-10-14 00:00:00',
      // 2026-10-14 起落款是接口下发的（署名 + 发布日期），不再是模板里写死的一行平台名，
      // 所以这条用例要自己把字段给全（真源：后端 AnnouncementSalutation::resolve）
      signature: '司南运营团队',
      signatureDate: '2026年10月14日',
      disclaimer: '<p class="warn-title">司南风险提示</p><p>只有一段</p>'
    }
    const wrapper = await mountDetail()
    // 纯文本分支：段落 + 落款在正文卡片内，提示接在落款之后、仍在同一张卡片里（§7.39）
    expect(wrapper.findAll('.notice-detail__p')).toHaveLength(2)
    const foot = wrapper.find('.notice-detail__foot')
    expect(foot.exists()).toBe(true)
    expect(foot.text()).toContain('司南运营团队')
    expect(foot.text()).toContain('2026年10月14日')
    expect(wrapper.find('.notice-detail__main').element.contains(foot.element)).toBe(true)
    const box = wrapper.find('.notice-detail__warning')
    expect(box.exists()).toBe(true)
    expect(afterInDocumentOrder(foot.element, box.element)).toBe(true)
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

  it('「司南风险提示」这几个字居中，正文段落不跟着居中（2026-10-15 你的要求）', () => {
    const src = readFileSync(resolve(process.cwd(), 'src/views/NoticeDetail.vue'), 'utf-8').replace(/\r\n/g, '\n')
    const title = src.match(/:deep\(\.warn-title\) \{([\s\S]*?)\n    \}/)
    expect(title, '找不到 .warn-title 样式块（后端下发的标题就靠它上样式）').not.toBeNull()
    expect(title[1]).toMatch(/text-align:\s*center;/)
    // 只居中这一句：外层灰块与正文段落都不带 text-align，否则整段声明变成居中对齐，读起来像诗
    const box = src.match(/&__warning \{([\s\S]*?)\n    \}/)
    expect(box, '找不到 __warning 样式块').not.toBeNull()
    expect(box[1].split(':deep(')[0]).not.toMatch(/text-align/)
    const pRule = src.match(/:deep\(p\) \{([^}]*)\}/)
    expect(pRule, '找不到 __warning 里的段落规则').not.toBeNull()
    expect(pRule[1]).not.toMatch(/text-align/)
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
