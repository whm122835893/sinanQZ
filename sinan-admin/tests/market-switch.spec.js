import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * 转赠 / 寄售开关：四格都得是真开关，而且只能走一个接口（2026-10-12）
 *
 * 起因：用户问「盲盒在详情里面怎么没有转赠和寄售的开关」。查下来不是缺功能 ——
 * 后端 PUT /collectibles/:id/market-config 一直支持（盲盒在库里就是 collectibles 的一行 tag=blindbox），
 * 盲盒**列表**页也一直能点；只有盲盒**详情**页把那两枚画成了一行只读文字（「转赠已关闭 · 寄售已关闭」），
 * 想改开关就得退回列表页。属于同一个功能在四个页面里长成了三种样子。
 *
 * 所以这里钉三条规矩，防止下一个页面又"只显示不能点"：
 *   1. 四格（藏品列表 / 藏品详情 / 盲盒列表 / 盲盒详情）都必须出现**真正的 el-switch**，
 *      绑定在 isTransferable / isResaleable 上 —— 光有"转赠已开启"这种文字不算；
 *   2. 每格必须调自己那一对 API（toggleCollectibleXxx / toggleBlindBoxXxx），不许在页面里自己拼接口；
 *   3. market-config 这条 URL 只准出现在 src/api/index.js 一处（单闸），页面里出现就是分叉。
 *
 * 顺带钉两条口径：寄售必须过密码验证弹窗（改的是二级市场流通），转赠的二次确认文案四格必须逐字一样。
 *
 * 这个仓库不装 @vue/test-utils，所以照旧用源码扫描 —— 判定函数抽在这里、能用合成样例做正反对照。
 */

const src = (rel) => readFileSync(resolve(process.cwd(), rel), 'utf8')

/** 把源码里每枚 <el-switch … /> 拆成独立的标签串（属性里会出现 "=>"，所以不能按 '>' 切） */
function switchTags(source) {
  return source.match(/<el-switch\b[\s\S]*?\/>/g) || []
}

/** 找出「显示了状态、但没有对应真开关」的字段名 */
function missingSwitches(source) {
  const fields = ['isTransferable', 'isResaleable']
  return fields.filter((f) => {
    if (!new RegExp(`${f}\\b`).test(source)) return false
    return !switchTags(source).some((tag) => tag.includes(f))
  })
}

/**
 * 剥掉注释再扫 —— 否则一句解释「后端接口叫 market-config」的注释会被当成"页面自己拼了接口"。
 * 去三种：HTML 注释、块注释、行注释（行注释要避开 URL 里的 `//`，所以只在行首或引号外出现时切）。
 */
function stripComments(source) {
  return source
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:'"\\])\/\/[^\n]*/g, '$1')
}

/** 页面里出现 market-config = 绕过 api 层自己拼接口 */
function rawMarketConfigCalls(source) {
  return (stripComments(source).match(/market-config/g) || []).length
}

/**
 * 寄售开关是否被「弹窗 + 密码验证」包着。
 * 三条一起才算：① 确实有一枚绑 isResaleable 的 el-switch；② 它的 @change 不许直接调 toggle
 * （直接调 = 跳过价格管控和密码验证，正是这次要防的走偏）；③ 文件里有 PasswordVerify，
 * 且它的 @verified 处理器名字落在 Resale / Price 上。
 */
function resaleHasPasswordGate(source) {
  const tag = switchTags(source).find((t) => t.includes('isResaleable'))
  if (!tag) return false
  if (/toggle(Collectible|BlindBox)Resale/.test(tag)) return false
  if (!/PasswordVerify\b/.test(source)) return false
  return /@verified="[^"]*(?:Resale|Price)/.test(source)
}

const CELLS = [
  { name: '藏品列表', rel: 'src/views/collectible/Index.vue', api: ['toggleCollectibleTransferable', 'toggleCollectibleResale'] },
  { name: '藏品详情', rel: 'src/views/collectible/Detail.vue', api: ['toggleCollectibleTransferable', 'toggleCollectibleResale'] },
  { name: '盲盒列表', rel: 'src/views/blindbox/Index.vue', api: ['toggleBlindBoxTransferable', 'toggleBlindBoxResale'] },
  { name: '盲盒详情', rel: 'src/views/blindbox/Detail.vue', api: ['toggleBlindBoxTransferable', 'toggleBlindBoxResale'] }
]

const TRANSFER_TIP = '用户端转赠按钮置灰（已发起待确认的转赠不受影响）'

describe('扫描器本身没瞎（合成样例做正反面）', () => {
  it('只有文字没有开关 → 报缺', () => {
    const fake = '<div>{{ detail.isTransferable ? "转赠已开启" : "转赠已关闭" }} · {{ detail.isResaleable ? "寄售已开启" : "寄售已关闭" }}</div>'
    expect(missingSwitches(fake).sort()).toEqual(['isResaleable', 'isTransferable'])
  })

  it('el-switch 绑上字段 → 不报', () => {
    const fake = `
      <el-switch :model-value="detail.isTransferable" @change="onA" />
      <el-switch :model-value="detail.isResaleable" @change="onB" />`
    expect(missingSwitches(fake)).toEqual([])
  })

  it('只补了一枚也算缺（这次踩的就是"只有寄售没转赠"这类半边修）', () => {
    const fake = '<el-switch :model-value="detail.isResaleable" @change="onB" />{{ detail.isTransferable }}'
    expect(missingSwitches(fake)).toEqual(['isTransferable'])
  })

  it('页面里自己拼 market-config 会被抓到', () => {
    expect(rawMarketConfigCalls('put(`/collectibles/${cid}/market-config`, {})')).toBe(1)
    expect(rawMarketConfigCalls('const x = 1')).toBe(0)
  })

  it('寄售开关没走密码弹窗 → gate 判 false', () => {
    const noPwd = '<el-switch :model-value="row.isResaleable" @change="(v) => toggleBlindBoxResale(row.id, v)" />'
    expect(resaleHasPasswordGate(noPwd)).toBe(false)
    const withPwd = `
      <el-switch :model-value="row.isResaleable" @change="openPrice(row)" />
      <el-dialog v-model="priceShow"><el-switch v-model="priceForm.enabled" /></el-dialog>
      <PasswordVerify v-model="pwdShow" @verified="onPriceVerified" />`
    expect(resaleHasPasswordGate(withPwd)).toBe(true)
  })
})

describe('四格开关现状', () => {
  it.each(CELLS)('$name：转赠 / 寄售都得是能点的开关', ({ rel }) => {
    expect(missingSwitches(src(rel)), `${rel} 里出现了"只显示状态、没有 el-switch"的字段`).toEqual([])
  })

  it.each(CELLS)('$name：只调自己那一对 API', ({ rel, api }) => {
    const s = src(rel)
    api.forEach((fn) => expect(s, `${rel} 应该调用 ${fn}`).toContain(fn))
  })

  it.each(CELLS)('$name：寄售改动必须过密码验证', ({ rel }) => {
    expect(resaleHasPasswordGate(src(rel)), `${rel} 的寄售开关没被"弹窗 + PasswordVerify"包住`).toBe(true)
  })

  it('单闸：market-config 只在 src/api/index.js 出现一次都不许多', () => {
    // 藏品那三条 + 盲盒那两条都从 api/index.js 出，页面里一处都不许有
    CELLS.forEach(({ name, rel }) => {
      expect(rawMarketConfigCalls(src(rel)), `${name} 里不该自己拼 market-config`).toBe(0)
    })
    const api = src('src/api/index.js')
    expect(rawMarketConfigCalls(api)).toBeGreaterThan(0)
  })

  it('转赠的二次确认文案四格逐字一样（口径不许各写一句）', () => {
    CELLS.forEach(({ name, rel }) => {
      expect(src(rel), `${name} 的转赠提示语与其余几格不一致`).toContain(TRANSFER_TIP)
    })
  })

  it('盲盒那对 API 是「先查盲盒拿影子藏品 id，再走 market-config」——不是第二条口径', () => {
    const api = src('src/api/index.js')
    const resale = api.slice(api.indexOf('export function toggleBlindBoxResale'), api.indexOf('// ============================================================\n// 订单 / 退款'))
    expect(resale).toContain('/blind-boxes/')
    expect(resale).toContain('collectibleId')
    expect(resale).toContain('market-config')
  })
})
