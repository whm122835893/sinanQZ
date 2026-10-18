import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { createHash } from 'node:crypto'

import AppEmpty from '@/components/AppEmpty.vue'

/**
 * 全局空状态（components/AppEmpty.vue）—— 口径变更史，改之前先读：
 *   · 2026-10-13 §7.28：铜鼎实物位图 → 内联 SVG「简笔画开箱 + 朱红回纹」，
 *     三条理由里最关键的一条是「回纹走 var(--color-primary)，后台改主题色图标跟着变」。
 *   · 2026-10-19 用户看完四版对照图点名要回纸箱位图（「直接把那张纸箱位图接回来」），
 *     同日又给了一张「玉雕龙盘铜司南 + 白云」改口：「用这个图做空状态图标，把背景抠掉。
 *     所有页面没有数据的时候就显示这个图。」⇒ 那一版是 -v1，纸箱那版没上线就被取代（§7.54）。
 *   · 同日（§7.55）用户再给一张「珊瑚粉红龙盘铜司南 + 白瓷祥云」：「空状态换成这一张」
 *     ⇒ 现值 = 这张 -v2。**同一个入口、同一套七步抠图，只有像素换了**。
 *
 * 所以这组用例盯的是五件事（这五条从 §7.54 起没改口径，改的是常量）：
 *   1) 渲染的是 <img>，地址精确指向 public 下那张图，且**文件名带 -vN 版本号**
 *      （public/ 下的图没有构建期 hash，撞 nginx 启发式缓存；换图必须换 URL，不许原地覆盖）；
 *      文件名主干仍叫 empty-jade-compass：-v1 是玉白那条龙、-v2 是珊瑚红这条，主干没跟着改色名 ——
 *      主干一改，「下次换图必须再 +1」就变成两套编号，版本号链比形容词准确重要；
 *   2) 那张图**真的带透明通道** —— 「把背景抠掉」是用户点名的要求，容器层用 VP8X 的 ALPHA 标志位 +
 *      ALPH 分块来钉；逐像素的四角 alpha=0 在 RUNBOOK §7.55 里离线量（Node 侧没有 webp 解码器，
 *      别在这里假装有）；
 *   3) 那张图的字节被钉住（md5 + 尺寸 + 体积上限）—— 位图没有描边色可钉，像素就是它的"色值"。
 *      ⚠️ 这三个常量**每换一张图都要现量重钉**，抄上一版的必然错（§7.54 就抄错过一次阈值，见那条第 5 点）；
 *   4) §7.28 那版内联 SVG 不许悄悄回来，文案 prop 只认 description，显示档仍是 120px；
 *   5) 全站只有这一个空状态出口，别有人再往 src 里塞第二张空状态图。
 */

/** 从组件里现读它引用的那张图，不在测试里写死路径（§7.51 的教训：常量要与真源互读） */
const componentSrc = readFileSync(resolve(process.cwd(), 'src/components/AppEmpty.vue'), 'utf-8')
const IMG_REF = (componentSrc.match(/<img[^>]*\bsrc="([^"]+)"/) || [])[1]
// 组件要是被人换回内联 SVG，这里就抠不到地址；留成空串让**具体用例**红，
// 而不是在模块顶层抛异常把整个 spec 打挂（反证 S1 要的就是"红得对"，不是"没跑起来"）。
const IMG_FILE = IMG_REF ? resolve(process.cwd(), 'public', IMG_REF.replace(/^\//, '')) : ''

/** WebP 扩展格式的头部：RIFF....WEBP + VP8X(标志位 / 宽高) —— 透明通道就记在标志位第 4 位 */
const webpHead = (buf) => ({
  riff: buf.subarray(0, 4).toString('ascii'),
  tag: buf.subarray(8, 12).toString('ascii'),
  chunk0: buf.subarray(12, 16).toString('ascii'),
  flags: buf[20],
  width: 1 + (buf[24] | (buf[25] << 8) | (buf[26] << 16)),
  height: 1 + (buf[27] | (buf[28] << 8) | (buf[29] << 16)),
  hasAlphaChunk: buf.subarray(0, Math.min(buf.length, 64)).includes('ALPH')
})

describe('AppEmpty · 龙盘铜司南空状态（-v2 位图）', () => {
  it('渲染的是位图，地址带 -vN 版本号，且只引用这一张', () => {
    const wrapper = mount(AppEmpty)
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)
    expect(IMG_REF).toBe('/images/tab/empty-jade-compass-v2.webp')
    // 换图必须换文件名（原地覆盖 = 老访客被 nginx 缓存钉在旧图上）
    expect(IMG_REF).toMatch(/-v\d+\.webp$/)
    expect(img.attributes('src')).toBe(IMG_REF)
    // 一张空状态只许有一张图
    expect(wrapper.findAll('img')).toHaveLength(1)
  })

  it('背景确实抠掉了：容器带透明通道（VP8X 的 ALPHA 位 + ALPH 分块）', () => {
    const head = webpHead(readFileSync(IMG_FILE))
    expect(head.riff).toBe('RIFF')
    expect(head.tag).toBe('WEBP')
    expect(head.chunk0).toBe('VP8X')
    // 0x10 = ALPHA 标志位；没有它就没有透明通道，"抠背景"这句话就是空的
    expect(head.flags & 0x10).toBe(0x10)
    expect(head.hasAlphaChunk).toBe(true)
  })

  it('§7.28 那版内联 SVG 不许悄悄回来（旧图形两组 g 必须都不在）', () => {
    const wrapper = mount(AppEmpty)
    expect(wrapper.find('svg').exists()).toBe(false)
    expect(wrapper.find('g.app-empty__box').exists()).toBe(false)
    expect(wrapper.find('g.app-empty__motif').exists()).toBe(false)
    expect(componentSrc).not.toMatch(/app-empty__(box|motif)/)
    // 位图版不该出现任何描边色值：颜色只有一个真源，就是那张图的像素
    expect(componentSrc).not.toMatch(/stroke:/)
    // 样式块里一个十六进制色都不许写死（注释里提实测色是允许的，所以只查 <style> 里面）
    const styleBlock = (componentSrc.match(/<style[\s\S]*<\/style>/) || [''])[0]
    expect(styleBlock).not.toMatch(/#[0-9a-fA-F]{3,8}\b/)
    expect(styleBlock).toMatch(/color:\s*\$color-text-secondary/)
  })

  it('那张图的字节被钉住：存在 + 448×448 WebP + md5 + 体积上限', () => {
    expect(existsSync(IMG_FILE)).toBe(true)
    const buf = readFileSync(IMG_FILE)
    const head = webpHead(buf)
    // 448 = 显示档 120 CSS px 在宽屏（32vw 换算下最大约 137.6 CSS px）× DPR 3 的最坏档
    expect([head.width, head.height]).toEqual([448, 448])
    expect(createHash('md5').update(buf).digest('hex'))
      .toBe('bbf51c3a54c63a961f8a825f46c31c77')
    // 上限取上一版铜鼎的 50,423 B：空状态是每页都要加载的图，换图不许把它撑大
    expect(buf.length).toBeLessThanOrEqual(50423)
    expect(buf.length).toBe(38944)
  })

  it('显示档仍是 §7.28 的 120px，object-fit 保住等比不裁切', () => {
    expect(componentSrc).toMatch(/\.app-empty__img\s*{\s*[^}]*width:\s*120px;\s*height:\s*120px;\s*object-fit:\s*contain/)
  })

  it('文案默认「暂无数据」，传 description 时以传入为准', () => {
    expect(mount(AppEmpty).find('.app-empty__text').text()).toBe('暂无数据')
    expect(mount(AppEmpty, { props: { description: '暂无关注的藏品' } })
      .find('.app-empty__text').text()).toBe('暂无关注的藏品')
  })

  it('文字色仍走设计令牌（位图不带主题色，文字必须还能跟着主题走）', () => {
    expect(componentSrc).toMatch(/\.app-empty__text\s*{\s*margin:[^;]+;\s*font-size:[^;]+;\s*color:\s*\$color-text-secondary/)
  })
})

/**
 * 防漂移：空状态图只此一处。
 * 铜鼎位图当年是「列表页 → 详情页 → 空状态」一路复制过来的，改一处漏一处；
 * 纸箱、铜鼎、上一版 -v1 三张现在都是仓库里的死文件，src 里一次都不许出现
 * （-v1 这条从 §7.55 起钉上：换版本时最容易手滑的就是"再指回上一张"）。
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
  const hit = (re) => sources
    .filter((f) => re.test(readFileSync(f, 'utf-8')))
    .map((f) => f.replace(srcRoot, '').replace(/\\/g, '/'))

  it('纸箱、铜鼎、上一版 -v1 三张旧图零引用，新图在 src 下只出现在 AppEmpty.vue 一处', () => {
    expect(hit(/empty-ding/)).toEqual([])
    expect(hit(/empty-carton/)).toEqual([])
    expect(hit(/empty-jade-compass-v1/)).toEqual([])
    expect(hit(/empty-jade-compass/)).toEqual(['/components/AppEmpty.vue'])
  })

  it('引用 AppEmpty 的页面只用 description 传文案（写成 text= 是不会生效的）', () => {
    // 抽签页当年就是写了 text="暂无抽签活动"，组件只认 description，页面一直显示「暂无数据」
    expect(hit(/<AppEmpty[^>]*\btext=/)).toEqual([])
  })

  it('AppEmpty 只声明 description 一个 prop', () => {
    expect(componentSrc).toMatch(/defineProps\(\{\s*description:\s*\{[^}]*\}\s*\}\)/)
    expect(componentSrc).not.toMatch(/image:\s*\{/)
  })
})
