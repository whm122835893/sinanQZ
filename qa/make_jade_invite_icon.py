#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
把写实「白玉佩 + 红绳」原图裁成五套主题共用的邀请图标（256 方画布 + 圆角小卡）。

⚠️ **本脚本自 §7.47 起停用**（2026-10-17）：用户把邀请图标改成「人形 + 加号」的现成矢量线条
（`src/components/AppIcon.vue` 里 `invite` 那条），五套主题共用的是**那份定义、不是这张位图**，
`invite-jade.webp` 已从仓库撤下（成品字节留在仓库外 `E:/tmp/v47/retired/`，md5 `bac1d979…`）。
脚本与 §7.46 的那套写实口径留着只有一个用途：**谁要把图标再改回位图，得能重出同一张、而不是重新画一张**。
所以别把它当"当前实现"读，也别指望 spec 里还有它的钉 —— §7.47 之后测的是矢量分支。

为什么要一个脚本而不是直接拿原图：
  1. AI 出的图右下角有一条约 16px 高的「AI生成」水印带（实测 x 880~987 / y 985~1000），
     必须裁掉 —— 与 §7.43 那轮「切带裁水印」是同一件事，只是这次带在角上。
  2. 圆角由脚本画，不靠模型画：模型画的边框会歪、还会带出「画中画」的框中框。
     这里用 4 倍超采样 mask，边缘是干净的抗锯齿弧。

原图**不入库**（仓库只存发布资产）。要重做就得用台账 §7.46 里记的那条 prompt 重新生成一张，
再把路径传进来。

产物默认是 **WebP**：同一张 256 方图 PNG 无损要 89,980 B、WebP q90 只要 9,724 B，
而这张图标在 C 端只有 28px 那么大，走的是手机 4G —— 8 倍差距没道理选 PNG。
（仓库里早有先例：邀请卡海报 `invite-banner-bg-v4.webp`。）代价是 vitest 那边解不开 webp 像素，
所以风格数（色数 / top8 / 均色）由本脚本离线量出来后**写进 spec 顶部的常量**，
spec 再用「常量的 md5 == 线上这个文件的 md5」把它们钉住 —— 见 §7.46 里那句自我限制。

用法：
  python qa/make_jade_invite_icon.py <raw.png> [--out DIR] [--size 256] [--radius 46] [--bottom-crop 49]
                                     [--format webp|png] [--quality 90]
"""
import argparse
import hashlib
import os
import sys

from PIL import Image, ImageDraw

DEFAULT_OUT = os.path.join("public", "images", "tab")
STEM = "invite-jade"


def md5(path):
    with open(path, "rb") as fh:
        return hashlib.md5(fh.read()).hexdigest()


def make(raw, out_dir, size, radius, bottom_crop, fmt, quality):
    im = Image.open(raw).convert("RGB")
    w, h = im.size
    if bottom_crop:
        if bottom_crop >= h:
            sys.exit("bottom-crop %d 把整张图裁没了（原图高 %d）" % (bottom_crop, h))
        im = im.crop((0, 0, w, h - bottom_crop))
    w, h = im.size
    side = min(w, h)
    x0 = (w - side) // 2
    y0 = (h - side) // 2
    im = im.crop((x0, y0, x0 + side, y0 + side))
    im = im.resize((size, size), Image.LANCZOS)

    ss = 4
    S = size * ss
    r = radius * ss
    mask = Image.new("L", (S, S), 0)
    d = ImageDraw.Draw(mask)
    # Pillow 7.2 没有 rounded_rectangle（8.2 才有），所以自己拼：两条带 + 四个角圆。
    d.rectangle([r, 0, S - 1 - r, S - 1], fill=255)
    d.rectangle([0, r, S - 1, S - 1 - r], fill=255)
    d.ellipse([0, 0, 2 * r - 1, 2 * r - 1], fill=255)
    d.ellipse([S - 2 * r, 0, S - 1, 2 * r - 1], fill=255)
    d.ellipse([0, S - 2 * r, 2 * r - 1, S - 1], fill=255)
    d.ellipse([S - 2 * r, S - 2 * r, S - 1, S - 1], fill=255)
    mask = mask.resize((size, size), Image.LANCZOS)

    out = im.convert("RGBA")
    out.putalpha(mask)
    name = STEM + ("." + fmt if fmt != "webp" else ".webp")
    path = os.path.join(out_dir, name)
    if fmt == "webp":
        out.save(path, format="WEBP", quality=quality, method=6)
    else:
        out.save(path, optimize=True, compress_level=9)
    return path, out


def measure(img):
    """发布资产的体检数：连续色阶（写实）+ 四角透明 + 圆角内侧不透明 + 均色。

    在内存里量、不在存完之后量：WebP 是有损的，量它等于量"压缩后剩下什么"，
    而风格这件事是压缩前定的（压缩只会把渐变抹得更平，不会把色块变成渐变）。
    """
    w, h = img.size
    px = img.load()
    quantized = {}
    opaque = 0
    acc = [0, 0, 0]
    for y in range(h):
        for x in range(w):
            r, g, b, a = px[x, y]
            if a > 100:
                opaque += 1
                acc[0] += r
                acc[1] += g
                acc[2] += b
                k = (r >> 4, g >> 4, b >> 4)
                quantized[k] = quantized.get(k, 0) + 1
    counts = sorted(quantized.values(), reverse=True)
    top8 = sum(counts[:8]) / float(opaque) if opaque else 0.0
    corners = [px[0, 0][3], px[w - 1, 0][3], px[0, h - 1][3], px[w - 1, h - 1][3]]
    return {
        "size": (w, h),
        "distinct_4bit": len(counts),
        "top8": top8,
        "mean": [acc[0] // opaque, acc[1] // opaque, acc[2] // opaque],
        "opaque_ratio": opaque / float(w * h),
        "corner_alpha": corners,
        "center_alpha": px[w // 2, h // 2][3],
    }


def cover_mean(path):
    im = Image.open(path).convert("RGB")
    w, h = im.size
    px = im.load()
    acc = [0, 0, 0]
    n = 0
    for y in range(0, h, 2):
        for x in range(0, w, 2):
            c = px[x, y]
            acc[0] += c[0]
            acc[1] += c[1]
            acc[2] += c[2]
            n += 1
    return [acc[0] // n, acc[1] // n, acc[2] // n]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("raw")
    ap.add_argument("--out", default=DEFAULT_OUT)
    ap.add_argument("--size", type=int, default=256)
    ap.add_argument("--radius", type=int, default=46)
    ap.add_argument("--bottom-crop", dest="bottom_crop", type=int, default=49)
    ap.add_argument("--format", choices=("webp", "png"), default="webp")
    ap.add_argument("--quality", type=int, default=90)
    ap.add_argument("--compare-cover", action="append", default=[],
                    help="公告首图路径，可重复；打印本图与它的均色欧氏距离（<64 算同档暖调）")
    a = ap.parse_args()

    if not os.path.isdir(a.out):
        os.makedirs(a.out)
    path, img = make(a.raw, a.out, a.size, a.radius, a.bottom_crop, a.format, a.quality)
    s = measure(img)
    print("%s  %dx%d  %d B  md5=%s" % (path, s["size"][0], s["size"][1], os.path.getsize(path), md5(path)))
    print("  4bit 色数=%d  top8=%.3f  均色=(%d,%d,%d)  不透明占比=%.3f  四角 alpha=%s  中心 alpha=%d"
          % (s["distinct_4bit"], s["top8"], s["mean"][0], s["mean"][1], s["mean"][2],
             s["opaque_ratio"], s["corner_alpha"], s["center_alpha"]))
    for c in a.compare_cover:
        cm = cover_mean(c)
        dist = sum((x - y) ** 2 for x, y in zip(s["mean"], cm)) ** 0.5
        print("  vs %-46s 均色=(%3d,%3d,%3d) 距离=%.1f" % (os.path.basename(c), cm[0], cm[1], cm[2], dist))


if __name__ == "__main__":
    main()


if __name__ == "__main__":
    main()
