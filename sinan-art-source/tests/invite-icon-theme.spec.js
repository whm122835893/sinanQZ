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

describe('§7.44：classic 那张 modern-invite 换成平面图标，另外四套本轮没动', () => {
  const file = TAB('modern-invite.png')

  it('它是平面的：色阶层数少、纯色集中（立体磨砂那张实测 229 阶 / 0.8%，会在这里红）', () => {
    const p = flatProfile(file)
    expect(p.q4, `量化后还有 ${p.q4} 档色阶 = 图里在走渐变，不是平面`).toBeLessThanOrEqual(100)
    expect(p.top8Ratio, `最常见的 8 个颜色只盖了 ${(p.top8Ratio * 100).toFixed(1)}% 的墨迹`).toBeGreaterThanOrEqual(0.8)
  })

  it('墨迹体量没掉档：外接框长边 ≥ 同家族 calendar 墨迹长边的 85%（画布都是 256，光看文件尺寸看不出变小）', () => {
    const inv = inkBox(file)
    const cal = inkBox(TAB('modern-calendar.png'))
    expect(Math.max(inv.w, inv.h) / Math.max(cal.w, cal.h), `墨迹长边 ${Math.max(inv.w, inv.h)} vs calendar ${Math.max(cal.w, cal.h)}`).toBeGreaterThanOrEqual(0.85)
  })

  it('用的还是家族那套浅灰主体色：深色卡的玻璃 tile 上不能出现一块黑底', () => {
    const { w, h, px } = decodeRgba(file)
    let lit = 0, ink = 0
    for (let i = 0; i < w * h * 4; i += 4) {
      if (px[i + 3] <= 51) continue
      ink++
      if (px[i] > 180 && px[i + 1] > 180 && px[i + 2] > 180) lit++
    }
    expect(lit / ink, `只有 ${(lit / ink * 100).toFixed(0)}% 的墨迹是亮色，压在深色卡上会糊成一团`).toBeGreaterThan(0.5)
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
