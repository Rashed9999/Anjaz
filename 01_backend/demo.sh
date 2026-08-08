#!/bin/bash
# ══════════════════════════════════════════════════════════════
# أميال باي — سكريبت الديمو الكامل
# الاستخدام:
#   ./demo.sh start          ← تشغيل كامل (أوّل مرّة)
#   ./demo.sh start-wa       ← تشغيل مع واتساب (ngrok)
#   ./demo.sh stop           ← إيقاف
#   ./demo.sh reset          ← إعادة ضبط كاملة (يحذف البيانات)
#   ./demo.sh logs           ← عرض السجلّات المباشرة
#   ./demo.sh ip             ← عرض IP للهاتف
#   ./demo.sh ngrok-url      ← عرض رابط ngrok الحالي
# ══════════════════════════════════════════════════════════════

set -e
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; NC='\033[0m'

log() { echo -e "${GREEN}✓${NC} $1"; }
warn() { echo -e "${YELLOW}⚠${NC} $1"; }
err() { echo -e "${RED}✗${NC} $1"; exit 1; }
info() { echo -e "${BLUE}ℹ${NC} $1"; }

# ── التحقّق من Docker ────────────────────────────────────
check_docker() {
    if ! command -v docker &>/dev/null; then
        err "Docker غير مثبَّت! ثبِّته من: https://docs.docker.com/get-docker/"
    fi
    if ! docker info &>/dev/null; then
        err "Docker غير مشغَّل! شغِّله أوّلاً."
    fi
}

# ── تجهيز ملف .env.demo ─────────────────────────────────
prepare_env() {
    if [ ! -f ".env.demo" ]; then
        err "لا يوجد .env.demo — هل أنت في مجلّد 01_backend؟"
    fi
    # نسخ .env.demo إلى .env داخل الـ build
    cp .env.demo .env
}

# ── الحصول على IP المحلّي ─────────────────────────────────
get_local_ip() {
    # يعمل على Mac وLinux
    if command -v ip &>/dev/null; then
        ip route get 1 | awk '{print $7; exit}' 2>/dev/null || hostname -I | awk '{print $1}'
    else
        # Mac
        ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo "127.0.0.1"
    fi
}

# ══════════════════════════════════════════════════════════
# الأوامر
# ══════════════════════════════════════════════════════════

case "${1:-help}" in

# ── start ────────────────────────────────────────────────
start)
    check_docker
    prepare_env
    echo ""
    echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║     أميال باي — بدء تشغيل الديمو             ║${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"
    echo ""

    info "بناء صورة Docker (قد يأخذ 3-5 دقائق في أوّل مرّة)..."
    docker compose build --quiet

    info "تشغيل الخدمات..."
    docker compose up -d

    info "انتظار التهيئة (migrations + seeders)..."
    sleep 15

    # انتظار حتى يردّ الـ API
    echo -n "⏳ انتظار الـ API"
    for i in $(seq 1 30); do
        if curl -s http://localhost:8000/health/liveness &>/dev/null; then
            echo ""
            break
        fi
        echo -n "."
        sleep 3
    done

    LOCAL_IP=$(get_local_ip)

    echo ""
    echo -e "${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║          الديمو جاهز! ✅                              ║${NC}"
    echo -e "${GREEN}╠══════════════════════════════════════════════════════╣${NC}"
    echo -e "${GREEN}║  API URL (لابتوب): http://localhost:8000              ║${NC}"
    echo -e "${GREEN}║  API URL (هاتف):   http://${LOCAL_IP}:8000            ║${NC}"
    echo -e "${GREEN}╠══════════════════════════════════════════════════════╣${NC}"
    echo -e "${GREEN}║  حسابات الديمو:                                      ║${NC}"
    echo -e "${GREEN}║    أدمن:    admin@amial.pay / Admin@2026              ║${NC}"
    echo -e "${GREEN}║    عميل 1:  777100001 / PIN: 1234                    ║${NC}"
    echo -e "${GREEN}║    عميل 2:  777100002 / PIN: 1234                    ║${NC}"
    echo -e "${GREEN}║    تاجر:    777200001 / PIN: 1234                    ║${NC}"
    echo -e "${GREEN}║    وكيل:    777300001 / PIN: 1234                    ║${NC}"
    echo -e "${GREEN}╠══════════════════════════════════════════════════════╣${NC}"
    echo -e "${GREEN}║  الخطوة التالية للهاتف:                              ║${NC}"
    echo -e "${GREEN}║    عدِّل BASE_URL في Flutter لـ:                     ║${NC}"
    echo -e "${GREEN}║    http://${LOCAL_IP}:8000/api/v1/                   ║${NC}"
    echo -e "${GREEN}║    ثمّ ابنِ APK جديداً                               ║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
    ;;

# ── start-wa (مع WhatsApp/ngrok) ─────────────────────────
start-wa)
    if [ -z "$NGROK_AUTHTOKEN" ] && ! grep -q "NGROK_AUTHTOKEN=." .env.demo 2>/dev/null; then
        warn "لم يُضبَط NGROK_AUTHTOKEN في .env.demo"
        warn "احصل على token مجاني: https://dashboard.ngrok.com/get-started/your-authtoken"
        warn "ثمّ أضفه لـ .env.demo: NGROK_AUTHTOKEN=your_token"
        echo ""
        info "تشغيل بدون واتساب (يمكنك إضافته لاحقاً)..."
    fi

    check_docker
    prepare_env
    docker compose --profile whatsapp up -d --build

    sleep 20

    echo -n "⏳ انتظار ngrok"
    for i in $(seq 1 20); do
        NGROK_URL=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null | \
            python3 -c "import sys,json; data=json.load(sys.stdin); print(data['tunnels'][0]['public_url'])" 2>/dev/null || echo "")
        if [ -n "$NGROK_URL" ]; then
            echo ""
            break
        fi
        echo -n "."
        sleep 2
    done

    if [ -n "$NGROK_URL" ]; then
        echo ""
        echo -e "${CYAN}═══════════════════════════════════════════════════${NC}"
        echo -e "${CYAN}  ✅ WhatsApp Webhook URL:${NC}"
        echo -e "${CYAN}  ${NGROK_URL}/wa/webhook${NC}"
        echo -e "${CYAN}═══════════════════════════════════════════════════${NC}"
        echo ""
        echo "⚡ الخطوات التالية لتفعيل واتساب:"
        echo "   1. افتح Meta Developer Portal → تطبيقك → WhatsApp → Webhook"
        echo "   2. أدخل Callback URL: ${NGROK_URL}/wa/webhook"
        echo "   3. Verify Token: amyal_webhook_2026"
        echo "   4. اشترك في حدث: messages"
    else
        warn "تعذّر الحصول على رابط ngrok — تحقّق من NGROK_AUTHTOKEN"
    fi
    ;;

# ── stop ────────────────────────────────────────────────
stop)
    check_docker
    info "إيقاف الديمو..."
    docker compose --profile whatsapp down
    log "تمّ الإيقاف (البيانات محفوظة)"
    ;;

# ── reset ────────────────────────────────────────────────
reset)
    check_docker
    warn "هذا سيحذف قاعدة البيانات وكل البيانات التجريبية!"
    read -p "هل أنت متأكّد؟ (اكتب 'نعم' للتأكيد): " CONFIRM
    if [ "$CONFIRM" = "نعم" ]; then
        docker compose --profile whatsapp down -v
        log "تمّت إعادة الضبط الكاملة"
    else
        info "تمّ الإلغاء"
    fi
    ;;

# ── logs ─────────────────────────────────────────────────
logs)
    check_docker
    docker compose logs -f amial-app
    ;;

# ── ip ───────────────────────────────────────────────────
ip)
    LOCAL_IP=$(get_local_ip)
    echo ""
    echo -e "${GREEN}IP للهاتف: http://${LOCAL_IP}:8000/api/v1/${NC}"
    echo ""
    echo "استخدم هذا الـ URL في:"
    echo "  lib/util/app_constants.dart  →  BASE_URL"
    ;;

# ── ngrok-url ────────────────────────────────────────────
ngrok-url)
    NGROK_URL=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null | \
        python3 -c "import sys,json; data=json.load(sys.stdin); print(data['tunnels'][0]['public_url'])" 2>/dev/null || echo "")
    if [ -n "$NGROK_URL" ]; then
        echo ""
        echo -e "${GREEN}Webhook URL: ${NGROK_URL}/wa/webhook${NC}"
    else
        warn "ngrok غير مشغَّل — شغِّل: ./demo.sh start-wa"
    fi
    ;;

# ── help ─────────────────────────────────────────────────
*)
    echo ""
    echo "أميال باي — سكريبت الديمو"
    echo ""
    echo "  ./demo.sh start       ← تشغيل الديمو (بدون واتساب)"
    echo "  ./demo.sh start-wa    ← تشغيل الديمو مع واتساب (ngrok)"
    echo "  ./demo.sh stop        ← إيقاف (البيانات محفوظة)"
    echo "  ./demo.sh reset       ← إعادة ضبط كاملة"
    echo "  ./demo.sh logs        ← سجلّات مباشرة"
    echo "  ./demo.sh ip          ← IP لتطبيق الهاتف"
    echo "  ./demo.sh ngrok-url   ← رابط ngrok الحالي"
    echo ""
    ;;

esac
