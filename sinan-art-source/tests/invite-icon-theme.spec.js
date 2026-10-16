import { describe, expect, it, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import zlib from 'node:zlib'

/**
 * 邀新卡图标：五套功能图标主题各出一张位图（2026-10-16，RUNBOOK §7.43）
 *
 * 用户口径是「图标也改成和日历一样风格的图标」，所以这里的钉法全部对着**同主题的 calendar** 比，
 * 而不是对着一个我脑子里的标准答案比：invite 与 calendar 必须走同一个渲染分支、同一套画布约定。
 *
 * 关键前提（踩过一次，写在 RUNBOOK §7.43）：classic 主题的 features 早就是位图了，
 * 主题 type 的 'svg' 只是标签。邀新卡原来是五套主题里唯一一个还在吃 AppIcon 矢量图的功能入口，
 * 所以「换成位图」这件事 = 给五个主题的 features.invite 各补一条 { type:'image' }。
 *
 * 2026-10-16 §7.44 又改一次：用户要「图标再换一个，平面的」，并明确只换**现在看到的这一张**
 * （= classic 家族的 modern-invite.png），另四套仍留立体/磨砂那张。所以这一节新增的三条钉
 * 只对着 modern-invite.png 生效：平面度（色阶层数 + 纯色集中度）、墨迹体量不掉档、主体仍是家族浅灰。
 * 「五套都要位图」那条口径没变，五张互不相同也没变。
 *
 * 2026-10-16 §7.45 再改一次：用户口径「图标再换，换成符合功能的图标」。信封表达的是「寄信」，
 * 而这张卡的动作是「下请柬邀好友」，所以五套主题**全部**换成同一套中式请柬轮廓（半圆拱顶 + 竖长帖身 +
 * 一圈深色描边 + 拱心一颗印泥红 + 三行字），换主题只换色槽。三条口径上的连锁：
 *   · §7.44 那句「只换现在看到的这一张、另四套留立体那张」的**范围**口径已被推翻（用户随后选了「五套主题各一格」），
 *     所以本节末尾从「只管 classic 三条」变成「五套各五条 + 三条跨套一致性」。
 *   · §7.44 那条「三个通道都 >180 的墨迹占比 >50%」的浅灰代理指标**换成墨迹平均相对亮度**：
 *     金色纸面 (242,226,178) 的蓝通道只有 178，拿旧判据会把 shanse 判成假红（旧口径实测 3.4%、新口径 0.606）。
 *   · top8 集中度从 classic 专用的 ≥0.8 改成五套共用的 ≥0.55：紧裁家族要从 256 再缩到 132，抗锯齿边像素占比涨了，
 *     实测五张 0.662~0.856。线先画在 0.6 时被反证 C 档（整张缩到 80%）以 0.599 顺带绊倒 —— 那个位置离成品只有 0.06，
 *     留给「重新出一版同风格图」的余量太小；而被换掉的立体四张最高才 0.170，降到 0.55 之后中间仍隔着 3 倍。
 *
 * 位图带不带透明通道、有没有把绿幕一起抠进来，jsdom 量不到，这里直接解 PNG 头 + 解 IDAT 自己数，
 * 这样「图标糊成一坨不透明方块」「边上镶一圈荧光绿」这类缺陷能在 CI 里红，而不是等人眼看。
 */

const ROOT = resolve(__dirname, '..')
const TAB = (name) => resolve(ROOT, 'public/images/tab', name)
// 主题 id 与文件名前缀不是一回事：classic 用 modern- 前缀（历史命名），glass(液态玻璃) 用 lg- 前缀
const THEMES = [
  { id: 'classic', prefix: 'modern' },
  { id: 'gem', prefix: 'gem' },
  { id: 'ink', prefix: 'ink' },
  { id: 'shanse', prefix: 'shanse' },
  { id: 'glass', prefix: 'lg' }
]

// ---------------- PNG 解析：IHDR + IDAT（含反滤波），只用到数 alpha/绿幕的程度 ----------------
function readPng(file) {
  const buf = readFileSync(file)
  if (buf.readUInt32BE(0) !== 0x89504e47) throw new Error(`${file} 不是 PNG`)
  let p = 8
  let w = 0; let h = 0; let depth = 0; let color = 0
  const idat = []
  while (p < buf.length) {
    const len = buf.readUInt32BE(p)
    const type = buf.subarray(p + 4, p + 8).toString('latin1')
    const data = buf.subarray(p + 8, p + 8 + len)
    if (type === 'IHDR') {
      w = data.readUInt32BE(0); h = data.readUInt32BE(4)
      depth = data[8]; color = data[9]
    } else if (type === 'IDAT') idat.push(Buffer.from(data))
    else if (type === 'IEND') break
    p += 12 + len
  }
  return { w, h, depth, color, idat: Buffer.concat(idat) }
}

/** 解出 RGBA 像素（只支持 8bit 真彩带 alpha，正好是本批抠图位图的格式） */
function decodeRgba(file) {
  const { w, h, depth, color, idat } = readPng(file)
  expect(color, `${file} 不是 RGBA PNG（colorType=${color}），深色卡底上会露出一块底色`).toBe(6)
  expect(depth, `${file} 位深不是 8`).toBe(8)
  const channels = 4
  const raw = zlib.inflateSync(idat)
  const stride = w * channels
  const out = Buffer.alloc(h * stride)
  const at = (row, col) => (row >= 0 && col >= 0 && col < stride ? out[row * stride + col] : 0)
  let q = 0
  for (let y = 0; y < h; y++) {
    const filter = raw[q++]
    for (let x = 0; x < stride; x++) {
      const A = x >= channels ? out[y * stride + x - channels] : 0
      const B = at(y - 1, x)
      const C = x >= channels ? at(y - 1, x - channels) : 0
      const v = raw[q + x]
      let rec
      if (filter === 0) rec = v
      else if (filter === 1) rec = v + A
      else if (filter === 2) rec = v + B
      else if (filter === 3) rec = v + ((A + B) >> 1)
      else {
        const pp = A + B - C
        const pa = Math.abs(pp - A); const pb = Math.abs(pp - B); const pc = Math.abs(pp - C)
        rec = v + (pa <= pb && pa <= pc ? A : pb <= pc ? B : C)
      }
      out[y * stride + x] = rec & 0xff
    }
    q += stride
  }
  return { w, h, px: out }
}

/** 数 alpha 与绿幕：透明通道有没有在用、抠图有没有把荧光绿镶在边上 */
function alphaProfile(file) {
  const { w, h, px } = decodeRgba(file)
  let solid = 0; let translucent = 0; let green = 0
  for (let i = 0; i < w * h * 4; i += 4) {
    const a = px[i + 3]
    if (a > 51) solid++
    if (a < 250) translucent++
    const r = px[i]; const g = px[i + 1]; const b = px[i + 2]
    // 绿幕判定与出图脚本同一口径：实心 + 绿得离谱 + 不太暗（暗部是阴影，不算残留）
    if (a > 230 && g - Math.max(r, b) > 60 && g > 90) green++
  }
  const n = w * h
  return { w, h, solidRatio: solid / n, softRatio: translucent / n, greenRatio: green / n }
}

// ---------------- 平面度与墨迹体量（§7.44 起 classic 那张换成平面图标） ----------------
/**
 * 「平面的」不能靠人眼验收，这里翻译成两条能数的口径：
 *   · 色阶层数：把墨迹按每通道 4bit 量化后有几种 —— 平面 = 少数几个纯色块，立体 = 连续渐变
 *   · 纯色集中度：最常见的 8 个精确颜色盖了多少比例的墨迹 —— 平面高、立体低
 * 两条阈值都是当场量出来对照后取的中段（见 RUNBOOK §7.44）：
 *   新平面图 modern-invite = 54 阶 / 94.9%，被它替换掉的立体图 = 229 阶 / 0.8% ⇒ 中间隔着 4 倍，不会误伤
 */
function flatProfile (file) {
  const { w, h, px } = decodeRgba(file)
  const q = new Map(); const exact = new Map()
  let ink = 0
  for (let i = 0; i < w * h * 4; i += 4) {
    if (px[i + 3] <= 51) continue
    ink++
    const qk = (px[i] >> 4 << 8) | (px[i + 1] >> 4 << 4) | (px[i + 2] >> 4)
    q.set(qk, (q.get(qk) || 0) + 1)
    const ek = (px[i] << 16) | (px[i + 1] << 8) | px[i + 2]
    exact.set(ek, (exact.get(ek) || 0) + 1)
  }
  const top8 = [...exact.values()].sort((a, b) => b - a).slice(0, 8).reduce((s, v) => s + v, 0)
  return { ink, q4: q.size, top8Ratio: top8 / ink }
}

/** 墨迹外接框：画布同是 256，但墨迹只占一半的图标在 28px 里会明显比兄弟小一圈 */
function inkBox (file) {
  const { w, h, px } = decodeRgba(file)
  let minx = w, miny = h, maxx = -1, maxy = -1
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      if (px[(y * w + x) * 4 + 3] > 51) {
        if (x < minx) minx = x; if (x > maxx) maxx = x
        if (y < miny) miny = y; if (y > maxy) maxy = y
      }
    }
  }
  return { w: maxx - minx + 1, h: maxy - miny + 1 }
}

// ---------------- 请柬轮廓与四个色槽（§7.45 起五套主题共用同一套轮廓） ----------------
/**
 * sRGB 相对亮度（与 WCAG 同一套线性化和权重）。
 * 判断「压在深色玻璃 tile 上糊不糊」要用它，不能用 §7.44 那句「三个通道都 >180」：
 * 金色纸面 (242,226,178) 蓝通道只有 178，旧判据会把 shanse 判成假红。
 */
function relLum (px, i) {
  const ch = (c) => { const v = c / 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4 }
  return 0.2126 * ch(px[i]) + 0.7152 * ch(px[i + 1]) + 0.0722 * ch(px[i + 2])
}

/**
 * 出图脚本 qa/gen_flat_invite_icon.py 里那四个色槽，抄一份到测试里当标准答案。
 * 下面有一条钉专门比对这两处 —— 色槽钉钉的是像素，脚本改了测试没改，四条钉就成了空气。
 */
const INVITE_PALETTE = {
  'modern-invite.png': { rim: [208, 210, 222], body: [240, 240, 243], line: [176, 180, 196], seal: [201, 39, 52] },
  'gem-invite.png': { rim: [186, 196, 208], body: [232, 238, 244], line: [150, 162, 178], seal: [176, 42, 58] },
  'ink-invite.png': { rim: [212, 204, 188], body: [246, 243, 236], line: [128, 124, 116], seal: [178, 44, 46] },
  'shanse-invite.png': { rim: [206, 164, 92], body: [242, 226, 178], line: [150, 112, 58], seal: [188, 50, 54] },
  'lg-invite.png': { rim: [196, 62, 74], body: [244, 230, 232], line: [198, 140, 148], seal: [192, 0, 0] }
}

/**
 * 墨迹平均相对亮度 + 四个色槽各占多少墨迹。
 * 容差是当场量的：色块之间最近的一对差 32（classic 的描边 208 vs 字 176），28 不会串色；
 * 印泥红与任何中性色三通道差都在 100 以上，45 宽裕。
 */
function inkProfile (file, pal) {
  const { w, h, px } = decodeRgba(file)
  const near = (i, c, tol) => Math.abs(px[i] - c[0]) <= tol && Math.abs(px[i + 1] - c[1]) <= tol && Math.abs(px[i + 2] - c[2]) <= tol
  let ink = 0; let lum = 0; let rim = 0; let body = 0; let line = 0; let seal = 0
  for (let i = 0; i < w * h * 4; i += 4) {
    if (px[i + 3] <= 51) continue
    ink++; lum += relLum(px, i)
    if (near(i, pal.seal, 45)) seal++
    else if (near(i, pal.line, 28)) line++
    else if (near(i, pal.rim, 28)) rim++
    else if (near(i, pal.body, 28)) body++
  }
  return { meanLum: lum / ink, rim: rim / ink, body: body / ink, line: line / ink, seal: seal / ink }
}

/**
 * 拱顶判据：从墨迹顶部往下 5% 高那一行的宽度 / 最宽那一行的宽度。
 * 信封和方卡是 1:1（顶边就是最宽处），请柬上半是个半圆 ⇒ 实测五张都在 0.47~0.50。
 * 为什么取 5% 而不是第一行：抗锯齿让第一行只剩一两个像素，那个比值是噪声，不是形状。
 */
function archRatio (file) {
  const { w, h, px } = decodeRgba(file)
  const rows = []
  for (let y = 0; y < h; y++) {
    let n = 0
    for (let x = 0; x < w; x++) if (px[(y * w + x) * 4 + 3] > 51) n++
    if (n) rows.push(n)
  }
  return rows[Math.round(0.05 * (rows.length - 1))] / Math.max(...rows)
}

// ---------------- 主题表本身 ----------------
const storeSrc = readFileSync(resolve(ROOT, 'src/stores/iconTheme.js'), 'utf8').replace(/\r\n/g, '\n')
const sfcSrc = readFileSync(resolve(ROOT, 'src/views/User.vue'), 'utf8').replace(/\r\n/g, '\n')

function themeFeaturesBlock(id) {
  const start = storeSrc.indexOf(`  ${id}: {`)
  if (start < 0) return ''
  const features = storeSrc.indexOf('features: {', start)
  const end = storeSrc.indexOf('\n    }', features)
  return storeSrc.slice(features, end)
}

describe('iconTheme · 五套主题的 invite 都是位图，且和 calendar 同一分支', () => {
  it('每条主题都写了 features.invite，指向 /images/tab/<前缀>-invite.png', () => {
    for (const { id, prefix } of THEMES) {
      const block = themeFeaturesBlock(id)
      expect(block.length, `${id} 主题的 features 块没找到，主题表结构变了`).toBeGreaterThan(50)
      const line = /\binvite:\s*\{\s*type:\s*'image',\s*image:\s*'([^']+)'\s*\}/.exec(block)
      expect(line, `${id} 主题的 invite 不是位图条目（§7.43 口径：五套都要位图）`).not.toBeNull()
      expect(line[1]).toBe(`/images/tab/${prefix}-invite.png`)
    }
  })

  it('invite 与 calendar 走同一个渲染分支：type 相同、都是 image（"和日历一样风格"的机器口径）', () => {
    for (const { id } of THEMES) {
      const block = themeFeaturesBlock(id)
      const cal = /\bcalendar:\s*\{\s*type:\s*'(\w+)'/.exec(block)
      const inv = /\binvite:\s*\{\s*type:\s*'(\w+)'/.exec(block)
      expect(cal && inv, `${id} 少 calendar 或 invite`).toBeTruthy()
      expect(inv[1], `${id} 的 invite 分支和 calendar 不一样`).toBe(cal[1])
      expect(inv[1]).toBe('image')
    }
  })

  it('五张 invite 互不相同：不会出现复制粘贴漏改，五套主题长得一个样', () => {
    const seen = new Map()
    for (const { id, prefix } of THEMES) {
      const buf = readFileSync(TAB(`${prefix}-invite.png`))
      const sum = `${buf.length}:${buf.subarray(0, 33).toString('hex')}`
      expect(seen.has(sum), `${id} 的 invite 和 ${seen.get(sum)} 撞了`).toBe(false)
      seen.set(sum, id)
    }
    expect(new Set(THEMES.map((t) => t.prefix)).size).toBe(5)
  })
})

describe('invite 位图本体：透明、没镶绿幕、够清晰、别拖慢「我的」页', () => {
  for (const { id, prefix } of THEMES) {
    const name = `${prefix}-invite.png`
    const file = TAB(name)

    it(`${name} 是带 alpha 的 RGBA，且透明通道真的在用`, () => {
      expect(existsSync(file), `缺文件 ${name}`).toBe(true)
      const { w, h } = readPng(file)
      const p = alphaProfile(file)
      expect(p.solidRatio, `${name} 几乎没有实心像素`).toBeGreaterThan(0.05)
      expect(p.softRatio, `${name} 整块都不透明 = 抠图没做，会在深色 tile 上露出一个方块`).toBeGreaterThan(0.005)
      expect([w, h].every((n) => n >= 56), `${name} 撑不起 28px 图标的 2 倍图`).toBe(true)
      expect(w).toBeLessThanOrEqual(256)
      expect(h).toBeLessThanOrEqual(256)
    })

    it(`${name} 没有绿幕残留（抠图边缘镶荧光绿是这批图最容易犯的病）`, () => {
      const p = alphaProfile(file)
      expect(p.greenRatio, `${name} 有 ${(p.greenRatio * 100).toFixed(2)}% 的像素是荧光绿`).toBeLessThan(0.005)
    })

    it(`${name} 的长边与同主题 calendar 同一档（画布约定没被破坏）`, () => {
      const { w, h } = readPng(file)
      const cal = readPng(TAB(`${prefix}-calendar.png`))
      const long = Math.max(w, h)
      const calLong = Math.max(cal.w, cal.h)
      // classic 家族是 256 方画布，其余四套是长边 132 的紧裁；差 15% 以内算同档
      expect(long / calLong, `${name} 长边 ${long} 与 calendar ${calLong} 不同档`).toBeGreaterThan(0.85)
      expect(long / calLong).toBeLessThan(1.15)
    })

    it(`${name} 体积守在 80KB 以内：一张功能入口图标不该比整页还重`, () => {
      expect(statSync(file).size).toBeLessThanOrEqual(80 * 1024)
    })
  }
})

describe('§7.45：五套主题都换成平面中式请柬，同一套轮廓只换四个色槽', () => {
  for (const { id, prefix } of THEMES) {
    const name = `${prefix}-invite.png`
    const file = TAB(name)
    const pal = INVITE_PALETTE[name]

    it(`${name} 是平面的：色阶层数少、纯色集中（换掉的立体那张实测 0.7%~17%，会在这里红）`, () => {
      const p = flatProfile(file)
      expect(p.q4, `量化后还有 ${p.q4} 档色阶 = 图里在走渐变，不是平面`).toBeLessThanOrEqual(100)
      expect(p.top8Ratio, `最常见的 8 个颜色只盖了 ${(p.top8Ratio * 100).toFixed(1)}% 的墨迹`).toBeGreaterThanOrEqual(0.55)
    })

    it(`${name} 是请柬轮廓不是信封：顶部往下 5% 那一行只有最宽处的一半不到（矩形或信封会接近 1:1）`, () => {
      const r = archRatio(file)
      expect(r, `5% 高处宽 / 最宽行 = ${r.toFixed(3)}，看不出半圆拱顶`).toBeLessThanOrEqual(0.6)
      expect(r, `5% 高处宽 / 最宽行 = ${r.toFixed(3)}，拱顶塌了或细得不像一张帖子`).toBeGreaterThanOrEqual(0.35)
    })

    it(`${name} 四个色槽各就各位：纸面过半、描边一圈、印泥红一小颗（红印是「请柬」的语义锚点）`, () => {
      const p = inkProfile(file, pal)
      expect(p.body, `纸面只占 ${(p.body * 100).toFixed(1)}% 的墨迹，主体色不对`).toBeGreaterThanOrEqual(0.5)
      expect(p.rim, `描边占 ${(p.rim * 100).toFixed(1)}%，一圈描边没了或糊成一块`).toBeGreaterThanOrEqual(0.1)
      expect(p.rim).toBeLessThanOrEqual(0.35)
      expect(p.seal, `印泥红占 ${(p.seal * 100).toFixed(1)}%，红印要么丢了、要么盖过纸面`).toBeGreaterThanOrEqual(0.02)
      expect(p.seal).toBeLessThanOrEqual(0.08)
    })

    it(`${name} 压在深色玻璃 tile 上不会糊：墨迹平均相对亮度 ≥ 0.5（换掉的立体那张实测 0.228~0.435）`, () => {
      const p = inkProfile(file, pal)
      expect(p.meanLum, `墨迹平均相对亮度只有 ${p.meanLum.toFixed(3)}`).toBeGreaterThanOrEqual(0.5)
    })

    it(`${name} 的墨迹把画布撑满：墨迹长边 / 画布长边 ≥ 0.85（紧裁家族的约定是"画布就是墨迹"，留一圈白就会在 28px 里比兄弟小一圈）`, () => {
      const inv = inkBox(file)
      const { w, h } = readPng(file)
      const ratio = Math.max(inv.w, inv.h) / Math.max(w, h)
      expect(ratio, `${id} 的墨迹长边 ${Math.max(inv.w, inv.h)} 只占画布 ${Math.max(w, h)} 的 ${(ratio * 100).toFixed(0)}%`).toBeGreaterThanOrEqual(0.85)
    })
  }

  // classic 家族多比一层：它是 256 方画布家族，画布撑满不等于跟兄弟一样大，得直接量 calendar 的墨迹。
  // 另外四套比不了 —— 它们的 calendar 是**索引色 PNG（colorType 3）**，decodeRgba 只解 8bit 真彩 RGBA，
  // 硬套会在断言里红成"calendar 不是 RGBA"。这不是测试写错，是那四张出厂图本来就不是同一类编码。
  it(`modern-invite 的墨迹体量与 modern-calendar 同档：长边 ≥ 85%（classic 是方画布家族，只能这么比）`, () => {
    const inv = inkBox(TAB('modern-invite.png'))
    const cal = inkBox(TAB('modern-calendar.png'))
    expect(Math.max(inv.w, inv.h) / Math.max(cal.w, cal.h),
      `classic 的墨迹长边 ${Math.max(inv.w, inv.h)} vs calendar ${Math.max(cal.w, cal.h)}`).toBeGreaterThanOrEqual(0.85)
  })

  it('五张共用同一套轮廓：拱顶比例互差不超过 0.06（换主题只许换色，不许顺手换形）', () => {
    const r = THEMES.map(({ prefix }) => archRatio(TAB(`${prefix}-invite.png`)))
    const spread = Math.max(...r) - Math.min(...r)
    expect(spread, `五张拱顶比例 ${r.map((v) => v.toFixed(3)).join(' / ')}，跨度 ${spread.toFixed(3)}`).toBeLessThanOrEqual(0.06)
  })

  it('测试里那份色槽表 = 出图脚本里那份（两边必须一起改，否则上面四条钉的是空气）', () => {
    const gen = readFileSync(resolve(ROOT, '..', 'qa', 'gen_flat_invite_icon.py'), 'utf8')
    expect(gen.length, '出图脚本没读到，这条钉等于没钉').toBeGreaterThan(500)
    for (const [name, pal] of Object.entries(INVITE_PALETTE)) {
      const prefix = name.replace('-invite.png', '')
      const entry = new RegExp(`'${prefix}',\\s*\\d+,\\s*'(?:canvas|tight)',\\s*\\{([\\s\\S]*?)\\}\\)`).exec(gen)
      expect(entry, `出图脚本 THEMES 里找不到 ${prefix} 那一条（改了脚本的名字/结构，这条要一起改）`).not.toBeNull()
      for (const slot of ['rim', 'body', 'line', 'seal']) {
        const m = new RegExp(`'${slot}':\\s*\\((\\d+),\\s*(\\d+),\\s*(\\d+)\\)`).exec(entry[1])
        expect(m, `脚本里 ${prefix} 缺 '${slot}' 色槽`).not.toBeNull()
        expect([+m[1], +m[2], +m[3]],
          `${prefix} 的 ${slot}：脚本写 [${m[1]},${m[2]},${m[3]}]、测试写 [${pal[slot]}]`).toEqual(pal[slot])
      }
    }
  })
})
describe('User.vue 的接线：拿到 image 分支就渲染 <img>，拿不到才退回 AppIcon', () => {
  it('卡片读的是 getFeatureIcon("invite")，并且两条分支都在', () => {
    expect(sfcSrc).toMatch(/getFeatureIcon\(\s*'invite'\s*\)/)
    expect(sfcSrc).toMatch(/inviteIcon\?\.type === 'image'/)
    expect(sfcSrc).toMatch(/:src="inviteIcon\.image"/)
    expect(sfcSrc).toMatch(/<AppIcon[^>]*:name="inviteIcon\?\.icon \|\| 'invite'"/)
  })

  it('运行时取到的图标地址，和主题表里写的那张是同一个文件（防止改了表忘了落图）', async () => {
    setActivePinia(createPinia())
    const { useIconThemeStore } = await import('@/stores/iconTheme')
    const store = useIconThemeStore()
    for (const { id, prefix } of THEMES) {
      expect(store.setFeatureTheme(id), `主题 ${id} 切不过去`).toBe(true)
      const icon = store.getFeatureIcon('invite')
      expect(icon.type).toBe('image')
      expect(icon.image).toBe(`/images/tab/${prefix}-invite.png`)
      expect(existsSync(resolve(ROOT, 'public' + icon.image)), `${id} 指向的 ${icon.image} 不在`).toBe(true)
    }
  })

  it('后台上传的自定义 invite 仍然优先于主题表（换图不能把运营口子挡掉）', async () => {
    setActivePinia(createPinia())
    const { useIconThemeStore } = await import('@/stores/iconTheme')
    const store = useIconThemeStore()
    store.setCustomIcons({ invite: '/uploads/custom-invite.png' })
    store.setFeatureTheme('custom')
    expect(store.getFeatureIcon('invite')).toEqual({ type: 'image', image: '/uploads/custom-invite.png' })
    store.setCustomIcons({ invite: '' })
    expect(store.getFeatureIcon('invite').image).toBe('/images/tab/modern-invite.png')
  })
})
