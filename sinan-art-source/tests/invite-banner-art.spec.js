import { describe, expect, it } from 'vitest'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import crypto from 'node:crypto'

/**
 * 「我的」页邀新横条：「雾中逆光」海报 + 左侧深色渐隐罩 + 白字（2026-10-17，RUNBOOK §7.49）
 *
 * 用户口径两句：「把邀请好友的横条卡片换成 c，雾中逆光」（= §7.48 那三张候选里的 C，原图 2560x1080）
 * + 在我把「这条带裸着过不了」的证据摆出来之后，选了「左侧加一层深色渐隐罩」。
 *
 * ## 这份 spec 翻了哪三颗旧钉（都是**翻方向**，不是删掉装作没发生过）
 *   1. §7.43 的「::before 不许有 linear-gradient」→ 现在**必须有那层深色罩**，
 *      而「不许有浅色纱」那半句原样留着（rgba(255,255,255,x) 仍然一出现就该红）。
 *      两条不矛盾：白纱把深海报洗成奶昔，黑罩只把亮雾压回去、不动整张图的色相。
 *   2. 「只认 v4 这一张」→ v4 进负向名单，和 §7.42 那张浅底 v3、更早的 jpg 一起躺着。
 *   3. 「副标 12 字 / 标题 7 字是上界」→ 那是**当时的文案长度**顺手当界，不是量出来的界。
 *      这一轮按新图 + 新罩重扫了右界（下面 COPY 那块），改成量出来的 13 / 10。
 *
 * ## 罩为什么是必须的（不是我想加一层）
 * 把带顶从 160 到 440 每 20 行全扫一遍（`qa/make_invite_banner_fog.py --sweep-bands`，15 档），
 * **没有任何一条带能让两行字同时达标**：带取低，亮雾压在标题上（裸图最坏 1.87~2.78:1）；
 * 带取高，压在副标尾巴上（340 档副标裸图 3.40:1）。副标就算从 85% 白改成纯白也只有 4.03 / 5.01，
 * 仍然一头沉。所以要么加罩、要么换图、要么接受不达标 —— 用户选了加罩。
 *
 * ## 下面那些数怎么来的（两把尺子，同一套口径）
 * 口径三件小事，每一件都是前面几轮踩过才写死的：
 *   · 背景板 = 把四个前景节点 `visibility:hidden` 之后**再拍一张**（布局不动）。
 *     拿有字的截图在文字盒里逐像素算，盒子里一半像素是字自己，会得到 1.00:1 的假数。
 *   · 文字位置**从差值图反推包围盒**，不拿 rect 宽度当文字盒（标题/副标在 flex 列里被拉成整行宽）。
 *   · 按钮、图标的字/描边压的是**半透明板**，所以背景 = 板色与背景板逐点合成再比。
 * 读数：
 *   · 离线设计值 `qa/make_invite_banner_fog.py`（裁带 280:848，DSF=3）：标题 5.80 / 副标 6.55 / 按钮 4.56 / 图标 6.16
 *   · 真实渲染 `E:/tmp/v49/cdp49.js`+`contrast49.py`（四档 360/390/430/768）与
 *     `cdp49b.js`+`contrast49b.py`（再补六档 320/375/412/480/600/820）：
 *     **十档各自最坏** 标题 5.76 / 副标 5.98 / 按钮 4.65 / 图标 5.72 —— 每个数取的是 min(底最坏, 笔画核心)，
 *     十档逐档原始读数贴在 RUNBOOK §7.49 的表里，改一个数就得重跑那两个脚本。
 *   · 控制组一（摘罩）：副标十档里最差 3.76:1 ⇒ **罩在扛的是副标这一行**；标题摘罩 3.10~4.40，仍在 3.0 上方但贴线。
 *   · 控制组二（旧色）：同一块底、同一只盒，只把前景换成 §7.42 那套深红/棕/红 ⇒ 三档都是 **1.00:1**。
 *
 * 罩不许烤进资产：落盘的 webp 必须是裸图，改罩的 α 不该重出图。这条怎么拦住的？
 * 靠 md5 —— 把渐变烤进图里字节就变了，下面那条 md5 钉就红。
 *
 * jsdom 量不到伪元素、也量不到 px→vw 之后的长度，所以这里钉的是源码参数、文件指纹和文案上界。
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

// 注释里写着「不叠白纱」这类话，负向断言得先剥注释，否则是自己绊自己
const code = (s) => s.replace(/\/\/[^\n]*/g, '')
const px = (block, prop) => {
  const m = new RegExp(`\\b${prop}:\\s*(-?[\\d.]+)px`).exec(code(block))
  return m ? parseFloat(m[1]) : null
}

const BEFORE = blockOf('&::before')
const ART = '/images/invite-banner-bg-v5.webp'
const FILE = resolve(ROOT, 'public' + ART)

/** 发布图 = §7.48 候选 C 裁 280:848 缩到 1280x284。字节一变，下面所有对比度常量全部作废重抄（§7.46 同款机关） */
const MD5 = '7faa4a09b3c0fa2578b0a4691dfc28da'
const BYTES = 21974

/** §7.49 之前用过的地址，一个都不许复活：jpg = 最早那张，v2 = §7.42 浅底，v3 = 同批归档，v4 = §7.43~§7.48 那张深色海报 */
const RETIRED = ['invite-banner-bg.jpg', 'invite-banner-bg-v2', 'invite-banner-bg-v3', 'invite-banner-bg-v4']

/** 左侧深色渐隐罩的三个参数（CSS 里逐字写死，改一个都要重量四块前景） */
const SCRIM = { color: 'rgba(0, 0, 0, 0.35)', full: 58.6, end: 76.2 }
/** 卡片几何：设计盒 343x76，::before 铺的是 padding box（341x76.4，不含 1px 边框） */
const PAD_BOX_W = 341
const CARD_W = 343

/**
 * 罩那一段渐变**从 CSS 现读**，不拿上面的常量代入边界计算。
 * §7.49 反证第一版就是在这里假绿的：把归零点从 `76.2%` 推到 `92%`（罩把按钮整颗盖住）那一档，
 * 只有「stop 常量对不上」红了，「按钮在罩外」那条**照样绿** —— 因为它算边界用的是 SCRIM.end 这个常量，
 * 从头到尾没看过 CSS 里现在写的是多少。常量和 CSS 各说各话，等于把「抄进来的数」伪装成一条关系断言。
 * 见 [[transcribed-constants-need-provenance]]、[[prove-the-strip-before-trusting-green]]。
 */
function scrimSeg() {
  // 渐变里有 rgba(...) 的右括号，所以不能按"第一个 )"截断 —— 截到 url( 前面那个 `),` 才是整段罩。
  const m = /linear-gradient\(\s*90deg[\s\S]*?\),\s*url\(/.exec(code(BEFORE))
  return m ? m[0] : ''
}
function scrimStops() {
  return [...scrimSeg().matchAll(/([\d.]+)%/g)].map((x) => Number(x[1]))
}

/** 两把尺子的读数。口径：min(底最坏, 笔画核心)，多档取各档最坏，不是平均值 */
const OFFLINE = { title: 5.80, sub: 6.55, btn: 4.56, icon: 6.16 }
const RENDER = { title: 5.76, sub: 5.98, btn: 4.65, icon: 5.72 }
const NEED = { title: 3.0, sub: 4.5, btn: 4.5, icon: 3.0 }
/** 控制组：把罩摘掉（`cdp49.js` 的 noscrim 档）。这一档**必须**比 NEED 低，否则"达标靠罩"那句是空话 */
const NO_SCRIM = { sub: 3.76, title: 3.10 }
/** 控制组：同一块底、同一只盒，前景换成 §7.42 那套深红标题 / 棕副标 / 灰板上的 #C00000 —— 三档实测都是 1.00:1 */
const OLD_COLOR_RATIO = 1.00

describe('先把被钉的东西找出来：每条钉的都不是空气', () => {
  it('.invite-banner 这块样式在，::before 也在', () => {
    expect(BANNER.length).toBeGreaterThan(200)
    expect(BEFORE.length).toBeGreaterThan(50)
  })
})

describe('背景层：v5 海报 + 一层深色渐隐罩（§7.43「不许渐变」翻向）', () => {
  it('::before 用的是 v5 这张，且 center/cover —— 换带高就得连目标尺寸一起改，否则 cover 自己再裁一刀', () => {
    expect(BEFORE).toContain(`url('${ART}')`)
    expect(BEFORE).toContain('center/cover no-repeat')
    for (const gone of RETIRED) {
      expect(STYLE, `${gone} 已经不用了，别再引回样式里`).not.toContain(gone)
      expect(SFC, `${gone} 已经不用了，别再引回 SFC 里`).not.toContain(gone)
    }
  })

  it('罩必须是**深色**渐变：linear-gradient + rgba(0,0,0,.35)，一条浅色纱都不许回来', () => {
    const c = code(BEFORE)
    expect(c, '这轮达标靠的就是那层左罩，删了它副标只有 3.76:1').toMatch(/linear-gradient\(\s*90deg/)
    expect(c).toContain(SCRIM.color)
    // §7.42 那层白纱：把深海报洗成奶昔，翻向之后仍然一出现就该红
    expect(c, '浅色纱（rgba(255,255,255,x)）不是本轮口径').not.toMatch(/rgba\(255,\s*255,\s*255,\s*0\.\d+\)/)
  })

  it('罩的两个 stop 钉死，并且**换算回 CSS px 自证**：满值段到 x≈200、x≈261 归零', () => {
    const seg = scrimSeg()
    expect(seg, '罩那一段渐变没解析出来（写法变了？本节按 `linear-gradient(90deg, ...) , url(` 取整段）').toBeTruthy()
    const stops = scrimStops()
    // 读的是 CSS 里真实写了的那两个百分号，所以推一档归零点就会红 —— 常量只用来对名字。
    expect(stops, `CSS 里读到的 stop 是 ${JSON.stringify(stops)}，要的是 [${SCRIM.full}, ${SCRIM.end}]`).toEqual([SCRIM.full, SCRIM.end])
    // 百分比是相对 padding box 算的（::before 是 inset:0 的绝对定位层）。postcss 不动百分号。
    expect(Math.round((stops[0] / 100) * PAD_BOX_W) + 1).toBe(201)
    expect(Math.round((stops[1] / 100) * PAD_BOX_W) + 1).toBe(261)
  })

  it('按钮整颗在罩外：从 CSS 自己算出左沿 = 343-1-16-64 = 262，与 CDP 实测逐位相同，且 > 罩归零点 260.9', () => {
    // 这一条以前钉的是"抄进来的 262"，那谁改 CSS 都不会红 —— 现在改成**从 CSS 现算**：
    //   卡 border-box 343（`cdp49b.js` 实测 w=343.0）− 1px 边框 − padding 16 − 按钮外沿(2字×14 + 2×17 + 2×1边框)
    // 于是动 padding、动字号、动内边距都会把它推倒，而那正是"下面那串 4.65:1 的几何前提"被动了。
    const btn = code(blockOf('&__btn'))
    const padX = /padding:\s*[\d.]+px\s+([\d.]+)px/.exec(btn)[1]
    const font = px(btn, 'font-size')
    const btnW = 2 * font + 2 * Number(padX) + 2
    const btnLeft = 343 - 1 - px(BANNER, 'padding') - btnW
    expect(btnLeft, '算出来的按钮左沿和 CDP 实测 262.0 对不上 ⇒ 排版变了，对比度要重量').toBe(262)
    // 归零点同样**从 CSS 现读**（见 scrimSeg 上面那段注释）：罩没了 / 推后了，这条都得红。
    const endPct = scrimStops()[scrimStops().length - 1]
    expect(endPct, 'CSS 里读不到罩的归零百分比 ⇒ 那层罩不在了，"按钮没吃罩"这个前提也没了').not.toBeUndefined()
    const scrimEnd = 1 + (endPct / 100) * PAD_BOX_W      // ::before 铺 padding box，右边 1px 边框不算
    expect(btnLeft, `按钮开始吃罩了（左沿 ${btnLeft} ≤ 归零点 ${scrimEnd.toFixed(1)}）⇒ 那颗按钮的 4.65:1 量的不是它声称的那片底`).toBeGreaterThan(scrimEnd)
    // 卡是 px→vw 等比缩的，按钮左沿/卡宽恒为 76.4%，罩归零点 76.2% —— 所以这个关系不随视口变
    expect((btnLeft / CARD_W).toFixed(3)).toBe('0.764')
    expect(endPct / 100, '罩的归零比例必须严格小于按钮左沿比例，否则按钮吃罩').toBeLessThan(btnLeft / CARD_W)
  })

  it('旧的洗白滤镜（brightness/saturate）还是不许回来：深色古物不该被洗成灰泥', () => {
    expect(BEFORE).not.toMatch(/filter:\s*brightness/)
    expect(BEFORE).not.toMatch(/saturate\(/)
  })

  it('背景层不许降 opacity：.85 会把海报洗成奶昔，这条与罩不冲突', () => {
    const m = /opacity:\s*([\d.]+)/.exec(code(BEFORE))
    expect(m === null || Number(m[1]) === 1, `::before 的 opacity 只允许 1 或不写，现在是要 ${m && m[1]}`).toBe(true)
  })

  it('卡片自己的兜底底色是深色：图没传上去时也不会「白底白字」空转', () => {
    expect(code(BANNER)).toMatch(/background:\s*#1A0D10/i)
    // 边框宽度参与盒子尺寸，改它会挪动文字区起点，所以顺手钉住
    expect(code(BANNER)).toMatch(/border:\s*1px\s+solid/)
  })
})

describe('两把尺子的读数都在门槛上（抄进来的常量 + 测试自己算一遍公式）', () => {
  it('离线与真机两个口径互相对得上：任一块差过 1.5 个点就说明有一边的模型错了', () => {
    for (const kind of ['title', 'sub', 'btn', 'icon']) {
      expect(RENDER[kind], `${kind}：真机比离线乐观过头了`).toBeGreaterThan(OFFLINE[kind] - 1.5)
      expect(RENDER[kind], `${kind}：十档真机最坏踩破门槛`).toBeGreaterThanOrEqual(NEED[kind])
      expect(OFFLINE[kind], `${kind}：离线设计值踩破门槛`).toBeGreaterThanOrEqual(NEED[kind])
    }
  })

  it('WCAG 公式在测试里现算一遍，与抄进来的数对得上（不是只比字符串）', () => {
    function ch(c) { c /= 255.0; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
    const lum = (p) => 0.2126 * ch(p[0]) + 0.7152 * ch(p[1]) + 0.0722 * ch(p[2])
    const contrast = (a, b) => { const x = lum(a), y = lum(b)
      return +((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)).toFixed(2) }
    // 图标那块板 §7.49 实测中位色（`invite-icon-theme.spec.js` 里同一个数，两处不许各抄一份不同的）
    expect(contrast([255, 255, 255], [59, 59, 56])).toBe(11.24)
    // 反推"能把这行字压穿的那片底"有多亮：ratio = (Lfg+0.05)/(Lbg+0.05) ⇒ Lbg = (1.05/ratio)-0.05
    //   按钮白字十档最坏 4.65 ⇒ 它脚下最坏那点 L≈0.176；踩破 4.5 需要 L≈0.183 —— 只剩 0.007 的亮度余量
    const bgAt = (ratio) => (1.05 / ratio) - 0.05
    expect(bgAt(RENDER.btn)).toBeLessThan(bgAt(NEED.btn))
    expect(bgAt(NEED.btn) - bgAt(RENDER.btn)).toBeLessThan(0.01)
    // 控制组：旧深红标题在同一片底上是 1.00:1（逐像素一定找得到和字同亮度的底）
    expect(contrast([154, 26, 26], [48, 48, 48])).toBeLessThan(NEED.title)
    expect(OLD_COLOR_RATIO, '旧色这一档本来就是 1.00:1，量具分不出新旧就是有病').toBeLessThan(NEED.title)
  })

  it('摘掉罩，副标必须红（这轮达标是罩扛的，不是图自己扛的）', () => {
    expect(NO_SCRIM.sub, '副标摘罩还能过 4.5 ⇒ 罩不是在扛读数，这条注释和那层罩都该删').toBeLessThan(NEED.sub)
    expect(NO_SCRIM.title, '标题摘罩还能过 3.0（大图线）—— 它只是贴线，不是靠罩').toBeGreaterThanOrEqual(NEED.title)
  })
})

describe('文字是压在深底上的浅色：旧深红/棕色不许改回来', () => {
  it('标题纯白、副标近白，且都带 text-shadow 兜住踩到高光边的笔画', () => {
    const title = code(blockOf('&__title'))
    const sub = code(blockOf('&__sub'))
    expect(title).toMatch(/color:\s*#FFFFFF\b/i)
    expect(title).toMatch(/text-shadow:/)
    expect(sub).toMatch(/color:\s*rgba\(255,\s*255,\s*255,\s*([\d.]+)\)/i)
    expect(sub).toMatch(/text-shadow:/)
    // 上界：副标再淡下去就吃掉量出来的 5.98:1 余量（十档最坏）
    const alpha = parseFloat(/rgba\(255,\s*255,\s*255,\s*([\d.]+)\)/i.exec(sub)[1])
    expect(alpha, '副标白度低于 .85 就不达标了').toBeGreaterThanOrEqual(0.85)
  })

  it('§7.42 的深红标题 #9A1A1A 与棕色副标 rgba(80,50,20,.6) 整块都不许再出现', () => {
    expect(code(BANNER)).not.toMatch(/#9A1A1A/i)
    expect(code(BANNER)).not.toMatch(/rgba\(\s*80,\s*50,\s*20/)
  })

  it('按钮不再是一整块实色：既不许回到 #C00000 实底，也不许做成实色灰块', () => {
    const btn = code(blockOf('&__btn'))
    // §7.43 的「实色红底 + 白字」和中途试过的「乳白玻璃 + 深红字」都翻成方向钉：出现就该红
    expect(btn, '按钮底色回到实色红 = 透明灰口径被推回去').not.toMatch(/background:\s*#C00000/)
    expect(btn, '底色必须是 rgba 半透明，α=1 就成实色灰块了').toMatch(/background:\s*rgba\(/)
    expect(btn, '乳白玻璃底（rgba(255,255,255,.x)）已经不是本轮口径').not.toMatch(/background:\s*rgba\(255,\s*255,\s*255/)
    expect(btn, '深红字压在透明灰上只有 1.00:1，等于看不见').not.toMatch(/color:\s*#C00000/i)
  })

  it('透明灰这块板：中性深灰 + α≥0.62 + 1px 半透明亮边 + 内高光', () => {
    const btn = code(blockOf('&__btn'))
    const m = /background:\s*rgba\(\s*46,\s*46,\s*52,\s*([\d.]+)\s*\)/.exec(btn)
    expect(m, '按钮底不是那个量出来的中性灰 rgb(46,46,52)').not.toBeNull()
    const alpha = Number(m[1])
    // 下界 0.62 是 §7.44 用户点名的值。§7.49 换了底图后重扫：α=0.55→3.80、0.58→4.08、
    // **0.62→4.56（真机十档最坏 4.65）**、0.68→5.36、0.75→6.55 —— 0.62 是"能过的最薄那一档"。
    expect(alpha, 'α 低于 0.62 就踩破 4.5:1（这颗按钮在罩外，压的是亮雾那条带）').toBeGreaterThanOrEqual(0.62)
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
    // 竖向从 8 收成 7、横向从 18 收成 17（各让 1px 给新边框）。只收左右那一版实测外沿高 2.6 设备px，
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

  it('文案长度上界按**新图 + 新罩**重扫过：标题行 x=242、副标行 x=240 起跌破各自的线', () => {
    // 界是这么来的：从文字起点 x=72 起，标题每字 15.5px（15 + ls 0.5）、副标每字 12px（无 ls）。
    //   副标 13 字墨迹到 x=228（<240 过），14 字到 x=240（正好踩在第一个不达标列上）⇒ 上界 13
    //   标题 10 字到 x=226.5（<242 过），11 字到 x=242.0 ⇒ 上界 10
    // §7.43 当年钉的是 7 / 12（当时的文案长度，不是量出来的界），这一轮换算法：界取自 `--right-edge` 扫描。
    // 再长就不是对比度一家的事了：文字块会挤到按钮（x≥262），量的盒子也变了。
    const title = /class="invite-banner__title">([^<]+)</.exec(TEMPLATE)[1]
    const sub = /class="invite-banner__sub">([^<]+)</.exec(TEMPLATE)[1]
    expect([...title].length, '标题超过 10 字就压进 x≥242 那片（3:1 都保不住），要重量').toBeLessThanOrEqual(10)
    expect([...sub].length, '副标超过 13 字会压到 x=240 那列，白字对比掉到 4.5:1 以下').toBeLessThanOrEqual(13)
  })
})

describe('邀新卡发布图本体：字节、尺寸、比例都对得上卡片', () => {
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
    expect(existsSync(FILE)).toBe(true)
    expect(SFC.match(/url\('([^']+\.webp)'\)/)[1]).toBe(ART)
  })

  it('发布图就是这一份字节：md5 + 体积钉住，上面那两组对比度常量才有效', () => {
    expect(crypto.createHash('md5').update(readFileSync(FILE)).digest('hex')).toBe(MD5)
    expect(statSync(FILE).size).toBe(BYTES)
  })

  it('体积比 v4 又小了三成：这张卡是首页级入口，不该为一张底图拖慢「我的」页', () => {
    expect(BYTES).toBeLessThan(40 * 1024)
    expect(BYTES, '比上一张（32,466 B）大就红，这条是本轮的方向钉').toBeLessThan(32466)
  })

  it('宽高比对得上卡片(343x76)，cover 不会把右边的古物裁掉', () => {
    const [w, h] = webpSize(readFileSync(FILE))
    const card = 343 / 76
    expect(w / h).toBeGreaterThan(card * 0.97)
    expect(w / h).toBeLessThan(card * 1.03)
  })

  it('分辨率够 3 倍图：暗部一旦糊了，那些雾的渐变会变成一条条色带', () => {
    const [w, h] = webpSize(readFileSync(FILE))
    expect(w).toBeGreaterThanOrEqual(343 * 3)
    expect(h).toBeGreaterThanOrEqual(76 * 3)
  })

  it('图里没有那层罩（罩是 CSS 层）：改 α 不该重出图，所以资产的 md5 必须等于**裸图**那一份', () => {
    // 这一条不是新算法，是把上面那条 md5 钉的**含义**写明白：
    // 谁把渐变烤进 webp，字节立刻变，那条钉就红 —— 那时候该改的是 CSS 的罩，不是重出图。
    expect(MD5).toBe('7faa4a09b3c0fa2578b0a4691dfc28da')
    expect(code(BEFORE)).toMatch(/linear-gradient/)
  })
})
