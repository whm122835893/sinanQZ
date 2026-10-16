import { describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync, existsSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import crypto from 'node:crypto'

/**
 * 邀新卡图标：五套主题共用一张写实「白玉佩 + 红绳」圆角小卡（RUNBOOK §7.46，2026-10-16 第三次改口径）
 *
 * 用户口径：「图标的风格要和公告详情首图一样的风格」，随后定了三个具体档位 ——
 *   物件 = **玉佩 + 红绳**（寄售那张首图里的同一件古物），
 *   形式 = **圆角小卡**（自带暖金渐变底，等于把首图缩成 28px 的缩略图，不靠抠图），
 *   覆盖 = **只出一张，五套共用**（不再每套一张）。
 *
 * 这条口径推翻了 §7.45（五套各一张平面中式请柬）与 §7.44（只换 classic 那一张），
 * 也让 §7.43「图标改成和日历一样风格」那种**比 calendar** 的钉法作废 ——
 * 现在比的是**公告详情首图**。旧钉是翻方向留下的（平面 → 写实、五张互不相同 → 五套共用一张、
 * 比 calendar → 比首图），不是把测试删掉装作没发生过；被换掉的五张平面图的实测数都写在下面的注释里，
 * 用来证明这些阈值不是照着成品反推的。
 *
 * ## 这一节为什么有一半的数是抄进来的（自我限制，别读成"像素在 CI 里重量过"）
 * 成品是 **WebP**：同一张 256 方图存 PNG 要 89,980 B、存 WebP q90 只要 9,724 B，
 * 而它在 C 端只有 28px，走的是手机 4G。代价是 **vitest 这边没有 WebP 解码器**
 * （node 标准库解不开有损 webp；仓库里海报那张的 spec 也只会解析 webp 的宽高、不解析像素）。
 * 所以下面那些风格数（色数 / top8 / 均色）是**离线量出来抄进来的**（量的对象就是 md5 钉住的那份成品字节，
 * 口径逐条写在常量旁边；`qa/make_jade_invite_icon.py` 打印的是同一张图**压缩前**的一组，差 ~4%），
 * 测试真正能证的是 **"这些数属于当前这一份字节"**（靠 md5 + 字节数两条钉）：
 * 谁换了图，md5 立刻红，必须重跑出图脚本、重抄数。这是防漂移的机关，
 * 但它**不等于**"CI 量过像素"。要在 CI 里重量，得先请一个解码器进依赖 —— 这批没做。
 */

const ROOT = resolve(__dirname, '..')
const TAB_DIR = resolve(ROOT, 'public/images/tab')
const BACKEND_COVER_DIR = resolve(ROOT, '..', 'sinan-nft-backend', 'public/uploads/notice-default')

/** 五套主题 id（classic 用 modern- 前缀、glass 用 lg- 前缀是历史命名；共用一张之后只剩 id 有用） */
const THEME_IDS = ['classic', 'gem', 'ink', 'shanse', 'glass']
/** 被 §7.46 换掉的那五张平面请柬：现在应该零引用 + 文件也不在 */
const RETIRED = ['modern-invite.png', 'gem-invite.png', 'ink-invite.png', 'shanse-invite.png', 'lg-invite.png']

const SHARED = {
  url: '/images/tab/invite-jade.webp',
  name: 'invite-jade.webp',
  bytes: 9724,
  md5: 'bac1d9799df84de057ef3c8cc70278c5',
  w: 256,
  h: 256,
  // 下面三个数是对**这份已压缩的成品字节**量的 —— 也就是上面 `md5` 钉住的那一份，
  // 这样"数属于这些字节"这句话才真的成立。口径：全像素逐点、alpha>=8 才算墨迹。
  // 注意别和出图脚本 `qa/make_jade_invite_icon.py` 打印的那组混：它量的是**WebP 压缩前**那张内存图，
  // 得 344 / 0.497（有损压缩会让量化色阶多几档），抄错来源就是"数和字节不同源"。
  distinct4bit: 357,   // 每通道截到 4bit 之后的量化色阶层数（写实 = 几百档，平面 = 几十档）
  top8: 0.495,         // 同一把尺下，最常见的 8 个量化色占墨迹的比例
  mean: [136, 93, 47]  // 全像素均色，用来和四张首图比色温
}

/** 四张公告详情首图 = 「同风格」的标准答案。均色是 PIL 隔 2px 全图抽样量的，md5 用来防它们被换掉。 */
const COVERS = [
  { name: 'announcement-activity.webp', mean: [78, 35, 35], md5: 'c6a2626313ce12e1202baf138c5b32c3' },
  { name: 'announcement-compose.webp', mean: [137, 114, 85], md5: 'a914b63fc0e7d2e479cc02067e88fad4' },
  { name: 'announcement-consignment.webp', mean: [142, 92, 48], md5: 'b944b8dc593c9a48f50dde5770098cb0' },
  { name: 'announcement-operation.webp', mean: [55, 58, 76], md5: '4dd103114fca2caa1505d8856e91493a' }
]

/** 首图那一族是写实渲染：同一把尺（全像素、4bit 量化）下色数 243~414、top8 0.468~0.596。
 *  被换掉的那五张平面请柬在同一把尺下是 47~54 / 0.923~0.977。
 *  换成精确 24bit 那把尺更说明问题：首图 21,133~39,684 种、top8 只占 0.027~0.080，
 *  新图标 11,038 种、0.031 —— **落在首图那族之内**；旧平面那批只有 564~679 种、0.659~0.852。 */
const PHOTO_REAL = { minDistinct: 150, maxDistinct: 500, maxTop8: 0.65 }
/** 色温同档的线：到最近一张首图的均色欧氏距离。实测 6.2（对寄售那张），四张里最远 92.9。 */
const NEAR_COVER_DIST = 64

function md5File(file) {
  return crypto.createHash('md5').update(readFileSync(file)).digest('hex')
}

function webpSize(buf) {
  if (buf.subarray(0, 4).toString('latin1') !== 'RIFF' || buf.subarray(8, 12).toString('latin1') !== 'WEBP') {
    throw new Error('不是 webp：这张图换了格式，本节"webp 量不到像素"那套前提要重估')
  }
  const fourCC = buf.subarray(12, 16).toString('latin1')
  if (fourCC === 'VP8 ') {
    const i = buf.indexOf(Buffer.from([0x9d, 0x01, 0x2a]))
    return [buf.readUInt16LE(i + 3) & 0x3fff, buf.readUInt16LE(i + 5) & 0x3fff]
  }
  if (fourCC === 'VP8X') return [1 + buf.readUIntLE(24, 3), 1 + buf.readUIntLE(27, 3)]
  if (fourCC === 'VP8L') {
    const bits = buf.readUInt32LE(21)
    return [1 + (bits & 0x3fff), 1 + ((bits >> 14) & 0x3fff)]
  }
  throw new Error(`未识别的 webp chunk: ${fourCC}`)
}

function dist(a, b) {
  return Math.sqrt(a.reduce((s, v, i) => s + (v - b[i]) ** 2, 0))
}

const storeSrc = readFileSync(resolve(ROOT, 'src/stores/iconTheme.js'), 'utf8').replace(/\r\n/g, '\n')
const sfcSrc = readFileSync(resolve(ROOT, 'src/views/User.vue'), 'utf8').replace(/\r\n/g, '\n')

function themeFeaturesBlock(id) {
  const start = storeSrc.indexOf(`  ${id}: {`)
  if (start < 0) return ''
  const features = storeSrc.indexOf('features: {', start)
  const end = storeSrc.indexOf('\n    }', features)
  return storeSrc.slice(features, end)
}

describe('§7.46：五套主题的 invite 共用同一张写实圆角小卡', () => {
  for (const id of THEME_IDS) {
    it(`${id} 的 invite 指向共用那张 webp（不再是每套一张）`, () => {
      const block = themeFeaturesBlock(id)
      expect(block.length, `${id} 主题的 features 块没找到，主题表结构变了`).toBeGreaterThan(50)
      const line = /\binvite:\s*\{\s*type:\s*'image',\s*image:\s*'([^']+)'\s*\}/.exec(block)
      expect(line, `${id} 的 invite 不是位图条目`).not.toBeNull()
      expect(line[1]).toBe(SHARED.url)
    })
  }

  it('invite 与 calendar 仍走同一个渲染分支：type 都是 image', () => {
    for (const id of THEME_IDS) {
      const block = themeFeaturesBlock(id)
      const cal = /\bcalendar:\s*\{\s*type:\s*'(\w+)'/.exec(block)
      const inv = /\binvite:\s*\{\s*type:\s*'(\w+)'/.exec(block)
      expect(cal && inv, `${id} 少 calendar 或 invite`).toBeTruthy()
      expect(inv[1], `${id} 的 invite 分支和 calendar 不一样`).toBe(cal[1])
      expect(inv[1]).toBe('image')
    }
  })

  it('共用路径在主题表里正好出现 5 次：不多一处（第 6 个入口偷偷共用）也不少一处（某套漏改）', () => {
    const hits = storeSrc.split(SHARED.url).length - 1
    expect(hits, `源码里 ${SHARED.url} 出现 ${hits} 次`).toBe(5)
  })

  it('旧口径不许复活：主题表里再没有任何 <前缀>-invite.png 字符串', () => {
    const leftovers = RETIRED.filter((n) => storeSrc.includes(n))
    expect(leftovers, `主题表还指着旧平面图：${leftovers.join(' / ')}`).toEqual([])
  })

  it('五张旧平面图确实从盘上撤了（引用清了、文件也清了，不留零引用孤儿）', () => {
    for (const n of RETIRED) {
      expect(existsSync(resolve(TAB_DIR, n)), `public/images/tab/${n} 还在，但它已经没人引用了`).toBe(false)
    }
  })
})

describe('共用图标本体：格式、尺寸、体积，以及把风格数钉住的那条指纹', () => {
  const file = resolve(TAB_DIR, SHARED.name)

  it('文件在、是 webp、256×256 方画布', () => {
    expect(existsSync(file), `缺文件 ${SHARED.name}`).toBe(true)
    expect(webpSize(readFileSync(file)), '画布不是 256×256（圆角小卡按方画布设计）').toEqual([SHARED.w, SHARED.h])
  })

  it(`md5 钉在 ${SHARED.md5.slice(0, 8)}…：换一个字节都要红，逼着重跑出图脚本、重抄风格数`, () => {
    expect(md5File(file), '成品字节变了 ⇒ 下面那些"写实 / 色温"的数不再属于这张图').toBe(SHARED.md5)
  })

  it(`体积守在 20KB 以内（写实图必须走 webp；同一张存 PNG 约 ${(SHARED.bytes * 9.25 / 1024).toFixed(0)}KB）`, () => {
    const size = statSync(file).size
    expect(size).toBe(SHARED.bytes)
    expect(size).toBeLessThanOrEqual(20 * 1024)
  })
})

describe('风格对齐公告详情首图：写实连续色阶 + 同档色温（数是对成品字节离线量的，靠上面那条 md5 绑定）', () => {
  it(`色阶层数够多 = 写实：4bit 量化后 ${SHARED.distinct4bit} 档，落在首图那一族（243~414）之内`, () => {
    expect(SHARED.distinct4bit, '色数太少 = 又画回平面色块了').toBeGreaterThanOrEqual(PHOTO_REAL.minDistinct)
    expect(SHARED.distinct4bit, '色数多到离谱 = 噪点或压缩糊了').toBeLessThanOrEqual(PHOTO_REAL.maxDistinct)
    // 被换掉的五张平面请柬同一把尺实测 47~54 档 ⇒ 下限 150 与旧形状之间还隔着近 3 倍，不会误伤下一版写实图
    expect(PHOTO_REAL.minDistinct).toBeGreaterThan(54)
  })

  it(`主色集中度够低 = 不是平面：最常见的 8 色只盖 ${(SHARED.top8 * 100).toFixed(0)}% 的墨迹`, () => {
    expect(SHARED.top8, 'top8 太高 = 少数几个纯色块 = 平面画法').toBeLessThanOrEqual(PHOTO_REAL.maxTop8)
    // 平面那批同一把尺实测 0.923~0.977；线画在 0.65，两边都还留着余量
    expect(PHOTO_REAL.maxTop8).toBeLessThan(0.92)
  })

  it('首图那四张的标准答案没漂：md5 逐个对上，否则下面的色温比较是拿旧均值比新图', () => {
    for (const c of COVERS) {
      const f = resolve(BACKEND_COVER_DIR, c.name)
      expect(existsSync(f), `首图 ${c.name} 不在了（后台换过出厂图？那本节均值要重量）`).toBe(true)
      expect(md5File(f), `${c.name} 字节变了，测试里抄的均色已过期`).toBe(c.md5)
    }
  })

  it(`图标与至少一张首图同色温（欧氏距离 ≤ ${NEAR_COVER_DIST}）：实测对寄售那张只差 6.2`, () => {
    const ds = COVERS.map((c) => ({ name: c.name, d: dist(SHARED.mean, c.mean) }))
    const nearest = ds.slice().sort((a, b) => a.d - b.d)[0]
    expect(nearest.d, `到四张首图的均色距离 ${ds.map((x) => `${x.name}:${x.d.toFixed(1)}`).join(' / ')}`).toBeLessThanOrEqual(NEAR_COVER_DIST)
    expect(nearest.name).toContain('consignment')
  })

  it('但对齐的是"一族风格"、不是照抄某一张：与最远那张仍差 >60', () => {
    expect(Math.max(...COVERS.map((c) => dist(SHARED.mean, c.mean)))).toBeGreaterThan(60)
  })
})

describe('User.vue 的接线与渲染盒：圆角小卡的前提是那个 28px 方盒', () => {
  it('卡片读的是 getFeatureIcon("invite")，image / AppIcon 两条分支都在', () => {
    expect(sfcSrc).toMatch(/getFeatureIcon\(\s*'invite'\s*\)/)
    expect(sfcSrc).toMatch(/inviteIcon\?\.type === 'image'/)
    expect(sfcSrc).toMatch(/:src="inviteIcon\.image"/)
    expect(sfcSrc).toMatch(/<AppIcon[^>]*:name="inviteIcon\?\.icon \|\| 'invite'"/)
  })

  it('图仍是 28px 见方 + object-fit: contain（换非方画布或改盒尺寸，圆角小卡就会变形）', () => {
    const img = /\.invite-banner[\s\S]*?&__img\s*\{([^}]*)\}/.exec(sfcSrc)
    expect(img, '找不到 .invite-banner__img 那条样式').toBeTruthy()
    const box = /width:\s*(\d+)px;\s*height:\s*(\d+)px/.exec(img[1])
    expect(box, `__img 的宽高不再是两个整数 px：${img[1].trim()}`).toBeTruthy()
    expect(+box[1]).toBe(+box[2])
    expect(+box[1]).toBeLessThanOrEqual(32)
    expect(img[1]).toMatch(/object-fit:\s*contain/)
  })

  it('外面那块 44px 磨砂圆角板还在（图自带圆角，底板给的是"浮在海报上"那一层）', () => {
    const plate = /\.invite-banner[\s\S]*?&__icon\s*\{([^}]*)\}/.exec(sfcSrc)
    expect(plate, '找不到 .invite-banner__icon 那条样式').toBeTruthy()
    expect(plate[1]).toMatch(/width:\s*44px;\s*height:\s*44px/)
    expect(plate[1]).toMatch(/border-radius:\s*12px/)
    expect(plate[1]).toMatch(/background:\s*rgba\(255,\s*255,\s*255,\s*0\.16\)/)
  })

  it('五套主题运行时取到的都是共用那张，且文件真在盘上', async () => {
    setActivePinia(createPinia())
    const { useIconThemeStore } = await import('@/stores/iconTheme')
    const store = useIconThemeStore()
    for (const id of THEME_IDS) {
      expect(store.setFeatureTheme(id), `主题 ${id} 切不过去`).toBe(true)
      const icon = store.getFeatureIcon('invite')
      expect(icon.type).toBe('image')
      expect(icon.image).toBe(SHARED.url)
      expect(existsSync(resolve(ROOT, 'public' + icon.image)), `${id} 指向的 ${icon.image} 不在`).toBe(true)
    }
  })

  it('后台上传的自定义 invite 仍优先于主题表；清空后回落到共用那张（不是旧的平面图）', async () => {
    setActivePinia(createPinia())
    const { useIconThemeStore } = await import('@/stores/iconTheme')
    const store = useIconThemeStore()
    store.setCustomIcons({ invite: '/uploads/custom-invite.png' })
    store.setFeatureTheme('custom')
    expect(store.getFeatureIcon('invite')).toEqual({ type: 'image', image: '/uploads/custom-invite.png' })
    store.setCustomIcons({ invite: '' })
    expect(store.getFeatureIcon('invite').image).toBe(SHARED.url)
  })
})
