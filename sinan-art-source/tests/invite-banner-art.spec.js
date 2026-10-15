import { describe, expect, it } from 'vitest'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * 「我的」页邀新卡：深色满幅海报（2026-10-16，RUNBOOK §7.43）
 *
 * 口径换了一次：§7.42 定的是「浅底图 + 左边铺白纱 + 深红字」，本轮用户推翻 ——
 * 要海报感、把白色遮罩删掉、字改白、图标换成与日历同风格的位图。
 * 按项目纪律，旧口径的钉**翻方向保留**而不是删掉：白纱、降透明度、深红标题这三样
 * 现在从「必须这么写」变成「一出现就该红」。
 *
 * 删掉遮罩后可读性就得由图自己扛，所以这几组数字是离线量出来的（GD 按真实 CSS 几何合成，
 * 脚本已随本轮删除，数字记在 RUNBOOK §7.43）：
 *   · 卡 343x76，文字起点 x=72（= padding16 + tile44 + gap12），副标墨迹右界 x=215（12 字 ≈143.3px）
 *   · 标题行盒内最坏 18.46:1、副标行盒内最坏 8.25:1；§7.42 旧渲染基线 8.28:1 / 3.32:1 ⇒ 只升不降
 *   · 图里「白字对比掉到 4.5:1 以下」的最左列：标题行 x=236、副标行 x=226 ⇒ 副标再加一个字就踩亮区
 *
 * 2026-10-16 再改一处（RUNBOOK §7.44）：那颗「邀请」按钮从 #C00000 实底 + 白字换成**透明灰 + 白字**。
 * 中途我先按「液态玻璃白 + 深红字」做过一版（α=0.85 那组数字见台账），用户随后把口径改成"透明灰色"，
 * 于是那版连同 backdrop-filter 一起被换掉 —— 两版的旧钉都留在下面，写成"一出现就该红"。
 * 灰板压在深色海报上，按钮覆盖范围内底图亮度跨度是 L=0.005~0.797（右端是玉璧高光），所以：
 *   白字最坏点（逐像素，不带 blur）：α=0.55→4.20、0.58→4.51、0.62→4.98、0.65→5.37（AA 线 4.5）
 *   按钮整框（含上下沿）最坏 4.64:1；把按钮往左扩到 x=200 各列仍 ≥4.5 ⇒ 标签加到 5 字以内不必重量
 *   同一个合成下深红 #C00000 字最坏只有 1.00~1.57:1 ⇒ 透明灰底上白字是唯一站得住的选择
 *   旧渲染是 6.48:1（实底红 + 白字，与底图无关）⇒ 换透明灰后降到 4.98:1，仍过 AA 但这是**降**，台账里写明
 *
 * 上面那几行是**离线 GD** 按 CSS 几何合成的读数。落位后又用 headless Chrome（CDP，390x844/dpr2）走真排版复量了一遍：
 * 「邀请」两字的真实行盒（29x16 设备px）上最坏 **5.12:1**，整块板子外接框上最坏 **4.69:1** —— 两套独立实现互相对上，才算数。
 * 复量还推翻了我自己写的一条注释：只把横向内边距从 18 收到 17 时，外沿比改之前**高 2.6 设备px**（上下两条边框没补回来），
 * 所以内边距是**四个方向各收 1px**（`7px 17px`），改完之后新旧外沿差 0.1px。
 *
 * jsdom 量不到 ::before 伪元素，也量不到 px→vw 之后的长度，所以这里钉的是参数和文案，实测数字看台账。
 */

const ROOT = resolve(__dirname, '..')
const SFC = readFileSync(resolve(ROOT, 'src/views/User.vue'), 'utf8')
const STYLE = SFC.slice(SFC.indexOf('<style'))
const TEMPLATE = SFC.slice(0, SFC.indexOf('<style'))

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

// 注释里写着「不叠白纱、也不降 opacity」这类话，负向断言得先剥注释，否则是自己绊自己
const code = (s) => s.replace(/\/\/[^\n]*/g, '')
const px = (block, prop) => {
  const m = new RegExp(`\\b${prop}:\\s*(-?[\\d.]+)px`).exec(code(block))
  return m ? parseFloat(m[1]) : null
}

const BEFORE = blockOf('&::before')
const ART = '/images/invite-banner-bg-v4.webp'

describe('邀新卡背景：深色满幅海报，只认 v4 这一张', () => {
  it('先把 .invite-banner 这块样式找出来，后面每条钉的都不是空气', () => {
    expect(BANNER.length).toBeGreaterThan(200)
    expect(BEFORE.length).toBeGreaterThan(50)
  })

  it('::before 用的是 v4 深色海报；旧 jpg、中途的 v2、上一轮的 v3 都不再被引用', () => {
    expect(BEFORE).toContain(`url('${ART}')`)
    expect(BEFORE).toContain('center/cover no-repeat')
    // v3 = §7.42 那套浅底图的归档地址，回到它上面来就是口径回退
    for (const gone of ['invite-banner-bg.jpg', 'invite-banner-bg-v2', 'invite-banner-bg-v3']) {
      expect(STYLE, `${gone} 已经不用了，别再引回样式里`).not.toContain(gone)
      expect(SFC, `${gone} 已经不用了，别再引回 SFC 里`).not.toContain(gone)
    }
  })

  it('旧的洗白滤镜（brightness/saturate）已经拆掉，深色古物不会被洗成灰泥', () => {
    expect(BEFORE).not.toMatch(/filter:\s*brightness/)
    expect(BEFORE).not.toMatch(/saturate\(/)
  })
})

describe('§7.42 的白纱与降透明度：翻成「一出现就该红」', () => {
  it('背景层不许再叠 linear-gradient 白纱（深色图自己扛可读性）', () => {
    expect(code(BEFORE)).not.toContain('linear-gradient')
    expect(code(BEFORE)).not.toMatch(/rgba\(255,\s*255,\s*255,\s*0\.\d+\)/)
  })

  it('背景层不许降 opacity：.85 会把深色海报洗成奶昔', () => {
    const m = /opacity:\s*([\d.]+)/.exec(code(BEFORE))
    expect(m === null || Number(m[1]) === 1, `::before 的 opacity 只允许 1 或不写，现在是要 ${m && m[1]}`).toBe(true)
  })

  it('卡片自己的兜底底色是深色：图没传上去时也不会「白底白字」空转', () => {
    expect(code(BANNER)).toMatch(/background:\s*#1A0D10/i)
    // 边框宽度参与盒子尺寸，改它会挪动文字区起点，所以顺手钉住
    expect(code(BANNER)).toMatch(/border:\s*1px\s+solid/)
  })
})

describe('文字改成压在深底上的浅色：旧深红/棕色不许改回来', () => {
  it('标题纯白、副标近白，且都带 text-shadow 兜住踩到高光边的笔画', () => {
    const title = code(blockOf('&__title'))
    const sub = code(blockOf('&__sub'))
    expect(title).toMatch(/color:\s*#FFFFFF\b/i)
    expect(title).toMatch(/text-shadow:/)
    expect(sub).toMatch(/color:\s*rgba\(255,\s*255,\s*255,\s*([\d.]+)\)/i)
    expect(sub).toMatch(/text-shadow:/)
    // 上界：副标再淡下去就吃掉量出来的 8.25:1 余量
    const alpha = parseFloat(/rgba\(255,\s*255,\s*255,\s*([\d.]+)\)/i.exec(sub)[1])
    expect(alpha, '副标白度低于 .85 就不达标了').toBeGreaterThanOrEqual(0.85)
  })

  it('§7.42 的深红标题 #9A1A1A 与棕色副标 rgba(80,50,20,.6) 整块都不许再出现', () => {
    expect(code(BANNER)).not.toMatch(/#9A1A1A/i)
    expect(code(BANNER)).not.toMatch(/rgba\(\s*80,\s*50,\s*20/)
  })

  it('按钮不再是一整块实色：既不许回到 #C00000 实底，也不许做成实色灰块', () => {
    const btn = code(blockOf('&__btn'))
    // §7.43 的「实色红底 + 白字」和这一轮中途试过的「乳白玻璃 + 深红字」都翻成方向钉：出现就该红
    expect(btn, '按钮底色回到实色红 = 透明灰口径被推回去').not.toMatch(/background:\s*#C00000/)
    expect(btn, '底色必须是 rgba 半透明，α=1 就成实色灰块了').toMatch(/background:\s*rgba\(/)
    expect(btn, '乳白玻璃底（rgba(255,255,255,.x)）已经不是本轮口径').not.toMatch(/background:\s*rgba\(255,\s*255,\s*255/)
    expect(btn, '深红字压在透明灰上只有 1.00~1.57:1，等于看不见').not.toMatch(/color:\s*#C00000/i)
  })

  it('透明灰这块板：中性深灰 + α≥0.62 + 1px 半透明亮边 + 内高光', () => {
    const btn = code(blockOf('&__btn'))
    const m = /background:\s*rgba\(\s*46,\s*46,\s*52,\s*([\d.]+)\s*\)/.exec(btn)
    expect(m, '按钮底不是那个量出来的中性灰 rgb(46,46,52)').not.toBeNull()
    const alpha = Number(m[1])
    // 下界 0.62：白字最坏点 α=0.58 只有 4.51:1、0.62 才 4.98:1（AA 线 4.5，按钮整框最坏 4.64:1）
    expect(alpha, 'α 低于 0.62 就踩破 4.5:1（底下海报右端有玉璧高光，最坏点在按钮上沿）').toBeGreaterThanOrEqual(0.62)
    expect(alpha, 'α=1 就不是"透明"灰了').toBeLessThan(1)
    expect(btn).toMatch(/color:\s*#FFFFFF\b/i)
    expect(btn).toMatch(/border:\s*1px\s+solid\s+rgba\(255,\s*255,\s*255,\s*[\d.]+\)/)
    expect(btn).toMatch(/box-shadow:[^;]*inset/)
  })

  it('按钮这块不靠 backdrop-filter 撑可读性：写了 blur，上面那串对比度数字就不再是真机读数', () => {
    expect(code(blockOf('&__btn'))).not.toMatch(/backdrop-filter/)
  })

  it('按钮外沿没被新边框撑大：1px 边框 + 四个方向内边距各收 1px，海报上被它盖住的那片像素区域没变', () => {
    const btn = code(blockOf('&__btn'))
    // 竖向从 8 收成 7、横向从 18 收成 17（各让 1px 给新边框）。写成简写整串是因为 px() 只取第一个数。
    // 只收左右那一版（8px 17px）浏览器实测外沿比旧版高 2.6 设备px —— 上下两条边框没补回来，
    // 被盖住的海报区域会长一圈，量对比度用的几何前提就废了，所以这里连 7 一起钉死。
    expect(btn, '按钮内边距应是 7px 17px（四个方向各让 1px 给边框，外沿才是同一个盒子）').toMatch(/padding:\s*7px\s+17px/)
    expect(btn).toMatch(/border:\s*1px\s+solid/)
    expect(px(btn, 'font-size')).toBe(14)
  })
})

describe('量对比度时那套几何前提：挪一位就得重量', () => {
  it('文字起点 = padding16 + 图标位44 + gap12 = 72px，三段都没被人改', () => {
    expect(px(BANNER, 'padding')).toBe(16)
    expect(px(BANNER, 'gap')).toBe(12)
    expect(px(blockOf('&__icon'), 'width')).toBe(44)
    expect(px(blockOf('&__icon'), 'width') + px(BANNER, 'padding') + px(BANNER, 'gap')).toBe(72)
    // 图标位是 44x44 的定宽块；flex-shrink:0 一旦被删，窄屏上 72 这个起点就不成立了
    expect(code(blockOf('&__icon'))).toMatch(/flex-shrink:\s*0/)
  })

  it('背景层压在下面：::before 是 z-index 0，图标/文字/按钮都是 z-index 1', () => {
    expect(BEFORE).toMatch(/z-index:\s*0/)
    for (const el of ['&__icon', '&__text', '&__btn']) {
      expect(blockOf(el), `${el} 要盖在背景图上`).toMatch(/z-index:\s*1/)
    }
  })

  it('文案本身钉住：改这两个字符串是有意动作，别顺手带进来', () => {
    expect(/class="invite-banner__title">([^<]+)</.exec(TEMPLATE)[1]).toBe('司南·邀新玩法')
    expect(/class="invite-banner__sub">([^<]+)</.exec(TEMPLATE)[1]).toBe('同游作伴，潮流好礼不设限')
  })

  it('文案长度是量出来的上界：副标第 13 个字会踩进 x≥226 的亮区（对比掉到 4.5:1 以下）', () => {
    // 故意与上一条分开：改了文案 + 更新了上一条的期望值之后，这一条还要继续拦着，逼人重量一次
    const title = /class="invite-banner__title">([^<]+)</.exec(TEMPLATE)[1]
    const sub = /class="invite-banner__sub">([^<]+)</.exec(TEMPLATE)[1]
    // 标题 7 字墨迹到 x=165（该行亮区从 236 起）、副标 12 字到 x=215（亮区从 226 起，一个字≈12px）
    expect([...title].length, '标题超过 7 字就要重量白字与玉璧高光边的间距').toBeLessThanOrEqual(7)
    expect([...sub].length, '副标超过 12 字会压到海报亮区，白字对比掉到 4.5:1 以下').toBeLessThanOrEqual(12)
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

  it('文件在，且 SFC 里写的地址就是它（不会出现换完图 404 露出深色兜底底）', () => {
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

  it('分辨率够 3 倍图：深色海报一旦糊了，那些暗部渐变会变成一条条色带', () => {
    const [w, h] = webpSize(readFileSync(file))
    expect(w).toBeGreaterThanOrEqual(343 * 3)
    expect(h).toBeGreaterThanOrEqual(76 * 3)
  })
})
