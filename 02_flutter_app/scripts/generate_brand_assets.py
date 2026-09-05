#!/usr/bin/env python3
"""Generate every native Amial icon from one corrected source.

The old repository contained mixed generations: the source artwork said AMIAL,
while Android's adaptive foreground and launch assets still said AMYAL and
carried a white rectangular frame. Keep this generator as the only path for
changing those generated files.

Run from 02_flutter_app:

    python3 scripts/generate_brand_assets.py
    python3 scripts/generate_launch_logo.py
"""

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'assets/image/logo.png'
BRANDING = ROOT / 'assets/branding'
ANDROID = ROOT / 'android/app/src/main/res'
IOS = ROOT / 'ios/Runner/Assets.xcassets/AppIcon.appiconset'

# The adaptive icon background in Android resources.
YELLOW = (254, 202, 30, 255)

DENSITIES = {
    'mdpi': (48, 108),
    'hdpi': (72, 162),
    'xhdpi': (96, 216),
    'xxhdpi': (144, 324),
    'xxxhdpi': (192, 432),
}


def is_white_frame(px):
    r, g, b, a = px
    return a > 0 and r >= 235 and g >= 225 and b >= 190


def is_brand_ink(px):
    r, g, b, a = px
    if a == 0:
        return False
    # Blue Arabic/Latin lettering and the red hamza/swoosh are the only
    # foreground inks. Everything else is old yellow-card or frame noise.
    blue = b > 70 and b > r * 1.08 and b > g * 1.05
    red = r > 150 and g < 160 and b < 130 and r > g * 1.28
    return blue or red


def without_white_frame(source):
    """Remove only the old white rectangle, preserving original lettering."""
    image = source.copy().convert('RGBA')
    pixels = image.load()
    for y in range(image.height):
        for x in range(image.width):
            if is_white_frame(pixels[x, y]):
                pixels[x, y] = YELLOW
    return image


def foreground_from(source):
    """Create a transparent adaptive foreground: lettering/swoosh only."""
    image = without_white_frame(source)
    pixels = image.load()
    for y in range(image.height):
        for x in range(image.width):
            if not is_brand_ink(pixels[x, y]):
                pixels[x, y] = (0, 0, 0, 0)
    box = image.getchannel('A').getbbox()
    if box is None:
        raise RuntimeError('The brand source contains no visible logo ink')
    mark = image.crop(box)
    target_width = 680
    target_height = round(target_width * mark.height / mark.width)
    canvas = Image.new('RGBA', (1024, 1024), (0, 0, 0, 0))
    canvas.alpha_composite(
        mark.resize((target_width, target_height), Image.Resampling.LANCZOS),
        ((1024 - target_width) // 2, (1024 - target_height) // 2),
    )
    return canvas


def square_from(source):
    """Fallback icon for pre-adaptive Android and iOS."""
    master = Image.new('RGBA', (1024, 1024), YELLOW)
    master.alpha_composite(foreground_from(source))
    return master


def write_png(image, path):
    path.parent.mkdir(parents=True, exist_ok=True)
    image.save(path, optimize=True)


def main():
    if not SOURCE.exists():
        raise SystemExit(f'Missing source: {SOURCE}')

    source = Image.open(SOURCE).convert('RGBA')
    master = square_from(source)
    foreground = foreground_from(source)

    # Canonical sources used by Flutter, launcher generation, and splash generation.
    write_png(master.resize(source.size, Image.Resampling.LANCZOS), SOURCE)
    write_png(master, BRANDING / 'icon_square_master.png')
    write_png(foreground, BRANDING / 'icon_foreground_wordmark.png')
    write_png(foreground, BRANDING / 'splash_icon.png')

    # The in-app wordmark has the same lettering and no independent stale text.
    write_png(master.resize((562, 562), Image.Resampling.LANCZOS), BRANDING / 'wordmark.png')

    for density, (launcher_size, foreground_size) in DENSITIES.items():
        write_png(master.resize((launcher_size, launcher_size), Image.Resampling.LANCZOS),
                  ANDROID / f'mipmap-{density}/ic_launcher.png')
        write_png(master.resize((launcher_size, launcher_size), Image.Resampling.LANCZOS),
                  ANDROID / f'mipmap-{density}/ic_launcher_round.png')
        write_png(foreground.resize((foreground_size, foreground_size), Image.Resampling.LANCZOS),
                  ANDROID / f'drawable-{density}/ic_launcher_foreground.png')

    # Preserve every iOS-required size but derive each from the same master.
    for path in IOS.glob('*.png'):
        with Image.open(path) as previous:
            size = previous.size
        write_png(master.resize(size, Image.Resampling.LANCZOS).convert('RGB'), path)

    print('Generated AMIAL launcher, adaptive, splash, and iOS icon assets.')


if __name__ == '__main__':
    main()
