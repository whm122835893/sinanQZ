import { describe, expect, it } from 'vitest'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * 「我的」页邀新卡背景图：换成与公告首图同一套古物写实风格（2026-10-15，RUNBOOK §7.42）
 *
 * 这张卡是 343x76 的窄横条，标题+副标题的可用宽度一直铺到卡片 71% 处，
 * 而发布图里有一条穿过整幅的细金线、右下角还有古物主体。所以"换图"必须连着改洗白方式：
 *   1. 旧的 opacity .3 + brightness(1.6) 只能洗浅图，压不住深色古物，会把玉璧洗成灰泥；
 *      现在改成"左边铺满白纱、72%~88% 收尽"，文字底下永远是纯白，右边才露古物；
 *   2. 文字区的对比是量出来的：新渲染标题最坏 8.28:1、副标 3.60:1，
 *      换图前旧渲染是 8.28:1 / 3.32:1 —— 只准不劣，不准更差；
 *   3. 图本身按卡片比例(4.51)预切成一条横带，避开生成器的右下角水印，
 *      所以文件宽高比必须留在 cover 不裁掉主体的区间内。
 *
 * jsdom 量不到 ::before 的伪元素样式，也量不到 px→vw 之后的长度，
 * 所以这里钉的是"参数没被人改回去"，实测数字见 RUNBOOK §7.42 的量表。
 */

const ROOT = resolve(__dirname, '..')
const SFC = readFileSync(resolve(ROOT, 'src/views/User.vue'), 'utf8')
const STYLE = SFC.slice(SFC.indexOf('<style'))

// .invite-banner 整块（SCSS 里嵌套层都用缩进收尾，第一个顶格 } 就是这块的结束）
const BANNER_START = STYLE.indexOf('.invite-banner {')
const BANNER = BANNER_START < 0 ? '' : STYLE.slice(BANNER_START, STYLE.indexOf('\n}', BANNER_START))

function blockOf(selector) {
  const start = BANNER.indexOf(selector)
  if (start < 0) return ''
  const body = BANNER.slice(start + selector.length)
  const open = body.indexOf('{')
  if (open < 0) return ''
  let depth = 0
  for (let i = open; i < body.length; i++) {
    if (body[i] === '{') depth++
    else if (body[i] === '}') { depth--; if (depth === 0) return body.slice(open + 1, i) }
  }
  return ''
}

const BEFORE = blockOf('&::before')
const ART = '/images/invite-banner-bg-v3.webp'

describe('邀新卡背景图：只认这一张同风格发布图', () => {
  it('先把 .invite-banner 这块样式找出来，后面每条钉的都不是空气', () => {
    expect(BANNER.length).toBeGreaterThan(200)
    expect(BEFORE.length).toBeGreaterThan(50)
  })

  it('::before 用的是 v3 古物图，旧的那张 jpg 与中途的 v2 都不再被引用', () => {
    expect(BEFORE).toContain(`url('${ART}')`)
    expect(BEFORE).toContain('center/cover no-repeat')
    expect(STYLE).not.toContain('invite-banner-bg.jpg')
    expect(STYLE).not.toContain('invite-banner-bg-v2')
    expect(SFC).not.toContain('invite-banner-bg.jpg')
  })

  it('旧的洗白滤镜（brightness/saturate）已经拆掉，深色古物不会被洗成灰泥', () => {
    expect(BEFORE).not.toMatch(/filter:\s*brightness/)
    expect(BEFORE).not.toMatch(/saturate\(/)
  })

  it('白纱铺满文字区再到 72%、88% 收尽：这两个 stop 是量出来的，改窄就压不住金线', () => {
    expect(BEFORE).toMatch(/linear-gradient\(\s*90deg,\s*#fff\s+0%,\s*#fff\s+72%/)
    expect(BEFORE).toMatch(/rgba\(255,\s*255,\s*255,\s*0\)\s+88%/)
    expect(BEFORE).toMatch(/opacity:\s*0\.85/)
  })

  it('背景层压在文字下面：::before 是 z-index 0，图标/文字/按钮都是 z-index 1', () => {
    expect(BEFORE).toMatch(/z-index:\s*0/)
    for (const el of ['&__icon', '&__text', '&__btn']) {
      expect(blockOf(el), `${el} 要盖在背景图上`).toMatch(/z-index:\s*1/)
    }
  })

  it('标题深红、按钮主红、副标棕色都没跟着换图一起动', () => {
    expect(blockOf('&__title')).toMatch(/color:\s*#9A1A1A/)
    expect(blockOf('&__btn')).toMatch(/background:\s*#C00000/)
    expect(blockOf('&__sub')).toMatch(/color:\s*rgba\(80,\s*50,\s*20,\s*0\.6\)/)
  })
})

describe('邀新卡发布图本体：尺寸、体积、比例都对得上卡片', () => {
  const file = resolve(ROOT, 'public' + ART)

  function webpSize(buf) {
    if (buf.subarray(0, 4).toString() !== 'RIFF' || buf.subarray(8, 12).toString() !== 'WEBP') {
      throw new Error('不是 webp')
    }
    const fourCC = buf.subarray(12, 16).toString()
    if (fourCC === 'VP8 ') {
      const i = buf.indexOf(Buffer.from([0x9d, 0x01, 0x2a]))
      return [buf.readUInt16LE(i + 3) & 0x3fff, buf.readUInt16LE(i + 5) & 0x3fff]
    }
    if (fourCC === 'VP8X') {
      return [1 + buf.readUIntLE(24, 3), 1 + buf.readUIntLE(27, 3)]
    }
    throw new Error(`未识别的 webp chunk: ${fourCC}`)
  }

  it('文件在，且 SFC 里写的地址就是它（不会出现换完图 404 露出白底）', () => {
    expect(existsSync(file)).toBe(true)
    expect(SFC.match(/url\('([^']+\.webp)'\)/)[1]).toBe(ART)
  })

  it('体积守在 40KB 以内：这张卡是首页级入口，不该为一张底图拖慢「我的」页', () => {
    expect(statSync(file).size).toBeLessThanOrEqual(40 * 1024)
  })

  it('宽高比对得上卡片(343x76)，cover 不会把右边古物裁掉', () => {
    const [w, h] = webpSize(readFileSync(file))
    const card = 343 / 76
    expect(w / h).toBeGreaterThan(card * 0.97)
    expect(w / h).toBeLessThan(card * 1.03)
  })
})
