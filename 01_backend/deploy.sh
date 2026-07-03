#!/bin/bash
# ══════════════════════════════════════════════════════════
# أميال باي — سكريبت النشر الإنتاجي
#
# الاستخدام:
#   ./deploy.sh
#
# ماذا يفعل:
#   1. نسخة احتياطية تلقائية قبل أيّ تغيير
#   2. بناء صورة جديدة
#   3. تشغيل migrations
#   4. إعادة تشغيل بترتيب آمن (queue أوّلاً، ثمّ app)
#   5. فحص health check قبل اعتبار النشر ناجحاً
#   6. Rollback تلقائي لو فشل health check
# ══════════════════════════════════════════════════════════

set -euo pipefail   # AMIAL-FIX: أوقف عند أيّ خطأ/متغيّر غير مُعرَّف/فشل أنبوب
GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

log() { echo -e "${GREEN}✓${NC} $1"; }
err() { echo -e "${RED}✗${NC} $1"; }
warn() { echo -e "${YELLOW}⚠${NC} $1"; }

COMPOSE_FILE="docker-compose.prod.yml"

if [ ! -f ".env.production" ]; then
    err ".env.production غير موجود! انسخه من .env.production.example أوّلاً."
    exit 1
fi

echo "╔════════════════════════════════════════╗"
echo "║   أميال باي — نشر إنتاجي                ║"
echo "╚════════════════════════════════════════╝"

# ── 1. نسخة احتياطية قبل أيّ شيء ─────────────────────────
if docker ps --format '{{.Names}}' | grep -q "amial-mysql-prod"; then
    log "نسخة احتياطية قبل النشر..."
    bash docker/backup.sh || warn "فشل النسخ الاحتياطي — تابع بحذر"
else
    warn "لا توجد حاوية MySQL قائمة بعد (أوّل نشر؟) — تخطّي النسخ الاحتياطي"
fi

# ── AMIAL-FIX: احفظ صورة النسخة الحالية لأجل rollback حقيقي ──
CURRENT_IMG="$(docker compose -f "$COMPOSE_FILE" images -q amial-app 2>/dev/null || true)"
if [ -n "$CURRENT_IMG" ]; then
    docker tag "$CURRENT_IMG" amial-app:rollback 2>/dev/null || true
    log "وُسِمت الصورة الحالية amial-app:rollback (للتراجع عند الفشل)"
fi

# ── 2. بناء الصورة الجديدة ───────────────────────────────
log "بناء الصورة..."
docker compose -f "$COMPOSE_FILE" build amial-app

# ── 3. تشغيل الخدمات المساندة أوّلاً (DB/Redis) ─────────
log "تشغيل MySQL + Redis..."
docker compose -f "$COMPOSE_FILE" up -d amial-mysql amial-redis

echo -n "⏳ انتظار جاهزية القاعدة"
DB_READY=false
for i in $(seq 1 30); do
    if docker compose -f "$COMPOSE_FILE" exec -T amial-mysql \
        mysqladmin ping -h localhost &>/dev/null; then
        echo ""; DB_READY=true; break
    fi
    echo -n "."
    sleep 2
done
# AMIAL-FIX: لا تُكمِل النشر إن لم تجهز القاعدة (كان يتابع صامتاً)
if [ "$DB_READY" = false ]; then
    err "قاعدة البيانات لم تجهز خلال المهلة — إيقاف النشر."
    exit 1
fi

# ── 4. تشغيل التطبيق (migrations تحدث داخل entrypoint) ──
log "تشغيل التطبيق (migrations + cache)..."
docker compose -f "$COMPOSE_FILE" up -d amial-app

# ── 5. فحص health check قبل اعتبار النشر ناجحاً ─────────
echo -n "⏳ فحص صحّة التطبيق"
HEALTHY=false
for i in $(seq 1 20); do
    if curl -sf http://localhost:8000/health/liveness &>/dev/null; then
        HEALTHY=true
        echo ""
        break
    fi
    echo -n "."
    sleep 3
done

if [ "$HEALTHY" = false ]; then
    err "فشل health check بعد النشر!"
    warn "عرض آخر السجلّات للتشخيص:"
    docker compose -f "$COMPOSE_FILE" logs --tail=50 amial-app || true

    # ── AMIAL-FIX: Rollback حقيقي إلى الصورة السابقة (بدل ترك المعطوبة حيّة) ──
    if docker image inspect amial-app:rollback >/dev/null 2>&1; then
        warn "التراجع إلى النسخة السابقة (amial-app:rollback)..."
        NEW_IMG="$(docker compose -f "$COMPOSE_FILE" images -q amial-app 2>/dev/null || true)"
        docker tag amial-app:rollback "$(docker compose -f "$COMPOSE_FILE" config --images | grep amial-app | head -1 || echo amial-app:latest)" 2>/dev/null || true
        docker compose -f "$COMPOSE_FILE" up -d --no-build amial-app || true
        echo -n "⏳ تحقّق صحّة النسخة المُستعادة"
        for i in $(seq 1 15); do
            if curl -sf http://localhost:8000/health/liveness &>/dev/null; then
                echo ""; log "تمّ التراجع — النسخة السابقة تعمل. راجع فشل النشر الجديد."
                exit 1
            fi
            echo -n "."; sleep 3
        done
        err "فشل التراجع أيضاً — تدخّل يدوي مطلوب فوراً!"
    else
        err "لا توجد صورة rollback (أوّل نشر؟) — النسخة الجديدة معطوبة، تدخّل يدوي مطلوب."
    fi
    exit 1
fi

log "التطبيق يعمل بصحّة جيّدة ✅"

# ── 6. readiness check شامل (DB + storage + queue depth) ─
READY_RESPONSE=$(curl -s http://localhost:8000/health/readiness || echo '{}')
echo "📊 حالة الجاهزية الكاملة:"
echo "$READY_RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$READY_RESPONSE"

echo ""
log "النشر اكتمل بنجاح 🎉"
