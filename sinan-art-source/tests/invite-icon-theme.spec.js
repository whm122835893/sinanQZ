import { describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync, existsSync, readdirSync } from 'node:fs'
import { resolve } from 'node:path'
import crypto from 'node:crypto'

/**
 * 邀新卡图标：五套主题共用**同一份矢量定义** —— `AppIcon` 的 `invite` 那条形（人形 + 加号）
 * （RUNBOOK §7.47，2026-10-17 第四次改口径）
 *
 * 用户口径三档：「邀请好友图标换成人形图标带加号」+「用现成的矢量线条（一行接线）」+「仍五套共用一张」。
 * 落到代码就是 `src/stores/iconTheme.js` 里那五个 `invite:` 行都写成 `{ type: 'svg', icon: 'invite' }`，
 * 画的东西来自 `src/components/AppIcon.vue:62` 那条 `path`（人头 circle + 肩弧 + 一竖一横的加号）。
 *
 * 这条口径推翻了 §7.46（一张写实玉佩圆角小卡 webp）、§7.45（五套各一张平面中式请柬）、
 * §7.44（只换 classic 那一张）、§7.43（比 calendar 的钉法）。**旧钉是翻方向留下的，不是删掉装作没发生过**：
 * 上面那几批的实测数（webp 9,724 B / 4bit 357 档 / top8 0.495 / 均色 (136,93,47)、
 * 五张平面 47~54 档）都留在下面的注释里，用来证明这一节的判据不是照着成品反推的。
 * 被换掉的那张 webp 成品字节留在仓库外 `E:/tmp/v47/retired/invite-jade.webp`（md5 `bac1d979…`），
 * 出图脚本 `qa/make_jade_invite_icon.py` 留着但已在文件头标注停用。
 *
 * ## 这一版比上一版**硬**在哪（也别忘了软在哪）
 * 上一版钉的是位图字节，风格数只能离线量完抄进来（vitest 没有 WebP 解码器）。
 * 这一版没有字节可钉 —— 图标是**一段 path 字符串 + 一个颜色**，于是：
 *   1. 形状、颜色、线宽、画布足迹全部在 CI 里**现算**（下面的 WCAG 亮度、线段解析、坐标包络都是真计算）；
 *   2. 唯一抄进来的数是「那块磨砂底板渲染出来是什么颜色」（`PLATE_RENDERED_RGB`）—— 它由海报图 +
 *      rgba 叠加 + backdrop-filter 合成，只有浏览器量得出来。所以对比度这条是
 *      **「测试算公式，CDP 供背景色」**，不是"像素在 CI 里重量过"。底板那三行 CSS 与海报 md5 一起钉住了，
 *      谁动底板/换海报，抄来的背景色就失效 ⇒ 必须重跑一遍（§7.47 用 `E:/tmp/v47/cdp47.js`，
 *      §7.49 换图后重抄用的是 `E:/tmp/v49/cdp49c.js`，见下面那段）。
 *   3. 另一处"抄"是 CDP 量到的几何（28.00 CSS px 方盒、线宽 2.33 px、getBBox 17×15 单位）。
 *      其中包络那条测试会**自己从 path 串算一遍**再与抄进来的数对上，两边不一致就是有人改了形状。
 *
 * ## 2026-10-17 §7.49：海报换成「雾中逆光」v5 并加了左侧深色罩，本节抄进来的那几个数已重抄
 * 图标本身**一个字节没动**（path 串、28px 盒、#FFFFFF 全部照旧），动的是它背后那块板压着的东西：
 * 海报 v4→v5、`::before` 多了一层左罩（图标在 x=16..60，正好落在罩的满值段里）。
 * 于是 `plateRGB` 从 [42,47,51] 抬到 [59,59,56]（亮雾比那张暗海报亮，罩只压回一点），
 * 白线对比从 13.52:1 降到 **11.24:1**，#C00000 控制组从 2.09:1 变 1.73:1 —— 两边都还在各自线内，
 * 但**方向是降**，所以这一节原来的余量薄了 2.3 个点，台账里按"降"记。
 * 重跑口径与 §7.47 完全一把尺（中位背景、>24 掩膜、前 10% 取中位），只是脚本换到
 * `E:/tmp/v49/cdp49c.js` + `icon49c.py`（同一视口 390x844/dpr2，藏的是 `.app-icon` 而不是整块板）。
 *
 * ## 2026-10-18 §7.51：海报 v5→v6→v7、**罩整层摘掉**，本节抄进来的数又重抄了一遍
 * 图标还是一个字节没动（path 串、28px 盒、#FFFFFF 照旧），动的还是它背后那块板压着的东西。
 * 四轮同一把尺的读数沿革（390×844 / DPR 2，中位背景）：
 *   §7.48 v4 暗海报、无罩      板 [42,47,51] L=0.0276 白线 13.52:1  #C00000 2.09:1  墨迹 637
 *   §7.49 v5 亮雾 + 左罩        板 [59,59,56] L=0.0434 白线 11.24:1  #C00000 1.73:1  墨迹 635
 *   §7.50 v6 爵 + 罩加深到 .50  板 [43,43,42] L=0.0241 白线 14.17:1  #C00000 2.19:1  墨迹 637
 *   §7.51 v7 水墨 + **摘罩**    板 [57,56,58] L=0.0400 白线 11.66:1  #C00000 1.80:1  墨迹 636
 * 本轮的方向是**升**（11.24 → 11.66），但比 §7.50 那版薄了 2.5 个点 —— 那 2.5 个点是罩给的，
 * 不是图给的：图标 x=16..60 正好在 §7.50 那副罩的满值段里，罩一摘，板下面就直接是 v7 那张浅灰宣纸。
 * 判据是非文字 3.0:1（WCAG 1.4.11），11.66 离线还有 3.9 倍，所以这轮的取舍是「够」而不是「最优」。
 * 脚本 `E:/tmp/v51/cdp51c.js` + `icon51c.py`；它的 `::before` 守卫是**反向**的：读到 linear-gradient
 * 就抛错（那说明 §7.51 的摘罩没生效），并确认底板下引的是 v7。
 *
 * ⚠️ 顺带修掉本节自己一个活 bug：旧版那句覆盖率断言除的是 `92 * 92`，而 `icon4x.json` 里
 *   `png` 从 §7.47 起一直是 **[90,90]**（45.75 CSS px × DPR 2 取整）。0.075 那档是拿错分母算出来的，
 *   它一直没红只是因为 `toBeCloseTo(0.075, 2)` 的容差比这个误差宽。现在分母改成实测的 90，
 *   并且与 `icon51c.json` 自己报的 `glyph_coverage`（0.0785）对齐 —— 覆盖率这条从"抄进来的数"变成"能对上原始读数"。
 */

const ROOT = resolve(__dirname, '..')
const TAB_DIR = resolve(ROOT, 'public/images/tab')
// POSTER_REF 与 User.vue 里 `::before` 那串 url 是**同一个常量**：本节抄进来的底板色只有在这一张图下才成立。
// 上一版这里写死的是 v5 的路径，而页面早已换到 v6 —— 本节连着两轮绿着量的是**没人用的那张图**（各说各话）。
// 所以下面那条测试改成从 sfcSrc 现读引用地址来对，不再让路径和 md5 各抄各的。见 [[transcribed-constants-need-provenance]]。
const POSTER_REF = '/images/invite-banner-bg-v7.webp'
const POSTER = resolve(ROOT, 'public' + POSTER_REF)

const THEME_IDS = ['classic', 'gem', 'ink', 'shanse', 'glass']

/** 三代旧口径的位图文件名，一个都不许复活（§7.45 五张平面请柬 / §7.46 写实玉佩 / §7.44 之前那批同名） */
const RETIRED_PNG = ['modern-invite.png', 'gem-invite.png', 'ink-invite.png', 'shanse-invite.png', 'lg-invite.png']
const RETIRED_WEBP = 'invite-jade.webp'

/** `AppIcon.vue` 里 `invite` 那条 path，逐字节钉住：换形状要红，红了就得重跑 CDP 重抄几何 */
const INVITE_PATH = '<circle cx="8" cy="8" r="3"/><path d="M2 20c.5-3.3 3.2-5.5 6-5.5s5.5 2.2 6 5.5"/><path d="M16 7v6M13 10h6"/>'

/** 渲染后实测（§7.51 重抄：`E:/tmp/v51/cdp51c.js` + `icon51c.py`，390×844 / DPR 2，与 §7.47 同一把尺） */
const RENDER = {
  // 那块 44px 磨砂底板**合成后**的中位色：rgba(255,255,255,.16) 叠在 v7 海报上、再过 blur(2px)。
  // §7.50 那版外面还有一层左罩（图标 x=16..60 在满值段里）把它压到 [43,43,42]；本轮罩摘了 ⇒ 回到 [57,56,58]。
  // 它是本节唯一抄进来的颜色，对比度公式在测试里现算。
  plateRGB: [57, 56, 58],
  plateLum: 0.0400,
  // 线条实色那档（掩膜内与背景差得最远的前 10% 取中位）与它相对底板的对比度
  //（§7.49 11.24 → §7.50 14.17 → 本轮 11.66：那 2.5 个点是罩给的，摘罩后与 §7.49 同档）
  inkWhite: [255, 255, 255],
  contrastWhite: 11.66,
  // 控制组：把颜色退回改之前的 #C00000，同一块底板上量到的最好一档只有 1.80:1 ⇒ 非文字 3:1 不达标。
  // （§7.47 2.09、§7.49 1.73、§7.50 2.19；四批的底板/海报不同版，结论一字不变。）
  inkRed: [192, 0, 0],
  contrastRed: 1.80,
  // 几何：AppIcon 的行内 style 给宽高 ⇒ 不吃 px→vw 那层转换，实测就是 28.00 CSS px 见方
  //（上一版那张位图走 SCSS 的 `&__img{width:28px}`，被 px→vw 放大成 29.11，两者差 3.8%）。
  boxCss: 28.0,
  strokeCss: 2.333,        // stroke-width=2（24 单位）× 28/24
  pngDevice: 90,           // 截图实测 90×90 设备像素（底板 45.75 CSS px × DPR 2 取整；旧版误按 92 算）
  inkPixels: 636,          // 线条占 636 px = 底板面积的 7.85%（§7.49 635 / §7.50 637，差的是抗锯齿抖动）
  coverage: 0.0785,        // 与 icon51c.json 自己报的 glyph_coverage 逐位相同
  fringePixels: 25,        // 抗锯齿那一圈只有 25 px ⇒ 不是"几颗亮点"，线是连续的
  // path 坐标包络（锚点 + 控制点），与 CDP 的 svg.getBBox() 逐位相同
  bboxUnits: [2, 5, 17, 15]
}

/** WCAG 1.4.11 非文字对比度下限（图标属于这一档），也是这一节真正的判据 */
const MIN_NONTEXT_CONTRAST = 3

// §7.51 的 v7「水墨写意·爵」（1280x284 / 7,554 B / 有损 webp，无 alpha）。
// 前身 v4 32,466 B（41dc507c…）、v5 21,974 B（7faa4a09…）、v6 21,236 B（e63b7372…）都已没人引用，
// 文件还躺在 public/images/ 等用户点头清理 —— 本节钉的是**当前这张**，回到任何一张旧的都是重抄。
const POSTER_MD5 = '22b468e82aa492cf45ed5ef7ccb3a8cf'

const storeSrc = readFileSync(resolve(ROOT, 'src/stores/iconTheme.js'), 'utf8').replace(/\r\n/g, '\n')
// 扫"旧位图还引用着没有"之前先把注释剥掉：这一条钉的是**代码引用**，不是"文档里不许提到旧文件名"——
// 主题表那段说明、本文件顶部的口径沿革都必然要点旧名字，连着注释一起扫就是自己给自己造反证。
const storeCode = storeSrc.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '')
const sfcSrc = readFileSync(resolve(ROOT, 'src/views/User.vue'), 'utf8').replace(/\r\n/g, '\n')
const iconSrc = readFileSync(resolve(ROOT, 'src/components/AppIcon.vue'), 'utf8').replace(/\r\n/g, '\n')

function md5File(file) {
  return crypto.createHash('md5').update(readFileSync(file)).digest('hex')
}

function themeFeaturesBlock(id) {
  const start = storeSrc.indexOf(`  ${id}: {`)
  if (start < 0) return ''
  const features = storeSrc.indexOf('features: {', start)
  const end = storeSrc.indexOf('\n    }', features)
  return storeSrc.slice(features, end)
}

/* ---------- 下面这几个小函数是"测试自己算"的部分 ---------- */

/** sRGB 相对亮度（WCAG 定义），线性化 + 0.2126/0.7152/0.0722 */
function relLum([r, g, b]) {
  const ch = (v) => (v / 255 <= 0.04045 ? v / 255 / 12.92 : ((v / 255 + 0.055) / 1.055) ** 2.4)
  return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b)
}

/** 对比度：(L1+0.05)/(L2+0.05)，与浏览器/CDP 那侧算法一致 */
function contrast(a, b) {
  const la = relLum(a)
  const lb = relLum(b)
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

/** 迷你 path 解释器：把 d 串里的锚点与控制点全部落成坐标（只支持这条 path 用到的 M/m/L/l/H/h/V/v/C/c/S/s） */
function parsePoints(d) {
  const tokens = d.match(/[a-zA-Z]|-?\d*\.?\d+/g) || []
  const pts = []
  let i = 0
  let x = 0
  let y = 0
  let cmd = ''
  const n = () => parseFloat(tokens[i++])
  while (i < tokens.length) {
    if (/^[a-zA-Z]$/.test(tokens[i])) { cmd = tokens[i++]; continue }
    if (cmd === 'M' || cmd === 'L') { x = n(); y = n(); pts.push([x, y]) }
    else if (cmd === 'm' || cmd === 'l') { x += n(); y += n(); pts.push([x, y]) }
    else if (cmd === 'H') { x = n(); pts.push([x, y]) }
    else if (cmd === 'h') { x += n(); pts.push([x, y]) }
    else if (cmd === 'V') { y = n(); pts.push([x, y]) }
    else if (cmd === 'v') { y += n(); pts.push([x, y]) }
    else if (cmd === 'C') {
      const p = [[n(), n()], [n(), n()], [n(), n()]]
      pts.push(...p); x = p[2][0]; y = p[2][1]
    } else if (cmd === 'c') {
      const a = [x + n(), y + n()]; const b = [x + n(), y + n()]; const e = [x + n(), y + n()]
      pts.push(a, b, e); x = e[0]; y = e[1]
    } else if (cmd === 'S') {
      const b = [n(), n()]; const e = [n(), n()]
      pts.push(b, e); x = e[0]; y = e[1]
    } else if (cmd === 's') {
      const b = [x + n(), y + n()]; const e = [x + n(), y + n()]
      pts.push(b, e); x = e[0]; y = e[1]
    } else throw new Error(`path 解释器不认识指令 ${cmd}：这条 path 的形状口径要重估`)
  }
  return pts
}

/** 整条 invite 定义（circle + 若干 path）的坐标包络 [minX, minY, w, h]。
 *  取的是锚点 + 控制点 ⇒ 是**保守**包络；这条 path 恰好没有外凸控制点，所以它与 CDP 的 getBBox 逐位相等。 */
function glyphExtents(path) {
  const pts = (path.match(/d="([^"]+)"/g) || []).flatMap((s) => parsePoints(s.slice(3, -1)))
  for (const c of path.match(/<circle[^>]*>/g) || []) {
    const [, cx, cy, r] = /cx="([\d.]+)" cy="([\d.]+)" r="([\d.]+)"/.exec(c).map(Number)
    pts.push([cx - r, cy - r], [cx + r, cy + r])
  }
  const xs = pts.map((p) => p[0])
  const ys = pts.map((p) => p[1])
  return [Math.min(...xs), Math.min(...ys), Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys)]
}

/* ---------- 人形 + 加号的结构解析 ---------- */

function invitePath() {
  const m = /(^|\n)\s*invite:\s*\{\s*path:\s*'([^']+)'\s*\}/.exec(iconSrc)
  expect(m, 'AppIcon.vue 里找不到 invite 那条 path —— 图标定义被挪走或改名了').toBeTruthy()
  return m[2]
}

/** 从 `M16 7v6M13 10h6` 里解析出「一竖 + 一横」两条线段（起点绝对、长度相对） */
function plusSegments(path) {
  const seg = /M([\d.]+) ([\d.]+)v([\d.\-]+)M([\d.]+) ([\d.]+)h([\d.\-]+)/.exec(path)
  if (!seg) return null
  const [, vx, vy, vh, hx, hy, hw] = seg.map(Number)
  return {
    vertical: { x: vx, from: vy, to: vy + vh, len: Math.abs(vh) },
    horizontal: { y: hy, from: hx, to: hx + hw, len: Math.abs(hw) }
  }
}

describe('§7.47：五套主题的 invite 共用同一份矢量定义（人形 + 加号）', () => {
  for (const id of THEME_IDS) {
    it(`${id} 的 invite 是 { type: 'svg', icon: 'invite' }：走矢量分支，不再是位图`, () => {
      const block = themeFeaturesBlock(id)
      expect(block.length, `${id} 主题的 features 块没找到，主题表结构变了`).toBeGreaterThan(50)
      const line = /\binvite:\s*\{\s*type:\s*'svg',\s*icon:\s*'invite'\s*\}/.exec(block)
      expect(line, `${id} 的 invite 不是那条矢量小人`).not.toBeNull()
    })
  }

  it('五套共用的是**一份**定义：AppIcon.vue 里 invite 只出现一次，五处指的都是同一个名字', () => {
    const defs = iconSrc.split('\n').filter((l) => /^\s{2}invite:\s*\{/.test(l))
    expect(defs.length, `AppIcon 里 invite 定义了 ${defs.length} 份，"共用一份"这句话就不成立了`).toBe(1)
    const names = THEME_IDS.map((id) => /icon:\s*'([^']+)'/.exec(/\binvite:\s*\{[^}]*\}/.exec(themeFeaturesBlock(id))[0])[1])
    expect(new Set(names).size, `五套指到的图标名不止一个：${names.join(' / ')}`).toBe(1)
    expect(names[0]).toBe('invite')
  })

  it('分支差异是有意的：invite 走 svg，同主题的 calendar 仍走 image（§7.46 那条"与 calendar 同分支"的旧钉已翻掉）', () => {
    for (const id of THEME_IDS) {
      const block = themeFeaturesBlock(id)
      const cal = /\bcalendar:\s*\{\s*type:\s*'(\w+)'/.exec(block)
      const inv = /\binvite:\s*\{\s*type:\s*'(\w+)'/.exec(block)
      expect(cal && inv, `${id} 少 calendar 或 invite`).toBeTruthy()
      expect(inv[1]).toBe('svg')
      expect(cal[1], `${id} 的 calendar 被顺带改成矢量了，这批没这个范围`).toBe('image')
    }
  })

  it('运行时：五套切过去，getFeatureIcon("invite") 拿到的都是那份矢量条目', async () => {
    setActivePinia(createPinia())
    const { useIconThemeStore } = await import('@/stores/iconTheme')
    const store = useIconThemeStore()
    for (const id of THEME_IDS) {
      expect(store.setFeatureTheme(id), `主题 ${id} 切不过去`).toBe(true)
      expect(store.getFeatureIcon('invite'), `${id} 取到的不是矢量条目`).toEqual({ type: 'svg', icon: 'invite' })
    }
  })

  it('后台上传的自定义 invite 仍优先；清空后回落到那份矢量（不是任何一张旧位图）', async () => {
    setActivePinia(createPinia())
    const { useIconThemeStore } = await import('@/stores/iconTheme')
    const store = useIconThemeStore()
    store.setCustomIcons({ invite: '/uploads/custom-invite.png' })
    store.setFeatureTheme('custom')
    expect(store.getFeatureIcon('invite')).toEqual({ type: 'image', image: '/uploads/custom-invite.png' })
    store.setCustomIcons({ invite: '' })
    expect(store.getFeatureIcon('invite')).toEqual({ type: 'svg', icon: 'invite' })
  })
})

describe('三代旧位图口径不许复活', () => {
  it('主题表里 invite-jade / <前缀>-invite.png 出现 0 次（§7.44~§7.46 三批的钉都在这条线下）', () => {
    const hits = [RETIRED_WEBP, ...RETIRED_PNG].filter((n) => storeCode.includes(n))
    expect(hits, `主题表还指着旧位图：${hits.join(' / ')}`).toEqual([])
    expect((storeCode.match(/icon:\s*'invite'/g) || []).length,
      '指向矢量小人的 `icon: \'invite\'` 不是 5 处 —— 有主题被漏掉或多了一处')
      .toBe(5)
  })

  it('盘上不留任何 invite 位图：public/images/tab 里文件名含 invite 的文件数 = 0', () => {
    const left = readdirSync(TAB_DIR).filter((f) => /invite/i.test(f))
    expect(left, `这些文件已零引用还留着：${left.join(' / ')}`).toEqual([])
  })

  it('位图分支本身必须留着：后台那个与主题无关的自定义上传口还靠它渲染', () => {
    expect(sfcSrc).toMatch(/inviteIcon\?\.type === 'image'/)
    expect(sfcSrc).toMatch(/:src="inviteIcon\.image"/)
    const img = /\.invite-banner[\s\S]*?&__img\s*\{([^}]*)\}/.exec(sfcSrc)
    expect(img, '.invite-banner__img 那条样式被当孤儿删了 ⇒ 后台自定义图会撑破底板').toBeTruthy()
    expect(img[1]).toMatch(/object-fit:\s*contain/)
  })
})

describe('矢量定义本体 = 人形 + 加号：形状逐字节钉，结构现场解析', () => {
  it('path 串逐字节等于钉住的那串（换一笔都要红，红了必须重跑 CDP 重抄几何）', () => {
    expect(invitePath()).toBe(INVITE_PATH)
  })

  it('人形自证：一个头（circle r=3）+ 一条带曲线的肩（c 指令），不是随便两个圆', () => {
    const path = invitePath()
    const circles = path.match(/<circle[^>]*>/g) || []
    expect(circles.length, `circle 有 ${circles.length} 个：人头应该只有一个`).toBe(1)
    const head = /<circle cx="([\d.]+)" cy="([\d.]+)" r="([\d.]+)"/.exec(circles[0])
    expect(head, '人头不是 cx/cy/r 那种写法，本节按字面解析的口径要重估').toBeTruthy()
    expect([Number(head[1]), Number(head[2]), Number(head[3])], '人头不在 (8,8)、半径不是 3').toEqual([8, 8, 3])
    expect(path, '肩弧没有曲线指令（c）= 直愣愣一条线，不是人形').toMatch(/<path d="M[\d.]+ [\d.]+c/)
  })

  it('加号自证：一竖一横、等长、互相穿过对方中点 ⇒ 是"+"，不是"×"也不是"−"', () => {
    const seg = plusSegments(invitePath())
    expect(seg, '没解析出"M x y v n M x y h n"这种一竖一横 = 加号被改掉了').toBeTruthy()
    expect(seg.vertical.len).toBe(seg.horizontal.len)
    expect(seg.vertical.len, '加号两笔太短，28px 盒子里会糊').toBeGreaterThanOrEqual(5)
    const vMid = (seg.vertical.from + seg.vertical.to) / 2
    const hMid = (seg.horizontal.from + seg.horizontal.to) / 2
    expect([seg.vertical.x, vMid], '竖笔没穿过横笔中点').toEqual([hMid, seg.horizontal.y])
    expect(seg.vertical.from, '竖笔起点在横笔下面 = 那是个"丁"字').toBeLessThan(seg.horizontal.y)
    expect(seg.vertical.to, '竖笔终点在横笔上面 = 那也是倒过来的"丁"').toBeGreaterThan(seg.horizontal.y)
  })

  it('是"线条"不是"色块"：fill=none、stroke=currentColor、stroke-width=2（用户选的就是矢量线条）', () => {
    expect(iconSrc).toMatch(/viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"/)
    expect(invitePath(), 'invite 这条被改成了填充式（fill 那支），线条口径就没了').not.toMatch(/fill="/)
  })

  it('画布足迹：测试从坐标算出的包络 == CDP 量到的 getBBox（17×15 / 24 单位），不是满幅大色块', () => {
    const got = glyphExtents(invitePath())
    expect(got, '坐标包络变了 ⇒ 下面那些 28px/线宽/覆盖率的实测数全部要重量').toEqual(RENDER.bboxUnits)
    // 包络只占画布 ~70% ⇒ 图标四周留白，28px 小盒子里不会挤成一团
    expect(got[2]).toBeLessThanOrEqual(20)
    expect(got[3]).toBeLessThanOrEqual(20)
  })
})

describe('看得境：白线对那块磨砂底板的对比度（公式在 CI 里现算，背景色来自 CDP 实测）', () => {
  it('User.vue 那条 AppIcon 的颜色钉成 #FFFFFF，且明确不许退回 #C00000', () => {
    const line = /<AppIcon[^>]*:name="inviteIcon\?\.icon \|\| 'invite'"[^>]*\/>/.exec(sfcSrc)
    expect(line, '邀请入口那条 AppIcon 找不到了（属性顺序变了？本节按整行解析）').toBeTruthy()
    const color = /color="([^"]+)"/.exec(line[0])
    expect(color, 'AppIcon 没写 color ⇒ 会继承页面文字色，深色海报上等于看不见').toBeTruthy()
    expect(color[1].toUpperCase()).toBe('#FFFFFF')
    expect(color[1].toUpperCase(), '退回 §7.44 那个深红 = 本轮实测 1.80:1，本节下面那条就是拦这个的').not.toBe('#C00000')
  })

  it('底板那三行 CSS 一起钉：它们变了，抄进来的 PLATE_RENDERED_RGB 就作废（这条是"背景色可追溯"的机关）', () => {
    const plate = /\.invite-banner[\s\S]*?&__icon\s*\{([^}]*)\}/.exec(sfcSrc)
    expect(plate, '找不到 .invite-banner__icon 那条样式').toBeTruthy()
    expect(plate[1]).toMatch(/width:\s*44px;\s*height:\s*44px/)
    expect(plate[1]).toMatch(/border-radius:\s*12px/)
    expect(plate[1]).toMatch(/background:\s*rgba\(255,\s*255,\s*255,\s*0\.16\)/)
    expect(plate[1]).toMatch(/backdrop-filter:\s*blur\(2px\)/)
    expect(md5File(POSTER), '海报字节变了 ⇒ 底板合成色要重量，对比度那条不许直接放过去').toBe(POSTER_MD5)
    // 本节量的一定是页面正在用的那张：路径与 md5 各抄各的，就会出现「spec 量 v5、页面早已 v7」的两轮空转
    const used = /url\('([^']+\.webp)'\)/.exec(sfcSrc)
    expect(used, 'User.vue 的 ::before 里没有 webp 引用了 ⇒ 本节那块底板的背景是谁？').toBeTruthy()
    expect(used[1], `本节抄进来的底板色量的是 ${POSTER_REF}，页面用的却是 ${used[1]}`).toBe(POSTER_REF)
    expect(sfcSrc, '§7.51 的罩已经摘掉：这里若读到渐变层，抄进来的 [57,56,58] 就不是这块板的色').not.toMatch(/linear-gradient/)
  })

  it(`白线 vs 实测底板 rgb(${RENDER.plateRGB.join(',')}) = ${RENDER.contrastWhite}:1 ≥ ${MIN_NONTEXT_CONTRAST}:1（WCAG 1.4.11 非文字）`, () => {
    const computed = contrast(RENDER.inkWhite, RENDER.plateRGB)
    expect(computed, '测试自己算出来的对比度与 CDP 抄进来的对不上 ⇒ 两边算法有一边变了').toBeCloseTo(RENDER.contrastWhite, 1)
    expect(computed).toBeGreaterThanOrEqual(MIN_NONTEXT_CONTRAST)
    expect(relLum(RENDER.plateRGB)).toBeCloseTo(RENDER.plateLum, 3)
  })

  it(`反证档内置：颜色退回 #C00000 只有 ${RENDER.contrastRed}:1 < ${MIN_NONTEXT_CONTRAST} ⇒ 这条阈值真的拦得住旧形状`, () => {
    const computed = contrast(RENDER.inkRed, RENDER.plateRGB)
    expect(computed).toBeCloseTo(RENDER.contrastRed, 1)
    expect(computed).toBeLessThan(MIN_NONTEXT_CONTRAST)
  })

  it('线宽守在 2~3 CSS px：太细手机上看不见，太粗就成了色块（实测 2.33，覆盖率 7.85%、抗锯齿圈只有 25 px）', () => {
    const sw = parseFloat(/stroke-width="([\d.]+)"/.exec(iconSrc)[1])
    const css = sw * (RENDER.boxCss / 24)
    expect(css, `算出来的线宽 ${css.toFixed(2)}px 不在 [2,3] 区间`).toBeGreaterThanOrEqual(2)
    expect(css).toBeLessThanOrEqual(3)
    expect(RENDER.boxCss).toBe(28)
    // 分母用**实测截图边长**（90×90），不再写死 92：老版那个 92 让这条一直绿着量错分母（见文件头 ⚠️）。
    expect(RENDER.pngDevice, '截图边长变了 ⇒ 覆盖率的分母要跟着换').toBe(90)
    expect(RENDER.inkPixels / (RENDER.pngDevice * RENDER.pngDevice)).toBeCloseTo(RENDER.coverage, 3)
    expect(RENDER.fringePixels, '抗锯齿那一圈比墨迹还多 = 线细到糊了').toBeLessThan(RENDER.inkPixels / 10)
  })
})

describe('渲染盒与"五套逐位一致"的实测留痕（抄录，标明量的地方）', () => {
  it(':size="28" 还在，且它是行内 style ⇒ 实测 28.00 CSS px（上一版位图走 SCSS 被 px→vw 放大成 29.11）', () => {
    const line = /<AppIcon[^>]*:size="(\d+)"[^>]*\/>/.exec(sfcSrc)
    expect(line, '邀请入口的 AppIcon 没写死 :size').toBeTruthy()
    expect(Number(line[1])).toBe(RENDER.boxCss)
    expect(iconSrc).toMatch(/width: size \+ 'px', height: size \+ 'px'/)
  })

  it('五套主题渲染出的那一格**逐位相同**：CDP 五张底板截图 md5 一致，主题确实切过（inventory 前缀五套不同）', () => {
    // 这条测试证不了像素（vitest 里没有浏览器），它钉的是**结构**：五处指同一个名字 + 那份定义只有一份。
    // 像素级一致是 `E:/tmp/v47/cdp47.js` 量的：plate-{classic,gem,ink,shanse,glass}.png 三张 md5 全等（21438e51…），
    // 而每套的 inventory 分别是 modern-/gem-/ink-/shanse-/lg- 前缀 ⇒ 不是"主题没切过去"造成的假一致。
    const names = THEME_IDS.map((id) => /icon:\s*'([^']+)'/.exec(/\binvite:\s*\{[^}]*\}/.exec(themeFeaturesBlock(id))[0])[1])
    expect(new Set(names).size).toBe(1)
    expect(iconSrc.split('\n').filter((l) => /^\s{2}invite:\s*\{/.test(l)).length).toBe(1)
  })

  it('旧口径那批的实测数留在测试里当"阈值不是反推"的证据（写实 webp 9,724 B / 4bit 357 档 / 均色 (136,93,47)）', () => {
    // §7.46：写实玉佩 webp 256×256 / 9,724 B / md5 bac1d9799df84de057ef3c8cc70278c5，
    //        4bit 357 档、top8 0.495、精确 24bit 11,038 种、top8 0.031、均色 (136,93,47)、相对亮度 0.1785。
    // §7.45：五张平面中式请柬 47~54 档 / top8 0.923~0.977 / 精确 564~679 种 / 0.659~0.852。
    // 这批之后图标不再有位图，所以这些数只留作"上一版的形状长什么样"的记录 + 反推证据，
    // 真正的判据换成了形状结构 + 对比度这两把能现场算的尺。
    const retired = [RETIRED_WEBP, ...RETIRED_PNG]
    for (const n of retired) {
      expect(existsSync(resolve(TAB_DIR, n)), `${n} 又回到仓库里了，但它没有任何引用`).toBe(false)
    }
    expect(storeCode.includes(RETIRED_WEBP)).toBe(false)
  })
})
