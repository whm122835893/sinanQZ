#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
把 AI 出的「青铜方鼎」原图裁成文物展览区头图，并**当场把白字对比度量出来**。

为什么要有这个脚本，而不是把图拖进编辑器手工处理：
  1. **角标水印**。这一批模型在右下角画了一枚「Qoder AI生成」白字（实测 bbox 右下角到 y=1070，
     距底只有 9 行）。这块头图是 `center/cover` 的 150px 横条，窄屏（≤388 CSS px，含 iPhone 12/13
     与多数 360 宽的安卓）会把这条带露出来 —— 与 §7.43「切带裁水印」、§7.46「角上那条带」是同一件事。
     所以裁行是**必须的**，不是美化。脚本裁完还要回头复检一次，裁不干净就非零退出。
  2. **对比度必须在这张图上量，不能等上线后靠眼睛**。这块头图原来是浅米黄底 + 深棕字（`#2c1d0f`），
     换成深暗底 + 白字之后，文字底下压着什么完全由这张图决定 —— §7.44 那次「深红线对磨砂板只有
     1.0~1.6:1 = 看不见」就是这么来的。
  3. **数要能重跑**。spec 里钉的是「这张 md5 的图 + 这套 CSS = 标题 X:1 / 副标 Y:1」这几个常量，
     vitest 解不开 WebP 像素，所以像素级的量由本脚本离线做，spec 用 md5 把数和图绑在一起（§7.46 同款限制）。

裁法：先按行裁掉水印带，再等比缩到 1280 宽。1280 不是随手取的 —— 邀请卡海报
（`invite-banner-bg-v4.webp`）就是 1280 宽，两张横幅同档，390 CSS px × 3 倍图要 1170 px，够用。
代价是平板（≥768 CSS px）上会轻微放大，深色图上看不出来，记在这里当已知取舍。

原图**不入库**（仓库只存发布资产）。要重做就得用台账 §7.48 里记的那条 prompt 重新生成一张。

用法：
  python qa/make_exhibit_header_bronze.py <raw.png> [--out DIR] [--rows 40:1020] [--width 1280]
                                          [--quality 82] [--no-write]
"""
import argparse
import hashlib
import os
import sys

from PIL import Image

DEFAULT_OUT = os.path.join("sinan-art-source", "public", "images", "exhibits")
STEM = "exhibit-header-bronze-ding-v2"

# ---- 与 Mall.vue `.exhibit-header` 对齐的渲染常量（改了 CSS 必须同步这里，spec 有钉）----
VW, VH = 390, 150          # CSS 视口宽 × 头图高
DSF = 3                    # 按 3 倍图量（最坏的现实像素密度）
GRAD_TOP, GRAD_BOTTOM = 0.08, 0.55   # linear-gradient(180deg, rgba(0,0,0,.08), rgba(0,0,0,.55))
PAD_X = 16                 # $page-padding
LINE_HEIGHT = 1.5          # global.scss:67
TITLE_PX, SUB_PX = 28, 13  # 标题 / 副标字号
TITLE_LS, SUB_LS = 2, 1    # letter-spacing
SUB_MARGIN_TOP = 6
TITLE_TEXT, SUB_TEXT = "文物展览区", "司南 · 数字文物典藏"
WHITE = (255, 255, 255)
SUB_ALPHA_WHITE = (255, 255, 255, 0.85)   # 副标 CSS 写 rgba(255,255,255,.85)，量时要和底色合成
OLD_BROWN = (0x2C, 0x1D, 0x0F)   # 换图前的标题色，只用来给量具做反证
WATERMARK_MARGIN = 15            # 裁行上界要在水印第一行之上留这么多


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


def crop_rows(im, rows):
    y0, y1 = rows
    w, h = im.size
    if not (0 <= y0 < y1 <= h):
        sys.exit("rows %d:%d 越界（原图 %dx%d）" % (y0, y1, w, h))
    return im.crop((0, y0, w, y1))


def render_band(im, vw=VW, vh=VH, dsf=DSF):
    """把 CSS `center/cover` 在 vw×vh 的盒子里真正显示出来的那块裁出来（含压暗渐变）。

    ⚠️ 这一步不能省：直接拿整张图算平均亮度会得出一个很好看的假数 —— 用户看到的只有中间那条带，
    而渐变层在带内的 α 是从上到下线性涨的。
    """
    W, H = vw * dsf, vh * dsf
    sw, sh = im.size
    s = max(float(W) / sw, float(H) / sh)
    dw, dh = int(round(sw * s)), int(round(sh * s))
    r = im.resize((dw, dh), Image.LANCZOS)
    top = (dh - H) // 2
    left = (dw - W) // 2
    r = r.crop((left, top, left + W, top + H))
    px = r.load()
    for y in range(H):
        a = GRAD_TOP + (GRAD_BOTTOM - GRAD_TOP) * (y / float(H - 1))
        for x in range(W):
            q = px[x, y]
            px[x, y] = (int(q[0] * (1 - a)), int(q[1] * (1 - a)), int(q[2] * (1 - a)))
    return r


def text_boxes():
    """标题 / 副标在 1 倍 CSS 坐标系里占的矩形（DSF=1 时 render_band 的输出即 CSS 像素）。"""
    tw = len(TITLE_TEXT) * TITLE_PX + TITLE_LS * len(TITLE_TEXT)
    sw_ = len(SUB_TEXT) * SUB_PX + SUB_LS * len(SUB_TEXT)
    block = TITLE_PX * LINE_HEIGHT + SUB_MARGIN_TOP + SUB_PX * LINE_HEIGHT
    top = (VH - block) / 2.0
    return {
        "title": (PAD_X, top, PAD_X + tw, top + TITLE_PX * LINE_HEIGHT),
        "sub": (PAD_X, top + TITLE_PX * LINE_HEIGHT + SUB_MARGIN_TOP, PAD_X + sw_,
                top + TITLE_PX * LINE_HEIGHT + SUB_MARGIN_TOP + SUB_PX * LINE_HEIGHT),
    }


def worst_in(img, box, fg):
    """文字覆盖区里最坏那一点的对比度（逐像素，不是平均）。fg 传 (r,g,b) 或 (r,g,b,alpha)。"""
    W, _ = img.size
    px = img.load()
    x0, y0, x1, y1 = [int(round(v)) for v in box]
    mn, mnpt = 99.0, None
    for y in range(max(y0, 0), min(y1, img.size[1])):
        for x in range(max(x0, 0), min(x1, W)):
            bg = px[x, y]
            if len(fg) == 4:          # 半透明白字：先和底色合成再算，别拿纯白当它是实色
                a = fg[3]
                f = tuple(int(round(fg[i] * a + bg[i] * (1 - a))) for i in range(3))
            else:
                f = fg
            rr = ratio(f, bg)
            if rr < mn:
                mn, mnpt = rr, (x, y)
    return mn, mnpt


def watermark_band(im):
    """角标水印所在的行带（行密度法），返回 (top, bottom, rows)；没找到返回 None。

    为什么不用「右下角有几个近白像素」这种判据：这张图上鼎座右侧本来就有一片低饱和的岩石高光，
    纯亮度判据在裁完之后会数出 184 个"疑似水印"，全是误报（第一版脚本就是这么写的，跑一次红一次）。
    文字是一横排，所以按行统计「亮且中性」（max>185 且 max-min<22）的像素数，一行 ≥6 个才算字。
    实测 A 这张：水印带 y=1052..1068（17 行、峰值 36 px/行），而岩石噪声每行 ≤2 个，分得很开。
    已知限制：B（器物压住右下角）与 C（雾太亮）这两张用它测不出带 —— 那两张没被选中，
    真要换风格得先肉眼看角标位置，别信这里返回的 None。
    """
    w, h = im.size
    px = im.load()
    rows = []
    for y in range(int(h * 0.75), h):
        c = 0
        for x in range(int(w * 0.55), w):
            r, g, b = px[x, y]
            mx, mn = max(r, g, b), min(r, g, b)
            if mx > 185 and (mx - mn) < 22:
                c += 1
        if c >= 6:
            rows.append(y)
    return (rows[0], rows[-1], len(rows)) if rows else None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("raw")
    ap.add_argument("--out", default=DEFAULT_OUT)
    ap.add_argument("--rows", default="40:1020")
    ap.add_argument("--width", type=int, default=1280)
    ap.add_argument("--quality", type=int, default=82)
    ap.add_argument("--no-write", action="store_true", help="只量不落盘（试参数用）")
    a = ap.parse_args()

    y0, y1 = [int(v) for v in a.rows.split(":")]
    src = Image.open(a.raw).convert("RGB")
    print("原图 %dx%d  %d B  md5=%s" % (src.size[0], src.size[1], os.path.getsize(a.raw), md5(a.raw)))
    band = watermark_band(src)
    if band:
        print("  角标水印带 y=%d..%d（%d 行）" % band)
        if y1 > band[0] - WATERMARK_MARGIN:
            sys.exit("❌ --rows 下界 %d 会留下水印（水印第一行 %d，安全线 %d）—— 把上界往下收"
                     % (y1, band[0], band[0] - WATERMARK_MARGIN))
    else:
        print("  ⚠️ 没测到水印带：可能是这张确实干净，也可能是判据对它不灵（见 watermark_band 的已知限制）")

    cut = crop_rows(src, (y0, y1))
    scale = a.width / float(cut.size[0])
    final = cut.resize((a.width, int(round(cut.size[1] * scale))), Image.LANCZOS)
    print("裁行 %d:%d → 缩到 %dx%d" % (y0, y1, final.size[0], final.size[1]))

    band = render_band(final)
    boxes = text_boxes()
    t_min, t_pt = worst_in(band, boxes["title"], WHITE)
    s_min, s_pt = worst_in(band, boxes["sub"], SUB_ALPHA_WHITE)
    # 量具自证：拿换图前那个深棕字来量同一张图，必须量出一个难看的数，否则这个量具是废的。
    tb_old, _ = worst_in(band, boxes["title"], OLD_BROWN)
    print("  渲染带 %dx%d（%d CSS px 视口 / DSF %d）" % (band.size[0], band.size[1], VW, DSF))
    print("  最坏点对比度：标题纯白 %.2f:1 @%s（大字要求 ≥3:1）  副标 85%% 白 %.2f:1 @%s（小字要求 ≥4.5:1）"
          % (t_min, t_pt, s_min, s_pt))
    print("  量具自证：旧深棕字 #2c1d0f 在同一张图上 = %.2f:1（应当远低于 3:1，否则量具有问题）" % tb_old)
    if t_min < 3.0 or s_min < 4.5:
        print("  ⚠️ 未达 WCAG，别落盘 —— 要么换构图，要么加暗罩")
    if tb_old > 2.0:
        print("  ⚠️ 反证档没红（旧色居然也有 %.2f:1），这个数不可信" % tb_old)
    if not a.no_write:
        if not os.path.isdir(a.out):
            os.makedirs(a.out)
        path = os.path.join(a.out, STEM + ".webp")
        final.save(path, format="WEBP", quality=a.quality, method=6)
        print("写入 %s  %d B  md5=%s" % (path, os.path.getsize(path), md5(path)))


if __name__ == "__main__":
    main()
