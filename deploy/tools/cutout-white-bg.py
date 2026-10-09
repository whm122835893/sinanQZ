"""把白底 logo 抠成透明 PNG：按「离背景白的色距」生成 alpha 斜坡 + 白边去污染。

用法：python cutout-white-bg.py <源图> <输出.png> [工作分辨率=480] [输出边长=240]
      换 empty-ding.png 时用的是 960 / 400。
抠完还要量化一次才压得动体积（PIL 对 RGBA 只接受 FASTOCTREE）：
    Image.open('out.png').convert('RGBA').quantize(256, Image.FASTOCTREE).save('out.png', optimize=True)
换图记得同步改 AppEmpty.vue 的 src；文件名不变时 nginx 的启发式缓存会让本地仍看到旧图。

为什么这样做：源图背景是 (254,254,254) 的准纯白，玉料最浅处离白也有 d>=85，
两者之间 15~80 只有约 1700 个像素（正好是 1~2px 的抗锯齿边），所以色距阈值
足够干净，不需要洪水填充；logo 内部的白色镂空（饕餮纹）本身就是背景色，会一起透掉。
"""
from PIL import Image, ImageFilter
import sys

SRC, OUT = sys.argv[1], sys.argv[2]
D_LOW, D_HIGH = 18.0, 75.0   # d<=D_LOW 全透明，d>=D_HIGH 全不透明，中间线性过渡
BG = (254.0, 254.0, 254.0)
WORK = int(sys.argv[3]) if len(sys.argv) > 3 else 480   # 处理分辨率
SIZE = int(sys.argv[4]) if len(sys.argv) > 4 else 240   # 输出边长（页面按 32vw 渲染，PC 上更大）
PAD = 0.04                    # 主体四周留白比例

im = Image.open(SRC).convert('RGB')
im.thumbnail((WORK, WORK), Image.LANCZOS)
W, H = im.size
px = im.load()

rgba = Image.new('RGBA', (W, H))
op = rgba.load()
minx, miny, maxx, maxy = W, H, -1, -1
for y in range(H):
    for x in range(W):
        r, g, b = px[x, y]
        d = ((r - BG[0]) ** 2 + (g - BG[1]) ** 2 + (b - BG[2]) ** 2) ** 0.5
        a = (d - D_LOW) / (D_HIGH - D_LOW)
        a = 0.0 if a <= 0 else (1.0 if a >= 1 else a)
        alpha = int(round(a * 255))
        if alpha > 8:
            minx = min(minx, x); maxx = max(maxx, x)
            miny = min(miny, y); maxy = max(maxy, y)
        # 白边去污染：边缘像素的 RGB 是「玉料 + 白底」的混合，按 alpha 反解回玉料本色
        if 0 < alpha < 255:
            f = alpha / 255.0
            ch = []
            for v, wv in ((r, BG[0]), (g, BG[1]), (b, BG[2])):
                v2 = (v - (1.0 - f) * wv) / f
                ch.append(int(max(0.0, min(255.0, v2)) + 0.5))
            r, g, b = ch
        op[x, y] = (r, g, b, alpha)

# 轻微羽化 alpha，让 1px 硬边看起来更柔
alpha_ch = rgba.getchannel('A').filter(ImageFilter.GaussianBlur(0.6))
rgba.putalpha(alpha_ch)

if maxx < 0:
    raise SystemExit('nothing left after keying - check thresholds')

bw, bh = maxx - minx + 1, maxy - miny + 1
side = max(bw, bh) * (1 + 2 * PAD)
crop = rgba.crop((minx, miny, maxx + 1, maxy + 1))
canvas = Image.new('RGBA', (int(side + 0.5), int(side + 0.5)), (0, 0, 0, 0))
canvas.paste(crop, ((canvas.width - bw) // 2, (canvas.height - bh) // 2), crop)
canvas = canvas.resize((SIZE, SIZE), Image.LANCZOS)
canvas.save(OUT)

# 调试图：分别叠在页面底色和深底上看边缘
for name, bgc in (('light', '#F7F8FA'), ('dark', '#2B2B2B')):
    base = Image.new('RGBA', (SIZE, SIZE), bgc)
    base.alpha_composite(canvas)
    base.convert('RGB').resize((360, 360), Image.LANCZOS).save(OUT.replace('.png', '-%s.png' % name))

print('src %dx%d -> subject %dx%d -> %dx%d, opaque px=%d' % (
    W, H, bw, bh, SIZE, SIZE, sum(1 for p in canvas.getdata() if p[3] > 250)))
