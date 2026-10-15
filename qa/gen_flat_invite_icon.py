"""
平面的「邀请」图标生成器（RUNBOOK §7.44）

为什么这个脚本要入库，而 §7.43 那批 AI 生成图的脚本没有：
  · 那张深色海报底图是 AI 出的，原图不留在仓库里，脚本留着也复现不出同一张图（只能复现「裁切+编码」那一步）；
  · 这张平面图标是**用几何图形画出来的**，三个色块 + 两条折线 + 一个圆，脚本本身就是它的源文件。
    没有这个脚本，下次有人想调粗封口 V、换封蜡颜色，只能重新猜坐标。

用法（本机 PIL 只有 7.2.0，没有 numpy）：
  "C:\\Program Files\\python\\python" qa/gen_flat_invite_icon.py
  产物默认落在 sinan-art-source/public/images/tab/modern-invite.png（覆盖前老版本在 git 里，直接 git show 取回）

画布约定（对着同家族 modern-calendar 量的，别单独改这张）：
  256x256 方画布、透明底；墨迹外接框 233x197（长边 91% 画布，calendar 是 94%）
  平面度实测：4bit 量化 54 档色阶、最常见 8 色覆盖 94.9% 墨迹
  （被它替换掉的立体那张是 229 档 / 0.8%，所以 tests/invite-icon-theme.spec.js 里的 100 档与 80% 两条线不会误伤）
"""
import os
import sys

from PIL import Image, ImageDraw

S = 4  # 4 倍超采样再缩回 256：斜边自带抗锯齿，同时每个色块内部保持纯色（平面图的命门是"内部不许有渐变"）

BODY = (240, 240, 243, 255)  # 家族主体色，取自 modern-calendar / modern-lottery 的大块浅灰
FLAP = (208, 210, 222, 255)  # 第二色块：封口分面，只压暗一档，不做高光/阴影
SEAL = (201, 39, 52, 255)    # 封蜡红：modern-lottery 上那颗点缀就是同一个红

OUT = os.path.join(os.path.dirname(__file__), os.pardir,
                   'sinan-art-source', 'public', 'images', 'tab', 'modern-invite.png')


def rounded_rect(d, z, box, rad, fill):
    """PIL 7.2 没有 ImageDraw.rounded_rectangle（8.2 才有），自己用两个矩形 + 四个圆拼
    注意坐标要经过 z() 放大到 4x 画布——漏掉这一步，主体会画在 1024 画布的左上角一小块里"""
    x0, y0, x1, y1 = box
    d.rectangle(z(x0 + rad, y0, x1 - rad, y1), fill=fill)
    d.rectangle(z(x0, y0 + rad, x1, y1 - rad), fill=fill)
    for cx, cy in [(x0, y0), (x1 - 2 * rad, y0), (x0, y1 - 2 * rad), (x1 - 2 * rad, y1 - 2 * rad)]:
        d.ellipse(z(cx, cy, cx + 2 * rad, cy + 2 * rad), fill=fill)


def build():
    im = Image.new('RGBA', (256 * S, 256 * S), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    z = lambda *a: [v * S for v in a]

    rounded_rect(d, z, (12, 30, 244, 226), 20, BODY)                                # 信封主体
    d.line(z(28, 48, 128, 150, 228, 48), fill=FLAP, width=26 * S, joint='curve')      # 封口 V（纯分面）
    d.ellipse(z(98, 120, 158, 180), fill=SEAL)                                        # 封蜡红点压在 V 顶点

    return im.resize((256, 256), Image.LANCZOS)


if __name__ == '__main__':
    out = os.path.normpath(sys.argv[1] if len(sys.argv) > 1 else OUT)
    img = build()
    img.save(out, optimize=True)
    print('wrote %s  %dx%d  %s  %d bytes' % (out, img.size[0], img.size[1], img.mode, os.path.getsize(out)))
