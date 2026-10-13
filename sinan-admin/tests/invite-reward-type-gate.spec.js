import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * 邀请活动的奖励下拉别再给出存不进去的类型（2026-10-13）
 *
 * 起因：你报「邀请活动保存显示未知类型」。根因是共享组件 RewardListEditor 的默认下拉里有
 * 「无奖励」(none)，而它既不在后端 RewardGrantService::TYPES 里，发放侧也不认——
 * 选了它只能换回一句「未知奖励类型 none」，还完全不知道是哪一档哪一位。
 *
 * 本轮口径是你定的「只改邀请的这一套」：
 *   - Invite.vue 自己声明一份 INVITE_REWARD_TYPES 传给两处 RewardListEditor；
 *   - 共享组件与签到/注册/抽奖/合成的下拉都不动；
 *   - 报错带位置（哪一档 · 第几位 · 什么类型）在后端 MarketingController::normalizeInviteRewards，
 *     那一侧的用例在 sinan-nft-backend/tests/unit/InviteRewardErrorPositionTest.php。
 *
 * 这个仓库没有 @vue/test-utils，所以照旧用源码扫描钉住「下拉真的收窄了、收窄的集合和后端一致」。
 */

const read = (rel) => readFileSync(resolve(process.cwd(), rel), 'utf8')

describe('Invite.vue：奖励类型白名单', () => {
  const src = () => read('src/views/marketing/Invite.vue')

  it('两处奖励编辑器都传了自己的类型白名单', () => {
    const s = src()
    expect(s).toMatch(/const INVITE_REWARD_TYPES = \[/)
    expect(s.match(/:types="INVITE_REWARD_TYPES"/g) || []).toHaveLength(2)
  })

  it('白名单里没有「无奖励」', () => {
    const s = src()
    const block = /const INVITE_REWARD_TYPES = \[([\s\S]*?)\]/.exec(s)
    expect(block, '找不到 INVITE_REWARD_TYPES').not.toBeNull()
    expect(block[1]).not.toMatch(/none/)
    expect(block[1]).toMatch(/collectible/)
  })

  it('none 只留在展示用的标签表里，并且注释说明了原因', () => {
    const s = src()
    expect(s).toMatch(/none: \{ label: '无奖励'/)
    expect(s).toMatch(/历史数据/)
  })
})

describe('白名单必须与后端认的类型全集一致', () => {
  const backend = resolve(process.cwd(), '../sinan-nft-backend/app/service/RewardGrantService.php')

  it('后端 TYPES 就是那六种，且不含 none', () => {
    const php = readFileSync(backend, 'utf8')
    const block = /public const TYPES = \[([\s\S]*?)\];/.exec(php)
    expect(block, 'RewardGrantService::TYPES 结构变了，这条对照要跟着改').not.toBeNull()
    const types = (block[1].match(/'([a-z_]+)'/g) || []).map((x) => x.replace(/'/g, ''))
    expect(types).toEqual([
      'collectible', 'priority_qualification', 'eligibility_qualification',
      'draw_chance', 'blindbox', 'points',
    ])

    const vue = read('src/views/marketing/Invite.vue')
    const mine = (vue.match(/const INVITE_REWARD_TYPES = \[([\s\S]*?)\]/)[1].match(/'([a-z_]+)'/g) || [])
      .map((x) => x.replace(/'/g, ''))
    expect(mine.slice().sort()).toEqual(types.slice().sort())
  })
})

describe('共享编辑器仍然保留全量下拉（本轮没有顺手改别人）', () => {
  it('RewardListEditor 的默认全量里仍然有 none', () => {
    const s = read('src/components/RewardListEditor.vue')
    expect(s).toMatch(/none: \{ label: '无奖励', tag: 'info' \}/)
    expect(s).toMatch(/props\.types \? props\.types\.filter/)
  })

  it('签到 / 注册 / 抽奖页没有被传 types', () => {
    for (const rel of [
      'src/views/marketing/CheckIn.vue',
      'src/views/marketing/Register.vue',
      'src/views/marketing/LuckyDraw.vue',
    ]) {
      expect(read(rel), rel + ' 本轮不该动').not.toMatch(/:types="/)
    }
  })
})

describe('Invite.vue：保存前的提示要说清是哪一格', () => {
  const src = () => read('src/views/marketing/Invite.vue')

  it('档位报错带上「第几档」，坏在哪一格一眼看得到', () => {
    const s = src()
    expect(s).toMatch(/第 \$\{i \+ 1\} 档/)
    expect(s).toMatch(/第 \$\{j \+ 1\} 个奖励位/)
    expect(s).toMatch(/function rewardTypeName\(/)
  })

  it('光秃秃的旧文案已经退干净', () => {
    const s = src()
    expect(s).not.toMatch(/邀请档位人数需在 1~50 之间/)
    expect(s).not.toMatch(/档位需至少配置一项奖励/)
    expect(s).not.toMatch(/」档位：\$\{err\}/)
    expect(s).not.toMatch(/被邀请人奖励：\$\{err\}/)
  })

  it('只有一项奖励时不硬写「第 1 个奖励位」', () => {
    expect(src()).toMatch(/rewards\.length === 1/)
  })
})

