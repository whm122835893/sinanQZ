import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { formatNo, serialNumber, noLabel } from '@/utils/serial'

/**
 * 藏品编号展示口径（2026-10-11 用户定）：
 *   库里存 SN-{藏品ID}-{序号}，C 端只显示尾部序号并带 # 前缀，形如 #7；
 *   发行 1000 份的藏品编号最大 #1000（取号不越界由后端 SerialService 保证）。
 *
 * 第一组用例盯格式化函数本身，第二组盯「别有人在模板里再手写 #」这件事。
 */

describe('utils/serial · 编号格式化', () => {
  it('标准编号只留尾部序号，并去掉前导零', () => {
    expect(formatNo('SN-3-0007')).toBe('#7')
    expect(formatNo('SN-3-1000')).toBe('#1000')
    expect(formatNo('SN-21-0001')).toBe('#1')
  })

  it('纯数字序号（其他接口直接下发序号）也能显示', () => {
    expect(formatNo('0042')).toBe('#42')
    expect(formatNo(42)).toBe('#42')
  })

  it('空值不显示，历史占位串原样给出（宁可难看也不能把这一件藏起来）', () => {
    expect(formatNo(null)).toBe('')
    expect(formatNo(undefined)).toBe('')
    expect(formatNo('')).toBe('')
    expect(formatNo('   ')).toBe('')
    expect(formatNo('TMP-1a2b3c4d5e6f7a8b')).toBe('TMP-1a2b3c4d5e6f7a8b')
  })

  it('serialNumber / noLabel 与 formatNo 口径一致', () => {
    expect(serialNumber('SN-9-0012')).toBe(12)
    expect(serialNumber('乱七八糟')).toBeNull()
    expect(noLabel('SN-9-0012')).toBe('12')
    expect(noLabel('乱七八糟')).toBe('')
  })

  it('寄售锁定比对继续用原始编号，不受展示格式影响', () => {
    // 显示成 #7，库存里的 items[].serial 仍是 SN-3-0007：两者不能混用
    expect(formatNo('SN-3-0007')).toBe('#7')
    expect('SN-3-0007' === formatNo('SN-3-0007')).toBe(false)
  })
})

/**
 * 防漂移：# 这个前缀只允许出现在 utils/serial 里。
 * 早先各页面自己拼 '#{{ serial }}'，同一个编号在仓库弹窗里是 SN-3-0007、
 * 在详情页是 #SN-3-0007，用户根本不知道是不是同一个东西。
 */
describe('编号展示防漂移', () => {
  // vitest 的工作目录就是项目根（见 vitest.config.js 的 include: tests/**/*.spec.js）；
  // jsdom 环境下 import.meta.url 不是 file: 协议，不能用 fileURLToPath 推路径。
  const srcRoot = resolve(process.cwd(), 'src')

  const vueFiles = []
  const walk = (dir) => {
    for (const name of readdirSync(dir)) {
      const p = `${dir}/${name}`
      if (statSync(p).isDirectory()) walk(p)
      else if (name.endsWith('.vue')) vueFiles.push(p)
    }
  }
  walk(srcRoot)

  // 会显示藏品编号的页面，必须引 formatNo
  const mustUseFormatNo = [
    'views/user/Collections.vue',
    'views/CollectionDetail.vue',
    'views/Activity.vue',
    'views/Resale.vue',
    'views/ResaleOrder.vue',
    'views/user/Orders.vue',
    'views/user/Purchase.vue'
  ]

  it.each(mustUseFormatNo)('%s 通过 formatNo 显示编号', (rel) => {
    const text = readFileSync(`${srcRoot}/${rel}`, 'utf-8')
    expect(text).toContain("import { formatNo } from '@/utils/serial'")
    expect(text).toMatch(/formatNo\(/)
  })

  it('没有任何页面再手写「# + 编号」的拼接', () => {
    // 命中形如 #{{ o.no }} / #{{ serial }} 的模板，或 '编号 #' + x 这类字符串拼接。
    // 注意不牵连 '空投单号 #' + o.id：那显示的是订单 ID，不是藏品编号。
    const badTemplate = /#\{\{[^}]*?(?:\.no\b|serial|Serial)[^}]*?\}\}/
    const badConcat = /'[^']*编号 #' *\+/
    const hits = vueFiles
      .filter((f) => {
        const text = readFileSync(f, 'utf-8')
        return badTemplate.test(text) || badConcat.test(text)
      })
      .map((f) => f.replace(srcRoot, ''))
    expect(hits).toEqual([])
  })

  it('# 前缀只在 utils/serial 里定义一次', () => {
    const utilText = readFileSync(`${srcRoot}/utils/serial.js`, 'utf-8')
    // 模板字符串里出现 `#${no}`，加上注释中的示例，源码里 # 前缀的产出点只有一处
    expect(utilText.match(/return `#\$\{no\}`/g)?.length).toBe(1)
  })
})
