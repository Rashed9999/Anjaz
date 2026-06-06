#!/usr/bin/env bash
#
# AMYAL-BRANDING-001
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

echo "=== Amyal Pay — Native Assets Generator ==="

# 1) flutter pub get لضمان الـ dependencies الجديدة (flutter_native_splash, flutter_launcher_icons)
echo "[1/4] flutter pub get..."
flutter pub get

# 2) توليد native splash
echo "[2/4] Generating native splash..."
dart run flutter_native_splash:create

# 3) توليد launcher icons (اختياري — أصلاً نسخنا الأيقونات يدوياً)
# نشغّله فقط لو أردت إعادة توليدها بالكامل من المصدر
# echo "[3/4] Generating launcher icons (optional)..."
# dart run flutter_launcher_icons

echo "[3/4] Skipping launcher_icons (using pre-generated icons from assets/branding/android,ios)"

# 4) clean & rebuild
echo "[4/4] flutter clean..."
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
