import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync, existsSync, statSync } from 'node:fs'
import { join, resolve, relative, sep } from 'node:path'

/**
 * 静态图死重闸口（2026-10-20，RUNBOOK §7.57）
 *
 * 大白话：`public/images/` 这个目录是「整包搬进 dist」的，搬之前没人问一句「这页真的会去要它吗」。
 * 所以一张图一旦放进去，除非有人专门来清，它会跟着每一次发版永远搬下去。§7.55~§7.56 清了空状态那三张，
 * 这一轮把剩下的量完，并且**装一道闸**：以后再往这个目录里扔一张没人引用的图，测试当场红。
 *
 * 闸口用的是「目录枚举 + 源码字符串扫描」两把尺一起量，不是硬编码三个文件名：
 *   - 枚举给出「磁盘上现在有哪些」；
 *   - 扫 `src/` 给出「代码里写到了哪些 `/images/...`」；
 *   - 两者之差必须逐条有理由（理由就写在下面 ALLOW 里，每条带自己的证据出处）。
 * 反证跑过两档：把已删的图放回 public 要红；往目录里塞一张名单外的假孤儿也要红（用例名记在 §7.57）。
 *
 * 为什么 ALLOW 里那些「代码已经不引用」的图这轮不删：
 *   - `collections/cover-1/2/3.jpg`：本地种子 `database/dev-only/seed-dev.sql` 与 `qa/e2e_*.php` 造数写的封面路径，
 *     生产库没引用、**本地沙箱在引用**，删了本地端到端的详情页就是破图；
 *   - `invite-banner-bg.jpg`、`exhibits/exhibit-header-ancient.jpg`：**线上那一版 CSS 现在还在请求它们**
 *     （现网 `assets/index-*.css` 实测命中）。它们等的是「第 9 + 第 10 那一批」上线，不是等一次单独的清理；
 *     上线之后把这两条从 ALLOW 划掉、补进 GONE。
 *   - `invite-banner-bg-v6.webp`：用户口径「保持未跟踪」= git 里没有它的副本，磁盘只有这一份 ⇒ 只许留不许删。
 */

const PUB = resolve(process.cwd(), 'public/images')
const DIST = resolve(process.cwd(), 'dist/images')
const SRC = resolve(process.cwd(), 'src')

function listUnder(base) {
  const out = []
  if (!existsSync(base)) return out
  const walk = (dir) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const p = join(dir, entry.name)
      if (entry.isDirectory()) walk(p)
      else out.push(relative(base, p).split(sep).join('/'))
    }
  }
  walk(base)
  return out.sort()
}

function listSource(dir) {
  const out = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name)
    if (entry.isDirectory()) out.push(...listSource(p))
    else if (/\.(vue|js|ts|scss|css)$/.test(entry.name)) out.push(p)
  }
  return out
}

/** src 里写过的 /images/... 串（保留前导斜杠，好和 URL 对齐） */
const REFERENCED = new Set()
for (const f of listSource(SRC)) {
  const text = readFileSync(f, 'utf8')
  for (const m of text.matchAll(/['"(]\/images\/[A-Za-z0-9/_.-]+/g)) REFERENCED.add(m[0].slice(1))
}

/** 零引用但**必须**留在磁盘上的，逐条给出处 */
const ALLOW = {
  'collections/cover-1.jpg': '本地种子与 qa 造数用它当藏品封面（seed-dev.sql、qa/e2e_*.php）',
  'collections/cover-2.jpg': '同上（盲盒封面）',
  'collections/cover-3.jpg': '同上',
  'invite-banner-bg.jpg': '线上旧版 CSS 还在请求 ⇒ 等第 9+10 批上线后翻进 GONE',
  'exhibits/exhibit-header-ancient.jpg': '同上：现网 assets/*.css 实测命中',
  'invite-banner-bg-v6.webp': '用户口径「保持未跟踪」= 无 git 副本，只许留',
}

/** §7.57 A 档清掉的：名字回来 = 死重回来 */
const GONE = ['invite-banner-bg-v4.webp', 'invite-banner-bg-v5.webp', 'invite-banner-bg-v7.webp']

const PUB_FILES = listUnder(PUB)
const url = (rel) => `/images/${rel}`

describe('闸口先自证：量的不是空气', () => {
  it('public/images 枚举得到东西、src 扫出的引用集合非空（两把尺都在手上）', () => {
    expect(PUB_FILES.length).toBeGreaterThan(50)
    expect(REFERENCED.size).toBeGreaterThan(30)
    expect(PUB_FILES).toContain('tab/tab-person.png')
    expect(REFERENCED.has(url('tab/tab-person.png'))).toBe(true)
  })

  it('ALLOW 里每条都还躺在磁盘上（把保钉撤了不会让它变成一条废话）', () => {
    for (const rel of Object.keys(ALLOW)) {
      expect(existsSync(join(PUB, rel)), `${rel} 不在了，但它登记的理由是：${ALLOW[rel]}`).toBe(true)
    }
  })
})

describe('死重不许回来：目录里只许有「被引用」或「有出处」的', () => {
  it('零引用、又不在 ALLOW 里的文件一个都不许存在（这条就是闸口）', () => {
    const orphans = PUB_FILES.filter((rel) => !REFERENCED.has(url(rel)) && !(rel in ALLOW))
    expect(orphans, `这些图没有代码引用、也没登记出处：${orphans.join(', ')}`).toEqual([])
  })

  it('A 档三张邀请条旧版已从磁盘消失', () => {
    for (const name of GONE) {
      expect(existsSync(join(PUB, name)), `${name} 又回来了`).toBe(false)
    }
  })

  it('邀请条一族磁盘上只剩 v6（留档）/ v8（在用）/ jpg（等上线）——精确集合，不是"少几张也算绿"', () => {
    expect(PUB_FILES.filter((rel) => /invite-banner-bg/.test(rel)).sort())
      .toEqual(['invite-banner-bg-v6.webp', 'invite-banner-bg-v8.webp', 'invite-banner-bg.jpg'])
  })

  it('展览区只剩旧古物图（等上线）+ 深暗底方鼎（在用）', () => {
    expect(PUB_FILES.filter((rel) => rel.startsWith('exhibits/')).sort())
      .toEqual(['exhibits/exhibit-header-ancient.jpg', 'exhibits/exhibit-header-bronze-ding-v2.webp'])
  })

  it('tab/ 下的 empty-* 只许有玉雕司南 v2（§7.56 那颗钉换个尺子再钉一遍）', () => {
    expect(PUB_FILES.filter((rel) => /^tab\/empty-/.test(rel)).sort()).toEqual(['tab/empty-jade-compass-v2.webp'])
  })
})

describe('构建产物不许留过期图（public 删了、dist 还留着 = 每次发版白搬一份）', () => {
  it('dist/images 里不许出现 public/images 没有的文件', () => {
    if (!existsSync(DIST)) return
    const stale = listUnder(DIST).filter((rel) => !PUB_FILES.includes(rel))
    expect(stale, `dist 里这些是过期产物，重新 build：${stale.join(', ')}`).toEqual([])
  })
})

describe('资产不许是空文件（防止用 0 字节冒充"图在"）', () => {
  it('public/images 下没有 0 字节的文件', () => {
    expect(PUB_FILES.filter((rel) => statSync(join(PUB, rel)).size === 0)).toEqual([])
  })
})
