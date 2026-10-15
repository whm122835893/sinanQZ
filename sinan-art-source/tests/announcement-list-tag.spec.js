import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * 公告列表页的「类型标签」底色（2026-10-15，RUNBOOK §7.41）
 *
 * 你的原话：「现在公告列表的公告分类全是红的，改成和详情里面的分类一样的颜色」。
 * 口径：
 *   1. 底色来自列表接口逐行下发的 tagColor（后端 AnnouncementCover::colorFor 已收敛成 #rrggbb），
 *      前端不再写死平台红；
 *   2. 校验与兜底只有 utils/tagColor.js 一个出口，列表页与详情页共用 ——
 *      同一条公告的分类标签在两处必须是同一个颜色，脏值也退回同一个主红；
 *   3. 只改颜色，标签的尺寸/圆角/文字仍走 AppTag 原来的样子（你没要求对齐像素）。
 */

const shared = vi.hoisted(() => ({ list: { list: [] }, detail: {} }))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' } }),
  useRouter: () => ({ push: vi.fn(), back: vi.fn(), options: { history: { state: { back: null } } } }),
  RouterLink: { template: '<a><slot /></a>' }
}))
vi.mock('@/utils/request', () => ({
  default: {
    // 列表走 '/announcements'，详情走 '/announcements/:id'，按第一个参数分开喂
    get: (url) => Promise.resolve(url === '/announcements' ? shared.list : shared.detail),
    post: async () => ({})
  }
}))

import Notice from '@/views/Notice.vue'

const FALLBACK = 'rgb(192, 0, 0)' // #C00000 平台主红

// jsdom 会把十六进制规范化成 rgb(...)，比对时统一换算
function toRgb(hex) {
  const m = /^#?([0-9a-fA-F]{6})$/.exec(hex)
  if (!m) return hex
  const v = parseInt(m[1], 16)
  return `rgb(${(v >> 16) & 255}, ${(v >> 8) & 255}, ${v & 255})`
}

function row(id, subtype, label, color, extra) {
  return {
    id,
    title: `第${id}条`,
    summary: '摘要',
    subtype,
    subtypeLabel: label,
    tagColor: color,
    createdAt: '2026-10-15 00:00:00',
    ...extra
  }
}

// 四类各一条，颜色就是后端 AnnouncementCover::SUBTYPES 里的主题色
const FOUR = [
  row(1, 'activity', '活动公告', '#C00000'),
  row(2, 'compose', '合成公告', '#B08D55'),
  row(3, 'consignment', '寄售公告', '#E89121'),
  row(4, 'operation', '运营公告', '#3D5A99')
]

function mountList() {
  const wrapper = mount(Notice, { attachTo: document.body })
  return flushPromises().then(() => wrapper)
}

const tagOf = (wrapper, index) => wrapper.findAll('.notice-item')[index].find('.app-tag')

describe('Notice 列表页 · 分类标签跟着接口上色', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    document.body.innerHTML = ''
    shared.list = { list: FOUR }
  })

  it('四类公告 = 四种底色，不再是清一色平台红', async () => {
    const wrapper = await mountList()
    const tags = wrapper.findAll('.notice-item .app-tag')
    expect(tags).toHaveLength(4)
    expect(tags.map((t) => t.text())).toEqual(['活动公告', '合成公告', '寄售公告', '运营公告'])
    FOUR.forEach((r, i) => {
      expect(tags[i].attributes('style') || '').toContain(toRgb(r.tagColor))
    })
  })

  it('单条公告自带非主题色也照接口走（后端 colorFor 已经优先取它）', async () => {
    shared.list = { list: [row(9, 'operation', '运营公告', '#123456')] }
    const wrapper = await mountList()
    expect(tagOf(wrapper, 0).attributes('style')).toContain(toRgb('#123456'))
  })

  it('tagColor 缺失或脏值：退回平台主红，不把脏值写进 style', async () => {
    const dirty = [undefined, null, '', 'red', '#fff', 'url(x)', '"><script>', ' ']
    shared.list = { list: dirty.map((c, i) => row(20 + i, 'consignment', '寄售公告', c)) }
    const wrapper = await mountList()
    dirty.forEach((_, i) => {
      const style = tagOf(wrapper, i).attributes('style') || ''
      expect(style).toContain(FALLBACK)
      expect(style).not.toContain('script')
      expect(style).not.toContain('url(')
    })
  })

  it('列表与详情同一出口：同一条公告在两处的底色一致', async () => {
    const { default: NoticeDetail } = await import('@/views/NoticeDetail.vue')
    shared.list = { list: [row(31, 'consignment', '寄售公告', '#E89121')] }
    shared.detail = { id: 31, title: '甲', content: '正文', subtype: 'consignment', subtypeLabel: '寄售公告', tagColor: '#E89121', createdAt: '' }
    const listWrapper = await mountList()
    // 底色要在挂详情之前读：fetchNotice 会把详情那个对象并回列表那一行（store 里的 sync-back），
    // 之后再看列表，读的就已经是详情塞回去的颜色，列表自己少接一个字段也照样「一致」—— 反证 B 就是这么漏掉的
    const listColor = (tagOf(listWrapper, 0).attributes('style') || '').match(/background-color:\s*([^;]+)/)[1].trim()
    const detailWrapper = mount(NoticeDetail, { attachTo: document.body })
    await flushPromises()
    const detailColor = (detailWrapper.find('.notice-detail__type').attributes('style') || '')
      .match(/background-color:\s*([^;]+)/)[1].trim()
    expect(detailColor).toBe(listColor)
    expect(detailColor).toBe(toRgb('#E89121'))
  })
})

// ---------------------------------------------------------------
// 防漂移：守卫只能有一个出口，列表页也不许再把颜色写死回去
// ---------------------------------------------------------------
const read = (p) => readFileSync(resolve(process.cwd(), p), 'utf-8').replace(/\r\n/g, '\n')

describe('§7.41 防漂移 · 标签色守卫收成单一出口', () => {
  const guard = read('src/utils/tagColor.js')
  const listView = read('src/views/Notice.vue')
  const detailView = read('src/views/NoticeDetail.vue')

  it('兜底色与那条 #rrggbb 正则只写在 utils/tagColor.js 里，两个页面都不许再各写一份', () => {
    expect(guard).toMatch(/#C00000/)
    expect(guard).toContain('/^#[0-9a-fA-F]{6}$/')
    for (const [name, src] of [['Notice.vue', listView], ['NoticeDetail.vue', detailView]]) {
      expect(src, `${name} 里不该再出现第二个兜底色`).not.toMatch(/#C00000/)
      expect(src, `${name} 里不该再出现第二条 #rrggbb 校验`).not.toMatch(/\[0-9a-fA-F\]\{6\}/)
      expect(src, `${name} 没走共享出口`).toMatch(/from '@\/utils\/tagColor'/)
    }
  })

  it('列表页的分类标签不许退回写死平台红（那正是这次要修的毛病）', () => {
    const tag = /<AppTag\b[^>]*>/.exec(listView)
    expect(tag, '找不到列表页那个分类标签').not.toBeNull()
    expect(tag[0], '分类标签必须绑 :color').toMatch(/:color="safeTagColor\(item\.tagColor\)"/)
    expect(tag[0], '不能再写死 type="primary"').not.toMatch(/type="primary"/)
  })

  it('store 的列表映射要带上 tagColor，否则页面拿不到颜色', () => {
    const store = read('src/stores/notice.js')
    const mapping = /async function fetchNotices\(\)[\s\S]*?notices\.value = [\s\S]*?\n {4}\}\)/.exec(store)
    expect(mapping, 'fetchNotices 的映射写法变了').not.toBeNull()
    expect(mapping[0]).toMatch(/tagColor:\s*a\.tagColor \|\| ''/)
  })
})
