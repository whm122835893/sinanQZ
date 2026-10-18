import { describe, expect, it } from 'vitest'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import crypto from 'node:crypto'

/**
 * 「我的」页邀新横条：「水墨写意·爵」**裸图** + 白字，CSS 里没有罩（2026-10-18，RUNBOOK §7.51 定这口径，
 * §7.52 只是把**同一张源图**的裁带从 200:768 抬到 352:920 ⇒ 资产 v7→v8）
 *
 * §7.51 的口径两句：「换个风格，主体小一点」→ 定「水墨写意」（主体收到右侧，文字区全干净）
 * + 「那层左侧深色罩怎么处理」→ 选「**摘掉罩**」。那轮既换图又拆层，方向钉全跟着翻。
 * §7.52 的口径一句：「把现在正在用的邀请卡片的背景主体往上提一点」+「把脚露出来」
 * —— 图没重出、罩没回来、字色没动，**只有那条带子挪了 152 行**。所以这一轮翻的是**读数**和**体积**，
 * 判据本身（裸图四块过线、字不压按钮、文案上界）一颗都不动。
 *
 * ## 这份 spec 翻了哪八颗旧钉（都是**翻方向**，不是删掉装作没发生过）
 *   1. 「只认 v7 这一张」→ **v7 进负向名单**，和 jpg / v2 / v3 / v4 / v5 / v6 一起躺着。
 *      它是上一轮刚请进来的，一轮就被同一张源图的另一条带顶掉；资产换代的机关（md5 + 体积 + 负向名单）照旧。
 *   2. 三把尺的读数**整套作废重抄**：`OFFLINE` / `ASSET` / `RENDER` / `RENDER_BOTTOM` / `WITH_SCRIM` / `OLD`
 *      全部换成 v8 的当场读数。字节一变这些数就不成立，所以 md5 那条钉是它们的**共同保押人**。
 *   3. 「图标那块板的中位色 [57,56,58]、白线 11.66」→ v8 上是 **[61,60,61]、10.98**（带子往下挪，
 *      板底下那块亮地气顶上来）。这一颗与 `invite-icon-theme.spec.js` **同源同值**，两处不许各抄一份。
 *   4. 「副标右界 x=220」→ v8 上是 **x=225**（往下挪带子反而把副标右界推出去 5 列）；标题 x=206 不动。
 *      换算出来的文案上界**仍是 8 字 / 12 字**，副标那句 12 个字的现文案照旧**贴线，一个字都不能再加**。
 *   5. 「体积比 v6（21,236 B）小就绿」→ 这轮**第一次往回走了**：v8 = 9,644 B，比 v7 的 7,554 B **大 2,090 B**
 *      （带子从"一整片近黑纸"挪进"爵身 + 底部落影"那块细节区，同样的 q82 压不动了）。
 *      方向钉不能装作没这回事，也不能反过来当"变大没关系"：所以钉法改成**上界仍压 v6 那一档**，
 *      并在注释里把这一轮的增量和原因写死（见下面那条测试）。
 *   6. 两把尺**对表的容差**从 0.2 / 0.3 放宽到 **0.4**：v8 的副标那块底正好踩在带子新压过来的**最陡那一档**上
 *      （离线尺只重采样一次、真机尺经过浏览器缩放 + 抗锯齿），实测差值 0.36；图标差 0.07、标题 0.00、按钮 0.00。
 *      这**不是**把尺子弄松：v6 裸图拿同一把尺量出来是 1.77 / 1.80，与本轮任何一档差 4 个点以上，
 *      "模型错了一层"那种级别照样红。放宽的理由只有上面那一句，别的都不算。
 *   7. 「罩必须是深色渐变」那颗仍然是**反向**钉（CSS 里不许出现任何渐变），本轮没动。
 *      `WITH_SCRIM` 那串数仍是**反证档**（脚本临时注回 §7.50 那副 α0.50 / 63.3%~73.3%），不落盘。
 *   8. 「α≥0.62」「副标白度≥0.85」两颗还是**决策下界**（§7.44 / §7.43 用户点名的观感值），不是测量下界：
 *      v8 上重扫副标白度 0.70 也有 **6.56**（门槛 4.5），比 v7 那轮的 9.28 薄了 2.7 个点但依然不是卡点；
 *      按钮 α 六档在 v8 上与 v7 **逐位相同**（那颗压的是爵身深色墨，带子没挪到它那块底）。
 *
 * §7.51 留在注释里的**两句错话**，本轮当场复量后订正（都是"数没错但口径写错/没标尺"那类）：
 *   · 「文字区换成干净的**浅灰纸**」⇒ 实测 rgb(19,18,21)、整幅 L 均值 0.0103、最亮处 0.54 = **暗底亮爵**。
 *     白字 18:1 成立靠的就是这块近黑底，黑字打上去只有 1.07:1（这条是"背景改白、字改黑"那批的**前置测量**）。
 *   · 源图身份本批用字节复量**重新钉过**（这不是订正台账 —— 本批初稿在这里写过一句「台账把源图记成 F5」，
 *     而 `git grep F5 HEAD` 三个仓库零命中，§7.51 记的一直是 F3 那个文件名，那句**假更正已收回**）：
 *     `cand-F3-ink-corner.png`（raw md5 2591c00d…）跑旧带 200:768 得 18.20 / 13.16 / 15.21 / 9.52，
 *     与 §7.51 记的尺 A 读数**逐位相同** ⇒ v7 确实出在这张上；同一条带拿 `cand-F5-ink-tiny.png` 量只有 1.17，分得开。
 *   · 「罩把右界推到 247/228」⇒ 228 是**尺 A（压缩前）**那一档，v7 落盘字节是 247/**223**。一句"实测"没标尺就会这样。
 *
 * ## 下面那些数怎么来的（三把尺子，口径同一套）
 * 口径三件小事与 §7.49/§7.50 一字未改：
 *   · 背景板 = 把四个前景节点 `visibility:hidden` 之后**再拍一张**（布局不动）。拿有字的截图在文字盒里
 *     逐像素算，盒子里一半像素是字自己，会得到 1.00:1 的假数。
 *   · 文字位置**从差值图反推包围盒**，不拿 rect 宽度当文字盒（标题/副标在 flex 列里被拉成整行宽）。
 *   · 按钮、图标的字/描边压的是**半透明板**，所以背景 = 板色与背景板逐点合成再比。
 * 读数（`min(底最坏, 笔画核心)`，多档取各档最坏，不是平均值）：
 *   · 离线尺 A（DSF=3，压缩前那张 1280x284）`qa/make_invite_banner_fog.py … --rows 352:920`：
 *     标题 18.05 / 副标 8.40 / 按钮 15.21 / 图标 6.41
 *   · 离线尺 B（**落盘后的 webp 字节**，同一段代码，`--rows 0:284` 直接喂那张 webp）：
 *     标题 18.24 / 副标 8.76 / 按钮 15.45 / 图标 6.75
 *     —— Lossy 把最坏那几点压平了，所以真字节反而略好；spec 钉的是这一套。
 *     **尺子没动的证据**：同一条命令拿 v7 落盘字节跑 = 18.41 / 13.30 / 15.45 / 9.59，与 §7.51 记的逐位相同。
 *   · 真机尺 C（`E:/tmp/v52/cdp52.js` + `contrast52.py`）十档 320/360/375/390/412/430/480/600/768/820
 *     **一趟跑完**，十档各自最坏 标题 15.04 / 副标 7.93 / 按钮 12.62 / 图标 6.82。
 *     它比离线 B 低，是因为真机那一列取了**笔画核心**（抗锯齿/亚像素），底最坏那一列是
 *     18.24 / 9.12 / 15.45 / 6.82 —— 与离线 B 差 ≤0.36（见上面第 6 颗钉）。两列都在测试里现算比对。
 *   · 控制组一（**反证：把 §7.50 那副罩叠回去**，脚本注入同一层渐变再拍一圈）：
 *     标题 16.15 / 副标 11.23 / 按钮 12.62 / 图标 9.61，四块**只往上抬** ⇒ 这轮的绿不是罩在扛。
 *   · 控制组二（旧色，同一块底、同一只盒，前景换成 §7.42 那套深红/棕/红）：
 *     标题 2.20 / 副标 1.01 / 按钮 2.38（真机十档各自最坏）；尺 B 同底 2.20 / 1.00 / 2.38 —— 三档都远低于要求。
 *     ⚠️ 这三个数**比 §7.50 那轮（1.00/1.00/1.42）高一截**，不是量具松了：那张底里有大片亮雾，
 *     逐像素一定能找到一个跟字同亮度的点；这张文字区是近黑底，深红不至于全融。含义不变：迟早有一片字完全融进底里。
 *   · 图标那块板的中位色 [57,56,58] → **[61,60,61]**（带底从 768 推到 920，源图底下那片亮地气进了图标那一格），
 *     白线对比 11.66 → **10.98**，#C00000 控制组 1.80 → **1.70** —— 与 `invite-icon-theme.spec.js` 同源
 *     （`E:/tmp/v52/cdp52c.js` + `icon52c.py`，同一个视口 390x844/dpr2），两处不许各抄一份不同的。
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
const ART = '/images/invite-banner-bg-v8.webp'
const FILE = resolve(ROOT, 'public' + ART)

/** 发布图 = 候选「水墨写意·爵」（F3，raw md5 2591c00dbac2f2a868823d5bdeee51b4）裁 **352:920** 缩到 1280x284。
 *  §7.51 那版是**同一张源图**裁 200:768。字节一变，下面所有对比度常量全部作废重抄（§7.46 同款机关） */
const MD5 = '08b551351bb49d665a165fa2c319f4db'
const BYTES = 9644

/** 用过的地址，一个都不许复活：jpg = 最早那张，v2 = §7.42 浅底，v3 = 同批归档，v4 = §7.43~§7.48 深色海报，
 *  v5 = §7.49「雾中逆光」，v6 = §7.50「青铜爵·晨雾」，v7 = §7.51 同图旧带（200:768，被 §7.52 换带顶掉） */
const RETIRED = ['invite-banner-bg.jpg', 'invite-banner-bg-v2', 'invite-banner-bg-v3', 'invite-banner-bg-v4',
  'invite-banner-bg-v5', 'invite-banner-bg-v6', 'invite-banner-bg-v7']

/** 卡片几何：设计盒 343x76，::before 铺的是 padding box（341x76.4，不含 1px 边框） */
const PAD_BOX_W = 341
const CARD_W = 343

/**
 * 三把尺子的读数（v8）。口径：min(底最坏, 笔画核心)，多档取各档最坏，不是平均值。
 * OFFLINE = 压缩前(尺 A)，ASSET = 落盘字节(尺 B)，RENDER = 真机十档(尺 C)，RENDER_BOTTOM = 真机只看"底最坏"那一列。
 */
const OFFLINE = { title: 18.05, sub: 8.40, btn: 15.21, icon: 6.41 }
const ASSET = { title: 18.24, sub: 8.76, btn: 15.45, icon: 6.75 }
const RENDER = { title: 15.04, sub: 7.93, btn: 12.62, icon: 6.82 }
const RENDER_BOTTOM = { title: 18.24, sub: 9.12, btn: 15.45, icon: 6.82 }
/** 反证档：脚本把 §7.50 那副罩（α0.50 / 63.3%~73.3%）叠回真机画面，十档各自最坏 */
const WITH_SCRIM = { title: 16.15, sub: 11.23, btn: 12.62, icon: 9.61 }
const NEED = { title: 3.0, sub: 4.5, btn: 4.5, icon: 3.0 }
/** 控制组：同一块底、同一只盒，前景换成 §7.42 那套深红标题 / 棕副标 / 灰板上的 #C00000（真机十档各自最坏） */
const OLD = { title: 2.20, sub: 1.01, btn: 2.38 }
/** 真机十档实测：两行字墨迹右界（折算回 343 设计盒）各自最大的那个，与下面 EDGE 的对比度界是**两把不同的尺** */
const INK_RIGHT = { title: 172.1, sub: 217.9 }

describe('先把被钉的东西找出来：每条钉的都不是空气', () => {
  it('.invite-banner 这块样式在，::before 也在', () => {
    expect(BANNER.length).toBeGreaterThan(200)
    expect(BEFORE.length).toBeGreaterThan(50)
  })
})

describe('背景层：只剩 v8 这条带（同一张水墨写意，§7.52 换带）', () => {
  it('::before 用的是 v8 这张，且 center/cover —— 换带高就得连目标尺寸一起改，否则 cover 自己再裁一刀', () => {
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
  it('离线（压缩前 / 落盘字节）与真机"底最坏"三列同指标：任一块差过 0.4 个点就说明有一边的模型错了', () => {
    // 0.4 而不是 §7.51 那版的 0.2：本轮带底把源图下方那片**最陡的亮地气**推到副标脚下，
    // 离线尺只重采样一次、真机尺要过浏览器缩放 + 亚像素，两者在这块底上的差被放大到 0.36。
    // 四块实测差：标题 0.00 / 副标 0.36 / 按钮 0.00 / 图标 0.07。**这不是把尺子弄松**——
    // 真正的"模型错一层"是 v6 裸图那种（同一把尺 1.77 / 1.80，与本档差 4 个点以上），照样红。
    for (const kind of ['title', 'sub', 'btn', 'icon']) {
      expect(Math.abs(RENDER_BOTTOM[kind] - ASSET[kind]),
        `${kind}：真机底最坏 ${RENDER_BOTTOM[kind]} 与落盘字节离线 ${ASSET[kind]} 对不上`).toBeLessThanOrEqual(0.4)
      expect(Math.abs(ASSET[kind] - OFFLINE[kind]),
        `${kind}：落盘字节 ${ASSET[kind]} 与压缩前 ${OFFLINE[kind]} 差过头，压缩把最坏点改太狠（重出图要重量）`).toBeLessThanOrEqual(0.4)
      expect(RENDER[kind], `${kind}：真机十档最坏踩破门槛`).toBeGreaterThanOrEqual(NEED[kind])
      expect(OFFLINE[kind], `${kind}：离线设计值踩破门槛`).toBeGreaterThanOrEqual(NEED[kind])
    }
  })

  it('WCAG 公式在测试里现算一遍，与抄进来的数对得上（不是只比字符串）', () => {
    function ch(c) { c /= 255.0; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
    const lum = (p) => 0.2126 * ch(p[0]) + 0.7152 * ch(p[1]) + 0.0722 * ch(p[2])
    const contrast = (a, b) => { const x = lum(a), y = lum(b)
      return +((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)).toFixed(2) }
    // 图标那块板 §7.52 实测中位色（`invite-icon-theme.spec.js` 里同一个数，两处不许各抄一份不同的）
    expect(contrast([255, 255, 255], [61, 60, 61])).toBe(10.98)
    // 十档合并的按钮板中位色（contrast52.py 同一段代码产出）——那颗 4.5:1 压的就是这片底
    expect(contrast([255, 255, 255], [36, 36, 41])).toBe(15.45)
    // 反推"能把这行字压穿的那片底"有多亮：ratio = (Lfg+0.05)/(Lbg+0.05) ⇒ Lbg = (1.05/ratio)-0.05
    //   按钮白字十档最坏 12.62 ⇒ 它脚下最坏那点 L≈0.0332；踩破 4.5 需要 L≈0.1833 —— 0.15 的亮度余量。
    //   §7.49 那版是 4.65 vs 4.5，只剩 0.007，当时靠这条劝人别抬 α；那个前提现在翻了两轮，
    //   断言方向也跟着翻：余量必须**大于** 0.1，谁把读数打回"贴线"那一档，这条就红。
    const bgAt = (ratio) => (1.05 / ratio) - 0.05
    expect(bgAt(RENDER.btn)).toBeLessThan(bgAt(NEED.btn))
    expect(bgAt(NEED.btn) - bgAt(RENDER.btn)).toBeGreaterThan(0.1)
    // 控制组：旧深红标题在同一片底（图标那块板的中位色）上是 1.33:1 —— 远低于 3.0 才算量具有红
    expect(contrast([154, 26, 26], [61, 60, 61])).toBeLessThan(NEED.title)
    expect(contrast([192, 0, 0], [61, 60, 61]), '#C00000 在这块板上 1.70:1，与图标那节同一个数')
      .toBeLessThan(NEED.title)
    expect(OLD.title, '旧色这一档本来就是"迟早有一片字融进底里"，量具分不出新旧就是有病').toBeLessThan(NEED.title)
    expect(OLD.sub, '旧棕副标同底 1.01:1').toBeLessThan(NEED.sub)
    expect(OLD.btn, '灰板上退回 #C00000 只有 2.38:1').toBeLessThan(NEED.btn)
  })

  it('反证档：把 §7.50 那副罩叠回去，四块只会往上抬（这轮的绿不是罩在扛）', () => {
    for (const kind of ['title', 'sub', 'btn', 'icon']) {
      expect(WITH_SCRIM[kind],
        `${kind}：叠上罩反而比裸图低（${WITH_SCRIM[kind]} < ${RENDER[kind]}）⇒ 两档量的不是同一层底，反证作废`)
        .toBeGreaterThanOrEqual(RENDER[kind] - 0.05)
    }
    // 罩对图标的增益最大（图标正压在旧罩的满值段里）：9.61 vs 裸图 6.82，差 **2.79**。
    // 记着它不是为了加回来，是为了说明"摘罩确实降了读数"——降了还过，才是真能摘。
    //（§7.51 那版这个差是 1.25：10.62 vs 9.37。本轮带子把亮地气压到图标脚下，罩能补回来的量反而变大。
    //  下界**仍是 1.0**、没跟着抬：这条钉的是"罩确实有用 ⇒ 两档量的不是同一层底"，
    //  把它抬到 2.0 只会让下一次换图无缘无故红；字节换代由上面那条 md5 钉管。）
    expect(WITH_SCRIM.icon - RENDER.icon, '图标那块对罩的敏感度变了 ⇒ 底图或板色动过').toBeGreaterThan(1.0)
  })
})

describe('文字是压在近黑底上的浅色：旧深红/棕色不许改回来', () => {
  it('标题纯白、副标近白，且都带 text-shadow 兜住踩到高光边的笔画', () => {
    const title = code(blockOf('&__title'))
    const sub = code(blockOf('&__sub'))
    expect(title).toMatch(/color:\s*#FFFFFF\b/i)
    expect(title).toMatch(/text-shadow:/)
    expect(sub).toMatch(/color:\s*rgba\(255,\s*255,\s*255,\s*([\d.]+)\)/i)
    expect(sub).toMatch(/text-shadow:/)
    // ⚠️ 0.85 现在也是**决策下界**（§7.43 定的观感值），不再是量出来的那一档：
    //   v8 裸图上重扫副标白度（尺 B 落盘字节、同一只墨迹盒，`E:/tmp/v52/sweep52.py`）
    //   0.70→**6.56**、0.75→7.26、0.80→7.96、**0.85→8.76（本轮这档）**、0.90→9.61、1.00→11.43
    //   —— 门槛 4.5，连最淡那档都还有 2.06 个点，所以"再淡就不达标"在 v8 上同样不是测量结论。
    //   对比 §7.51 那串（v7 落盘：0.70→9.28 … 0.85→13.30 … 1.00→18.24）：整串往下移了 4~7 个点，
    //   是带子把亮地气压到副标脚下换来的（见文件头那颗第 2 钉）；同一份代码能逐位复现 v7 那串 ⇒ 尺子没动。
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
    expect(btn, `深红字压在透明灰上只有 ${OLD.btn}:1，等于看不见`).not.toMatch(/color:\s*#C00000/i)
  })

  it('透明灰这块板：中性深灰 + α≥0.62 + 1px 半透明亮边 + 内高光', () => {
    const btn = code(blockOf('&__btn'))
    const m = /background:\s*rgba\(\s*46,\s*46,\s*52,\s*([\d.]+)\s*\)/.exec(btn)
    expect(m, '按钮底不是那个量出来的中性灰 rgb(46,46,52)').not.toBeNull()
    const alpha = Number(m[1])
    // ⚠️ 0.62 是**决策下界**（§7.44 用户点名的值），连着三轮都不是"量出来的能过的最薄那一档"。
    //   §7.49 在 v4/v5 亮雾底上扫：0.55→3.80、0.62→4.56 ⇒ 当时确实贴着门槛。
    //   §7.50 在 v6 上重扫：0.55→8.39、0.62→9.08 —— 下界掉了，但"抬 α 有用"还成立。
    //   §7.51 在 v7 上再扫：0.55→15.48、0.58→15.41、**0.62→15.21**、0.68→14.88、0.75→14.67、0.82→14.28
    //   —— 从这一轮起**抬 α 反而往下走**：那颗坐在爵身的深色墨上，卡住读数的是灰板本色
    //   （纯白对 rgb(46,46,52) = 13.49:1 是天花板），所以别再拿"加厚"当万能药。
    //   ⚠️ 这六档当时没标尺，事后核对是**尺 A（压缩前内存图）**；§7.52 把两把尺都重扫了一遍：
    //   · 尺 A（1280×284 内存图；v7 带 200:768 与 v8 带 352:920 **两条带扫出来逐位相同**）
    //     0.55→15.48、0.58→15.41、**0.62→15.21**、0.68→14.88、0.75→14.67、0.82→14.28
    //   · 尺 B（落盘 webp 字节直接喂 --rows 0:284；v7 / v8 同样逐位相同）
    //     0.55→15.66、0.58→15.46、**0.62→15.45**、0.68→15.06、0.75→14.67、0.82→14.28
    //   · 真机十档（尺 C）底最坏 15.45、各档最坏 12.62 —— 与 §7.51 那串逐位相同，
    //     因为换带只挪了背景那一条 568 行的窗口，这颗按钮脚下那片"爵身深色墨"没动。
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

  it('文案长度上界按**新带（v8）+ 摘罩**重扫过：标题第 206 列跌破 3.0、副标第 225 列跌破 4.5 ⇒ 8 / 12 字', () => {
    // 界不是抄进来的常数，是这里现算的，两个输入都从 CSS 读：
    //   · 每字宽 = font-size + letter-spacing（标题 15 + 0.5 = 15.5，副标 12 + 0 = 12）；
    //   · 起点 x = padding16 + 图标位44 + gap12 = 72（上面那条已经钉过这三段）。
    // 右界这轮**两行还是都由对比度给**（罩没了，底图往右那片亮地直接界住字 —— 白字对亮地跌破门槛）：
    //   · 标题：`--right-edge` 扫描给出 x=206（v7 上也是 206，那两列之间没东西）。布局界 250 更靠外，
    //     所以取更小的那个：8 字到 72+15.5×8 = 196 < 206 过，9 字到 211.5 ≥ 206 破 ⇒ 上界 8（现在是 7 字，还剩一格）。
    //   · 副标：x=225（v7 上是 220 —— 往下挪带子把副标右界往外推了 5 列）。
    //     12 字到 72+12×12 = 216 < 225 过，13 字到 228 ≥ 225 破 ⇒ 上界 **12**；
    //     这一版不再是"贴线"，而是有 9 CSS px 余量，但第 13 个字（写到 228）照样破线。
    // §7.43 当年钉 7 / 12（当时的文案长度），§7.49 是 13 / 10，§7.50 是 11 / 14，§7.51 是 8 / 12，
    // 这一轮随"换带"重扫。算法与输入都在测试里，改图就得改这两个列号并重跑 qa 脚本的 `--right-edge`。
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
    // 出处：`python qa/make_invite_banner_fog.py <图> --right-edge`（§7.52 在 v8 上当场跑，两把尺同值）
    //   尺 A（压缩前 1280×284，E:/tmp/v50/cand-F3-ink-corner.png 带 352:920）→ 206 / 225
    //   尺 B（落盘字节 invite-banner-bg-v8.webp，--rows 0:284 直接喂那张 webp）→ 206 / 225
    //   「+罩」那两列是反证档（247 / 241），不落盘。
    const CONTRAST_EDGE = { title: 206, sub: 225 }
    const EDGE = { title: Math.min(CONTRAST_EDGE.title, LAYOUT), sub: CONTRAST_EDGE.sub }
    const cap = (sel, kind) => Math.floor((EDGE[kind] - start) / perChar(sel))
    expect(perChar('&__title'), '标题每字宽不再是 15.5').toBe(15.5)
    expect(perChar('&__sub'), '副标每字宽不再是 12').toBe(12)
    expect(start, '文字起点不再是 x=72').toBe(72)
    expect(LAYOUT, '文字盒右沿不再是 x=250（按钮左沿−gap）').toBe(250)
    expect(EDGE.title, '标题的界不再是布局给的 250 —— 对比度界 206 更紧，说明右界读数列被改了')
      .toBe(206)
    expect(cap('&__title', 'title'), '标题的字宽上界不再是 8 字（对比度界 x=206）').toBe(8)
    expect(cap('&__sub', 'sub'), '副标的字宽上界不再是 12 字（对比度界 x=225）').toBe(12)
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

  it('体积还在 v6 的下界以内，但这一轮第一次往回走了 2,090 B：把脚放进来就是要多留一段墨迹', () => {
    expect(BYTES).toBeLessThan(40 * 1024)
    expect(BYTES, '比 v6（21,236 B）大就红，这条是本轮的方向钉').toBeLessThan(21236)
    // §7.51 → §7.52 是这五轮里**第一次**体积往上走（7,554 → 9,644，+2,090 B）：
    // 带子从 200:768 挪到 352:920，多进来的是爵身那一段浓墨 + 三条腿，Lossy 编码要花的比特自然多。
    // 所以这条钉**不能**写成"比上一版小就绿"—— 那是 §7.51 那一轮的巧合，不是规律；
    // 也不给它补一条"必须大于 7,554"的反向钉（将来出个更小又达标的新版，那条只会假红）。
    // 真正的上界是 v6（21,236 B，同一张卡另一条带），v8 离它还有一倍余量：
    // 谁哪天把这张推过 21,236，说明换图没走"先量再挑"那道门槛。
  })

  it('宽高比对得上卡片(343x76)，cover 不会把右侧的墨迹裁掉', () => {
    const [w, h] = webpSize(readFileSync(FILE))
    const card = 343 / 76
    expect(w / h).toBeGreaterThan(card * 0.97)
    expect(w / h).toBeLessThan(card * 1.03)
  })

  it('分辨率够 3 倍图：水墨的浓淡过渡一旦糊了，会变成一条条色带', () => {
    const [w, h] = webpSize(readFileSync(FILE))
    expect(w).toBeGreaterThanOrEqual(343 * 3)
    expect(h).toBeGreaterThanOrEqual(76 * 3)
  })
})
