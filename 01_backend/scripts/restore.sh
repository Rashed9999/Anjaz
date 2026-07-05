#!/bin/bash
#
# AMIAL-BACKUP-001 (v1.0-C)
#
# سكريبت استعادة من نسخة احتياطية.
#
# **الاستخدام:**
#   ./scripts/restore.sh /var/backups/amial_pay/amial_*_db.sql.gz
#
# **⚠️ تحذير:** هذا يستبدل قاعدة البيانات الحالية كاملةً. اعمل backup
# جديد قبل التشغيل لو في production.

set -euo pipefail

if [ $# -lt 1 ]; then
    echo "Usage: $0 <db_backup.sql.gz>"
    echo "  e.g.: $0 /var/backups/amial_pay/amial_prod_20260517_020000_db.sql.gz"
    exit 1
fi

BACKUP_FILE="$1"
if [ ! -f "$BACKUP_FILE" ]; then
    echo "❌ Backup file not found: $BACKUP_FILE"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$APP_DIR/.env"

# ENV > .env (متغيّرات البيئة المضبوطة مسبقاً لها الأولوية)
if [ -f "$ENV_FILE" ]; then
    while IFS='=' read -r k v; do
        [[ "$k" =~ ^DB_ ]] || continue
        [[ -z "${!k+x}" ]] && export "$k=$v"
    done < <(grep -v '^#' "$ENV_FILE" | grep -E '^DB_')
fi

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_DATABASE:?}"
: "${DB_USERNAME:?}"
: "${DB_PASSWORD:?}"

# ============================================================
# Verify checksum
# ============================================================
if [ -f "${BACKUP_FILE}.sha256" ]; then
    echo "[1/4] Verifying checksum..."
    if ! sha256sum -c "${BACKUP_FILE}.sha256"; then
        echo "❌ Checksum mismatch! Backup file is corrupted."
        exit 1
    fi
    echo "    ✓ Checksum OK"
else
    echo "⚠️  No checksum file found — proceeding without verification"
fi

# ============================================================
# Confirmation
# ============================================================
echo ""
echo "============================================================"
echo "⚠️  RESTORE WILL REPLACE DATABASE: $DB_DATABASE"
echo "    Source: $BACKUP_FILE"
echo "    Host:   $DB_HOST:$DB_PORT"
echo "============================================================"
# RESTORE_ASSUME_YES=1 يتخطّى السؤال (لأتمتة اختبار التعافي/CI فقط)
if [ "${RESTORE_ASSUME_YES:-0}" = "1" ]; then
    echo "RESTORE_ASSUME_YES=1 → متابعة بلا سؤال (وضع مؤتمت)"
else
    read -p "Type 'CONFIRM RESTORE' to proceed: " confirmation
    if [ "$confirmation" != "CONFIRM RESTORE" ]; then
        echo "Aborted."
        exit 1
    fi
fi

# ============================================================
# Pre-restore backup (safety)
# ============================================================
echo ""
echo "[2/4] Creating safety backup of current DB..."
SAFETY_BACKUP="/tmp/amial_pre_restore_$(date +%s).sql.gz"
mysqldump \
    --host="$DB_HOST" --port="$DB_PORT" \
    --user="$DB_USERNAME" --password="$DB_PASSWORD" \
    --single-transaction --quick \
    "$DB_DATABASE" 2>/dev/null | gzip > "$SAFETY_BACKUP"
echo "    ✓ Safety backup: $SAFETY_BACKUP"

# ============================================================
# Restore
# ============================================================
echo ""
echo "[3/4] Restoring from $BACKUP_FILE..."
gunzip -c "$BACKUP_FILE" | mysql \
    --host="$DB_HOST" --port="$DB_PORT" \
    --user="$DB_USERNAME" --password="$DB_PASSWORD" \
    "$DB_DATABASE"
echo "    ✓ Database restored"

# ============================================================
# Post-restore checks
# ============================================================
echo ""
echo "[4/4] Running post-restore checks..."
cd "$APP_DIR"
php artisan migrate:status | tail -5 || echo "    (migrate:status unavailable)"
php artisan cache:clear
php artisan config:clear
echo "    ✓ Caches cleared"

echo ""
echo "============================================================"
echo "✓ Restore completed."
echo "  Safety backup of pre-restore state: $SAFETY_BACKUP"
echo "============================================================"
