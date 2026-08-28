#!/usr/bin/env bash
#
# AMIAL-BRANDING-001
# Script: generate_native_assets.sh
#
# يولّد:
#   1. native splash لـ Android + iOS من assets/branding/splash_icon.png
#   2. launcher icons (لو احتجت تجاوز الأيقونات الحالية)
#
# تشغيل (من جذر المشروع):
#   chmod +x generate_native_assets.sh
#   ./generate_native_assets.sh

set -e

echo "=== Amial Pay — Native Assets Generator ==="

# 1) flutter pub get لضمان الـ dependencies الجديدة (flutter_native_splash, flutter_launcher_icons)
echo "[1/4] flutter pub get..."
flutter pub get

# 2) توحيد الأصل قبل أي توليد: لا نسخة بالهجاء القديم (بالياء) ولا إطار أبيض.
echo "[2/5] Generating canonical brand assets..."
python3 scripts/generate_brand_assets.py
python3 scripts/generate_launch_logo.py

# 3) توليد native splash
echo "[3/5] Generating native splash..."
dart run flutter_native_splash:create

# 4) clean & rebuild
echo "[4/5] flutter clean..."
flutter clean
flutter pub get

echo ""
echo "✓ Native assets generated."
echo ""
echo "Build the APK now with:"
echo "  flutter build apk --debug"
echo ""
echo "Or run on connected device:"
echo "  flutter run"
