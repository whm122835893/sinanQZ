import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * 「弹窗点得出、看不见」的防漂移测试（2026-10-12）
 *
 * 你报的 bug：后台右上角点「修改密码」，页面什么都不弹。
 * 根因跟后端、跟点击逻辑都没关系 —— AppHeader.vue 里那枚 el-dialog 就写在一个
 * 带 `backdrop-filter: blur(10px)` 的 .app-header 里面。
 *
 * 大白话：CSS 有条规矩 —— 祖先一旦加了 backdrop-filter / filter / transform 这类
 * 「视觉特效」，它就从「透明的观众」变成「舞台盒子」。弹窗内部是 position:fixed，
 * 本来该按浏览器窗口铺满，可祖先一变盒子，它只能在这个盒子里铺，
 * 而 .app-header 高 56px —— 弹窗被压成头上一条 56px 的缝，等于看不见。
 * （实测：同一个 fixed 子元素在带 blur 的 header 里量到 515×56，去掉 blur 后 515×545。）
 *
 * Element Plus 的 `append-to-body` 就是把弹窗从原地搬去 <body> 底下，跳出这个盒子。
 * 仓库里 PasswordVerify.vue、盲盒/藏品详情页早就是这么写的，只有 AppHeader 这枚漏了。
 * 注意这条规矩不是「所有弹窗都得加」：login/Index.vue 的验证码弹窗不在带 blur 的
 * .login__card 里面，就不需要加。所以要按「有没有被特效祖先包住」来判断，
 * 本文件就是这条规矩的机器版。
 *
 * 这个仓库不装 @vue/test-utils（见 airdrop-issue-gate.spec.js 的说明），
 * 所以用源码扫描：先从 style 里拿到「特效类名」，再顺着 template 的标签嵌套栈
 * 判断每枚 el-dialog / el-drawer 是否被特效祖先包住。
 * 倒数第三组用例拿一段合成源码当正例，钉住扫描器本身没瞎。
 */

const src = (rel) => readFileSync(resolve(process.cwd(), rel), 'utf8');

/** 会把 position:fixed 的「包含块」从浏览器窗口手里抢过去的属性 */
const DANGER_PROPS =
  /(?:^|[;{\s])(?:[-a-z]*-)?backdrop-filter\s*:|(?:^|[;{\s])filter\s*:|(?:^|[;{\s])(?:transform|perspective|will-change|contain)\s*:/;

/** 样式里带特效的类名 = 它们一旦包住弹窗，弹窗就被困住 */
function dangerClasses(styleSrc) {
  const found = new Set();
  for (const rule of styleSrc.match(/[^{}]+\{[^{}]*\}/g) ?? []) {
    const braceAt = rule.indexOf('{');
    const selector = rule.slice(0, braceAt);
    const body = rule.slice(braceAt);
    if (!DANGER_PROPS.test(body) && !/@include\s+glass\b/.test(body)) continue;
    for (const m of selector.matchAll(/\.([\w-]+)/g)) found.add(m[1]);
  }
  return found;
}

const VOID_TAGS = new Set(['img', 'input', 'br', 'hr', 'meta', 'link']);
const OVERLAYS = new Set(['el-dialog', 'el-drawer']);

/** 顺着 template 的嵌套栈找出「被特效祖先包住又没搬去 body」的弹窗 */
function trappedOverlays(templateSrc, classes) {
  const stack = [];
  const trapped = [];
  const tagRe = /<(\/?)([a-zA-Z][\w-]*)((?:"[^"]*"|'[^']*'|[^>])*)>/g;
  for (const match of templateSrc.matchAll(tagRe)) {
    const [, closing, tag, attrs] = match;
    if (closing) {
      for (let i = stack.length - 1; i >= 0; i -= 1) {
        if (stack[i].tag === tag) {
          stack.length = i;
          break;
        }
      }
      continue;
    }
    if (OVERLAYS.has(tag)) {
      const blockedBy = stack.filter((a) => a.classes.some((c) => classes.has(c)));
      if (blockedBy.length && !/\bappend-to-body\b/.test(attrs)) {
        trapped.push({
          component: tag,
          title: (attrs.match(/title="([^"]*)"/) ?? [])[1] ?? '(无 title)',
          ancestors: blockedBy.flatMap((a) => a.classes.filter((c) => classes.has(c))),
        });
      }
      continue;
    }
    if (VOID_TAGS.has(tag) || /\/\s*$/.test(attrs)) continue;
    const classAttr = (attrs.match(/class="([^"]*)"/) ?? [])[1] ?? '';
    stack.push({ tag, classes: classAttr.split(/\s+/).filter(Boolean) });
  }
  return trapped;
}

/** 单文件口径：scoped 样式只影响自己文件里的类名 */
function scanFile(rel) {
  const text = src(rel);
  const styles = [...text.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]).join('\n');
  const template = (text.match(/<template>([\s\S]*)<\/template>/) ?? [])[1] ?? '';
  return trappedOverlays(template, dangerClasses(styles));
}

function listVueFiles(dir = resolve(process.cwd(), 'src'), rel = 'src') {
  const out = [];
  for (const name of readdirSync(join(dir, ''))) {
    const abs = join(dir, name);
    const key = rel ? `${rel}/${name}` : name;
    if (statSync(abs).isDirectory()) out.push(...listVueFiles(abs, key));
    else if (abs.endsWith('.vue')) out.push(key);
  }
  return out;
}

describe('特效祖先里的弹窗必须 append-to-body（否则点了没有页面）', () => {
  it('扫描器本身没瞎：被 blur 祖先包住的弹窗要被抓出来，没包住/已搬走的要放过', () => {
    const classes = dangerClasses('.shell { backdrop-filter: blur(10px); }');
    expect(classes.has('shell')).toBe(true);

    expect(trappedOverlays('<div class="shell"><el-dialog title="X"></el-dialog></div>', classes)).toHaveLength(1);
    expect(
      trappedOverlays('<div class="shell"><el-dialog append-to-body title="X"></el-dialog></div>', classes)
    ).toHaveLength(0);
    // 中间隔几层普通 div 也算被包住（特效祖先是「往上找」，不看邻座）
    expect(
      trappedOverlays('<div class="shell"><div class="plain"><el-dialog title="X"></el-dialog></div></div>', classes)
    ).toHaveLength(1);
    // 祖先干净 / 只是同级有特效 → 不该误报
    expect(trappedOverlays('<div class="plain"><el-dialog title="X"></el-dialog></div>', classes)).toHaveLength(0);
    expect(
      trappedOverlays('<div class="shell"></div><div class="plain"><el-dialog title="X"></el-dialog></div>', classes)
    ).toHaveLength(0);
    expect(
      trappedOverlays('<img class="shell" /><div class="shell"><el-drawer></el-drawer></div>', classes).map((t) => [
        t.component,
        t.ancestors.join('.'),
      ])
    ).toEqual([['el-drawer', 'shell']]);
  });

  it('全仓库 src/**/*.vue 没有一枚被困住却没搬去 body 的弹窗/抽屉', () => {
    const files = listVueFiles();
    expect(files.length).toBeGreaterThan(50);
    const offenders = files.flatMap((rel) =>
      scanFile(rel).map((t) => `${rel} <${t.component} title="${t.title}"> 被 .${t.ancestors.join('/.')} 困住`)
    );
    expect(offenders).toEqual([]);
  });

  it('AppHeader 的「修改登录密码」已挂 append-to-body，且留了解释', () => {
    const s = src('src/layouts/components/AppHeader.vue');
    const dialog = s.match(/<el-dialog[\s\S]*?>\s*<el-form/)?.[0] ?? '';
    expect(dialog).toMatch(/title="修改登录密码"/);
    expect(dialog).toMatch(/\bappend-to-body\b/);
    expect(s).toMatch(/backdrop-filter/);
    expect(s).toMatch(/包含块/);
  });

  it('登录页的验证码弹窗不吃这个亏：它不在带 blur 的 .login__card 里', () => {
    const s = src('src/views/login/Index.vue');
    const card = s.slice(s.indexOf('<div class="login__card"'), s.indexOf('<!-- local graphic 弹窗 -->'));
    expect(card.length).toBeGreaterThan(10);
    expect(card).not.toMatch(/<el-dialog/);
    expect(s).toMatch(/backdrop-filter: blur\(6px\)/);
  });

  it('组件内嵌弹窗的先例（PasswordVerify）仍带 append-to-body', () => {
    // 它自己被用在各种带特效的卡片里，祖先特效由使用方决定，所以必须一直搬去 body
    expect(src('src/components/PasswordVerify.vue')).toMatch(/<el-dialog[\s\S]{0,200}append-to-body/);
  });
});
