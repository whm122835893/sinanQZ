import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * 公告「敬语 + 落款署名」的后台入口（2026-10-14，RUNBOOK §7.37）
 *
 * 你要的口径：「每一条公告都要有敬语和署名，署名在发布公告时可改」，落在这儿是两处：
 *   1) 发布 / 编辑弹窗各加一格（留空 = 跟随全局默认，不是「不要这句」）；
 *   2) 全局默认两条文案进「系统 → 全局参数 → 内容与公告」，改完实时生效不用发版。
 *
 * 落款后面那个日期后台**没有口**：后端按该公告的实际生效时间自动带（AnnouncementSalutation::dateFor），
 * 所以下面有一条专门盯「弹窗里不能出现填日期的输入框」。
 *
 * 这个仓库不装 @vue/test-utils（见 airdrop-issue-gate.spec.js 的说明），所以走源码扫描；
 * 三端字面量的对齐由 sinan-nft-backend/tests/unit/AnnouncementSalutationTest.php 那份跨仓检查钉。
 */

const src = (rel) => readFileSync(resolve(process.cwd(), rel), 'utf8').replace(/\r\n/g, '\n');

describe('后台 · 公告敬语与落款入口', () => {
  const page = src('src/views/content/Announcements.vue');
  const api = src('src/api/index.js');
  const cfg = src('src/views/system/Config.vue');

  it('两个新参数都归进了「内容与公告」分组', () => {
    const group = cfg.match(/key: 'content'[\s\S]*?\]/);
    expect(group, 'GROUPS 里没有 content 分组').not.toBeNull();
    expect(group[0]).toContain("name: '内容与公告'");
    expect(group[0]).toContain('announcement_greeting_default');
    expect(group[0]).toContain('announcement_signature_default');
  });

  it('两个参数是普通单行文本框（不是多行、不是图片、不是布尔）', () => {
    for (const key of ['announcement_greeting_default', 'announcement_signature_default']) {
      const meta = cfg.match(new RegExp(`${key}:\\s*\\{([^}]*)\\}`));
      expect(meta, `缺 ${key} 的 META 条目：它会掉进「其他参数」，运营找不到`).not.toBeNull();
      expect(meta[1]).toMatch(/label: '/);
      expect(meta[1]).not.toContain('multiline: true');
      expect(meta[1]).not.toContain('image: true');
      expect(meta[1]).not.toContain('bool: true');
      // 描述里要写明「留空不是没有，而是回落到出厂值」，否则运营清空了会以为页面会变空
      expect(meta[1]).toContain('留空则用系统出厂值');
    }
  });

  it('发布弹窗两格都在：绑表单、占位符来自接口的全局默认', () => {
    for (const field of ['greeting', 'signature']) {
      expect(page, `弹窗缺 ${field} 输入框`).toContain(`v-model="form.${field}"`);
      expect(page, `${field} 的占位符应当用接口带下来的全局默认，写死一份就是第二处真相`)
        .toContain(`:placeholder="salutation.${field}"`);
      // 2026-10-14 第二轮口径：新建时这两格不是空串，而是预填「上一条公告实际用的那句」
      // （用户原话「第一次填写之后就保存，不用每次都填，除非手动更换」）。写回空串就是退回上一版。
      expect(page).toContain(`${field}: lastSalutation.value.${field} || ''`);
      expect(page).toContain(`${field}: a.${field} || ''`); // 编辑时回填
      expect(page).toContain(`${field}: f.${field}.trim()`); // 保存时提交
    }
  });

  it('落款日期在后台没有填写口，只在提示语里说明由系统带', () => {
    expect(page).not.toContain('v-model="form.signatureDate"');
    expect(page).toContain('日期由系统按这条公告的实际生效时间自动跟在名字后面');
  });

  it('弹窗留了两句指路话：默认会带上上一条用的、清空后回到全局默认、去哪改默认', () => {
    expect(page).toContain('上一条公告实际用的那句');
    expect(page).toContain('上一条公告实际用的名字');
    // 「清空 = 这条不再固定、改回跟随全局默认」这句必须留着，否则运营想换回默认时会以为清不掉
    expect(page).toContain('清空后保存 = 这条跟随全局默认');
    expect(page).toContain('清空后保存 = 这条的名字跟随全局默认');
    expect(page).toContain('全局参数');
    expect(page).toContain('不用发版');
  });

  it('api 层：读映射 + 写空串 + 把后端的 salutation 与 lastSalutation 都透出来', () => {
    for (const field of ['greeting', 'signature']) {
      expect(api).toContain(`${field}: s(a.${field})`);
      expect(api, `${field} 留空要传空串（后端把空串当「跟随全局默认」），别传 null 变成「不改这列」`)
        .toContain(`${field}: payload.${field} || ''`);
    }
    expect(api).toContain('salutation: d.salutation || {}');
    // 预填值也走同一个信封：前端自己去翻列表挑「最新一条」会踩上 is_top 排序的坑，见后端 lastUsed 的注释
    expect(api).toContain('lastSalutation: d.lastSalutation || {}');
    // 全局默认与四个默认头图同一次列表请求带回，运营只有内容权限时也拿得到（/system/configs 要 system:config）
    expect(api).toContain('covers: d.covers || {}');
  });

  it('占位符与预填值都来自列表接口那一次请求，不额外开接口', () => {
    const loader = page.match(/async function loadCovers\(\) \{([\s\S]*?)\n\}/);
    expect(loader, 'loadCovers 结构变了').not.toBeNull();
    expect(loader[1]).toContain('getAnnouncements({ page: 1, size: 1 })');
    expect(loader[1]).toContain('res.data?.salutation || {}');
    expect(loader[1]).toContain('res.data?.lastSalutation || {}');
    expect(page).toContain("const salutation = ref({})");
    expect(page).toContain("const lastSalutation = ref({})");
  });

  it('预填的三根线都在：声明 → 接口取值 → emptyForm 用它', () => {
    const empty = page.match(/function emptyForm\(\) \{([\s\S]*?)\n\}/);
    expect(empty, 'emptyForm 结构变了').not.toBeNull();
    for (const field of ['greeting', 'signature']) {
      expect(empty[1]).toContain(`${field}: lastSalutation.value.${field} || ''`);
    }
    // 预填取的是「上一条实际用的」，不是全局默认：拿成 defaults 就等于没实现「填一次一直沿用」
    expect(empty[1], '预填读成了全局默认，不是上一条实际用的值').not.toContain('salutation.value');
    // 顺序钉：emptyForm() 的初值会读 lastSalutation，const 有暂时性死区，声明排在 form 之后初次挂载就 ReferenceError
    // 先单独断言「找得到」再比大小：indexOf 找不到返回 -1，-1 < 任何下标都成立，那样这条会假绿
    const declPos = page.indexOf('const lastSalutation = ref({})');
    const formPos = page.indexOf('const form = ref(emptyForm())');
    expect(declPos, 'lastSalutation 没在 setup 顶层声明').toBeGreaterThanOrEqual(0);
    expect(formPos, 'form 的初值不是 emptyForm()').toBeGreaterThanOrEqual(0);
    expect(declPos).toBeLessThan(formPos);
  });

  it('保存成功后补刷一次：刚发的那条要立刻成为下一条的预填值', () => {
    const save = page.match(/async function onSave\(\) \{([\s\S]*?)\n\}/);
    expect(save, 'onSave 结构变了').not.toBeNull();
    // 行锚定而不是 toContain：把这行注释掉仍含「loadCovers()」子串，toContain 会假绿（本项目踩过）
    expect(save[1], '保存成功后没补刷预填值').toMatch(/^\s*loadCovers\(\)$/m);
  });
});

