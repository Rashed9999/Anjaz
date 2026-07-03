#!/bin/bash
# ══════════════════════════════════════════════════════════
# أميال باي — نسخ احتياطي آلي لقاعدة البيانات
#
# الاستخدام اليدوي:
#   ./docker/backup.sh
#
# للأتمتة (على خادم الإنتاج، بلا Docker):
#   crontab -e
#   0 3 * * * cd /path/to/01_backend && ./docker/backup.sh >> storage/logs/backup.log 2>&1
# ══════════════════════════════════════════════════════════

set -e

BACKUP_DIR="${BACKUP_DIR:-./backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
CONTAINER="${DB_CONTAINER:-amial-mysql-prod}"

mkdir -p "$BACKUP_DIR"

echo "🗄️  نسخ احتياطي — $(date)"

# قراءة بيانات الاتصال من .env.production
if [ -f ".env.production" ]; then
    export $(grep -E '^DB_(DATABASE|USERNAME|PASSWORD)=' .env.production | xargs)
fi

BACKUP_FILE="${BACKUP_DIR}/amial_pay_${TIMESTAMP}.sql.gz"

# النسخ عبر حاوية Docker (لا حاجة لتثبيت mysql-client على المضيف)
docker exec "$CONTAINER" mysqldump \
    -u"${DB_USERNAME}" -p"${DB_PASSWORD}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    "${DB_DATABASE}" | gzip > "$BACKUP_FILE"

if [ -s "$BACKUP_FILE" ]; then
    SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    echo "✓ نسخة قاعدة البيانات: $BACKUP_FILE ($SIZE)"
else
    echo "❌ فشل النسخ الاحتياطي — الملف فارغ!"
    rm -f "$BACKUP_FILE"
    exit 1
fi

# ── AMIAL-FIX: نسخ storage/app (وثائق KYC، إيصالات، ملفّات مرفوعة) ──
# قاعدة البيانات وحدها لا تكفي — المرفقات على القرص تُفقَد بدونها.
STORAGE_FILE="${BACKUP_DIR}/amial_pay_storage_${TIMESTAMP}.tar.gz"
if [ -d "storage/app" ]; then
    tar -czf "$STORAGE_FILE" -C storage app 2>/dev/null && \
        echo "✓ نسخة المرفقات (storage/app): $STORAGE_FILE ($(du -h "$STORAGE_FILE" | cut -f1))" || \
        echo "⚠️ تعذّر نسخ storage/app"
fi

# ── حذف النسخ الأقدم من فترة الاحتفاظ ────────────────────
find "$BACKUP_DIR" -name "amial_pay_*.sql.gz" -mtime "+${RETENTION_DAYS}" -delete
find "$BACKUP_DIR" -name "amial_pay_storage_*.tar.gz" -mtime "+${RETENTION_DAYS}" -delete
echo "✓ حُذفت النسخ الأقدم من ${RETENTION_DAYS} يوماً"

echo "📊 النسخ الحالية:"
ls -lh "$BACKUP_DIR"/*.sql.gz 2>/dev/null | tail -5
