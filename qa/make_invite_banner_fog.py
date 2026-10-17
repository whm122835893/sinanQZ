#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
把 AI 出的原图裁成「我的」页邀新横条的底图，并**当场把白字 / 透明灰按钮的对比度量出来**。
（§7.49 起这张脚本管的是「雾中逆光」鼎 = v5，§7.50 是「青铜爵·晨雾」= v6，
 §7.51 是「水墨写意·爵」= v7。**同一张脚本换图不换纪律**，下面的条目 1/2/4 都带各批实测数。）

为什么又要这么一张脚本（§7.48 的 `make_exhibit_header_bronze.py` 是同一套纪律，几何完全不同）：
  1. **卡片只有 76 CSS px 高**，比文物头图（150）还窄得多，所以"从原图哪一条带里取"直接决定
     文字底下压着什么。C/v5 那张的**太阳和亮雾在画面上半**（实测 y<360 平均亮度 90~102、p90 到 231），
     取带取高了白字就压在雾上 —— 那正是 §7.44 里"深红线对磨砂板只有 1.0~1.6:1 = 看不见"的成因。
  2. **角标水印**每张都要**当场量位置**，别沿用上一批的 `--wm-top`。实测：C（v5）首行 1025、
     D（爵，v6）首行 1021、F3（水墨写意，v7）首行 **1037**、列 2378..2528。
     台账 §7.48 里已经写明 `watermark_band()` 那个"亮且中性"的判据**对这类图不灵**
     （雾把水印染成暖灰，饱和度判据过不去，实测只报 y=989/990 两行，全是湿地反光的误报），
     所以这里的位置是**肉眼 + 局部亮度扫描**定的。v7 选的带 260:828 下界远在 1037 之上，
     **水印根本不进画面**，所以这一批不需要 §7.48 那种抹水印的裁掉一档 —— 但带下界安全线仍留着，
     免得下次换带把水印拖进来。
  3. **按钮这块是"合成后再量"**。它不压在裸图上，而是压在 `rgba(46,46,52,α)` 这块透明灰板上，
     白字对比度是"白 vs 灰板和底图合成出来的那个颜色"。α 一低，底图的亮斑就顶上来 ——
     §7.43 量的 α=0.62→4.98:1 是**上一张海报**的数，换图之后必须重跑，不能沿用。
  4. **这张**不**需要罩**（§7.51 的结论，也是本脚本默认值翻转的原因）。
     历史：§7.49 的「雾中逆光」鼎、§7.50 的「青铜爵·晨雾」都**必须有罩** —— 那两张的亮部
     是一大片弥散的雾，而卡片**没有任何横向裁剪手段**（`center/cover` 下整条 2560 宽的图
     正好压满卡片宽，只有纵向带能选），主体只要落在源列 2%%..73.3%%（文字区）里，
     那一片亮雾就一定压在字底下，只能靠 CSS 的 `::before` 深色渐变把它压回去（当时扫 15 档带
     「没有任何一条能同时让两行字达标」，最坏 1.89%%..3.40%%）。
     §7.51 换成水墨写意、主体收到**右侧**之后，文字区底下是干净的浅灰纸，**裸图就过**：
     带顶 160..440 那 15 档**每一档四块全过**（裸图最差的也还有 标题 15.05 / 副标 7.77 / 按钮 15.21 /
     图标 3.89），所以带**不是按对比度选的，是按观感 + 取四块都好的一档**：选定 **200:768**
     （裸图 标题 18.20 / 副标 13.16 / 按钮 15.21 / 图标 9.52 —— 15 档里唯一四块同时最好的那一档）。
     所以用户 2026-10-18 点头把罩摘掉。⇒ 本脚本罩**默认关**，`--with-scrim` 才是反证档。
     ⚠️ 就算某张图需要罩，罩也**不许烤进资产**：落盘的 webp 永远是裸图，量的时候由本脚本合成，
     否则换罩的 α 就得重出图。`render_card()` 返回裸图，`apply_scrim()` 只给测量用。
  5. **落位门槛**（§7.51 新增，写死在 `main()` 里）：一次跑批要**同时**满足
     ① 本档四块达 WCAG（3.0/4.5/4.5/3.0）；② 旧色三档**全红**（量具有红）；
     ③ `--control` 那张**上一批没过**的图，裸图拿同一把尺量**仍然不过**（证明赢的是构图，不是尺子松了）。
     三条不齐就 `exit 2`，不落盘。`--check-only` 只跑门槛不写文件；`--overwrite` 不传就不许盖已有资产。

     摘罩的**代价要写明白**：罩本来还顺手把两行字的**右界**往外推。裸图（v7 落盘字节实测）
     标题第一个跌破 3.0 的列在 **x=206**、副标跌破 4.5 的列在 **x=220** ⇒ 文案上限收到
     **标题 8 字 / 副标 12 字**（现在 7 字 / 12 字，副标正好贴线、再写一个字就出问题）。
     叠回 §7.50 那层罩能把右界推到 247/228。这条线由 `--right-edge` 现量，spec 里有对应钉。

口径与 §7.48 一致：这里量的是「设计值 / 裸板最坏点」（不含 text-shadow 的增益），
真机那两套读数（底板 = 藏字截图、笔画核心 = 差值 ≥0.8×最大差值）由 `E:/tmp/v49` 的 CDP 脚本复量，
spec 用 md5 把数和图绑在一起。

裁法：按行裁带 → 等比缩到 1280 宽。1280×284 不是随手取的 —— 上一张 `invite-banner-bg-v4.webp`
就是 1280×284，卡片 343×76 的宽高比 4.513，图 4.507，`center/cover` 上下只裁掉 0.1 CSS px，
等于整条带都会露出来。换带高就得同时换目标高，否则 cover 会自己再裁一刀，量的几何就不是量的几何了。

原图**不入库**（仓库只存发布资产）。要重做就得用 RUNBOOK §7.48 台账里记的那条 prompt 重新生成一张。

用法：
  python qa/make_invite_banner_fog.py <raw.png> [--out DIR] [--rows 200:768] [--width 1280]
                                      [--quality 82] [--no-write] [--alpha-sweep]
                                      [--with-scrim] [--right-edge] [--sweep-bands]
"""
import argparse
import hashlib
import os
import re
import sys

from PIL import Image

DEFAULT_OUT = os.path.join("sinan-art-source", "public", "images")
STEM = "invite-banner-bg-v7"   # §7.51 起 = 水墨写意的爵；v6（青铜爵·晨雾）**没提交就被推翻了**，v5 是雾中逆光的鼎。**别拿默认值覆盖上一批的成品**

# ---- 与 User.vue `.invite-banner` 对齐的渲染常量（改了 CSS 必须同步这里，spec 有钉）----
# 设计盒 343x76 = 375 视口 - 左右 margin 16，高 = padding 16*2 + 图标位 44。
# postcss px→vw（viewportWidth 375）把整块等比缩放，所以卡片宽高比在任何视口都是 4.513，
# 这条带子里"显示出来的就是整张图"这件事与视口无关。
CW, CH = 343.0, 76.0       # 卡片内容盒（CSS px，设计档 375 视口）
DSF = 3                    # 按 3 倍图量（最坏的现实像素密度）
PAD = 16.0
ICON = 44.0
TEXT_X = PAD + ICON + 12   # = 72，spec 里钉死的文字起点
TITLE_PX, SUB_PX = 15, 12  # 字号
TITLE_LS, SUB_LS = 0.5, 0.0   # 标题有 letter-spacing:0.5px，副标没写 ⇒ 0
LINE_HEIGHT = 1.5
SUB_GAP = 4               # `&__text { gap: 4px }`：两行之间那 4px 也算在文字块里
TITLE_TEXT = "司南·邀新玩法"        # 7 字
SUB_TEXT = "同游作伴，潮流好礼不设限"  # 12 字
WHITE = (255, 255, 255)
SUB_ALPHA = 0.85
BTN_RGB = (46, 46, 52)     # 透明灰板的本色
BTN_ALPHA = 0.62           # 现在 CSS 里写的值
BTN_FONT, BTN_PAD_X, BTN_PAD_Y, BTN_TEXT = 14, 17, 7, "邀请"
ICON_PLATE_ALPHA = 0.16    # 图标那块底板 rgba(255,255,255,.16)
# 左侧深色渐隐罩 —— **§7.51 起 CSS 里已经没有这一层**，这三个常量只剩一个用途：
# 给 `--with-scrim` 那档**反证**用（"罩只会往上抬，不会把达标的图变成不达标"）。
# x ≤ FULL_UPTO 满值 α，到 FADE_END 线性归零。
# §7.49（鼎，v5）那一批是 0.35 / 200 / 260 ⇄ CSS 58.6% / 76.2%；
# §7.50（爵，v6）换主体之后**副标尾巴那几个字（x=206..211）正好落在旧的渐隐段上**，
# 所以满值区要一路盖到副标墨迹右界 x=216，归零点反而往前收到 250（按钮左沿 263，留 13px）。
# 换算口径沿用上一批：CSS 百分比 = 脚本列号 / 341（::before 铺在 padding box 上）。
SCRIM_A = 0.50
SCRIM_FULL_UPTO, SCRIM_FADE_END = 216.0, 250.0

# 控制组：换图前那两套"看不见"的配色，用来证明量具有红
OLD_TITLE_RED = (0x9A, 0x1A, 0x1A)      # §7.42 深红标题
OLD_SUB_BROWN = (80, 50, 20, 0.6)       # §7.42 棕色副标
OLD_BTN_RED = (0xC0, 0x00, 0x00)        # §7.43 之前的实底红按钮上的白字 → 反过来：灰板上的深红字


def md5(path):
    with open(path, "rb") as fh:
        return hashlib.md5(fh.read()).hexdigest()


def lin(c):
    c = c / 255.0
    return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4


def lum(p):
    return 0.2126 * lin(p[0]) + 0.7152 * lin(p[1]) + 0.0722 * lin(p[2])


def ratio(a, b):
    la, lb = lum(a), lum(b)
    if la < lb:
        la, lb = lb, la
    return (la + 0.05) / (lb + 0.05)


def over(fg, bg, alpha):
    return tuple(int(round(fg[i] * alpha + bg[i] * (1 - alpha))) for i in range(3))


def crop_rows(im, rows):
    y0, y1 = rows
    w, h = im.size
    if not (0 <= y0 < y1 <= h):
        sys.exit("rows %d:%d 越界（原图 %dx%d）" % (y0, y1, w, h))
    return im.crop((0, y0, w, y1))


def render_card(im, dsf=DSF):
    """把 CSS `center/cover` 在 343x76 卡片里真正显示出来的那块裁出来（裸图，无渐变）。

    与头图那张的差别：邀新卡的 `::before` **没有**压暗渐变（§7.43 定的口径就是"深色图自己扛"），
    所以这里只裁不压。要是这一版为了达标又加了罩，记得回来在这里把罩也合成上，否则量的不是渲染的。
    """
    W, H = int(round(CW * dsf)), int(round(CH * dsf))
    sw, sh = im.size
    s = max(float(W) / sw, float(H) / sh)
    dw, dh = int(round(sw * s)), int(round(sh * s))
    r = im.resize((dw, dh), Image.LANCZOS)
    top, left = (dh - H) // 2, (dw - W) // 2
    return r.crop((left, top, left + W, top + H))


def apply_scrim(card, a=SCRIM_A, full=SCRIM_FULL_UPTO, fade=SCRIM_FADE_END):
    """把 CSS 那层左侧深色罩合成到副本上（只给测量用；落盘的资产是裸图）。"""
    out = card.copy()
    px = out.load()
    for y in range(out.size[1]):
        for x in range(out.size[0]):
            cx = x / float(DSF)
            al = a if cx <= full else (0.0 if cx >= fade else a * (fade - cx) / (fade - full))
            if al > 0:
                px[x, y] = over((0, 0, 0), px[x, y][:3], al)
    return out


def boxes():
    """标题 / 副标 / 按钮 / 图标板在 1 倍 CSS 坐标系里的矩形（DSF=1 时 render_card 输出即 CSS px）。

    坐标系取的是**卡片 padding box**（::before 是 `inset:0` 的绝对定位层，绝对定位按 padding box 铺），
    所以左边距从 padding 起算，不含那 1px 边框。文字块按 `&__text` 的 flex 列居中：
    标题行盒 22.5 + gap 4 + 副标行盒 18 = 44.5，正好等于图标位的高度 44（差 0.5 是行盒取整）。
    这套盒子和 §7.43 当年量的「文字起点 x=72、副标墨迹右界 x=215」是同一口径。
    """
    tw = len(TITLE_TEXT) * TITLE_PX + TITLE_LS * (len(TITLE_TEXT) - 1)
    sw_ = len(SUB_TEXT) * SUB_PX + SUB_LS * (len(SUB_TEXT) - 1)
    tl, sl = TITLE_PX * LINE_HEIGHT, SUB_PX * LINE_HEIGHT
    block = tl + SUB_GAP + sl
    top = (CH - block) / 2.0
    bw = len(BTN_TEXT) * BTN_FONT + 2 * BTN_PAD_X + 2   # +2 = 左右各 1px 边框
    bh = BTN_FONT * LINE_HEIGHT + 2 * BTN_PAD_Y + 2
    return {
        "title": (TEXT_X, top, TEXT_X + tw, top + tl),
        "sub": (TEXT_X, top + tl + SUB_GAP, TEXT_X + sw_, top + tl + SUB_GAP + sl),
        "btn": (CW - PAD - bw, (CH - bh) / 2.0, CW - PAD, (CH + bh) / 2.0),
        "icon": (PAD, (CH - ICON) / 2.0, PAD + ICON, (CH + ICON) / 2.0),
    }


def worst_in(img, box, fg, plate=None, need=None):
    """文字覆盖区里最坏那一点的对比度（逐像素）。plate 给了就先和它合成（按钮/图标板那种）。

    need 给了顺带把"哪几列不达标"报回来（CSS 列号）—— 只报一个最坏值不够用：
    这一轮的病灶是副标尾巴那 6 个字压在亮雾上，得知道**从第几列开始踩雷**，
    才能钉住"文案再加一个字就要重量"那条上界。
    """
    px = img.load()
    x0, y0, x1, y1 = [int(round(v * DSF)) for v in box]
    mn, mnpt = 99.0, None
    colmin = {}
    for y in range(max(y0, 0), min(y1, img.size[1])):
        for x in range(max(x0, 0), min(x1, img.size[0])):
            bg = px[x, y][:3]
            if plate is not None:
                bg = over(plate[0], bg, plate[1])
            f = fg
            if len(f) == 4:
                f = over(f[:3], bg, f[3])
            rr = ratio(f, bg)
            if rr < mn:
                mn, mnpt = rr, (x // DSF, y // DSF)
            c = x // DSF
            if c not in colmin or rr < colmin[c]:
                colmin[c] = rr
    bad = sorted([c for c, v in colmin.items() if need and v < need]) if need else []
    return mn, mnpt, bad


def right_edges(img, scrim=False, scrim_a=None, scrim_full=None, scrim_fade=None):
    """两行字各能往右伸到第几列：从文字起点逐列扫到卡片右内边，报**第一个跌破线的列**。

    为什么要单独扫这个：`worst_in()` 只量"现在这行字盖住的那一段"，它给不出上界。
    文案哪天多长一个字，压的就是**现在没人量**的那一列 —— §7.43 那条"副标 12 字是上界"
    就是这么量的（当年亮区从 x=226 起）。
    ⚠️ §7.51 起 `scrim` 默认 **False**（CSS 里没罩了）：**摘罩的代价全落在这个指标上** ——
    罩本来把右界往外推，裸图的右界只由图自己决定，所以文案上限要按裸图重扫，别沿用有罩那批的数。
    """
    card = render_card(img)
    if scrim:
        card = apply_scrim(card, SCRIM_A if scrim_a is None else scrim_a,
                           SCRIM_FULL_UPTO if scrim_full is None else scrim_full,
                           SCRIM_FADE_END if scrim_fade is None else scrim_fade)
    px = card.load()
    bx = boxes()
    out = {}
    for kind, fg, need in (("title", WHITE, 3.0), ("sub", (255, 255, 255, SUB_ALPHA), 4.5)):
        _, y0, _, y1 = bx[kind]
        yy0, yy1 = max(int(round(y0 * DSF)), 0), min(int(round(y1 * DSF)), card.size[1])
        first_bad = None
        for c in range(int(TEXT_X), int(CW - PAD)):
            mn = 99.0
            for y in range(yy0, yy1):
                bg = px[c * DSF, y][:3]
                f = over(fg[:3], bg, fg[3]) if len(fg) == 4 else fg
                mn = min(mn, ratio(f, bg))
            if mn < need:
                first_bad = c
                break
        out[kind] = (first_bad, need)
    return out


def measure(final, scrim=False, scrim_a=None, btn_alpha=None, scrim_full=None, scrim_fade=None):
    """按 CSS 几何量四块（标题 / 副标 / 按钮 / 图标板）的最坏点，返回读数字典。

    ⚠️ `scrim` 默认 **False**（§7.51）：CSS 的 `::before` 现在只剩图本身，没有深色渐变罩。
    """
    card = render_card(final)
    if scrim:
        card = apply_scrim(card, SCRIM_A if scrim_a is None else scrim_a,
                           SCRIM_FULL_UPTO if scrim_full is None else scrim_full,
                           SCRIM_FADE_END if scrim_fade is None else scrim_fade)
    ba = BTN_ALPHA if btn_alpha is None else btn_alpha
    bx = boxes()
    t, tpt, tbad = worst_in(card, bx["title"], WHITE, need=3.0)
    s, spt, sbad = worst_in(card, bx["sub"], (255, 255, 255, SUB_ALPHA), need=4.5)
    b, bpt, _ = worst_in(card, bx["btn"], WHITE, plate=(BTN_RGB, ba), need=4.5)
    i, ipt, _ = worst_in(card, bx["icon"], WHITE, plate=(WHITE, ICON_PLATE_ALPHA), need=3.0)
    # 量具自证：拿换图前那两套"看不见"的配色量同一张图，三档都必须**远低于各自要求**。
    # 具体数**随图而变**，别记成常量：鼎/爵那两张底里有大片亮雾（亮度连续铺满），逐像素找最坏点
    # 一定能找到一个跟字同亮度的点，所以近乎 1.00:1；v7 这张文字区是浅纸，旧棕副标只有 1.28、
    # 旧深红标题反而有 2.38（浅底上深红不至于全融，但仍远低于 3.0）。**含义是"迟早有一片字融进底里"**，
    # 不是"整行都看不见"——所以这一档只当量具有红的证据，不当读数。
    ot, _, _ = worst_in(card, bx["title"], OLD_TITLE_RED)
    os_, _, _ = worst_in(card, bx["sub"], OLD_SUB_BROWN)
    ob, _, _ = worst_in(card, bx["btn"], OLD_BTN_RED, plate=(BTN_RGB, ba))
    return {"title": (t, tpt, tbad), "sub": (s, spt, sbad), "btn": (b, bpt, ba),
            "icon": (i, ipt), "old": (ot, os_, ob), "scrim": scrim}


def report(r, tag="裸图（CSS 里就这层，罩已摘）"):
    t, tpt, tbad = r["title"]
    s, spt, sbad = r["sub"]
    b, bpt, ba = r["btn"]
    i, ipt = r["icon"]
    print("  %s最坏点：标题纯白 %.2f:1（≥3）@%s  副标85%%白 %.2f:1（≥4.5）@%s  "
          "按钮白字(α=%.2f灰板) %.2f:1@%s  图标白描(α=%.2f白板) %.2f:1@%s"
          % (tag, t, tpt, s, spt, ba, b, bpt, ICON_PLATE_ALPHA, i, ipt))
    print("  %s未达标列（CSS px）：标题 %s  副标 %s"
          % (tag, tbad if tbad else "无", sbad if sbad else "无"))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("raw")
    ap.add_argument("--out", default=DEFAULT_OUT)
    ap.add_argument("--rows", default="200:768", help="原图裁带（默认 = §7.51 选定的 200:768，缩到 1280x284）")
    ap.add_argument("--width", type=int, default=1280)
    ap.add_argument("--quality", type=int, default=82)
    ap.add_argument("--no-write", action="store_true", help="只量不落盘（试参数用）")
    ap.add_argument("--check-only", action="store_true",
                    help="跑完自证/对照/落位门槛，**不写任何文件**（反证脚本用这个）")
    ap.add_argument("--overwrite", action="store_true",
                    help="目标资产已存在时允许覆盖。**不带这个开关就撞车即停** —— "
                         "落盘目标必须和 --stem 一起当场核对，别让默认值盖掉上一批的成品")
    ap.add_argument("--stem", default=STEM,
                    help="落盘文件名（不含扩展名）。默认 = 当前那一版（见 STEM 常量注释）；"
                         "试参数/出对照图时传临时名（如 tmp-v7），**别让它静默覆盖上一批的成品**")
    ap.add_argument("--wm-top", type=int, default=1037,
                    help="水印首行（原图行号）。F3（水墨写意）实测 1037 / x 2378..2528；每张新图都要**当场量**再传，别沿用")
    ap.add_argument("--alpha-sweep", action="store_true", help="按钮 α 扫档，看 0.62 还站不站得住")
    ap.add_argument("--with-scrim", action="store_true",
                    help="把 §7.50 那层左罩叠回来再量 —— **现在只当反证档用**（证明「罩只会往上抬」）；"
                         "默认关，因为 CSS 里已经没有这一层")
    ap.add_argument("--scrim-a", type=float, default=None, help="临时改罩的 α 试档（不改常量）")
    ap.add_argument("--scrim-full", type=float, default=None,
                    help="临时改「满值到哪一列」（CSS px，默认取常量 200）—— 换主体之后亮部会挪位置，"
                         "满值区必须盖到**墨迹右界**，否则最后几个字正好吃在渐隐段上")
    ap.add_argument("--scrim-fade", type=float, default=None,
                    help="临时改「归零列」（默认取常量 260）—— 上界是按钮左沿，见 boxes()['btn']")
    ap.add_argument("--right-edge", action="store_true",
                    help="裸图/有罩各扫一遍两行字的右界并折算成**文案上限（几个字）**（钉文案上界用）")
    ap.add_argument("--control", default=None, metavar="路径[:带-带]",
                    help="对照档：另拿一张**上一批不达标的图**（如 v6 的原始候选）用同一把尺裸图量一遍，"
                         "它**必须不过**；它要也过了，说明尺子松了，本档的绿不可信。带号不传则沿用 --rows")
    ap.add_argument("--sweep-bands", action="store_true",
                    help="带顶从 160 到 440 每 20 行扫一遍，逐档报**裸图四块**的最坏点（§7.49 用它证明"
                         "「没有一条带能裸过」；§7.51 用它证明反过来 —— 每条带都裸过）")
    a = ap.parse_args()

    src = Image.open(a.raw).convert("RGB")
    print("原图 %dx%d  %d B  md5=%s" % (src.size[0], src.size[1], os.path.getsize(a.raw), md5(a.raw)))

    def to_final(y0, y1):
        cut = crop_rows(src, (y0, y1))
        scale = a.width / float(cut.size[0])
        return cut.resize((a.width, int(round(cut.size[1] * scale))), Image.LANCZOS)

    if a.sweep_bands:
        print("  带顶 160..440（步长 20，带高固定 568 = 1280x284 的比例）—— "
              "§7.51 起**看裸图那四档**，CSS 里没有罩；「+罩」列只是反证（罩只会往上抬）：")
        for y0 in range(160, 441, 20):
            f = to_final(y0, y0 + 568)
            bare = measure(f, scrim=False)
            withs = measure(f, scrim=True)
            print("  band %4d..%4d  裸图 标题 %5.2f 副标 %5.2f 按钮 %5.2f 图标 %5.2f | +罩 %5.2f/%5.2f  %s"
                  % (y0, y0 + 568, bare["title"][0], bare["sub"][0], bare["btn"][0], bare["icon"][0],
                     withs["title"][0], withs["sub"][0],
                     "裸图四块全过" if min(bare["title"][0] / 3.0, bare["sub"][0] / 4.5,
                                           bare["btn"][0] / 4.5, bare["icon"][0] / 3.0) >= 1.0
                     else "裸图不过，得改构图或字色"))
        return

    y0, y1 = [int(v) for v in a.rows.split(":")]
    # 水印：这类 AI 图按 §7.48 的"亮且中性"判据测不出带（见文件头第 2 条），所以这里只按**实测位置**
    # 守一条线。这条线现在是参数（--wm-top）：**每张新图都要重新量**。
    # D（爵，v6）实测首行 1021 / x 2274..2508；F3（水墨写意，v7）实测首行 1037 / x 2378..2528，
    # 选定的带 200:768 下界远在安全线（1022）之上 ⇒ 这一批**不需要**抹水印那一步。
    wm_top = a.wm_top
    if y1 > wm_top - 15:
        print("  ⚠️ --rows 下界 %d 会留下水印（实测水印首行 %d，安全线 %d）" % (y1, wm_top, wm_top - 15))
    final = to_final(y0, y1)
    print("裁行 %d:%d → 缩到 %dx%d（卡 343x76 比例 %.3f，图 %.3f）"
          % (y0, y1, final.size[0], final.size[1], CW / CH, final.size[0] / float(final.size[1])))

    card = render_card(final)
    _a = SCRIM_A if a.scrim_a is None else a.scrim_a
    _f = SCRIM_FULL_UPTO if a.scrim_full is None else a.scrim_full
    _e = SCRIM_FADE_END if a.scrim_fade is None else a.scrim_fade
    sc = bool(a.with_scrim)
    print("  渲染盒 %dx%d（DSF %d）｜罩：%s" % (card.size[0], card.size[1], DSF,
          ("反证档：把 §7.50 那层叠回来 —— 左 %.2f 满值到 x=%g，x=%g 归零" % (_a, _f, _e))
          if sc else "无（§7.51 起 CSS 里就只剩图这一层）"))
    r = measure(final, scrim=sc, scrim_a=a.scrim_a,
                scrim_full=a.scrim_full, scrim_fade=a.scrim_fade)
    report(r)
    ot, os_, ob = r["old"]
    print("  量具自证：旧深红标题 #9A1A1A %.2f:1  旧棕副标 rgba(80,50,20,.6) %.2f:1  "
          "灰板上的深红按钮字 %.2f:1（三档都应远低于各自要求）" % (ot, os_, ob))
    bad = []
    if r["title"][0] < 3.0 or r["sub"][0] < 4.5 or r["btn"][0] < 4.5 or r["icon"][0] < 3.0:
        bad.append("未达 WCAG")
    if max(ot, os_, ob) > 3.0:
        bad.append("反证档没红（旧色居然 ≥3:1），这个数不可信")
    if a.control:
        # 对照档：拿**上一批那张没过的图**（v6 裸图）用同一把尺量，它必须**不过**。
        # 只有"新图绿 + 旧图红"两头都对上，才说明赢的是构图，不是尺子松了。
        # 传法 `路径:带-带`；路径里也有冒号（Windows 盘符），所以只有末段形如 `280-848` 才当带号。
        head, sep, tail = a.control.rpartition(":")
        cpath, crows = (head, tail) if sep and re.fullmatch(r"\d+-\d+", tail) else (a.control, a.rows)
        c0, c1 = [int(v) for v in crows.split("-")]
        csrc = Image.open(cpath).convert("RGB")
        ccut = crop_rows(csrc, (c0, c1))
        csc = a.width / float(ccut.size[0])
        cr = measure(ccut.resize((a.width, int(round(ccut.size[1] * csc))), Image.LANCZOS),
                     scrim=False)
        report(cr, tag="对照档（上一批 %s 带 %d:%d，同一把尺）：" % (os.path.basename(cpath), c0, c1))
        if min(cr["title"][0] / 3.0, cr["sub"][0] / 4.5, cr["btn"][0] / 4.5, cr["icon"][0] / 3.0) >= 1.0:
            bad.append("对照档（旧图裸图）**也过了** —— 这把尺子松了，新图那个绿不可信")
    if bad:
        print("  ⚠️ " + "；".join(bad) + " —— 别落盘，换带/改罩/改字色得先定")
    if a.right_edge:
        for label, s2 in (("裸图", False), ("有罩（反证）", True)):
            e = right_edges(final, scrim=s2, scrim_a=a.scrim_a,
                            scrim_full=a.scrim_full, scrim_fade=a.scrim_fade)
            parts = []
            for kind, per_char in (("title", TITLE_PX + TITLE_LS), ("sub", SUB_PX + SUB_LS)):
                col, need = e[kind]
                parts.append("%s行 第一个跌破 %.1f 的列 = %s（换算成字：从 x=%d 起每字 %.1fpx，"
                             "现在 %d 字的墨迹右界在 x=%d）"
                             % (kind, need, col if col is not None else "无（一路到右内边都过）",
                                int(TEXT_X), per_char, len(TITLE_TEXT if kind == "title" else SUB_TEXT),
                                int(boxes()[kind][2])))
            print("  右界 %s：%s" % (label, "；".join(parts)))
            if not s2:
                # 上限 = 「第一个跌破线的列」之前还能塞几个字。注意**别拿现在那行字的右界当上界**：
                # 现在 7 字只画到 x=180，能写的字数要看 x=206 才跌破，直接 min 到一起会少数一个字。
                for kind, per_char in (("title", TITLE_PX + TITLE_LS), ("sub", SUB_PX + SUB_LS)):
                    col = e[kind][0]
                    cur = int(boxes()[kind][2])
                    limit = int(CW - PAD) if col is None else col
                    cap = int((limit - TEXT_X) / per_char)
                    print("    裸图 %s 行：可写到 x<%d ⇒ 上限 %d 字（每字 %.1fpx；现在 %d 字画到 x=%d，%s）"
                          % (kind, limit, cap, per_char,
                             len(TITLE_TEXT if kind == "title" else SUB_TEXT), cur,
                             "还有余量" if cap >= len(TITLE_TEXT if kind == "title" else SUB_TEXT)
                             else "已经超了"))
    if a.alpha_sweep:
        bx = boxes()
        base = apply_scrim(card, _a, _f, _e) if sc else card
        print("  按钮 α 扫档（白字最坏点）：" + "  ".join(
            "%.2f→%.2f" % (al, worst_in(base, bx["btn"], WHITE, plate=(BTN_RGB, al))[0])
            for al in (0.55, 0.58, 0.62, 0.68, 0.75, 0.82)))
    if a.check_only:
        print("  --check-only：落位门槛 = %s" % ("通过（自证全红、对照全红、本档四块全绿）"
                                          if not bad else "不通过：" + "；".join(bad)))
        sys.exit(0 if not bad else 1)
    if not a.no_write:
        if bad:
            sys.exit("❌ 上面那些不达标，拒绝落盘")
        target = os.path.join(a.out, a.stem + ".webp")
        if os.path.exists(target) and not a.overwrite:
            sys.exit("❌ 落位目标已存在：%s（md5=%s）—— 先核对 --out/--stem 指的就是**这一批**的资产，"
                     "确认要覆盖再加 --overwrite" % (target, md5(target)))
        print("  落位门槛：自证（旧色三档）全红、对照档（旧图裸图）全红、本档四块全绿 ⇒ 允许落盘")
        if not os.path.isdir(a.out):
            os.makedirs(a.out)
        path = os.path.join(a.out, a.stem + ".webp")
        final.save(path, format="WEBP", quality=a.quality, method=6)
        print("写入 %s  %d B  md5=%s" % (path, os.path.getsize(path), md5(path)))


if __name__ == "__main__":
    main()
