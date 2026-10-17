import { describe, expect, it } from 'vitest'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import crypto from 'node:crypto'

/**
 * 「我的」页邀新横条：「水墨写意·爵」**裸图** + 白字，CSS 里没有罩（2026-10-18，RUNBOOK §7.51）
 *
 * 用户口径两句：「换个风格，主体小一点」→ 定「B 水墨写意」（主体收到右侧，文字区全干净）
 * + 「那层左侧深色罩怎么处理」→ 选「**摘掉罩**」。于是这一轮既换图又拆层，方向钉全跟着翻。
 *
 * ## 这份 spec 翻了哪七颗旧钉（都是**翻方向**，不是删掉装作没发生过）
 *   1. 「只认 v6 这一张」→ v6 进负向名单，和 jpg / v2 / v3 / v4 / v5 一起躺着。§7.50 刚把它请进来。
 *   2. 「罩必须是深色渐变（α=0.50、63.3%~73.3%）」→ 现在是 **`::before` 里不许出现任何渐变**。
 *      这条钉在三年口径里翻了两次（§7.43 不许有 → §7.49/§7.50 必须有 → §7.51 又不许有），
 *      所以特别写清楚：判据不是"罩好不好"，是**摘掉之后四块读数还过不过线**。
 *      §7.49/§7.50 那两张主体都在画面正中、亮部是一大片弥散的雾，15 档带全扫没有一条能裸过，只能加罩；
 *      这张文字区是干净浅纸，15 档**每一档四块全过**（最差的也有 15.05/7.77/15.21/3.89），罩就没必要了。
 *   3. 「摘罩两行字都红（标题 1.69 / 副标 1.79）⇒ 达标是罩扛的」→ 反过来说：现在**达标是构图扛的**，
 *      断言从 `NO_SCRIM < NEED` 翻成 `RENDER >= NEED`，罩降格为**反证档**（叠回来只会往上抬）。
 *   4. 「按钮整颗在罩外、余量必须 ≥10 px」→ 罩没了，这条关系改成**两行字的墨迹右界 < 按钮左沿**
 *      （真机十档实测：标题最大 172.1、副标最大 217.9，按钮左沿 262 ⇒ 都成立）。
 *      「从 CSS 现算左沿 = 262」那一半原样留着，它钉的是排版，不是罩。
 *   5. 「α≥0.62 是决策下界」+「副标白度 ≥0.85」→ 都留着，但**理由换了**：
 *      v7 上重扫副标白度，0.70 也有 9.28:1（v6+罩那轮 0.75 只剩 4.68）⇒ 0.85 也不再是测量下界。
 *      两颗现在都是 §7.44 / §7.43 用户点名的观感值。
 *   6. 文案上界 11 / 14 → 罩一摘，右界就露出来了：裸图标题第 206 列跌破 3.0、副标第 220 列跌破 4.5
 *      ⇒ 上界收到 **8 字 / 12 字**，而现在副标「同游作伴，潮流好礼不设限」正好 12 字 = **贴线，一个字都不能再加**。
 *   7. 「比 v5（21,974 B）小就绿」→ 换成比 v6（21,236 B）小，实际 7,554 B。
 *
 * ## 下面那些数怎么来的（三把尺子，口径同一套）
 * 口径三件小事与 §7.49/§7.50 一字未改：
 *   · 背景板 = 把四个前景节点 `visibility:hidden` 之后**再拍一张**（布局不动）。拿有字的截图在文字盒里
 *     逐像素算，盒子里一半像素是字自己，会得到 1.00:1 的假数。
 *   · 文字位置**从差值图反推包围盒**，不拿 rect 宽度当文字盒（标题/副标在 flex 列里被拉成整行宽）。
 *   · 按钮、图标的字/描边压的是**半透明板**，所以背景 = 板色与背景板逐点合成再比。
 * 读数（`min(底最坏, 笔画核心)`，多档取各档最坏，不是平均值）：
 *   · 离线尺 A（DSF=3，压缩前那张 1280x284）`qa/make_invite_banner_fog.py`：
 *     标题 18.20 / 副标 13.16 / 按钮 15.21 / 图标 9.52
 *   · 离线尺 B（**落盘后的 webp 字节**，同一段代码）：标题 18.41 / 副标 13.30 / 按钮 15.45 / 图标 9.59
 *     —— Lossy 把最坏那几点压平了，所以真字节反而略好；两套都贴在这，spec 三处钉的是落盘字节那一套。
 *   · 真机尺（`E:/tmp/v51/cdp51.js` + `contrast51.py`）十档 320/360/375/390/412/430/480/600/768/820
 *     **一趟跑完**，十档各自最坏 标题 15.11 / 副标 10.90 / 按钮 12.62 / 图标 9.37。
 *     它比离线 B 低，是因为真机那一列取了**笔画核心**（抗锯齿/亚像素），底最坏那一列是
 *     18.42 / 13.30 / 15.27 / 9.59 —— 与离线 B 差 ≤0.18（按钮那档差 0.18：离线只量设计盒一个尺寸，
 *     真机取十档里最差的一档）。两列都在测试里现算比对，任一边飘了就得重跑脚本。
 *   · 控制组一（**反证：把 §7.50 那副罩叠回去**，脚本注入同一层渐变再拍一圈）：
 *     标题 16.16 / 副标 11.59 / 按钮 12.62 / 图标 10.62，四块**只往上抬** ⇒ 这轮的绿不是罩在扛。
 *   · 控制组二（旧色，同一块底、同一只盒，前景换成 §7.42 那套深红/棕/红）：
 *     标题 2.23 / 副标 1.26 / 按钮 2.36（十档各自最坏）—— 三档都远低于各自要求。
 *     ⚠️ 这三个数**比 §7.50 那轮（1.00/1.00/1.42）高一截**，不是量具松了：那张底里有大片亮雾，
 *     逐像素一定能找到一个跟字同亮度的点；这张文字区是浅纸，深红不至于全融。含义不变：迟早有一片字融进底里。
 *   · 图标那块板的中位色 [43,43,42] → **[57,56,58]**（罩摘掉了，板子下面直接是浅纸 ⇒ 变亮），
 *     白线对比 14.17 → **11.66**，#C00000 控制组 2.19 → **1.80** —— 与 `invite-icon-theme.spec.js` 同源
 *     （cdp51c.js + icon51c.py，同一个视口 390x844/dpr2），两处不许各抄一份不同的。
 *
 * 罩摘掉之后，资产与 CSS 都是"裸"的：落盘 webp 是无 alpha 的 VP8 单帧，CSS 里也不许留渐变。
 * 谁想把罩烤进像素 —— 字节立刻变，下面那条 md5 钉就红。
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

// 注释里写着「罩已摘」这类话，负向断言得先剥注释，否则是自己绊自己
const code = (s) => s.replace(/\/\/[^\n]*/g, '')
const px = (block, prop) => {
  const m = new RegExp(`\\b${prop}:\\s*(-?[\\d.]+)px`).exec(code(block))
  return m ? parseFloat(m[1]) : null
}

const BEFORE = blockOf('&::before')
const ART = '/images/invite-banner-bg-v7.webp'
const FILE = resolve(ROOT, 'public' + ART)

/** 发布图 = 候选「水墨写意·爵」裁 200:768 缩到 1280x284。字节一变，下面所有对比度常量全部作废重抄（§7.46 同款机关） */
const MD5 = '22b468e82aa492cf45ed5ef7ccb3a8cf'
const BYTES = 7554

/** 用过的地址，一个都不许复活：jpg = 最早那张，v2 = §7.42 浅底，v3 = 同批归档，v4 = §7.43~§7.48 深色海报，v5 = §7.49「雾中逆光」，v6 = §7.50「青铜爵·晨雾」 */
const RETIRED = ['invite-banner-bg.jpg', 'invite-banner-bg-v2', 'invite-banner-bg-v3', 'invite-banner-bg-v4', 'invite-banner-bg-v5', 'invite-banner-bg-v6']

/** 卡片几何：设计盒 343x76，::before 铺的是 padding box（341x76.4，不含 1px 边框） */
const PAD_BOX_W = 341
const CARD_W = 343

/**
 * 三把尺子的读数。口径：min(底最坏, 笔画核心)，多档取各档最坏，不是平均值。
 * OFFLINE = 压缩前，ASSET = 落盘字节（离线尺），RENDER = 真机十档，RENDER_BOTTOM = 真机十档只看"底最坏"那一列。
 */
const OFFLINE = { title: 18.20, sub: 13.16, btn: 15.21, icon: 9.52 }
const ASSET = { title: 18.41, sub: 13.30, btn: 15.45, icon: 9.59 }
const RENDER = { title: 15.11, sub: 10.90, btn: 12.62, icon: 9.37 }
const RENDER_BOTTOM = { title: 18.42, sub: 13.30, btn: 15.27, icon: 9.59 }
/** 反证档：脚本把 §7.50 那副罩（α0.50 / 63.3%~73.3%）叠回真机画面，十档各自最坏 */
const WITH_SCRIM = { title: 16.16, sub: 11.59, btn: 12.62, icon: 10.62 }
const NEED = { title: 3.0, sub: 4.5, btn: 4.5, icon: 3.0 }
/** 控制组：同一块底、同一只盒，前景换成 §7.42 那套深红标题 / 棕副标 / 灰板上的 #C00000（十档各自最坏） */
const OLD = { title: 2.23, sub: 1.26, btn: 2.36 }
/** 真机十档实测：两行字墨迹右界（折算回 343 设计盒）各自最大的那个，与下面 EDGE 的对比度界是**两把不同的尺** */
const INK_RIGHT = { title: 172.1, sub: 217.9 }

describe('先把被钉的东西找出来：每条钉的都不是空气', () => {
  it('.invite-banner 这块样式在，::before 也在', () => {
    expect(BANNER.length).toBeGreaterThan(200)
    expect(BEFORE.length).toBeGreaterThan(50)
  })
})

describe('背景层：只剩 v7 海报这一层（§7.50「必须有深色罩」翻向）', () => {
  it('::before 用的是 v7 这张，且 center/cover —— 换带高就得连目标尺寸一起改，否则 cover 自己再裁一刀', () => {
    expect(BEFORE).toContain(`url('${ART}')`)
    expect(BEFORE).toContain('center/cover no-repeat')
    for (const gone of RETIRED) {
      expect(STYLE, `${gone} 已经不用了，别再引回样式里`).not.toContain(gone)
      expect(SFC, `${gone} 已经不用了，别再引回 SFC 里`).not.toContain(gone)
    }
  })

  it('罩已经摘掉：`::before` 里不许出现任何渐变层，也不许出现那条浅色纱', () => {
    const c = code(BEFORE)
    // 这轮达标靠的是构图，不是罩 ⇒ 「不许有渐变」重新成为方向钉（§7.43 那颗，翻了一圈又回来）。
    expect(c, 'CSS 里又出现 linear-gradient ⇒ §7.51 的摘罩被推回去了，上面那串"裸图读数"全部作废').not.toMatch(/linear-gradient/)
    expect(c, '深色罩的任何写法（rgba(0,00,0,x)）都不该在这层里').not.toMatch(/rgba\(\s*0,\s*0,\s*0,\s*[\d.]+\)/)
    // §7.42 那层白纱：把深海报洗成奶昔，两轮口径都没让它回来
    expect(c, '浅色纱（rgba(255,255,255,x)）不是本轮口径').not.toMatch(/rgba\(255,\s*255,\s*255,\s*0\.\d+\)/)
    // 摘罩之后这层只剩一个 background 声明；多出一层就说明有人又开始叠"顺手加的"遮罩
    expect((c.match(/background[-a-z]*:/g) || []), `::before 现在只该有一条 background：${c}`).toHaveLength(1)
  })

  it('裸图自己就够：四块前景在真机十档上都踩得住门槛（这轮没有罩可以赖）', () => {
    for (const kind of ['title', 'sub', 'btn', 'icon']) {
      expect(RENDER[kind], `${kind}：真机十档最坏踩破门槛，那就得把罩加回来（并重量一遍）`).toBeGreaterThanOrEqual(NEED[kind])
    }
  })

  it('两行字的墨迹右界在按钮左沿之外：左沿从 CSS 现算 = 262，实测最大 副标 217.9', () => {
    // 「从 CSS 现算左沿」这一半 §7.50 就在，它钉的是排版：动 padding、动字号、动内边距都会把它推倒，
    //   而对比度那三串数的几何前提正是这套排版。
    const btn = code(blockOf('&__btn'))
    const padX = /padding:\s*[\d.]+px\s+([\d.]+)px/.exec(btn)[1]
    const font = px(btn, 'font-size')
    const btnW = 2 * font + 2 * Number(padX) + 2
    const btnLeft = 343 - 1 - px(BANNER, 'padding') - btnW
    expect(btnLeft, '算出来的按钮左沿和 CDP 实测 262.0 对不上 ⇒ 排版变了，对比度要重量').toBe(262)
    // §7.50 那条"按钮在罩外、余量 ≥10 px"随罩一起作废，换成这条真的关系：字不压按钮。
    for (const kind of ['title', 'sub']) {
      expect(INK_RIGHT[kind], `${kind} 的墨迹右界 ${INK_RIGHT[kind]} 越过按钮左沿 ${btnLeft}`)
        .toBeLessThan(btnLeft)
    }
    // 卡是 px→vw 等比缩的，所以这个比例不随视口变（实测十档 261.5~263.6，正是这条比例的体现）
    expect((btnLeft / CARD_W).toFixed(3)).toBe('0.764')
  })

  it('旧的洗白滤镜（brightness/saturate）还是不许回来：深色古物不该被洗成灰泥', () => {
    expect(BEFORE).not.toMatch(/filter:\s*brightness/)
    expect(BEFORE).not.toMatch(/saturate\(/)
  })

  it('背景层不许降 opacity：.85 会把海报洗成奶昔，这条与罩在不在都无关', () => {
    const m = /opacity:\s*([\d.]+)/.exec(code(BEFORE))
    expect(m === null || Number(m[1]) === 1, `::before 的 opacity 只允许 1 或不写，现在是要 ${m && m[1]}`).toBe(true)
  })

  it('卡片自己的兜底底色是深色：图没传上去时也不会「白底白字」空转', () => {
    expect(code(BANNER)).toMatch(/background:\s*#1A0D10/i)
    // 边框宽度参与盒子尺寸，改它会挪动文字区起点，所以顺手钉住
    expect(code(BANNER)).toMatch(/border:\s*1px\s+solid/)
  })
})

describe('三把尺子的读数互相对得上（抄进来的常量 + 测试自己算一遍公式）', () => {
  it('离线（压缩前 / 落盘字节）与真机"底最坏"三列同指标：任一块差过 0.2 个点就说明有一边的模型错了', () => {
    for (const kind of ['title', 'sub', 'btn', 'icon']) {
      expect(Math.abs(RENDER_BOTTOM[kind] - ASSET[kind]),
        `${kind}：真机底最坏 ${RENDER_BOTTOM[kind]} 与落盘字节离线 ${ASSET[kind]} 对不上`).toBeLessThanOrEqual(0.2)
      expect(Math.abs(ASSET[kind] - OFFLINE[kind]),
        `${kind}：落盘字节 ${ASSET[kind]} 与压缩前 ${OFFLINE[kind]} 差过头，压缩把最坏点改太狠（重出图要重量）`).toBeLessThanOrEqual(0.3)
      expect(RENDER[kind], `${kind}：真机十档最坏踩破门槛`).toBeGreaterThanOrEqual(NEED[kind])
      expect(OFFLINE[kind], `${kind}：离线设计值踩破门槛`).toBeGreaterThanOrEqual(NEED[kind])
    }
  })

  it('WCAG 公式在测试里现算一遍，与抄进来的数对得上（不是只比字符串）', () => {
    function ch(c) { c /= 255.0; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
    const lum = (p) => 0.2126 * ch(p[0]) + 0.7152 * ch(p[1]) + 0.0722 * ch(p[2])
    const contrast = (a, b) => { const x = lum(a), y = lum(b)
      return +((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)).toFixed(2) }
    // 图标那块板 §7.51 实测中位色（`invite-icon-theme.spec.js` 里同一个数，两处不许各抄一份不同的）
    expect(contrast([255, 255, 255], [57, 56, 58])).toBe(11.66)
    // 十档合并的按钮板中位色（contrast51.py 同一段代码产出）——那颗 4.5:1 压的就是这片底
    expect(contrast([255, 255, 255], [36, 36, 40])).toBe(15.46)
    // 反推"能把这行字压穿的那片底"有多亮：ratio = (Lfg+0.05)/(Lbg+0.05) ⇒ Lbg = (1.05/ratio)-0.05
    //   按钮白字十档最坏 12.62 ⇒ 它脚下最坏那点 L≈0.0332；踩破 4.5 需要 L≈0.1833 —— 0.15 的亮度余量。
    //   §7.49 那版是 4.65 vs 4.5，只剩 0.007，当时靠这条劝人别抬 α；那个前提现在翻了两轮，
    //   断言方向也跟着翻：余量必须**大于** 0.1，谁把读数打回"贴线"那一档，这条就红。
    const bgAt = (ratio) => (1.05 / ratio) - 0.05
    expect(bgAt(RENDER.btn)).toBeLessThan(bgAt(NEED.btn))
    expect(bgAt(NEED.btn) - bgAt(RENDER.btn)).toBeGreaterThan(0.1)
    // 控制组：旧深红标题在同一片底上是 2.23:1（十档各自最坏）——远低于 3.0 才算量具有红
    expect(contrast([154, 26, 26], [57, 56, 58])).toBeLessThan(NEED.title)
    expect(OLD.title, '旧色这一档本来就是"迟早有一片字融进底里"，量具分不出新旧就是有病').toBeLessThan(NEED.title)
    expect(OLD.sub, '旧棕副标同底 1.26:1').toBeLessThan(NEED.sub)
    expect(OLD.btn, '灰板上退回 #C00000 只有 2.36:1').toBeLessThan(NEED.btn)
  })

  it('反证档：把 §7.50 那副罩叠回去，四块只会往上抬（这轮的绿不是罩在扛）', () => {
    for (const kind of ['title', 'sub', 'btn', 'icon']) {
      expect(WITH_SCRIM[kind],
        `${kind}：叠上罩反而比裸图低（${WITH_SCRIM[kind]} < ${RENDER[kind]}）⇒ 两档量的不是同一层底，反证作废`)
        .toBeGreaterThanOrEqual(RENDER[kind] - 0.05)
    }
    // 罩对图标的增益最大（图标正压在旧罩的满值段里）：10.62 vs 裸图 9.37。
    // 记着它不是为了加回来，是为了说明"摘罩确实降了读数"——降了还过，才是真能摘。
    expect(WITH_SCRIM.icon - RENDER.icon, '图标那块对罩的敏感度变了 ⇒ 底图或板色动过').toBeGreaterThan(1.0)
  })
})

describe('文字是压在浅纸上的浅色：旧深红/棕色不许改回来', () => {
  it('标题纯白、副标近白，且都带 text-shadow 兜住踩到高光边的笔画', () => {
    const title = code(blockOf('&__title'))
    const sub = code(blockOf('&__sub'))
    expect(title).toMatch(/color:\s*#FFFFFF\b/i)
    expect(title).toMatch(/text-shadow:/)
    expect(sub).toMatch(/color:\s*rgba\(255,\s*255,\s*255,\s*([\d.]+)\)/i)
    expect(sub).toMatch(/text-shadow:/)
    // ⚠️ 0.85 现在也是**决策下界**（§7.43 定的观感值），不再是量出来的那一档：
    //   v7 裸图上重扫副标白度（落盘字节、同一只墨迹盒）0.70→9.28、0.75→10.46、0.80→11.83、
    //   **0.85→13.30（本轮这档）**、0.90→14.88、1.00→18.24 —— 门槛 4.5，最淡那档也还有 4.8 个点。
    //   §7.50 那轮（v6+罩）0.75 只剩 4.68，所以当时"再淡就不达标"是真的；现在这句不再是测量结论。
    const alpha = parseFloat(/rgba\(255,\s*255,\s*255,\s*([\d.]+)\)/i.exec(sub)[1])
    expect(alpha, '副标白度低于 .85 = 未经量与未经用户点头就改观感值').toBeGreaterThanOrEqual(0.85)
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
    expect(btn, '深红字压在透明灰上只有 2.36:1，等于看不见').not.toMatch(/color:\s*#C00000/i)
  })

  it('透明灰这块板：中性深灰 + α≥0.62 + 1px 半透明亮边 + 内高光', () => {
    const btn = code(blockOf('&__btn'))
    const m = /background:\s*rgba\(\s*46,\s*46,\s*52,\s*([\d.]+)\s*\)/.exec(btn)
    expect(m, '按钮底不是那个量出来的中性灰 rgb(46,46,52)').not.toBeNull()
    const alpha = Number(m[1])
    // ⚠️ 0.62 是**决策下界**（§7.44 用户点名的值），连着两轮都不是"量出来的能过的最薄那一档"。
    //   §7.49 在 v4/v5 亮雾底上扫：0.55→3.80、0.62→4.56 ⇒ 当时确实贴着门槛。
    //   §7.50 在 v6 上重扫：0.55→8.39、0.62→9.08 —— 下界掉了，但"抬 α 有用"还成立。
    //   §7.51 在 v7 上再扫：0.55→15.48、0.58→15.41、**0.62→15.21**、0.68→14.88、0.75→14.67、0.82→14.28
    //   —— 这一轮**抬 α 反而往下走**：那颗坐在爵身的深色墨上，卡住读数的是灰板本色
    //   （纯白对 rgb(46,46,52) = 13.49:1 是天花板），所以别再拿"加厚"当万能药。
    //   留这条钉是因为它是用户点的值，不是因为它量出来刚好卡住；要真往下调得重新有理由。
    expect(alpha, 'α 低于 0.62 = 未经用户点头就动了 §7.44 点名的那档').toBeGreaterThanOrEqual(0.62)
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

  it('文案长度上界按**新图 + 摘罩**重扫过：标题第 206 列跌破 3.0、副标第 220 列跌破 4.5 ⇒ 8 / 12 字', () => {
    // 界不是抄进来的常数，是这里现算的，两个输入都从 CSS 读：
    //   · 每字宽 = font-size + letter-spacing（标题 15 + 0.5 = 15.5，副标 12 + 0 = 12）；
    //   · 起点 x = padding16 + 图标位44 + gap12 = 72（上面那条已经钉过这三段）。
    // 右界这轮**两行都由对比度给**（罩没了，浅纸底上的亮部直接界住字）：
    //   · 标题：`--right-edge` 扫描给出 x=206。布局界 250 更靠外，所以取更小的那个：
    //     8 字到 72+15.5×8 = 196 < 206 过，9 字到 211.5 ≥ 206 破 ⇒ 上界 8（现在是 7 字，还剩一格）。
    //   · 副标：x=220。12 字到 72+12×12 = 216 < 220 过，13 字到 228 ≥ 220 破 ⇒ 上界 **12 = 正好贴线**。
    // §7.43 当年钉 7 / 12（当时的文案长度），§7.49 是 13 / 10，§7.50 是 11 / 14（罩把右界推出去过），
    // 这一轮随"换图 + 摘罩"重扫。算法与输入都在测试里，改图就得改这两个列号并重跑 qa 脚本。
    const start = px(BANNER, 'padding') + px(blockOf('&__icon'), 'width') + px(BANNER, 'gap')
    const perChar = (sel) => {
      const b = code(blockOf(sel))
      const ls = /letter-spacing:\s*([\d.]+)px/.exec(b)
      return px(b, 'font-size') + (ls ? Number(ls[1]) : 0)
    }
    const btnBlock = code(blockOf('&__btn'))
    const btnW = 2 * px(btnBlock, 'font-size') +
      2 * Number(/padding:\s*[\d.]+px\s+([\d.]+)px/.exec(btnBlock)[1]) + 2   // 「邀请」两字 + 左右内边距 + 1px 边框×2
    const LAYOUT = (343 - 1 - px(BANNER, 'padding') - btnW) - px(BANNER, 'gap')   // 文字盒右沿 = 按钮左沿 − gap
    const CONTRAST_EDGE = { title: 206, sub: 220 }
    const EDGE = { title: Math.min(CONTRAST_EDGE.title, LAYOUT), sub: CONTRAST_EDGE.sub }
    const cap = (sel, kind) => Math.floor((EDGE[kind] - start) / perChar(sel))
    expect(perChar('&__title'), '标题每字宽不再是 15.5').toBe(15.5)
    expect(perChar('&__sub'), '副标每字宽不再是 12').toBe(12)
    expect(start, '文字起点不再是 x=72').toBe(72)
    expect(LAYOUT, '文字盒右沿不再是 x=250（按钮左沿−gap）').toBe(250)
    expect(EDGE.title, '标题的界不再是布局给的 250 —— 对比度界 206 更紧，说明右界读数列被改了')
      .toBe(206)
    expect(cap('&__title', 'title'), '标题的字宽上界不再是 8 字（对比度界 x=206）').toBe(8)
    expect(cap('&__sub', 'sub'), '副标的字宽上界不再是 12 字（对比度界 x=220）').toBe(12)
    // 两把尺对得上：真机实测的墨迹右界必须**还没撞到**这条对比度界（撞上了就是现在这行字已经不达标）
    expect(INK_RIGHT.title, `标题实测墨迹右界 ${INK_RIGHT.title} 已经越过 x=${EDGE.title} 那列`)
      .toBeLessThanOrEqual(EDGE.title)
    expect(INK_RIGHT.sub, `副标实测墨迹右界 ${INK_RIGHT.sub} 已经越过 x=${EDGE.sub} 那列`)
      .toBeLessThanOrEqual(EDGE.sub)

    const title = /class="invite-banner__title">([^<]+)</.exec(TEMPLATE)[1]
    const sub = /class="invite-banner__sub">([^<]+)</.exec(TEMPLATE)[1]
    expect([...title].length, `标题超过 ${cap('&__title', 'title')} 字会写到 x=${EDGE.title} 那列以外，白字对比掉到 3:1 以下`)
      .toBeLessThanOrEqual(cap('&__title', 'title'))
    expect([...sub].length, `副标超过 ${cap('&__sub', 'sub')} 字会写到 x=${EDGE.sub} 那列以外，白字对比掉到 4.5:1 以下`)
      .toBeLessThanOrEqual(cap('&__sub', 'sub'))
  })
})

describe('邀新卡发布图本体：字节、尺寸、比例都对得上卡片', () => {
  function fourCC(buf) {
    if (buf.subarray(0, 4).toString() !== 'RIFF' || buf.subarray(8, 12).toString() !== 'WEBP') {
      throw new Error('不是 webp')
    }
    return buf.subarray(12, 16).toString()
  }

  function webpSize(buf) {
    if (fourCC(buf) === 'VP8 ') {
      const i = buf.indexOf(Buffer.from([0x9d, 0x01, 0x2a]))
      return [buf.readUInt16LE(i + 3) & 0x3fff, buf.readUInt16LE(i + 5) & 0x3fff]
    }
    if (fourCC(buf) === 'VP8X') {
      return [1 + buf.readUIntLE(24, 3), 1 + buf.readUIntLE(27, 3)]
    }
    throw new Error(`未识别的 webp chunk: ${fourCC(buf)}`)
  }

  it('文件在，且 SFC 里写的地址就是它（不会出现换完图 404 露出深色兜底底）', () => {
    expect(existsSync(FILE)).toBe(true)
    expect(SFC.match(/url\('([^']+\.webp)'\)/)[1]).toBe(ART)
  })

  it('发布图就是这一份字节：md5 + 体积钉住，上面那三组对比度常量才有效', () => {
    expect(crypto.createHash('md5').update(readFileSync(FILE)).digest('hex')).toBe(MD5)
    expect(statSync(FILE).size).toBe(BYTES)
  })

  it('资产是无 alpha 的单帧 lossy（VP8）：罩要回来只会被烤进像素，而 md5 那条会红', () => {
    // §7.50 时"罩不烤进资产"靠 md5 + CSS 里有渐变两头钉；这轮 CSS 里没渐变，
    // 所以补一头**格式**钉：带 alpha / 动图的 VP8X 或退回 png/jpg 都说明有人把遮罩烤进图里了。
    expect(fourCC(readFileSync(FILE))).toBe('VP8 ')
  })

  it('体积比上一版又小了三分之二：这张卡是首页级入口，不该为一张底图拖慢「我的」页', () => {
    expect(BYTES).toBeLessThan(40 * 1024)
    expect(BYTES, '比 v6（21,236 B）大就红，这条是本轮的方向钉').toBeLessThan(21236)
  })

  it('宽高比对得上卡片(343x76)，cover 不会把右侧的墨迹裁掉', () => {
    const [w, h] = webpSize(readFileSync(FILE))
    const card = 343 / 76
    expect(w / h).toBeGreaterThan(card * 0.97)
    expect(w / h).toBeLessThan(card * 1.03)
  })

  it('分辨率够 3 倍图：浅纸底一旦糊了，那些水墨的过渡会变成一条条色带', () => {
    const [w, h] = webpSize(readFileSync(FILE))
    expect(w).toBeGreaterThanOrEqual(343 * 3)
    expect(h).toBeGreaterThanOrEqual(76 * 3)
  })
})
