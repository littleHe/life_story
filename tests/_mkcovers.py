from PIL import Image
import os

src = r"D:\Work\2026\AiWork\life_story\backend\public\uploads\chapter-bg"
dst = r"D:\Work\2026\AiWork\life_story\front\public\assets\covers"
os.makedirs(dst, exist_ok=True)

for i in range(1, 7):
    p = os.path.join(src, f"bg{i}.png")
    im = Image.open(p).convert("RGB")
    w0, h0 = im.size
    W = 800
    im2 = im.resize((W, max(1, round(h0 * W / w0))), Image.LANCZOS)
    im2.save(os.path.join(dst, f"cover{i}.jpg"), "JPEG", quality=82, optimize=True)
    W2 = 1200
    im3 = im.resize((W2, max(1, round(h0 * W2 / w0))), Image.LANCZOS)
    im3.save(os.path.join(dst, f"banner{i}.jpg"), "JPEG", quality=82, optimize=True)
    print(f"bg{i}: {w0}x{h0} -> cover 800x{im2.size[1]}, banner 1200x{im3.size[1]}")
