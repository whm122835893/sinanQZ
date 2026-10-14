import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * 公告详情页的「敬语 + 落款署名 + 首图位置」（2026-10-14，RUNBOOK §7.37）
 *
 * 需求原话：「公告首图放在顶部居中的位置，圆角10px。每一条公告都要有敬语和署名，署名在发布公告时可改」
 *
 * C 端这半边要钉住的三件事：
 *   1. 敬语在正文之前、落款在两个正文分支**之外** —— 嵌进纯文本分支的话富文本公告就没落款了；
 *   2. 三个值都是纯文本插值（{{ }}），运营在落款里写尖括号只会显示成字面量，不会变成标签；
 *   3. 首图通宽居中 + 圆角 10px，不再左右出血。
 * 三级回落（这条自己填的 > 全局默认 > 出厂值）与日期算法都在后端，由
 * sinan-nft-backend/tests/unit/AnnouncementSalutationTest.php 钉；这里只认接口给的最终值。
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

function source() {
  return readFileSync(resolve(process.cwd(), 'src/views/NoticeDetail.vue'), 'utf-8').replace(/\r\n/g, '\n')
}

function mountDetail() {
  const wrapper = mount(NoticeDetail, { attachTo: document.body })
  return flushPromises().then(() => wrapper)
}

describe('NoticeDetail · 敬语与落款署名', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('富文本正文：开头有敬语、结尾有落款，顺序是敬语 → 正文 → 落款', async () => {
    shared.detail = {
      id: 1,
      title: '盲盒玩法上线',
      content: RICH,
      subtype: 'activity',
      createdAt: '2026-10-14 00:00:00',
      greeting: '各位藏友：',
      signature: '司南运营团队',
      signatureDate: '2026年10月14日'
    }
    const wrapper = await mountDetail()
    const greeting = wrapper.find('.notice-detail__greeting')
    const foot = wrapper.find('.notice-detail__foot')
    expect(greeting.exists()).toBe(true)
    expect(greeting.text()).toBe('各位藏友：')
    expect(foot.exists()).toBe(true)
    expect(foot.text()).toContain('司南运营团队')
    expect(foot.text()).toContain('2026年10月14日')

    // 落款必须在正文卡片里面（和风险提示那张卡分开）
    const main = wrapper.find('.notice-detail__main')
    expect(main.element.contains(foot.element)).toBe(true)

    // 位置：敬语是正文区的第一个元素、落款是最后一个（读起来才是「称呼 → 正文 → 落款」）
    const body = wrapper.find('.notice-detail__body')
    expect(body.element.contains(greeting.element)).toBe(true)
    expect(body.element.firstElementChild).toBe(greeting.element)
    expect(body.element.lastElementChild).toBe(foot.element)
  })

  it('纯文本正文：同样有敬语与落款（两块都写在两个分支之外）', async () => {
    shared.detail = {
      id: 2,
      title: '系统维护通知',
      content: PLAIN,
      createdAt: '2026-10-14 00:00:00',
      greeting: '尊敬的司南用户：',
      signature: '司南运营团队',
      signatureDate: '2026年10月14日'
    }
    const wrapper = await mountDetail()
    expect(wrapper.findAll('.notice-detail__p')).toHaveLength(2)
    expect(wrapper.find('.notice-detail__greeting').text()).toBe('尊敬的司南用户：')
    expect(wrapper.find('.notice-detail__foot').exists()).toBe(true)
  })

  it('落款是纯文本：运营写的尖括号不会变成标签', async () => {
    shared.detail = {
      id: 3,
      title: '甲',
      content: PLAIN,
      createdAt: '',
      greeting: '<img src=x onerror=alert(1)>',
      signature: '<b>加粗</b>'
    }
    const wrapper = await mountDetail()
    const foot = wrapper.find('.notice-detail__foot')
    // 用 {{ }} 插值才会这样：尖括号原样出现在文本里，DOM 里没有对应的元素
    expect(foot.text()).toBe('<b>加粗</b>')
    expect(foot.find('b').exists()).toBe(false)
    const greeting = wrapper.find('.notice-detail__greeting')
    expect(greeting.text()).toBe('<img src=x onerror=alert(1)>')
    expect(greeting.find('img').exists()).toBe(false)
  })

  it('接口没给敬语与落款（旧后端）时不留空行空框', async () => {
    for (const value of [undefined, '', null]) {
      shared.detail = { id: 4, title: '甲', content: RICH, createdAt: '', greeting: value, signature: value }
      const wrapper = await mountDetail()
      expect(wrapper.find('.notice-detail__greeting').exists()).toBe(false)
      expect(wrapper.find('.notice-detail__foot').exists()).toBe(false)
    }
  })

  it('只有落款名没有日期时，落款只显示名字', async () => {
    shared.detail = {
      id: 5,
      title: '甲',
      content: RICH,
      createdAt: '',
      greeting: '乙：',
      signature: '司南运营团队',
      signatureDate: ''
    }
    const wrapper = await mountDetail()
    expect(wrapper.find('.notice-detail__foot').text()).toBe('司南运营团队')
    expect(wrapper.find('.notice-detail__foot-date').exists()).toBe(false)
  })

  it('store 只透传三个字段，不自己拼文案', async () => {
    const pair = { greeting: '各位藏友：', signature: '寄售小组', signatureDate: '2026年10月14日' }
    shared.detail = { id: 6, title: '丙', content: RICH, createdAt: '', ...pair }
    const { useNoticeStore } = await import('@/stores/notice')
    const store = useNoticeStore()
    expect(await store.fetchNotice(6)).toMatchObject(pair)

    shared.detail = { id: 7, title: '丁', content: RICH, createdAt: '' }
    const bare = await store.fetchNotice(7)
    expect(bare).toMatchObject({ greeting: '', signature: '', signatureDate: '' })
  })

  it('首图通宽居中、圆角 10px，旧的左右出血写法已去掉', () => {
    const src = source()
    const m = src.match(/&__cover \{([\s\S]*?)\n  \}/)
    expect(m, '找不到 __cover 样式块').not.toBeNull()
    expect(m[1]).toMatch(/width:\s*100%;/)
    expect(m[1]).toMatch(/margin:[^;]*auto[^;]*;/)
    expect(m[1]).toMatch(/border-radius:\s*10px;/)
    // 0×0 的浏览器里量不出像素，所以「出血已取消」这条只能钉源码：宽度加法和负 margin 都不该留
    expect(m[1]).not.toMatch(/calc\(100% \+ 32px\)/)
    expect(m[1]).not.toMatch(/margin:[^;]*-\d+px/)
  })

  it('敬语与落款写在两个正文分支之外（模板结构断言）', () => {
    const src = source()
    const rich = src.indexOf('v-if="notice.richContent"')
    const plain = src.indexOf('v-else>')
    const foot = src.indexOf('class="notice-detail__foot"')
    const greeting = src.indexOf('{{ notice.greeting }}')
    expect(greeting).toBeGreaterThan(-1)
    expect(greeting).toBeLessThan(rich, '敬语应在正文分支之前')
    expect(foot).toBeGreaterThan(rich)
    expect(foot).toBeGreaterThan(plain, '落款嵌在纯文本分支里的话，富文本公告就没落款了')
  })
})
