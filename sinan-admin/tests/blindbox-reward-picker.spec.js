import { readFileSync, readdirSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * 奖励编辑器选得了盲盒（2026-10-13，你点头「那就修共享组件」）
 *
 * 起因：上一轮查邀请「未知类型」时发现更底下的一层洞——共享组件 RewardListEditor 的
 * 盲盒分支跟「抽奖次数」共用同一行「只填数量」的控件，全仓库后台前端没有一处出现 blindboxId，
 * 也就是四个活动（邀请/签到/注册/抽奖）里选「盲盒」都存不进去：
 * 后端 RewardGrantService::validate 要的字段是 blindboxId，前端根本没地方填。
 *
 * 修法（单闸口：只改组件本身，四个页面各自补「取盲盒列表 + 传下去 + 保存前校验」）：
 *   - 组件新增 blindboxes prop 与盲盒下拉（值 = nft_blind_boxes.id，跟后端一致）；
 *   - 四个页面把 getBlindBoxList 的结果传进去；
 *   - 每页保存前拦一句「盲盒奖励需选择具体盲盒（发哪一个）」。
 *
 * 这仓库没有 @vue/test-utils，照旧用源码扫描钉住接线；重点是「组件里那个洞（共用一行）
 * 不能被改回去」和「用它的页面必须一个不漏地传 blindboxes」。
 */

const read = (rel) => readFileSync(resolve(process.cwd(), rel), 'utf8')
const EDITOR = 'src/components/RewardListEditor.vue'
const VIEWS = [
  'src/views/marketing/Invite.vue',
  'src/views/marketing/Register.vue',
  'src/views/marketing/CheckIn.vue',
  'src/views/marketing/LuckyDraw.vue',
]

/** 递归列出 src 下所有 .vue/.js，用来证明「用这个组件的页面就这四个」 */
function listSourceFiles(dir) {
  const out = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name)
    if (entry.isDirectory()) out.push(...listSourceFiles(p))
    else if (/\.(vue|js)$/.test(entry.name)) out.push(p)
  }
  return out
}

describe('RewardListEditor.vue：盲盒有自己的选择行', () => {
  it('声明了 blindboxes prop（缺省空数组，老调用方不会炸）', () => {
    expect(read(EDITOR)).toMatch(/blindboxes:\s*\{\s*type:\s*Array,\s*default:\s*\(\)\s*=>\s*\[\s*\]\s*\}/)
  })

  it('切到盲盒类型时会带上 blindboxId 字段', () => {
    const s = read(EDITOR)
    expect(s).toMatch(/else if \(t === 'blindbox'\) Object\.assign\(base, \{ blindboxId: null, quantity: 1 \}\)/)
  })

  it('盲盒与抽奖次数不再共用同一行控件（这就是原来选不了的根因）', () => {
    const s = read(EDITOR)
    expect(s).not.toContain("r.type === 'draw_chance' || r.type === 'blindbox'")
    expect(s).not.toContain("t === 'draw_chance' || t === 'blindbox'")
    expect(s).toMatch(/<template v-else-if="r\.type === 'blindbox'">/)
  })

  it('下拉的值是盲盒 id、显示名是盲盒名，且空列表时给出去哪新建的提示', () => {
    const s = read(EDITOR)
    const block = /<template v-else-if="r\.type === 'blindbox'">([\s\S]*?)<\/template>/.exec(s)
    expect(block, '找不到盲盒那一行的模板').not.toBeNull()
    expect(block[1]).toContain('v-model="r.blindboxId"')
    expect(block[1]).toMatch(/:key="b\.id"[^>]*:label="b\.name"[^>]*:value="b\.id"/)
    expect(block[1]).toContain('没有盲盒')
    expect(block[1]).toContain('盲盒管理')
  })

  it('摘要文案把盲盒名带出来（不再只有「盲盒 ×1」）', () => {
    const s = read(EDITOR)
    expect(s).toMatch(/if \(r\.type === 'blindbox'\) return `\$\{t\}「\$\{bname\(r\.blindboxId\)\}」×\$\{r\.quantity \?\? 1\}`/)
  })

  it('none 仍留在组件默认类型表里：抽奖的「空奖」依赖它，别顺手删', () => {
    const s = read(EDITOR)
    expect(s).toMatch(/none:\s*\{/)
  })
})

describe('四个活动页面：把盲盒列表接到组件上', () => {
  it.each(VIEWS)('%s 取盲盒列表并传下去', (rel) => {
    const s = read(rel)
    expect(s).toContain('getBlindBoxList')
    expect(s).toMatch(/const blindboxes = ref\(\[\]\)/)
    // 每一处 <RewardListEditor 都必须带 :blindboxes，一处不落
    const usages = (s.match(/<RewardListEditor/g) || []).length
    const binds = (s.match(/:blindboxes="blindboxes"/g) || []).length
    expect(usages, rel + ' 里没找到奖励编辑器').toBeGreaterThan(0)
    expect(binds).toBe(usages)
  })

  it.each(VIEWS)('%s 保存前拦「选了盲盒但没选具体哪个盲盒」', (rel) => {
    expect(read(rel)).toMatch(/blindbox.*需选择具体盲盒/)
  })

  it('后台用这个组件的页面只有这四个（漏一个就是漏一个选不了盲盒的活动）', () => {
    // Windows 下 resolve() 给出反斜杠路径，统一成正斜杠再截相对路径
    const root = resolve(process.cwd()).replace(/\\/g, '/') + '/'
    const relOf = (f) => f.replace(/\\/g, '/').replace(root, '')
    const consumers = listSourceFiles(resolve(process.cwd(), 'src'))
      .map(relOf)
      .filter((rel) => rel !== EDITOR && /\bRewardListEditor\b/.test(read(rel)))
      .sort()
    expect(consumers).toEqual([...VIEWS].sort())
  })
})

describe('跨端契约：前端交出去的字段名 = 后端要的字段名', () => {
  const backend = read('../sinan-nft-backend/app/service/RewardGrantService.php')

  it('后端 blindbox 奖励要的就是 blindboxId，且它是 blind_boxes.id', () => {
    expect(backend).toMatch(/\$config\['blindboxId'\]/)
    expect(backend).toContain('盲盒奖励必须指定 blindboxId')
    expect(backend).toMatch(/case 'blindbox':[\s\S]{0,900}?\$result \+= \['blindboxId' => \$bid, 'quantity' => \$qty\]/)
  })

  it('发放侧按 blindboxId 去 blind_boxes 找 collectible_id（所以下拉必须给盒 id，不是藏品 id）', () => {
    expect(backend).toMatch(/case 'blindbox':\s*\n\s*return self::grantCollectible\(\(int\) \$rewardConfig\['blindboxId'\]/)
    expect(backend).toMatch(/Db::name\('blind_boxes'\)->where\('id', \$targetId\)/)
  })

  it('六类奖励里就有 blindbox：前端组件不该完全没有它的入口', () => {
    expect(backend).toMatch(/public const TYPES = \[[\s\S]*?'blindbox'[\s\S]*?\];/)
  })
})
