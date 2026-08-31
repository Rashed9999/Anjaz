#!/usr/bin/env python3
"""
AMIAL-SPLASH-004 — توليد شعار شاشة الإقلاع الأصلية (أندرويد).

شاشة الإقلاع هي ما يظهر قبل أن يبدأ Flutter. كانت تعرض
@mipmap/ic_launcher_foreground — وجه الأيقونة التكيّفية — وهي مصمَّمة
بهامش أمان يبلغ ثلث المساحة لتُقصّ داخل قناع دائري. فالشعار فيها لا يملأ
إطاره ولا يقع في مركزه البصري، وعرضُها خامّةً يُظهر شعاراً صغيراً منزاحاً.

هذا السكربت يولّد launch_logo بخمس كثافات من الشعار الأصلي.

    python3 scripts/generate_launch_logo.py

يُعاد تشغيله متى تغيّر الشعار. لا تُحرَّر المخرجات يدوياً.

كما يحدّث splash_icon.png الخاص بأندرويد 12+ من المصدر الموحد
assets/branding/splash_icon.png. كانت هذه الملفات الخمسة قديمة وتحمل
AMYAL بينما شاشة Flutter تحمل AMIAL، فتظهر العلامة بخطأ قبل بدء Flutter.
"""

import os
import sys

try:
    from PIL import Image
except ImportError:
    sys.exit('يحتاج Pillow:  pip install Pillow')

SOURCE = 'assets/image/logo.png'
SPLASH_ICON_SOURCE = 'assets/branding/splash_icon.png'
OUT_DIR = 'android/app/src/main/res/drawable-{}'

# نفس @color/amial_yellow في colors.xml. أي اختلاف يُظهر حافةً حول الشعار.
SPLASH_BG = (0xFE, 0xCA, 0x1E)

# أصفر الشعار نفسه — يحلّ محلّ الرِمّ الفاتح.
LOGO_YELLOW = (254, 202, 28)

# عرض الشعار بوحدات dp. اختير قريباً من نسبته في سبلاش Flutter الذي يليه
# مباشرةً (62% من العرض بحدّ 150dp)، فلا تقع قفزة في الحجم عند الانتقال.
DP_WIDTH = 165
SPLASH_ICON_DP = 288

DENSITIES = {'mdpi': 1, 'hdpi': 1.5, 'xhdpi': 2, 'xxhdpi': 3, 'xxxhdpi': 4}


def strip_pale_rim(image: Image.Image) -> int:
    """يستبدل الرِمّ الفاتح المطبوع عند حدّ المستطيل المستدير.

    ما يميّزه: أحمرُه عالٍ وأزرقُه مرتفع عن أصفر الشعار (28). وألوان الشعار
    الثلاثة تنجو من الشرطين:
        الأصفر (254,202,28)  → b=28  دون الحدّ
        الأزرق (~20,60,140)  → r=20  دون الحدّ
        الأحمر (~220,30,40)  → r=220 دون الحدّ
    """
    px = image.load()
    width, height = image.size
    changed = 0

    for x in range(width):
        for y in range(height):
            r, g, b, a = px[x, y]
            if a > 0 and r > 235 and b > 45:
                px[x, y] = (*LOGO_YELLOW, a)
                changed += 1

    return changed


def main() -> int:
    if not os.path.isfile(SOURCE):
        sys.exit(f'لم يُعثر على {SOURCE} — شغّل السكربت من جذر 02_flutter_app')

    src = Image.open(SOURCE).convert('RGBA')
    width, height = src.size
    print(f'المصدر: {width}×{height}')
    print(f'بكسلات الرِمّ المُستبدلة: {strip_pale_rim(src)}')

    # التسطيح على لون الخلفية يُزيل حافة الشفافية المستديرة كلياً.
    flat = Image.new('RGB', (width, height), SPLASH_BG)
    flat.paste(src, (0, 0), src)

    for name, scale in DENSITIES.items():
        w = int(DP_WIDTH * scale)
        h = round(w * height / width)
        directory = OUT_DIR.format(name)
        os.makedirs(directory, exist_ok=True)
        flat.resize((w, h), Image.LANCZOS).save(
            f'{directory}/launch_logo.png', optimize=True)
        print(f'  {name:<9} {w}×{h}')

    if not os.path.isfile(SPLASH_ICON_SOURCE):
        sys.exit(f'لم يُعثر على {SPLASH_ICON_SOURCE}')

    splash = Image.open(SPLASH_ICON_SOURCE).convert('RGBA')
    for name, scale in DENSITIES.items():
        size = int(SPLASH_ICON_DP * scale)
        directory = OUT_DIR.format(name)
        splash.resize((size, size), Image.LANCZOS).save(
            f'{directory}/splash_icon.png', optimize=True)
        print(f'  splash {name:<9} {size}×{size}')

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
