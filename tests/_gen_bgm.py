#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
生成 5 首「温馨 · 叙事感」背景音乐（纯标准库合成，无版权顾虑）。

用法：C:/Users/Admin/.workbuddy/binaries/python/versions/3.13.12/python.exe tests/_gen_bgm.py

产物：backend/public/uploads/bgm/bgm-1..5.wav（22050Hz 单声道 16bit，约 40 秒，可循环）
将来在后台「背景音乐」里换成你自己的曲子即可，格式与文件名无关。
"""
import math
import os
import struct
import wave

SR = 22050
DUR = 42.0          # 总时长（秒）
OUT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'public', 'uploads', 'bgm')


def midi(n: int) -> float:
    return 440.0 * (2 ** ((n - 69) / 12.0))


# 五声音阶偏移（大调：0 2 4 7 9；小调：0 3 5 7 10）——五声不易撞音，适合做背景
MAJOR_PENTA = [0, 2, 4, 7, 9]
MINOR_PENTA = [0, 3, 5, 7, 10]

# (文件名, 曲名, 根音 MIDI, 音阶, 音符间隔秒, 衰减, 铃音泛音比例)
TRACKS = [
    ('bgm-1', '温暖晨光', 60, MAJOR_PENTA, 1.35, 1.5, 0.22),
    ('bgm-2', '岁月静好', 65, MAJOR_PENTA, 1.55, 1.3, 0.18),
    ('bgm-3', '旧时光',   57, MAJOR_PENTA, 1.70, 1.2, 0.20),
    ('bgm-4', '家人的温度', 62, MAJOR_PENTA, 1.25, 1.6, 0.26),
    ('bgm-5', '慢慢讲述', 57, MINOR_PENTA, 1.80, 1.1, 0.16),
]


def add_note(buf, start, freq, dur, amp, decay, shimmer):
    """把一颗「钢琴/风铃感」的音符叠加进缓冲区（正弦 + 两个泛音，指数衰减）"""
    n0 = int(start * SR)
    n = int(dur * SR)
    for i in range(n):
        idx = n0 + i
        if idx >= len(buf):
            return
        t = i / SR
        env = math.exp(-decay * t) * min(1.0, t / 0.015)
        s = math.sin(2 * math.pi * freq * t)
        s += 0.30 * math.sin(2 * math.pi * freq * 2 * t)
        s += shimmer * math.sin(2 * math.pi * freq * 3 * t)
        buf[idx] += s * amp * env


def render(name, root, scale, step, decay, shimmer):
    total = int(DUR * SR)
    buf = [0.0] * total

    # 低音铺底：根音与五度，极低音量 + 缓慢起伏，营造「叙事」的空间感
    for m in (root - 12, root - 5):
        f = midi(m)
        for i in range(total):
            t = i / SR
            swell = 0.55 + 0.45 * math.sin(2 * math.pi * t / 26.0)
            buf[i] += 0.055 * swell * math.sin(2 * math.pi * f * t)

    # 旋律：在音阶里做「上行为主、偶尔回落」的缓慢琶音，听起来像在轻轻讲述
    t = 0.6
    deg = 0
    while t < DUR - 2.5:
        oct_shift = 12 if (deg % 5 in (3, 4)) else 0
        note = root + scale[deg % len(scale)] + oct_shift
        amp = 0.30 if deg % 4 else 0.36
        add_note(buf, t, midi(note), 3.4, amp, decay, shimmer)
        # 每 3 个音里插一个低八度和声，增加厚度
        if deg % 3 == 2:
            add_note(buf, t + 0.06, midi(note - 12), 3.0, 0.14, decay, shimmer * 0.6)
        t += step * (1.0 if deg % 3 else 1.35)
        deg += 1 if (deg % 7 not in (5, 6)) else -1

    # 归一化到 -3dB 左右，避免削顶
    peak = max(abs(v) for v in buf) or 1.0
    k = 0.70 / peak
    frames = bytearray()
    for v in buf:
        x = int(max(-1.0, min(1.0, v * k)) * 32767)
        frames += struct.pack('<h', x)

    os.makedirs(OUT_DIR, exist_ok=True)
    path = os.path.join(OUT_DIR, name + '.wav')
    with wave.open(path, 'wb') as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(bytes(frames))
    return path, len(frames) / 2 / SR


if __name__ == '__main__':
    for name, title, root, scale, step, decay, shimmer in TRACKS:
        p, dur = render(name, root, scale, step, decay, shimmer)
        size = os.path.getsize(p) / 1024 / 1024
        print('OK  %-22s %-8s %5.1fs  %4.1f MB  %s' % (title, name + '.wav', dur, size, p))
