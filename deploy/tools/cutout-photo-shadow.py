"""带投影的实物渲染图抠图：从画面边缘做连通生长（洪水填充）判背景，再羽化 + 去白边。

用法：python cutout-photo-shadow.py <源图> <输出.png> [工作分辨率=960] [输出边长=400] [导出蒙版.png]
      换 empty-ding.png 时用的是：... "<源图>" empty-ding.png 960 400 mask.png
      第 5 个参数导出整张工作分辨率的 alpha 蒙版，配合「把被删掉的像素标红叠回原图」验收，
      一眼能看出哪里漏吃（只看透明图本身看不出少了一块）。

为什么不能用「离白多远」直接算 alpha（那是 cutout-white-bg.py 的做法）：
这张图脚下有一片接触阴影，最暗处离白 d≈204，和器物中段一样深；鼎身内部又有接近纯白的
高光（d 低到 30）。全局阈值会把阴影当主体留下、又把高光打出洞。
连通生长只把「和画面四边连通」的浅色区域判为背景，所以孤立的高光天然保住；
而阴影是平滑渐变（每像素 d 只变 2~4），能顺着渐变一路走到器物边缘，
器物轮廓处单像素 d 跳变 100+，正好停在边缘。

但纯连通生长会把鼎耳尖部吃掉一块（渲染在那里给了接近纯白的高光，和背景连成一片）。
所以按区域分档，见下面的 SPLIT：分界线以上只认「离白 < STRICT」的纯白，不给渐变活口；
分界线以下才允许沿渐变生长去吃投影。投影只出现在器物脚下那一条带子里，画面上半部本来就是干净纯白。
"""
from PIL import Image, ImageFilter
import sys
from collections import deque

SRC, OUT = sys.argv[1], sys.argv[2]
WORK = int(sys.argv[3]) if len(sys.argv) > 3 else 960
SIZE = int(sys.argv[4]) if len(sys.argv) > 4 else 400
STEP = 26.0      # 相邻像素允许的最大色距（沿渐变生长的步长）
D_CAP = 262.0    # 背景像素离白的上限：阴影最暗 ~204，器物暗部 300+
SPLIT = 0.66     # 分界线（占画面高度比例）：以上只认纯白，以下才允许沿渐变吃投影
PAD = 0.03

im = Image.open(SRC).convert('RGB')
im.thumbnail((WORK, WORK), Image.LANCZOS)
W, H = im.size
px = im.load()

# 展平成三个 list，纯 Python 下比逐次 px[x,y] 快一个量级
R = [0.0] * (W * H); G = [0.0] * (W * H); B = [0.0] * (W * H)
i = 0
for y in range(H):
    for x in range(W):
        p = px[x, y]
        R[i] = p[0]; G[i] = p[1]; B[i] = p[2]
        i += 1


def dist_bg(i):
    return ((R[i] - 254.0) ** 2 + (G[i] - 254.0) ** 2 + (B[i] - 254.0) ** 2) ** 0.5


bg = bytearray(W * H)
YS = int(H * SPLIT)          # 分界线：以上只认纯白，以下允许沿渐变生长
STRICT = 45.0                # 上半区「纯白」判据（背景实测 d 0~14，鼎耳尖高光 d 59~130）
q = deque()
for x in range(W):
    for i in (x, (H - 1) * W + x):
        if not bg[i] and dist_bg(i) < (STRICT if i < W * YS else D_CAP):
            bg[i] = 1; q.append(i)
for y in range(H):
    for i in (y * W, y * W + W - 1):
        if not bg[i] and dist_bg(i) < (STRICT if i < W * YS else D_CAP):
            bg[i] = 1; q.append(i)
seeds = len(q)

while q:
    i = q.popleft()
    x, y = i % W, i // W
    r, g, b = R[i], G[i], B[i]
    for j in ((i - W) if y else -1, (i + W) if y < H - 1 else -1,
              (i - 1) if x else -1, (i + 1) if x < W - 1 else -1):
        if j < 0 or bg[j]:
            continue
        dj = dist_bg(j)
        if (j // W) < YS:
            if dj < STRICT:                      # 上半区：只有纯白才算背景，不给渐变活口
                bg[j] = 1; q.append(j)
        elif dj < D_CAP:
            dr, dg, db = R[j] - r, G[j] - g, B[j] - b
            if dr * dr + dg * dg + db * db < STEP * STEP:
                bg[j] = 1
                q.append(j)

# 封闭在主体里的浅色区（鼎内腔、腿间的地面亮斑）不属于背景，但也不是阴影：
# 只处理与外背景不连通的空洞 —— 这里靠上面的连通性已经天然区分，无需额外处理。
mask = Image.new('L', (W, H))
mm = mask.load()
minx, miny, maxx, maxy = W, H, -1, -1
for y in range(H):
    base = y * W
    for x in range(W):
        if bg[base + x]:
            mm[x, y] = 0
        else:
            mm[x, y] = 255
            if x < minx: minx = x
            if x > maxx: maxx = x
            if y < miny: miny = y
            if y > maxy: maxy = y

# 羽化：硬边 mask 轻糊一下，配合后面的降采样得到柔边
mask = mask.filter(ImageFilter.GaussianBlur(1.4))
rgba = Image.new('RGBA', (W, H))
if len(sys.argv) > 5:
    mask.save(sys.argv[5])          # 调试用：整张工作分辨率的 alpha 蒙版
out = rgba.load()
al = mask.load()
for y in range(H):
    for x in range(W):
        i = y * W + x
        a = al[x, y]
        r, g, b = R[i], G[i], B[i]
        if 0 < a < 255:            # 去白边：把混进边缘的白底反解掉
            f = a / 255.0
            r = int(max(0.0, min(255.0, (r - (1 - f) * 254.0) / f)) + 0.5)
            g = int(max(0.0, min(255.0, (g - (1 - f) * 254.0) / f)) + 0.5)
            b = int(max(0.0, min(255.0, (b - (1 - f) * 254.0) / f)) + 0.5)
        out[x, y] = (r, g, b, a)

bw, bh = maxx - minx + 1, maxy - miny + 1
side = max(bw, bh) * (1 + 2 * PAD)
canvas = Image.new('RGBA', (int(side + .5), int(side + .5)), (0, 0, 0, 0))
canvas.paste(rgba.crop((minx, miny, maxx + 1, maxy + 1)),
             ((int(side + .5) - bw) // 2, (int(side + .5) - bh) // 2),
             rgba.crop((minx, miny, maxx + 1, maxy + 1)))
canvas = canvas.resize((SIZE, SIZE), Image.LANCZOS)
canvas.quantize(colors=256, method=Image.FASTOCTREE).save(OUT, optimize=True)

for tag, c in (('light', '#F7F8FA'), ('dark', '#2B2B2B')):
    b = Image.new('RGBA', (SIZE, SIZE), c)
    b.alpha_composite(canvas)
    b.convert('RGB').save(OUT.replace('.png', '-%s.png' % tag))

print('work %dx%d seeds=%d bg=%d/%d (%.1f%%) subject=%dx%d -> %s' % (
    W, H, seeds, sum(bg), W * H, 100.0 * sum(bg) / (W * H), bw, bh, OUT))
