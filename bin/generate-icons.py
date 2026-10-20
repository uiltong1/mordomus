#!/usr/bin/env python3
from __future__ import annotations

import pathlib

from PIL import Image, ImageDraw

OUT = pathlib.Path(__file__).resolve().parent.parent / "frontend" / "public"
SS = 4

BG = "#0f172a"
ROOF = "#38bdf8"
BASE = "#94a3b8"


def draw_icon(size: int, maskable: bool = False) -> Image.Image:
    s = size * SS
    img = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)

    if maskable:
        d.rectangle([0, 0, s, s], fill=BG)
        k = 0.52
    else:
        radius = round(s * 14 / 64)
        d.rounded_rectangle([0, 0, s - 1, s - 1], radius=radius, fill=BG)
        k = 1.0

    def pt(x: float, y: float) -> tuple[int, int]:
        return (round(s * x / 64 * k + s * (1 - k) / 2), round(s * y / 64 * k + s * (1 - k) / 2))

    stroke = max(1, round(s * 5 / 64 * k))

    def stroke_path(points: list[tuple[float, float]], color: str) -> None:
        d.line([pt(x, y) for x, y in points], fill=color, width=stroke, joint="curve")
        r = stroke / 2
        for x, y in points:
            cx, cy = pt(x, y)
            d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=color)

    stroke_path([(16, 40), (32, 16), (48, 40)], ROOF)
    stroke_path([(24, 46), (40, 46)], BASE)

    return img.resize((size, size), Image.LANCZOS)


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    targets = [
        ("icon-192.png", 192, False),
        ("icon-512.png", 512, False),
        ("icon-maskable-512.png", 512, True),
        ("apple-touch-icon.png", 180, False),
    ]
    for name, size, maskable in targets:
        path = OUT / name
        draw_icon(size, maskable).save(path, "PNG", optimize=True)
        print(f"{path.relative_to(OUT.parent.parent)}  {size}x{size}{' maskable' if maskable else ''}")


if __name__ == "__main__":
    main()
