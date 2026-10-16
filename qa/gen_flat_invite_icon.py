"""
平面的「邀请」图标生成器（初版 RUNBOOK §7.44，五套主题版 §7.45）

为什么这个脚本要入库，而 §7.43 那批 AI 生成图的脚本没有：
  · 那张深色海报底图是 AI 出的，原图不留在仓库里，脚本留着也复现不出同一张图（只能复现「裁切+编码」那一步）；
  · 这几张平面图标是**用几何图形画出来的**，一个拱顶轮廓 + 一圈描边 + 一颗印章 + 三行字，
    脚本本身就是它们的源文件。没有这个脚本，下次想调粗印章、换纸色，只能重新猜坐标。

用法（本机 PIL 只有 7.2.0，没有 numpy）：
  "C:\\Program Files\\python\\python" qa/gen_flat_invite_icon.py            # 五张全出，直接覆盖仓库里那五个文件
  "C:\\Program Files\\python\\python" qa/gen_flat_invite_icon.py classic    # 只出一套
  "C:\\Program Files\\python\\python" qa/gen_flat_invite_icon.py --out DIR  # 全出到别处（比对用，不碰仓库）

造型口径（2026-10-16 §7.45，用户口径「换成符合功能的图标」）：
  信封那张表达的是「寄信」，这张卡片的动作是「下请柬邀好友」，所以画成中式请柬的轮廓：
  上半一个半圆拱（帖子形）、下半竖长卡片、外圈一圈深色描边、拱心里一颗印泥红、下方三行字（一行比一行短）。
  五套主题共用同一套轮廓，只换四个色槽（描边/纸面/字/印泥），这样「互不相同」和「都表达邀请」能同时成立。

画布约定（对着同家族 calendar 量的，见 RUNBOOK §7.43 那张表）：
  · classic（modern- 前缀）：256x256 方画布、透明底、墨迹长边要到 calendar 墨迹的 85% 以上（它家是 242）
  · 其余四套：紧裁、长边 132（各自 calendar 的长边也都是 132）
  · 4 倍超采样再缩回：斜边自带抗锯齿，色块内部保持纯色 —— 平面图的命门是「内部不许有渐变」
"""
import os
import sys

from PIL import Image, ImageDraw

S = 4  # 4 倍超采样
TAB_DIR = os.path.join(os.path.dirname(__file__), os.pardir,
                       'sinan-art-source', 'public', 'images', 'tab')

# 主题 id → (文件前缀, 长边像素, None=方画布或 'tight'=紧裁, 四个色槽)
# 色槽取自各家族 calendar 的主色（离线量化后取 top 档，见 §7.45 表），印泥红统一留在红族：
# 「请柬 + 红印」是这五张共同的语义锚点，换主题不该把功能换掉。
THEMES = {
    'classic': ('modern', 256, 'canvas', {
        'rim': (208, 210, 222), 'body': (240, 240, 243), 'line': (176, 180, 196), 'seal': (201, 39, 52)}),
    'gem': ('gem', 132, 'tight', {
        'rim': (186, 196, 208), 'body': (232, 238, 244), 'line': (150, 162, 178), 'seal': (176, 42, 58)}),
    'ink': ('ink', 132, 'tight', {
        'rim': (212, 204, 188), 'body': (246, 243, 236), 'line': (128, 124, 116), 'seal': (178, 44, 46)}),
    'shanse': ('shanse', 132, 'tight', {
        'rim': (206, 164, 92), 'body': (242, 226, 178), 'line': (150, 112, 58), 'seal': (188, 50, 54)}),
    'glass': ('lg', 132, 'tight', {
        'rim': (196, 62, 74), 'body': (244, 230, 232), 'line': (198, 140, 148), 'seal': (192, 0, 0)}),
}


def rounded_rect(d, z, box, rad, fill):
    """PIL 7.2 没有 ImageDraw.rounded_rectangle（8.2 才有），自己用两个矩形 + 四个圆拼
    注意坐标要经过 z() 放大到 4x 画布——漏掉这一步，主体会画在 1024 画布的左上角一小块里"""
    x0, y0, x1, y1 = box
    d.rectangle(z(x0 + rad, y0, x1 - rad, y1), fill=fill)
    d.rectangle(z(x0, y0 + rad, x1, y1 - rad), fill=fill)
    for cx, cy in [(x0, y0), (x1 - 2 * rad, y0), (x0, y1 - 2 * rad), (x1 - 2 * rad, y1 - 2 * rad)]:
        d.ellipse(z(cx, cy, cx + 2 * rad, cy + 2 * rad), fill=fill)


def card(d, z, cx, yc, r, x0, y0, x1, y1, rad, fill):
    """请柬轮廓：上半是圆心 (cx, yc)、半径 r 的半圆拱，下半是圆角矩形 (x0, y0..x1, y1)
    两者共用同一条中线与左右边界，接痕正好落在 yc 这条水平线上（描边版与内芯版只差 12 单位）"""
    d.pieslice(z(cx - r, yc - r, cx + r, yc + r), 180, 360, fill=fill)
    rounded_rect(d, z, (x0, yc, x1, y1), rad, fill)


def build(pal):
    """按 256 逻辑单位画，返回已缩回 256x256 的 RGBA"""
    im = Image.new('RGBA', (256 * S, 256 * S), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    z = lambda *a: [v * S for v in a]
    a = lambda c: (c[0], c[1], c[2], 255)

    card(d, z, 128, 104, 96, 32, 104, 224, 248, 10, a(pal['rim']))      # 外圈：深色描边
    card(d, z, 128, 104, 84, 44, 104, 212, 236, 8, a(pal['body']))       # 内芯：纸面，四边各让 12
    d.ellipse(z(104, 50, 152, 98), fill=a(pal['seal']))                  # 印泥红，压在拱心里
    for i, x1 in enumerate((192, 176, 152)):                             # 三行字，一行比一行短
        y0 = 132 + i * 28
        rounded_rect(d, z, (64, y0, x1, y0 + 12), 6, a(pal['line']))

    return im.resize((256, 256), Image.LANCZOS)


def fit(img, mode, long_edge):
    """classic 铺回 256 方画布；其余四套紧裁到墨迹框再把长边缩到 132（各自家族的约定）"""
    box = img.getbbox()
    if mode == 'canvas':
        return img
    cropped = img.crop(box)
    w, h = cropped.size
    scale = long_edge / max(w, h)
    return cropped.resize((max(1, int(round(w * scale))), max(1, int(round(h * scale)))), Image.LANCZOS)


def target(theme):
    prefix, long_edge, mode, pal = THEMES[theme]
    return fit(build(pal), mode, long_edge), os.path.join(TAB_DIR, '%s-invite.png' % prefix)


if __name__ == '__main__':
    args = [a for a in sys.argv[1:]]
    out_dir = None
    if '--out' in args:
        i = args.index('--out')
        out_dir, args = args[i + 1], args[:i] + args[i + 2:]
    wanted = args or list(THEMES)
    for name in wanted:
        img, path = target(name)
        if out_dir:
            path = os.path.join(out_dir, os.path.basename(path))
        img.save(path, optimize=True)
        print('wrote %s  %dx%d  %s  %d bytes' % (path, img.size[0], img.size[1], img.mode, os.path.getsize(path)))
