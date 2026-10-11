import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * 盲盒「盲盒内容」那一块的数据源与角标口径（2026-10-11）
 *
 * 这块模板从一开始就写好了，却从来没显示过一次：
 * 它读的是 featuredItem?.items，而首页发售区的映射层（stores/collection.js）
 * 压根没有 items 字段，后端 featured 接口也从没下发奖池 —— 于是 v-if 恒假。
 * 现在改成读详情接口下发的 items（后端单点：app/controller/BlindBoxes.php 的 poolOf），
 * 这里钉住三件事，防止再退回死代码：
 *   ① 数据源是详情接口，不是 featured 列表（顺带解决深链直进详情页时 featured 未加载）；
 *   ② 概率文案由后端算好，前端不再自己乘 100；
 *   ③ 稀有度四档的中文与后端 rarityOf 的返回值同名，改一边就必须改另一边。
 */

const detail = resolve(__dirname, '../src/views/CollectionDetail.vue')
const storeSrc = resolve(__dirname, '../src/stores/collection.js')
const text = readFileSync(detail, 'utf-8')

describe('CollectionDetail · 盲盒内容数据源', () => {
  it('奖池列表取自详情接口的 items', () => {
    expect(text).toMatch(/const blindboxItems = computed\(\(\) => rawField\('items'\)/)
  })

  it('不再从 featured 列表上找 items（那是当初恒为空的根因）', () => {
    expect(text).not.toMatch(/featuredItem\.value\?\.items/)
    // store 的 featured 映射里也不能凭空冒出一个 items，避免有人改用另一条链路
    expect(readFileSync(storeSrc, 'utf-8')).not.toMatch(/^\s*items:/m)
  })

  it('有内容才渲染整块，且渲染封面/名称/稀有度/概率四项', () => {
    expect(text).toMatch(/v-if="blindboxItems\.length"/)
    expect(text).toMatch(/:src="it\.coverImage"/)
    expect(text).toMatch(/\{\{ it\.name \}\}/)
    expect(text).toMatch(/'blindbox-items__rarity--' \+ it\.rarity/)
    expect(text).toMatch(/\{\{ it\.probability \}\}/)
  })

  /** 后端下发的是 "100%" 这类成品文案；前端再乘一次就变 10000% */
  it('概率原样展示，前端不做二次换算', () => {
    expect(text).not.toMatch(/probability\s*[*/]\s*100/)
    expect(text).not.toMatch(/toFixed\([^)]*\)[^}]*\}\}\s*%/)
  })

  /** 与后端 BlindBoxes::rarityOf 的四档一一对应（同名类，缺一档就有奖品显示成无色块） */
  it('稀有度四档样式齐全', () => {
    for (const label of ['普通', '稀有', '史诗', '传说']) {
      expect(text).toContain(`&--${label}`)
    }
  })
})
