import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * 后台「图片清理」页的保护口径提示（RUNBOOK 7.20 S3）
 *
 * 体检发现两处会把在用的图判成「零引用」：公告富文本正文里的 <img> 没被算进引用，
 * 而「先传图、后点保存」那段时间新图也没有任何宽限期。后端把两条判定收进了
 * UploadCleanupService::protectedReason() 一个闸门，这里锁的是**页面上得说实话**：
 * 受保护的张数要显示（否则列表空着会被读成「真的没垃圾」），
 * 小时数与排除目录必须读接口下发（后端改 24 小时，这句话不能变成假话）。
 */
const read = (target) => readFileSync(new URL(target, import.meta.url), 'utf8')

describe('图片清理页：受保护文件要显式说明', () => {
  const vue = read('../src/views/system/ImageCleanup.vue')

  it('提示语说明两类不参与清理的图，且小时数来自接口', () => {
    expect(vue).toContain('不参与这里的清理')
    expect(vue).toContain('protectedInfo.graceHours')
    expect(vue).toContain('protectedInfo.excludedBiz')
  })

  it('受保护张数进工具栏：列表少了几张要能解释', () => {
    expect(vue).toContain('protectedInfo.count')
    expect(vue).toContain('受保护未列出')
  })

  it('口径数字由接口下发，页面默认值只做兜底（不许反过来当唯一来源）', () => {
    expect(vue).toMatch(/p\.graceHours\s*\?\?\s*24/)
    expect(vue).toMatch(/p\.excludedBiz/)
  })

  it('默认筛选仍然不带 announcement：BIZ_OPTIONS 里没有它', () => {
    expect(vue).toMatch(/const BIZ_OPTIONS = \[[^\]]*\]/)
    expect(vue).not.toMatch(/const BIZ_OPTIONS = \[[^\]]*announcement[^\]]*\]/)
  })
})

describe('后端闸门与前端提示同一套话术', () => {
  it('后端受保护原因里带「不参与自动清理」和「上传不足」，与页面解释一致', () => {
    const target = '../../sinan-nft-backend/app/admin/service/UploadCleanupService.php'
    let src
    try {
      src = read(target)
    } catch {
      // 只 checkout 了 sinan-admin 时跳过：这条是跨仓库锁，不是本地必备依赖
      return;
    }
    expect(src).toContain('不参与自动清理')
    expect(src).toContain('上传不足')
    expect(src).toContain('private const NEW_UPLOAD_GRACE_HOURS = 24')
  })

  it('接口把宽限期与排除目录带给前端', () => {
    const target = '../../sinan-nft-backend/app/admin/controller/UploadCleanupController.php'
    let src
    try {
      src = read(target)
    } catch {
      return;
    }
    expect(src).toContain('graceHours')
    expect(src).toContain('excludedBizDirs')
    expect(src).toContain('受保护')
  })
})
