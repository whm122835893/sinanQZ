import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * 公告《风险提示》的后台入口（2026-10-14，RUNBOOK §7.32）
 *
 * 你要的口径：文案放后台可改、实时生效不用发版。落地在「系统 → 全局参数 → 内容与公告」：
 *   announcement_disclaimer_enabled  —— 布尔开关（关掉 = 完全恢复改造前）
 *   announcement_disclaimer          —— 多行文本框，一整段声明
 *
 * 这个仓库不装 @vue/test-utils（见 airdrop-issue-gate.spec.js 的说明），所以走源码扫描。
 * 盯的是三件容易悄悄退化的事：
 *   1) 两个键都进了分组 —— 没进 GROUPS 的键会掉到「其他参数」，按原始键名 + 160px 单行框渲染，
 *      一整段声明挤在小框里等于改不动；
 *   2) 文案键走 textarea 分支，而不是掉进最后的单行 el-input；
 *   3) 公告编辑页留了一句指路话，运营不用先读文档才知道提示是哪来的、去哪改。
 */

const src = (rel) => readFileSync(resolve(process.cwd(), rel), 'utf8').replace(/\r\n/g, '\n');

describe('全局参数 · 公告风险提示入口', () => {
  const cfg = src('src/views/system/Config.vue');

  it('两个键都归进了「内容与公告」分组', () => {
    const group = cfg.match(/key: 'content'[\s\S]*?\]/);
    expect(group, 'GROUPS 里没有 content 分组').not.toBeNull();
    expect(group[0]).toContain('announcement_disclaimer_enabled');
    expect(group[0]).toContain('announcement_disclaimer');
    expect(group[0]).toContain("name: '内容与公告'");
  });

  it('开关是布尔、文案是多行框', () => {
    const boolMeta = cfg.match(/announcement_disclaimer_enabled:\s*\{([^}]*)\}/);
    expect(boolMeta, '缺 announcement_disclaimer_enabled 的 META 条目').not.toBeNull();
    expect(boolMeta[1]).toContain('bool: true');

    const textMeta = cfg.match(/announcement_disclaimer:\s*\{([^}]*)\}/);
    expect(textMeta, '缺 announcement_disclaimer 的 META 条目').not.toBeNull();
    expect(textMeta[1]).toContain('multiline: true');
    expect(textMeta[1]).not.toContain('json: true');
  });

  it('textarea 分支排在兜底的单行输入框之前', () => {
    const at = cfg.indexOf('v-else-if="META[cfg.key]?.multiline"');
    // 兜底分支的认人方式：那枚固定 160px 的单行框。（顶层还有一个 <template v-else> 是给
    // el-skeleton 收尾用的，不能拿它当参照物 —— 第一版就是误把它当成了兜底分支。）
    const fallback = cfg.indexOf('<el-input v-model="cfg.value" style="width: 160px" />');
    expect(at).toBeGreaterThan(-1);
    expect(fallback).toBeGreaterThan(at);
    expect(cfg.slice(at, at + 400)).toContain('type="textarea"');
  });

  it('长文案不参与 JSON 校验（否则一整段中文会被判「值需为合法 JSON」）', () => {
    const onSave = cfg.match(/async function onSave\(cfg\) \{([\s\S]*?)\n\}/);
    expect(onSave, 'onSave 结构变了').not.toBeNull();
    expect(onSave[1]).toMatch(/if \(META\[cfg\.key\]\?\.json\)/);
    expect(onSave[1]).not.toContain('multiline');
  });

  it('公告编辑页告诉运营提示从哪来、去哪改', () => {
    const page = src('src/views/content/Announcements.vue');
    expect(page).toContain('风险提示');
    expect(page).toContain('全局参数');
    expect(page).toContain('不用发版');
  });
});
