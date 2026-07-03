#!/bin/bash
# ══════════════════════════════════════════════════════════════
# أميال باي — بناء APK للأندرويد
#
# الاستخدام:
#   cd ../02_flutter_app
#   bash ../01_backend/build-apk.sh
#
# أو مع IP محدّد:
#   bash ../01_backend/build-apk.sh 192.168.1.5
# ══════════════════════════════════════════════════════════════

set -e
GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'

# ── تحديد IP ─────────────────────────────────────────────
if [ -n "$1" ]; then
    LOCAL_IP="$1"
else
    # كشف تلقائي
    if command -v ip &>/dev/null; then
        LOCAL_IP=$(ip route get 1 2>/dev/null | awk '{print $7; exit}' || hostname -I | awk '{print $1}')
    else
        LOCAL_IP=$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo "")
    fi
fi

if [ -z "$LOCAL_IP" ]; then
    echo -e "${YELLOW}⚠ لم أتمكّن من كشف IP تلقائياً.${NC}"
    read -p "أدخل IP لابتوبك يدوياً: " LOCAL_IP
fi

BASE_URL="http://${LOCAL_IP}:8000/api/v1/"

echo ""
echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║     أميال باي — بناء APK للأندرويد          ║${NC}"
echo -e "${CYAN}╠══════════════════════════════════════════════╣${NC}"
echo -e "${CYAN}║  BASE_URL: ${BASE_URL}${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"
echo ""

# ── التحقّق من Flutter ────────────────────────────────────
if ! command -v flutter &>/dev/null; then
    echo "✗ Flutter غير مثبَّت!"
    echo "  ثبِّته من: https://flutter.dev/docs/get-started/install"
    exit 1
fi

# ── التحقّق من أنّنا في مجلّد Flutter ───────────────────
if [ ! -f "pubspec.yaml" ]; then
    echo "✗ شغِّل هذا السكريبت من داخل مجلّد Flutter (02_flutter_app)!"
    exit 1
fi

# ── تنزيل الحزم ──────────────────────────────────────────
echo "📦 تنزيل حزم Flutter..."
flutter pub get

# ── بناء APK ─────────────────────────────────────────────
echo ""
echo "🔨 بناء APK (قد يأخذ 2-5 دقائق)..."
flutter build apk \
    --release \
    --dart-define=BASE_URL="${BASE_URL}" \
    --dart-define=APP_ENV=demo \
    --split-per-abi

APK_PATH="build/app/outputs/flutter-apk/app-arm64-v8a-release.apk"
APK_SIZE=$(du -sh "$APK_PATH" 2>/dev/null | cut -f1 || echo "?")

echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║          APK جاهز! ✅                                ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║  الملفّ: ${APK_PATH}${NC}"
echo -e "${GREEN}║  الحجم:  ${APK_SIZE}                                  ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║  طرق التثبيت على الهاتف:                            ║${NC}"
echo -e "${GREEN}║                                                      ║${NC}"
echo -e "${GREEN}║  1. USB (الأسرع):                                    ║${NC}"
echo -e "${GREEN}║     adb install ${APK_PATH}${NC}"
echo -e "${GREEN}║                                                      ║${NC}"
echo -e "${GREEN}║  2. واي فاي (بدون USB):                              ║${NC}"
echo -e "${GREEN}║     انسخ APK للهاتف عبر أيّ تطبيق مشاركة            ║${NC}"
echo -e "${GREEN}║     ثمّ ثبِّته (السماح بمصادر غير معروفة مطلوب)       ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${YELLOW}مهمّ: تأكّد أنّ الهاتف على نفس شبكة الواي فاي كاللابتوب!${NC}"
