import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

import { AIRDROP_STATUS, AIRDROP_TYPES, issueBlockReason } from '@/utils/airdrop'

/**
 * 空投「批量发放」置灰与自动圈名单（2026-10-11 第三批 · 缺陷 5）
 *
 * 需求原话：「后台空投保存时就自动圈一次名单，发放按钮置灰写明原因」。
 * 落地的两半：
 *   1. 后端保存活动时顺手圈一次名单（见后端 AirdropEligibilityWiringTest）；
 *   2. 前端不再用 v-if 把「批量发放」藏掉，改成常驻 + 置灰 + tooltip 写原因。
 *
 * 这个仓库不装 @vue/test-utils，所以判定逻辑抽到 src/utils/airdrop.js 里直接测，
 * 页面那侧用源码扫描钉住"确实接上了、没在页面里另写一份"。
 */

const viewSrc = () =>
  readFileSync(resolve(process.cwd(), 'src/views/marketing/AirdropManage.vue'), 'utf8')

describe('issueBlockReason：能不能发，一句话说明白', () => {
  const row = (over = {}) => ({ type: 'direct', status: 'active', eligibleCount: 12, ...over })

  it('进行中 + 名单有人 = 可以发（空串）', () => {
    expect(issueBlockReason(row())).toBe('')
  })

  it.each(['draft', 'paused', 'ended'])('非进行中（%s）要说清当前状态并给出下一步', (status) => {
    const reason = issueBlockReason(row({ status }))
    expect(reason).toContain(AIRDROP_STATUS[status].label)
    expect(reason).toContain('进行中')
    expect(reason).toContain('编辑')
  })

  it('名单为空时要点名「保存会自动圈」和「生成名单」两个出口', () => {
    const reason = issueBlockReason(row({ eligibleCount: 0 }))
    expect(reason).toContain('名单')
    expect(reason).toContain('生成名单')
  })

  it('后端下发的是数字串，"0" 也算没名单', () => {
    expect(issueBlockReason(row({ eligibleCount: '0' }))).toContain('生成名单')
    expect(issueBlockReason(row({ eligibleCount: '8' }))).toBe('')
  })

  it('字段缺失（脏行）不能谎报"可以发"', () => {
    expect(issueBlockReason({})).toBe('该资格类型不支持批量发放')
    expect(issueBlockReason(undefined)).toBe('该资格类型不支持批量发放')
  })

  it('七种资格类型都支持圈名单（新增类型要同步这里）', () => {
    expect(Object.keys(AIRDROP_TYPES)).toEqual(['direct', 'hold', 'condition', 'checkin', 'register', 'login', 'invite'])
    Object.entries(AIRDROP_TYPES).forEach(([k, v]) => {
      expect(v.snapshottable, `${k} 必须能圈名单，否则按钮会永远灰着`).toBe(true)
      expect(v.label).toBeTruthy()
    })
  })
})

describe('AirdropManage.vue 已接到这套判定上（防漂移）', () => {
  it('「批量发放」不再被 v-if 藏起来', () => {
    const src = viewSrc()
    expect(src).not.toMatch(/v-if="row\.status === 'active'/)
    expect(src).toMatch(/:disabled="!!issueBlockReason\(row\)"/)
    // 置灰按钮不派发 hover，必须靠外层 span 才能弹出 tooltip
    expect(src).toMatch(/am__issue-wrap/)
    expect(src).toMatch(/<el-tooltip[\s\S]{0,220}?issueBlockReason\(row\)/)
  })

  it('映射与判定只从 utils/airdrop 引一份', () => {
    const src = viewSrc()
    expect(src).toMatch(/from '@\/utils\/airdrop'/)
    expect(src).not.toMatch(/const AIRDROP_TYPES = \{/)
    expect(src).not.toMatch(/const AIRDROP_STATUS = \{/)
    expect(src).not.toMatch(/function issueBlockReason/)
  })

  it('生成名单的提示读后端真实键名 kept_issued（以前读 keptIssued，数字一直是空的）', () => {
    const src = viewSrc()
    expect(src).toMatch(/res\.data\.kept_issued/)
    expect(src).not.toMatch(/res\.data\.keptIssued/)
  })

  it('保存后的提示用后端原话，因为"名单有没有圈出来"只有后端知道', () => {
    const src = viewSrc()
    expect(src).toMatch(/ElMessage\.success\(res\.message/)
    // onIssue 前置拦截，避免置灰原因与真实提交各说各话
    expect(src).toMatch(/if \(issueBlockReason\(row\)\) return/)
  })
})
