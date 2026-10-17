import { describe, expect, it } from 'vitest'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import crypto from 'node:crypto'

/**
 * 文物展览区头图：深暗底青铜方鼎 + 白字（2026-10-17，RUNBOOK §7.48）
 *
 * 用户口径三句：「文物展览区的顶部背景图再换一张」+ 选「青铜重器：鼎 / 簋为主角」
 * + 选「深暗底 + 白字」+ 三张候选里点了 A（方鼎）。
 *
 * 这块格子以前没人管过：§7.x 从头到尾没动过展览区头图，`exhibit-header-ancient.jpg`
 * 是 2026-09-25 落的，全仓库只有 `Mall.vue:94` 一处引用，也没有任何测试钉过它 —— 所以这一份 spec
 * 是**新建**，不是翻转旧钉（与 §7.43~§7.47 那五轮邀新卡不同，那几轮是推翻，这一轮是开荒）。
 *
 * 关键的一条：**换暗底图必须连文字色一起换**。旧标题色 `#2c1d0f` 压在新图上，
 * 真截图逐像素量下来「比背景板亮的点 = 0 个、盒内最大亮度差 0.0145」= 完全看不见。
 * 所以字色那三条（正色 + 两个旧色不许回来）与图本身是**同一件事**，谁单独回退都会红。
 *
 * 下面这些数不是估的，两套独立实现各量一遍、互相对上才算数：
 *   · 离线设计值（qa/make_exhibit_header_bronze.py，按 390x150 盒 + 28px 字合成）：标题 20.37:1、副标 14.52:1
 *   · 真渲染（headless Chrome CDP，四档视口逐档量，见 RUNBOOK §7.48 的表）：下面 cdp 那四个数是
 *     **四档各自的最坏值**（不是某一档的读数）—— 标题底 13.67:1 出在 360 档、笔画核心 12.96:1 出在 430 档；
 *     副标底 14.06:1（390 与 430 同值）、笔画核心 11.49:1 出在 360 档。
 *     与离线那套差这么多是因为 postcss 把 SCSS 的 px 全换成了 vw（设计基准 375），
 *     390 视口下盒子是 156 高、字是 29.12px，量具取的盒子不一样；**两边都远高于门槛，四档结论一致**。
 *   · 文字安全区右界（从"藏掉字"那张背景板上逐列扫出来的第一列亮区）：标题行 x=205.7、副标行 x=318.7（390 视口 CSS px）
 *
 * jsdom 量不到背景图，也量不到 px→vw 之后的长度，所以这里钉的是源码参数、文件指纹和文案上界；
 * 像素级的对比度钉在常量里，用 md5 把「这几个数」和「这一张图」绑死 —— 换图不改数就红，改图不改数也红（§7.46 同款限制）。
 */

const ROOT = resolve(__dirname, '..')
const SFC = readFileSync(resolve(ROOT, 'src/views/Mall.vue'), 'utf8')
const STYLE = SFC.slice(SFC.indexOf('<style'))
const TEMPLATE = SFC.slice(0, SFC.indexOf('<style'))

const HEAD_START = STYLE.indexOf('.exhibit-header {')
const HEAD = HEAD_START < 0 ? '' : STYLE.slice(HEAD_START, STYLE.indexOf('\n}', HEAD_START))

function blockOf(selector) {
  const start = HEAD.indexOf(selector)
  if (start < 0) return ''
  const body = HEAD.slice(start + selector.length)
  const open = body.indexOf('{')
  if (open < 0) return ''
  let depth = 0
  for (let i = open; i < body.length; i++) {
    if (body[i] === '{') depth++
    else if (body[i] === '}') { depth--; if (depth === 0) return body.slice(open + 1, i) }
  }
  return ''
}

// 注释里写着「旧深棕字 #2c1d0f 只有 1.25:1」这类话，负向断言得先剥注释，否则是自己绊自己
const code = (s) => s.replace(/\/\/[^\n]*/g, '')

const ART = '/images/exhibits/exhibit-header-bronze-ding-v2.webp'
const OLD_ART = 'exhibit-header-ancient.jpg'
const FILE = resolve(ROOT, 'public' + ART)
const MD5 = 'd09524b2440f316d4553bc7a84b33df2'
const BYTES = 52972

// 量具读数（见文件头那两套实现）。改图必须重量并同步这里，md5 那条会先拦下来。
// cdp 四个数 = 四档视口逐档量的**最坏值**（原始逐档读数在 E:\tmp\v48\contrast48.txt）：
//   档      标题底/核心      副标底/核心
//   360x780  13.67 / 13.40   14.07 / 11.49
//   390x844  13.81 / 13.02   14.06 / 11.64
//   430x932  13.81 / 12.96   14.06 / 11.71
//   768x1024 19.91 / 16.50   14.09 / 11.63
// 原来这里抄串过档（titleInk 取了 390 的 13.02、subInk 却取了 360 的 11.49），2026-10-17 改成"每档都取最坏"。
const CONTRAST = {
  offline: { title: 20.37, sub: 14.52 },
  cdp: { titlePlate: 13.67, titleInk: 12.96, subPlate: 14.06, subInk: 11.49 },
  // 控制组：旧深棕字压在这张暗底上，真截图里"比背景板亮的点"的个数
  brownInkPixels: 0,
  safeZone: { titleX: 205.7, subX: 318.7 }
}

describe('展览区头图：只认这一张深暗底方鼎，且旧图不许回来', () => {
  it('先把 .exhibit-header 这块样式找出来，后面每条钉的都不是空气', () => {
    expect(HEAD_START, 'Mall.vue 里找不到 .exhibit-header 块').toBeGreaterThan(-1)
    expect(blockOf('&__title')).toContain('color')
    expect(blockOf('&__sub')).toContain('color')
  })

  it('文件在，SFC 里写的地址就是它（不会出现换完图 404 露出一块深色兜底底）', () => {
    expect(existsSync(FILE)).toBe(true)
    expect(code(STYLE).match(/url\('([^']+)'\)/)[1]).toBe(ART)
  })

  it('旧那张浅底青绿山水的引用一处都不剩（源码 + 模板），这块格子不留双轨', () => {
    expect(code(SFC)).not.toContain(OLD_ART)
    expect(SFC).not.toContain(OLD_ART)
  })

  it('发布图就是这一份字节：md5 与体积钉住，下面那些对比度常量才有效', () => {
    expect(crypto.createHash('md5').update(readFileSync(FILE)).digest('hex')).toBe(MD5)
    expect(statSync(FILE).size).toBe(BYTES)
  })

  it('体积比旧图小一个数量级：这是列表页首屏，不该为一张头图拖慢它', () => {
    expect(BYTES).toBeLessThan(511 * 1024 / 8)   // 旧图 523,525 B
    expect(statSync(FILE).size).toBeLessThanOrEqual(80 * 1024)
  })
})

describe('几何：cover 的裁切与分辨率', () => {
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

  const [W, H] = webpSize(readFileSync(FILE))
  // 头图盒子 375x150 设计 px；postcss 把两个数都换成 vw，所以**这个比例在任何视口都不变**
  const BOX = 375 / 150

  it('宽高比贴着盒子：cover 只会横向各裁掉 2% 左右，不会把右边那只鼎裁掉', () => {
    expect(W / H).toBeGreaterThan(BOX)
    expect((W / H - BOX) / (W / H) / 2, '单侧裁切比例').toBeLessThan(0.05)
  })

  it('分辨率够 3 倍图：暗部一旦糊成色带，那块"博物馆打光"就没了', () => {
    expect(W).toBeGreaterThanOrEqual(390 * 3)
    expect(H).toBeGreaterThanOrEqual(150 * 3)
  })

  it('盒子高度仍是 150 设计 px：改它等于改裁切带，那些对比度数全部作废', () => {
    const m = /height:\s*(\d+(?:\.\d+)?)px/.exec(code(HEAD))
    expect(m && parseFloat(m[1]), '.exhibit-header 的 height').toBe(150)
  })
})

describe('暗底必须配白字：字色与投影三组钉', () => {
  it('标题纯白、副标 85% 白（与量具里那两个数一一对应）', () => {
    expect(code(blockOf('&__title'))).toMatch(/color:\s*#FFFFFF/)
    expect(code(blockOf('&__sub'))).toMatch(/color:\s*rgba\(255,\s*255,\s*255,\s*0\.85\)/)
  })

  it('旧那两个浅底深字色不许回到这块块里：#2c1d0f 在这张图上量出来是"看不见"', () => {
    const body = code(HEAD)
    expect(body).not.toMatch(/#2c1d0f/i)
    expect(body).not.toMatch(/#5a3a1f/i)
  })

  it('投影换成压深的黑，原来那圈暖白辉光（给浅底深字用的）一出现就该红', () => {
    const body = code(HEAD)
    expect(body).not.toContain('rgba(255,245,215')
    expect(blockOf('&__title')).toMatch(/text-shadow:\s*0 1px 6px rgba\(0,0,0,0\.65\)/)
  })

  it('那层上浅下深的压暗渐变还在：它是这两个对比度数的一部分，删了要重量', () => {
    const body = code(HEAD)
    expect(body).toMatch(/linear-gradient\(180deg,\s*rgba\(0,0,0,0\.08\)\s*0%,\s*rgba\(0,0,0,0\.55\)\s*100%\)/)
  })
})

describe('对比度：两套独立实现都要过 WCAG，控制组必须红', () => {
  it('标题（28px 粗体 = 大字号，要求 ≥3:1）：离线 20.37、真渲染四档最坏 底 13.67 / 笔画核心 12.96', () => {
    expect(CONTRAST.offline.title).toBeGreaterThanOrEqual(3)
    expect(CONTRAST.cdp.titlePlate).toBeGreaterThanOrEqual(3)
    expect(CONTRAST.cdp.titleInk).toBeGreaterThanOrEqual(3)
  })

  it('副标（13px 正文，要求 ≥4.5:1）：离线 14.52、真渲染四档最坏 底 14.06 / 笔画核心 11.49', () => {
    expect(CONTRAST.offline.sub).toBeGreaterThanOrEqual(4.5)
    expect(CONTRAST.cdp.subPlate).toBeGreaterThanOrEqual(4.5)
    expect(CONTRAST.cdp.subInk).toBeGreaterThanOrEqual(4.5)
  })

  it('控制组：旧深棕字在这张底上"比背景板亮的点"必须是 0 个 —— 这条红就说明量具有病', () => {
    expect(CONTRAST.brownInkPixels).toBe(0)
  })
})

describe('文案长度：踩过亮区就得重量，别顺手改文案', () => {
  const title = /class="exhibit-header__title">([^<]+)</.exec(TEMPLATE)[1]
  const sub = /class="exhibit-header__sub">([^<]+)</.exec(TEMPLATE)[1]

  it('标题就是这五个字', () => {
    expect(title).toBe('文物展览区')
    expect(sub).toBe('司南 · 数字文物典藏')
  })

  it('标题 ≤6 字：第 7 个字的墨迹会踩进 x≥205.7 的鼎身亮区，白字掉到 3:1 以下', () => {
    // 390 视口下每个字 ≈31.2px（29.12 字号 + 2.08 字距），现墨迹右界 x≈169.7；6 字到 201，7 字到 232
    expect([...title].length, '标题超过 6 字要重量白字与鼎身高光边的间距').toBeLessThanOrEqual(6)
  })

  it('副标 ≤22 字：超过会踩进 x≥318.7 的亮区，85% 白掉到 4.5:1 以下', () => {
    // 副标行更靠下，那一行的亮区在 318.7；现墨迹右界 x≈146.7，每字 ≈14.9px ⇒ 还能再放 11 个字
    expect([...sub].length, '副标超过 22 字要重量').toBeLessThanOrEqual(22)
  })
})
