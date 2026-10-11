import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * 后台两处「口径已经改到后端，表单别再自己算」（2026-10-11 第三批）
 *
 *   1. 抽签购：需求定的是「中签后 5 分钟才显示购买按钮」，
 *      旧表单却让运营填「中签有效期 起止」，还硬编码默认「抽签时间 + 24 小时」。
 *      purchase_start 留空会被 MySQL 写成 0000-00-00，把窗口永久锁死，
 *      用户那单「中签了却没有购买按钮」就是这么来的。
 *      现在开放购买时刻由后端 RaffleService::purchaseGate 统一算，
 *      表单只剩一个选填的「购买截止」。
 *
 *   2. 盲盒封面：原来是 5 张硬编码预设图挑一张，运营想换自己图做不到。
 *      改成上传（口径对齐「创建藏品」：5MB、四种格式、同一句文案）。
 *      不加列 —— 盲盒 1:1 绑一条 collectibles，封面就是那条藏品的 image。
 *
 * 这个仓库没有 @vue/test-utils，所以用源码扫描钉住"字段真的从表单里退了、
 * 上传真的接上了"，避免改到一半留个既能填又不生效的假字段。
 */

const read = (rel) => readFileSync(resolve(process.cwd(), rel), 'utf8')

describe('RaffleEdit.vue：购买窗口只留选填截止', () => {
  const src = () => read('src/views/marketing/RaffleEdit.vue')

  it('purchaseStart 这个字段已经从表单里退干净', () => {
    const s = src()
    expect(s).not.toMatch(/purchaseStart\s:/)
    expect(s).not.toMatch(/v-model="form\.purchaseStart"/)
    expect(s).not.toMatch(/res\.purchaseStart/)
    expect(s).toMatch(/购买窗口不再有 purchaseStart/)
  })

  it('换成固定口径展示：中签后 5 分钟，且标明不可配置', () => {
    const s = src()
    expect(s).toMatch(/开放购买/)
    expect(s).toMatch(/中签后 5 分钟/)
    expect(s).toMatch(/不可配置/)
    // 5 分钟 = 300 秒，与后端 PURCHASE_DELAY_SECONDS 同口径
    expect(s).toMatch(/const PURCHASE_DELAY_SECONDS = 300/)
  })

  it('硬编码的 24 小时默认有效期已下线，截止默认留空 = 不限', () => {
    const s = src()
    expect(s).not.toMatch(/24 \* 3600 \* 1000\)\s*\n\s*form\.value\.purchaseEnd/)
    expect(s).toMatch(/form\.value\.purchaseEnd = ''/)
    expect(s).toMatch(/购买截止/)
    expect(s).toMatch(/留空 = 不限/)
  })

  it('截止文案说清"填早了不生效"，别再让人配出死窗口', () => {
    expect(src()).toMatch(/早于|等于开放时刻|无效/)
  })
})

describe('blindbox/Edit.vue：封面改为上传', () => {
  const src = () => read('src/views/blindbox/Edit.vue')

  it('5 张硬编码预设图已下线', () => {
    const s = src()
    expect(s).not.toMatch(/coverOptions/)
    expect(s).not.toMatch(/\/images\/collections\/cover/)
    expect(s).toMatch(/cover: ''/)
  })

  it('走统一上传通道，biz=blindbox（后端按 biz 分目录，不加新列）', () => {
    const s = src()
    expect(s).toMatch(/import \{[^}]*uploadImage[^}]*\} from '@\/api'/)
    expect(s).toMatch(/uploadImage\(file, 'blindbox'\)/)
    expect(s).toMatch(/<el-upload/)
    // 2026-10-11 晚间口径调整：选择器放开成 image/*，格式判断交回 api 层总闸（HEIC 自动转 JPEG）与后端白名单。
    // 原先这里锁的是四种 MIME 的列表 —— 那会让 iPhone 相册的照片连选都选不上。
    expect(s).toMatch(/accept="image\/\*"/)
  })

  it('体积闸门与「创建藏品」同一句文案', () => {
    const s = src()
    expect(s).toMatch(/file\.size > 5 \* 1024 \* 1024/)
    expect(s).toContain("'图片大小不能超过 5MB'")
  })

  it('封面变成必填：后端创建盲盒时 image 是必需参数', () => {
    const s = src()
    expect(s).toMatch(/cover: \[\{ required: true, message: '请上传盲盒封面图'/)
    expect(s).toMatch(/prop="cover"/)
  })

  it('上传成功后回填 cover 并清掉校验红字', () => {
    const s = src()
    expect(s).toMatch(/form\.value\.cover = res\.data\.url/)
    expect(s).toMatch(/clearValidate\('cover'\)/)
  })
})
