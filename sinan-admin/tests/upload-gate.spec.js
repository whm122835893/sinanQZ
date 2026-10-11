import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const shared = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  del: vi.fn(),
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }
}))

vi.mock('@/utils/request', () => shared)

import { uploadImage } from '@/api'

/**
 * 图片上传体积闸门
 *
 * 口径来自「创建藏品」：前端选完图就拦在 5MB，文案 `图片大小不能超过 5MB`，
 * 与后端 UploadController::MAX_SIZE 同一个数。2026-10-11 你要求「需要上传图片的地方
 * 都改成和创建藏品的一样」，实测发现抽奖奖品图压根没有这句校验（大文件一路打到后端才拿
 * 4220），富文本编辑器的文案还差两个字。所以：
 *   - src/api/index.js 的 uploadImage 加了总闸，页面漏写也拦得住；
 *   - 最后一组用例逐个读七个调用方的源码，锁住「谁都不许漂走」。
 */
const MESSAGE = '图片大小不能超过 5MB'
const FIVE_MB = 5 * 1024 * 1024

const blobOf = (bytes) => new Blob([new Uint8Array(bytes)])

beforeEach(() => {
  shared.post.mockReset()
  shared.post.mockResolvedValue({ code: 0, message: 'ok', data: { url: '/uploads/collection/202610/a.png' } })
})

describe('uploadImage：API 层总闸', () => {
  it('超过 5MB 的图片不发请求，直接按视图层约定返回 code!==0 + 同一句文案', async () => {
    const res = await uploadImage(blobOf(FIVE_MB + 1), 'marketing')

    expect(shared.post).not.toHaveBeenCalled()
    expect(res.code).not.toBe(0)
    expect(res.message).toBe(MESSAGE)
    expect(res.data).toBeNull()
  })

  it('刚好 5MB 放行（与创建藏品用的 > 号一致，别让边界值被误拦）', async () => {
    await uploadImage(blobOf(FIVE_MB), 'collection')

    expect(shared.post).toHaveBeenCalledTimes(1)
    expect(shared.post.mock.calls[0][0]).toBe('/upload/image')
  })

  it('正常图片照旧带上 file 与 biz 走 /upload/image', async () => {
    const file = blobOf(2048)
    await uploadImage(file, 'artifact')

    const [, formData, options] = shared.post.mock.calls[0]
    expect(formData.get('biz')).toBe('artifact')
    expect(formData.get('file')).toBeTruthy()
    expect(options).toMatchObject({ silent: true })
  })
})

describe('七个上传入口与创建藏品同口径', () => {
  const sites = [
    ['创建藏品（基准）', '../src/views/collectible/Edit.vue'],
    ['文物展馆', '../src/views/content/Artifacts.vue'],
    ['首页轮播', '../src/views/content/Banners.vue'],
    ['页面装修', '../src/views/content/Decoration.vue'],
    ['富文本编辑器（公告/详情）', '../src/components/RichTextEditor.vue'],
    ['抽奖奖品图', '../src/views/marketing/LuckyDraw.vue'],
    // 2026-10-11 第三批：盲盒封面从「5 张硬编码预设图」改成上传，口径同步对齐
    ['盲盒封面图', '../src/views/blindbox/Edit.vue']
  ]

  sites.forEach(([label, path]) => {
    it(`${label}：既有 5MB 前置校验，又是同一句文案`, () => {
      const src = readFileSync(new URL(path, import.meta.url), 'utf8')

      expect(src).toMatch(/file\.size > 5 \* 1024 \* 1024/)
      expect(src).toContain(`'${MESSAGE}'`)
    });
  })

  // 2026-10-11 你报「创建藏品也上传不了图片了」：11 次失败全卡在后端内容检查上，
  // 而文件选择器当时只放 JPG/PNG/WEBP/GIF 四种 MIME —— iPhone 相册的照片（HEIC）连选都选不上，
  // 更谈不上让 api 层的总闸帮忙转格式。所以选择器放开成 image/*，格式判断交回总闸与后端。
  sites.forEach(([label, path]) => {
    it(`${label}：选择器不再替后端拒收格式（accept="image/*"）`, () => {
      const src = readFileSync(new URL(path, import.meta.url), 'utf8')

      expect(src).toContain('accept="image/*"')
      expect(src).not.toMatch(/accept="image\/(jpeg|png)/)
    })
  })

  // 提示语也要同一句：口径变了（HEIC 会自动转 JPG）却只改了创建藏品，其余六处就会继续误导运营
  const TIP = '支持 JPG / PNG / WEBP / GIF（HEIC 会自动转成 JPG），大小不超过 5MB'
  ;[['创建藏品（基准）', '../src/views/collectible/Edit.vue'],
    ['文物展馆', '../src/views/content/Artifacts.vue'],
    ['首页轮播', '../src/views/content/Banners.vue'],
    ['抽奖奖品图', '../src/views/marketing/LuckyDraw.vue'],
    ['盲盒封面图', '../src/views/blindbox/Edit.vue']
  ].forEach(([label, path]) => {
    it(`${label}：画面上的格式提示是同一句话`, () => {
      const src = readFileSync(new URL(path, import.meta.url), 'utf8')

      expect(src).toContain(TIP)
    })
  })
})

/**
 * uploadImage 的格式兜底：非四格式在浏览器里转成 JPEG 再传
 *
 * jsdom 没有 canvas 实现，所以这一组自己伪造一条最小画布（能出 Blob 就行），
 * 目的是锁住「谁该转、谁不该转、转不动时怎么收场」这三件事，不是验证像素。
 */
describe('uploadImage：非四格式自动转 JPEG', () => {
  const fileOf = (name, type, bytes = 2048) => new File([new Uint8Array(bytes)], name, { type })
  const sentFile = () => shared.post.mock.calls[0][1].get('file')

  let createElementSpy
  let bitmapSpy

  /** 伪造画布：getContext 给个空壳，toBlob 直接回一个 JPEG Blob */
  const stubCanvas = (blobFactory = () => new Blob([new Uint8Array(1024)], { type: 'image/jpeg' })) => {
    const real = document.createElement.bind(document)
    createElementSpy = vi.spyOn(document, 'createElement').mockImplementation((tag, ...rest) => {
      if (String(tag).toLowerCase() !== 'canvas') return real(tag, ...rest)
      return {
        width: 0,
        height: 0,
        getContext: () => ({ fillStyle: '', fillRect: () => {}, drawImage: () => {} }),
        toBlob: (cb, type, quality) => cb(blobFactory(type, quality))
      }
    })
  }

  const stubBitmap = (impl = async () => ({ width: 120, height: 80, close: () => {} })) => {
    bitmapSpy = vi.fn(impl)
    vi.stubGlobal('createImageBitmap', bitmapSpy)
  }

  afterEach(() => {
    createElementSpy?.mockRestore()
    vi.unstubAllGlobals()
  })

  it('HEIC 会被转成 JPEG 再发出去，原文件不落网', async () => {
    stubCanvas()
    stubBitmap()

    const res = await uploadImage(fileOf('IMG_0001.HEIC', 'image/heic'), 'collection')

    expect(res.code).toBe(0)
    expect(shared.post).toHaveBeenCalledTimes(1)
    expect(sentFile().type).toBe('image/jpeg')
    expect(sentFile().name).toMatch(/\.jpg$/i)
    expect(sentFile().name).toBe('IMG_0001.jpg')
  })

  it('按 EXIF 方向摆正：转码时必须带 imageOrientation', async () => {
    stubCanvas()
    const source = fileOf('IMG_0002.HEIC', 'image/heif')
    stubBitmap()

    await uploadImage(source, 'collection')

    expect(bitmapSpy).toHaveBeenCalledWith(source, { imageOrientation: 'from-image' })
  })

  it('JPG / PNG / WEBP / GIF 原样透传（PNG 的透明、GIF 的动画不许被顺手抹掉）', async () => {
    stubCanvas()
    stubBitmap()

    const keep = [
      ['cover.jpg', 'image/jpeg'],
      ['cover.png', 'image/png'],
      ['cover.webp', 'image/webp'],
      ['loop.gif', 'image/gif']
    ]
    for (const [name, type] of keep) {
      shared.post.mockClear()
      const source = fileOf(name, type)
      await uploadImage(source, 'misc')

      expect(sentFile()).toBe(source)
      expect(bitmapSpy).not.toHaveBeenCalled()
    }
  })

  it('BMP/TIFF 这类「能显示但后端不收」的格式也走转码', async () => {
    stubCanvas()
    stubBitmap()

    await uploadImage(fileOf('scan.tiff', 'image/tiff'), 'artifact')

    expect(sentFile().type).toBe('image/jpeg')
  })

  it('类型与文件名都读不出来时照旧原样上传，不比今天差', async () => {
    stubCanvas()
    stubBitmap()

    const blind = new Blob([new Uint8Array(512)])
    await uploadImage(blind, 'collection')

    expect(shared.post).toHaveBeenCalledTimes(1)
    // FormData 会把裸 Blob 包成 File（jsdom 也这么干），所以比内容不比对象身份
    expect(sentFile().size).toBe(blind.size)
    expect(sentFile().type).toBe('')
    expect(bitmapSpy).not.toHaveBeenCalled()
  })

  it('浏览器解不开这张图（例如没有 HEIC 解码器）：不发请求，给一句能照着做完就的话', async () => {
    stubCanvas()
    stubBitmap(async () => { throw new Error('EncodingError') })

    const res = await uploadImage(fileOf('IMG_0003.HEIC', 'image/heic'), 'collection')

    expect(shared.post).not.toHaveBeenCalled()
    expect(res.code).not.toBe(0)
    expect(res.message).toContain('HEIC')
    expect(res.message).toContain('截图')
    expect(res.message).toContain('兼容性最好')
  })

  it('环境根本没有 canvas / createImageBitmap：同样不发请求，也不装作传成功了', async () => {
    vi.stubGlobal('createImageBitmap', undefined)

    const res = await uploadImage(fileOf('IMG_0004.HEIC', 'image/heic'), 'collection')

    expect(shared.post).not.toHaveBeenCalled()
    expect(res.code).not.toBe(0)
  })

  it('转完仍然超过 5MB：还是同一句话拦下，闸门不许因为转码而变松', async () => {
    stubCanvas(() => new Blob([new Uint8Array(FIVE_MB + 1)], { type: 'image/jpeg' }))
    stubBitmap()

    const res = await uploadImage(fileOf('big.HEIC', 'image/heic'), 'collection')

    expect(shared.post).not.toHaveBeenCalled()
    expect(res.message).toBe(MESSAGE)
  })

  it('原始文件就超 5MB 时先按体积拦，不做无谓的转码', async () => {
    stubCanvas()
    stubBitmap()

    const res = await uploadImage(fileOf('huge.HEIC', 'image/heic', FIVE_MB + 1), 'collection')

    expect(res.message).toBe(MESSAGE)
    expect(bitmapSpy).not.toHaveBeenCalled()
  })

  it('JPEG 质量固定，别让人随手调成 1.0 把体积闸顶爆', async () => {
    let usedQuality = 0
    stubCanvas((_type, quality) => {
      usedQuality = quality
      return new Blob([new Uint8Array(1024)], { type: 'image/jpeg' })
    })
    stubBitmap()

    await uploadImage(fileOf('IMG_0005.HEIC', 'image/heic'), 'collection')

    expect(usedQuality).toBeGreaterThan(0)
    expect(usedQuality).toBeLessThanOrEqual(0.95)
  })
})

/**
 * 上传单独放宽超时（RUNBOOK 7.20 S2）
 *
 * 生产出口带宽 1Mbps ≈ 128KB/s，而 axios 实例是全局 `timeout: 20000`：20 秒最多推约 2.5MB。
 * 2026-10-12 实测生产在用的那张藏品图就有 2.4MB —— 传一张 4MB 的图会失败，
 * 报的还是含糊的超时，不是「图太大」。所以只给上传这一类请求放宽到 120 秒（与后端
 * proxy_read_timeout / fastcgi_read_timeout 对齐），**5MB 闸门与其余接口的 20 秒都不许跟着动**。
 */
describe('uploadImage：上传单独放宽超时', () => {
  it('发请求时带上 120 秒 timeout（与 silent 一起，走同一个总闸出口）', async () => {
    await uploadImage(blobOf(2048), 'collection')

    expect(shared.post.mock.calls[0][2]).toMatchObject({ silent: true, timeout: 120000 })
  })

  it('放宽的是「等多久」，不是「能传多大」：超 5MB 照旧不发请求', async () => {
    const res = await uploadImage(blobOf(FIVE_MB + 1), 'collection')

    expect(shared.post).not.toHaveBeenCalled()
    expect(res.message).toBe(MESSAGE)
  })

  it('request.js 把 opts.timeout 透给 axios，实例默认仍是 20 秒（别把全站接口都放宽）', () => {
    // 路径必须走变量：写成字面量时 Vite 会把 new URL('...', import.meta.url) 编译成资源 URL
    // （http://localhost:3000/...），readFileSync 当场报「The URL must be of scheme file」。
    const target = '../src/utils/request.js'
    const src = readFileSync(new URL(target, import.meta.url), 'utf8')

    expect(src).toMatch(/timeout:\s*20000/)
    expect(src).toMatch(/\.\.\.\(opts\.timeout \? \{ timeout: opts\.timeout \} : \{\}\)/)
  })
})

