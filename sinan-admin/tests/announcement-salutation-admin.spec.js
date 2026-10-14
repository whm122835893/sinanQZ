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
      expect(page).toContain(`${field}: ''`);            // emptyForm 里有这一格
      expect(page).toContain(`${field}: a.${field} || ''`); // 编辑时回填
      expect(page).toContain(`${field}: f.${field}.trim()`); // 保存时提交
    }
  });

  it('落款日期在后台没有填写口，只在提示语里说明由系统带', () => {
    expect(page).not.toContain('v-model="form.signatureDate"');
    expect(page).toContain('日期由系统按这条公告的实际生效时间自动跟在名字后面');
  });

  it('弹窗留了两句指路话：留空会用哪句、去哪改默认', () => {
    expect(page).toContain('留空 = 用这句');
    expect(page).toContain('留空 = 名字用全局默认');
    expect(page).toContain('全局参数');
    expect(page).toContain('不用发版');
  });

  it('api 层：读映射 + 写空串 + 把后端的 salutation 透出来', () => {
    for (const field of ['greeting', 'signature']) {
      expect(api).toContain(`${field}: s(a.${field})`);
      expect(api, `${field} 留空要传空串（后端把空串当「跟随全局默认」），别传 null 变成「不改这列」`)
        .toContain(`${field}: payload.${field} || ''`);
    }
    expect(api).toContain('salutation: d.salutation || {}');
    // 全局默认与四个默认头图同一次列表请求带回，运营只有内容权限时也拿得到（/system/configs 要 system:config）
    expect(api).toContain('covers: d.covers || {}');
  });

  it('占位符的数据来自列表接口那一次请求，不额外开接口', () => {
    const loader = page.match(/async function loadCovers\(\) \{([\s\S]*?)\n\}/);
    expect(loader, 'loadCovers 结构变了').not.toBeNull();
    expect(loader[1]).toContain('getAnnouncements({ page: 1, size: 1 })');
    expect(loader[1]).toContain('res.data?.salutation || {}');
    expect(page).toContain("const salutation = ref({})");
  });
});
