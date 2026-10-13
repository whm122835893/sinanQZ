import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { resolve } from 'node:path'

import AppEmpty from '@/components/AppEmpty.vue'

/**
 * 全局空状态（components/AppEmpty.vue）—— 2026-10-13 用户定：
 *   「空状态改成简笔画箱子图标，符合网站特点」。
 *   线上原来是铜鼎实物位图（50KB PNG，见 RUNBOOK 7.5），现换成内联 SVG 简笔画开箱 + 回纹。
 *
 * 这组用例盯三件事：
 *   1) 图形确实是内联 SVG（换回 <img> 就拿不到主题色、也要重新跟 nginx 缓存较劲）；
 *   2) 两处描边都走设计令牌，不写死色值（回纹必须跟后台主题色联动）；
 *   3) 全站只有这一个空状态组件，别有人再往 src 里塞位图空状态图或写错 prop。
 */
describe('AppEmpty · 简笔画箱子空状态', () => {
  it('渲染的是内联 SVG，不再引用任何位图', () => {
    const wrapper = mount(AppEmpty)
    expect(wrapper.find('svg').exists()).toBe(true)
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('箱体线条 + 回纹两组都在，且各有自己的作用', () => {
    const wrapper = mount(AppEmpty)
    const box = wrapper.find('g.app-empty__box')
    const motif = wrapper.find('g.app-empty__motif')
    expect(box.exists()).toBe(true)
    expect(motif.exists()).toBe(true)
    // 开箱：6 条路径（箱口、箱内沿、两片翻盖、左右两壁）
    expect(box.findAll('path')).toHaveLength(6)
    // 回纹：两壁各 2 枚
    expect(motif.findAll('path')).toHaveLength(4)
  })

  it('文案默认「暂无数据」，传 description 时以传入为准', () => {
    expect(mount(AppEmpty).find('.app-empty__text').text()).toBe('暂无数据')
    expect(mount(AppEmpty, { props: { description: '暂无关注的藏品' } })
      .find('.app-empty__text').text()).toBe('暂无关注的藏品')
  })

  it('描边色只用设计令牌，不写死十六进制', () => {
    const text = readFileSync(resolve(process.cwd(), 'src/components/AppEmpty.vue'), 'utf-8')
    expect(text).toMatch(/\.app-empty__box\s*{\s*stroke:\s*\$color-text-secondary/)
    // 回纹跟随后台主题色：$color-primary 编译成 var(--color-primary)，site.js 运行时会改写
    expect(text).toMatch(/\.app-empty__motif\s*{\s*stroke:\s*\$color-primary/)
    expect(text).not.toMatch(/stroke:\s*['"]?#/)
  })
})

/**
 * 防漂移：空状态图只此一处。
 * 铜鼎位图当年是「列表页 → 详情页 → 空状态」一路复制过来的，改一处漏一处；
 * 现在图形写在组件里，任何页面再引一张空状态图都算走偏。
 */
describe('空状态防漂移', () => {
  const srcRoot = resolve(process.cwd(), 'src')
  const files = []
  const walk = (dir) => {
    for (const name of readdirSync(dir)) {
      const p = `${dir}/${name}`
      if (statSync(p).isDirectory()) walk(p)
      else files.push(p)
    }
  }
  walk(srcRoot)
  const sources = files.filter((f) => /\.(vue|js)$/.test(f))

  it('src 下没有任何页面再引用空状态位图', () => {
    const hits = sources.filter((f) => /empty-ding|empty-carton/.test(readFileSync(f, 'utf-8')))
    expect(hits).toEqual([])
  })

  it('引用 AppEmpty 的页面只用 description 传文案（写成 text= 是不会生效的）', () => {
    // 抽签页当年就是写了 text="暂无抽签活动"，组件只认 description，页面一直显示「暂无数据」
    const bad = sources
      .map((f) => ({ f, text: readFileSync(f, 'utf-8') }))
      .filter(({ text }) => /<AppEmpty[^>]*\btext=/.test(text))
      .map(({ f }) => f.replace(srcRoot, ''))
    expect(bad).toEqual([])
  })

  it('AppEmpty 只声明 description 一个 prop', () => {
    const text = readFileSync(resolve(srcRoot, 'components/AppEmpty.vue'), 'utf-8')
    expect(text).toMatch(/defineProps\(\{\s*description:\s*\{[^}]*\}\s*\}\)/)
    expect(text).not.toMatch(/image:\s*\{/)
  })
})
