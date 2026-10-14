import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * 公告详情页的头图横幅 + 彩色类型标签（2026-10-14，RUNBOOK §7.36）
 *
 * 口径（真源都在后端 app/service/AnnouncementCover.php，C 端只负责渲染）：
 *   1. coverImage = 「这条公告自己上传的图 > 该类型默认头图 > 空串」，后端已经三级选完，
 *      C 端不再自己判优先级；空串就整块不渲染，不留灰坑；
 *   2. tagColor   = 公告自带 tag_color（后端只收 #rrggbb）> 该类型主题色，
 *      C 端拿到的一定是安全十六进制色；万一不是（旧接口/脏数据）就退回平台主红；
 *   3. 类型标签文案取接口 subtypeLabel，本地 categoryLabels 只当兜底；
 *   4. 版式顺序：标题 → 类型标签 + 日期 → 头图 → 正文 →（风险提示独立卡片）。
 *
 * 后端那半边（选图优先级、颜色收敛、四个键）由
 * sinan-nft-backend/tests/unit/AnnouncementCoverTest.php 钉。
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

const RICH = '<p>正文第一段</p>'
const FALLBACK = 'rgb(192, 0, 0)' // #C00000，平台主红

function mountDetail() {
  const wrapper = mount(NoticeDetail, { attachTo: document.body })
  return flushPromises().then(() => wrapper)
}

// jsdom 会把十六进制色规范化成 rgb(...)，比对时统一换算，避免 #3D5A99 / rgb(61, 90, 153) 各说各话
function toRgb(hex) {
  const m = /^#?([0-9a-fA-F]{6})$/.exec(hex)
  if (!m) return hex
  const v = parseInt(m[1], 16)
  return `rgb(${(v >> 16) & 255}, ${(v >> 8) & 255}, ${v & 255})`
}

function detail(extra) {
  return {
    id: 1,
    title: '寄售规则调整通知',
    content: RICH,
    subtype: 'consignment',
    subtypeLabel: '寄售公告',
    tagColor: '#E89121',
    coverImage: '/uploads/notice-default/announcement-consignment.webp',
    createdAt: '2026-10-14 00:00:00',
    disclaimer: '',
    ...extra
  }
}

describe('NoticeDetail · 头图横幅与类型标签', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('接口给了 coverImage：横幅按后端算好的地址渲染', async () => {
    shared.detail = detail()
    const wrapper = await mountDetail()
    const cover = wrapper.find('img.notice-detail__cover')
    expect(cover.exists()).toBe(true)
    expect(cover.attributes('src')).toBe('/uploads/notice-default/announcement-consignment.webp')
  })

  it('coverImage 是空串（该类型默认图被清空）：整块不渲染，不留灰坑', async () => {
    for (const value of ['', undefined, null]) {
      shared.detail = detail({ coverImage: value })
      const wrapper = await mountDetail()
      expect(wrapper.find('img.notice-detail__cover').exists()).toBe(false)
    }
  })

  it('类型标签：文案用接口的 subtypeLabel，底色用接口的 tagColor', async () => {
    shared.detail = detail()
    const wrapper = await mountDetail()
    const tag = wrapper.find('.notice-detail__type')
    expect(tag.text()).toBe('寄售公告')
    expect(tag.attributes('style')).toContain(toRgb('#E89121'))
  })

  it('tagColor 认不下来时退回平台主红，不把脏值写进 style', async () => {
    for (const dirty of ['#fff', 'red', 'url(x)', '"><script>', ' ', undefined]) {
      shared.detail = detail({ tagColor: dirty })
      const wrapper = await mountDetail()
      const style = wrapper.find('.notice-detail__type').attributes('style') || ''
      expect(style).toContain(FALLBACK)
      expect(style).not.toContain('script')
      expect(style).not.toContain('url(')
    }
  })

  it('版式顺序：标题 → 标签+日期 → 头图 → 正文', async () => {
    shared.detail = detail()
    const wrapper = await mountDetail()
    const main = wrapper.find('.notice-detail__main')
    const order = ['.notice-detail__title', '.notice-detail__meta', 'img.notice-detail__cover', '.notice-detail__body']
      .map((sel) => main.find(sel).element)
    const all = Array.from(main.element.querySelectorAll('*'))
    const idx = order.map((el) => all.indexOf(el))
    expect(idx.every((i) => i >= 0), '四个块都在主卡片里').toBe(true)
    expect(idx).toEqual([...idx].sort((a, b) => a - b))
  })
})

describe('notice store · 头图字段透传与四类清单', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('详情：coverImage / tagColor / subtypeLabel 原样透出，缺字段就兜空串和运营公告', async () => {
    shared.detail = detail({ disclaimer: '<p class="warn-title">司南风险提示</p>' })
    const { useNoticeStore } = await import('@/stores/notice')
    const store = useNoticeStore()
    const d = await store.fetchNotice(1)
    expect(d.coverImage).toBe('/uploads/notice-default/announcement-consignment.webp')
    expect(d.tagColor).toBe('#E89121')
    expect(d.categoryLabel).toBe('寄售公告')

    // 老后端 / 没标类型的存量公告：按运营公告算，跟后端 normalize() 一个口径
    shared.detail = { id: 2, title: '甲', content: RICH, createdAt: '' }
    const e = await store.fetchNotice(2)
    expect(e.coverImage).toBe('')
    expect(e.tagColor).toBe('')
    expect(e.category).toBe('operation')
    expect(e.categoryLabel).toBe('运营公告')
  })

  it('列表页分类：四类齐全，顺序与后端一致', async () => {
    const { useNoticeStore } = await import('@/stores/notice')
    const store = useNoticeStore()
    const keys = store.categories.map((c) => c.key)
    expect(keys).toEqual(['all', 'activity', 'compose', 'consignment', 'operation'])
    const labels = store.categories.filter((c) => c.key !== 'all').map((c) => c.label)
    expect(labels).toEqual(['活动公告', '合成公告', '寄售公告', '运营公告'])
  })
})

// ---------------------------------------------------------------
// 跨仓防漂移：本地那份四类清单要和后端真源一字不差
// （CI 只跑单仓时这份文件不在，就跳过而不是造假绿）
// ---------------------------------------------------------------
const backendSrc = (() => {
  try {
    return readFileSync(
      resolve(process.cwd(), '../sinan-nft-backend/app/service/AnnouncementCover.php'),
      'utf-8'
    ).replace(/\r\n/g, '\n')
  } catch {
    return null
  }
})()
const storeSrc = readFileSync(resolve(process.cwd(), 'src/stores/notice.js'), 'utf-8').replace(/\r\n/g, '\n')
const crossRepo = backendSrc ? it : it.skip

crossRepo('后端 SUBTYPES 的「键 → 中文标签」和 notice.js 的 categoryLabels 完全一致', () => {
  const m = /public const SUBTYPES = \[([\s\S]*?)\n    \];/.exec(backendSrc)
  expect(m, '后端 AnnouncementCover.php 里 SUBTYPES 的结构变了，这条防漂移要跟着一并改').not.toBeNull()
  const fromBackend = new Map()
  const one = /'([a-z]+)'\s*=>\s*\[\s*'label' => '([^']+)'/g
  let g
  while ((g = one.exec(m[1]))) fromBackend.set(g[1], g[2])
  expect([...fromBackend.keys()]).toEqual(['activity', 'compose', 'consignment', 'operation'])

  const local = new Map()
  const two = /([a-z]+):\s*'([^']+)'/g
  const block = /const categoryLabels = \{([\s\S]*?)\n  \}/.exec(storeSrc)
  expect(block, 'notice.js 的 categoryLabels 写法变了').not.toBeNull()
  while ((g = two.exec(block[1]))) local.set(g[1], g[2])

  expect([...local.entries()]).toEqual([...fromBackend.entries()])
})
