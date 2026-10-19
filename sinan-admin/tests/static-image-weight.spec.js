import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync, existsSync, statSync } from 'node:fs'
import { join, resolve, relative, sep } from 'node:path'

/**
 * 后台静态图死重闸口（2026-10-20，RUNBOOK §7.57）
 *
 * 大白话：后台的 `public/images/` 也是「整包搬进 dist」的，而且这仓库里它历史上被当成过 C 端目录的镜像——
 * 当年发版是「两站各解一次包」，C 端的一整套底部图标、旧海报、旧纸箱空状态图就这么躺进了后台站，
 * 之后再没人管。这轮清掉后台侧确认没人在用的四张，并装上和 C 端同款的闸口。
 *
 * 和 C 端那份（`static-image-weight.spec.js`）同一把尺：目录枚举 + 源码 `/images/...` 扫描，差额必须逐条有出处。
 * 后台这边多一层事实：**库里存的路径也会被请求**——列表页 `:src="row.image"` 是直出的，
 * 所以生产数据指着的三张（banner#1 的 slide-3、藏品 #2/#3/#4 的 cover-collection-5/bb1）删了就是后台破图，
 * 它们进 ALLOW 而不是删除档位。这三条的出处是当场只读查生产库量出来的，不是推断。
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

const REFERENCED = new Set()
for (const f of listSource(SRC)) {
  const text = readFileSync(f, 'utf8')
  for (const m of text.matchAll(/['"(]\/images\/[A-Za-z0-9/_.-]+/g)) REFERENCED.add(m[0].slice(1))
}

/** 代码零引用但必须留着，每条带出处 */
const ALLOW = {
  'brand-logo.png': '本地种子 SQL 里有一行存的就是这个路径（`database/dev-only/seed-dev.sql`）',
  'collections/cover-1.jpg': '本地种子 + qa 造数的藏品封面',
  'collections/cover-2.jpg': '同上',
  'collections/cover-3.jpg': '同上',
  'collections/cover-collection-5.jpg': '生产库实测：`nft_collectibles.image`(#2) 与 `nft_inbox.image`(#4) 存的是它，后台列表 :src="row.image" 直出',
  'collections/cover-collection-bb1.jpg': '生产库实测：藏品 #3/#4 的封面路径',
  'hero/slide-3.jpg': '生产库实测：`nft_banners.image`(#1) 首页轮播用的就是它',
}

/** §7.57 A 档从后台清掉的（生产库、本地库、后台代码三层都零引用） */
const GONE = [
  'collections/cover-collection-4.jpg',
  'collections/cover-collection-6.jpg',
  'hero/slide-2.jpg',
  'tab/empty-carton.png',
]

const PUB_FILES = listUnder(PUB)
const url = (rel) => `/images/${rel}`

describe('后台闸口先自证：量的不是空气', () => {
  it('public/images 枚举得到东西、src 扫出的引用集合非空', () => {
    expect(PUB_FILES.length).toBeGreaterThan(10)
    expect(REFERENCED.size).toBeGreaterThan(2)
    expect(PUB_FILES).toContain('tab/tab-person.png')
    expect(REFERENCED.has(url('tab/tab-person.png'))).toBe(true)
  })

  it('ALLOW 里每条都还躺在磁盘上（登记了理由的图不许被顺手删）', () => {
    for (const rel of Object.keys(ALLOW)) {
      expect(existsSync(join(PUB, rel)), `${rel} 不在了，但它登记的理由是：${ALLOW[rel]}`).toBe(true)
    }
  })
})

describe('后台死重不许回来', () => {
  it('零引用、又不在 ALLOW 里的文件一个都不许存在（这条就是闸口）', () => {
    const orphans = PUB_FILES.filter((rel) => !REFERENCED.has(url(rel)) && !(rel in ALLOW))
    expect(orphans, `这些图后台代码没引用、也没登记出处：${orphans.join(', ')}`).toEqual([])
  })

  it('A 档四张已从磁盘消失', () => {
    for (const rel of GONE) expect(existsSync(join(PUB, rel)), `${rel} 又回来了`).toBe(false)
  })

  it('hero/ 只剩生产库在用的 slide-3；C 端那两张幻灯片归 C 端仓库管', () => {
    expect(PUB_FILES.filter((rel) => rel.startsWith('hero/')).sort()).toEqual(['hero/slide-3.jpg'])
  })

  it('collections/ 只剩种子三张 + 生产库两张，4/6 不许回来', () => {
    expect(PUB_FILES.filter((rel) => rel.startsWith('collections/')).sort()).toEqual([
      'collections/cover-1.jpg', 'collections/cover-2.jpg', 'collections/cover-3.jpg',
      'collections/cover-collection-5.jpg', 'collections/cover-collection-bb1.jpg',
    ])
  })

  it('tab/ 下不许再有 empty-*（空状态是 C 端那唯一入口的事，后台不养第二份）', () => {
    expect(PUB_FILES.filter((rel) => /^tab\/empty-/.test(rel))).toEqual([])
  })

  it('后台站不该替 C 端养底部图标（当年解错包留下的那 60 张不许回来）', () => {
    const cSide = PUB_FILES.filter((rel) => /^(tab\/(tab-home|tab-bag|tab-gift|gem-|ink-|lg-|modern-|shanse-))/.test(rel))
    expect(cSide, `这些是 C 端底部导航的图标，后台代码零引用：${cSide.join(', ')}`).toEqual([])
  })
})

describe('构建产物不许留过期图', () => {
  it('dist/images 里不许出现 public/images 没有的文件', () => {
    if (!existsSync(DIST)) return
    const stale = listUnder(DIST).filter((rel) => !PUB_FILES.includes(rel))
    expect(stale, `dist 里这些是过期产物，重新 build：${stale.join(', ')}`).toEqual([])
  })
})

describe('资产不许是空文件', () => {
  it('public/images 下没有 0 字节的文件', () => {
    expect(PUB_FILES.filter((rel) => statSync(join(PUB, rel)).size === 0)).toEqual([])
  })
})
