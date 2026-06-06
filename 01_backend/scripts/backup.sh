#!/bin/bash
#
# AMIAL-BACKUP-001 (v1.0-C)
#
# نسخة احتياطية يومية كاملة لقاعدة بيانات Amyal Pay + الملفات الحساسة.
#
# **التشغيل:**
#   chmod +x scripts/backup.sh
#   ./scripts/backup.sh
#
# **في crontab (يومياً 02:00 صباحاً):**
#   0 2 * * * /var/www/amial_pay/scripts/backup.sh >> /var/log/amial_backup.log 2>&1
#
# **يتطلب:**
#   - mysqldump, gzip, tar, awscli (لـ S3 sync اختياري)
#   - متغيرات env: DB_DATABASE, DB_USERNAME, DB_PASSWORD, BACKUP_DIR, BACKUP_S3_BUCKET (اختياري)

set -euo pipefail

# ============================================================
# الإعدادات
# ============================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$APP_DIR/.env"

# اقرأ متغيرات من .env
if [ -f "$ENV_FILE" ]; then
    export $(grep -v '^#' "$ENV_FILE" | grep -E '^(DB_|BACKUP_|APP_)' | xargs)
fi

# ============================================================
# Defaults
# ============================================================
: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_DATABASE:?DB_DATABASE not set in .env}"
: "${DB_USERNAME:?DB_USERNAME not set in .env}"
: "${DB_PASSWORD:?DB_PASSWORD not set in .env}"
: "${BACKUP_DIR:=/var/backups/amial_pay}"
: "${BACKUP_RETENTION_DAYS:=30}"

TIMESTAMP=$(date +'%Y%m%d_%H%M%S')
HOST_TAG=$(hostname -s)
BACKUP_NAME="amial_${HOST_TAG}_${TIMESTAMP}"
DB_BACKUP_FILE="$BACKUP_DIR/${BACKUP_NAME}_db.sql.gz"
FILES_BACKUP_FILE="$BACKUP_DIR/${BACKUP_NAME}_files.tar.gz"

mkdir -p "$BACKUP_DIR"

echo "============================================================"
echo "Amial Pay Backup — $TIMESTAMP"
echo "============================================================"

# ============================================================
# 1. Database backup
# ============================================================
echo "[1/4] Backing up MySQL database..."
mysqldump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USERNAME" \
    --password="$DB_PASSWORD" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --no-tablespaces \
    --default-character-set=utf8mb4 \
    "$DB_DATABASE" 2>/dev/null | gzip -9 > "$DB_BACKUP_FILE"

DB_SIZE=$(du -h "$DB_BACKUP_FILE" | cut -f1)
echo "    ✓ Database: $DB_BACKUP_FILE ($DB_SIZE)"

# ============================================================
# 2. Files backup (KYC docs, receipts, uploads)
# ============================================================
echo "[2/4] Backing up sensitive files..."
tar -czf "$FILES_BACKUP_FILE" \
    -C "$APP_DIR" \
    --exclude='storage/framework/cache' \
    --exclude='storage/framework/sessions' \
    --exclude='storage/framework/views' \
    --exclude='storage/logs/*.log' \
    storage/app \
    .env \
    2>/dev/null || true

FILES_SIZE=$(du -h "$FILES_BACKUP_FILE" | cut -f1)
echo "    ✓ Files: $FILES_BACKUP_FILE ($FILES_SIZE)"

# ============================================================
# 3. Checksum (للتحقق من السلامة)
# ============================================================
echo "[3/4] Computing checksums..."
sha256sum "$DB_BACKUP_FILE" > "${DB_BACKUP_FILE}.sha256"
sha256sum "$FILES_BACKUP_FILE" > "${FILES_BACKUP_FILE}.sha256"
echo "    ✓ SHA256 sums recorded"

# ============================================================
# 4. Off-site sync (اختياري — لـ S3/Wasabi/BackBlaze)
# ============================================================
if [ -n "${BACKUP_S3_BUCKET:-}" ] && command -v aws &> /dev/null; then
    echo "[4/4] Syncing to S3..."
    aws s3 cp "$DB_BACKUP_FILE" "s3://${BACKUP_S3_BUCKET}/backups/" --storage-class STANDARD_IA
    aws s3 cp "$FILES_BACKUP_FILE" "s3://${BACKUP_S3_BUCKET}/backups/" --storage-class STANDARD_IA
    aws s3 cp "${DB_BACKUP_FILE}.sha256" "s3://${BACKUP_S3_BUCKET}/backups/"
    aws s3 cp "${FILES_BACKUP_FILE}.sha256" "s3://${BACKUP_S3_BUCKET}/backups/"
    echo "    ✓ Synced to s3://${BACKUP_S3_BUCKET}/backups/"
else
    echo "[4/4] Skipped S3 sync (BACKUP_S3_BUCKET not set or awscli missing)"
    echo "    ⚠️  Recommendation: configure off-site backup before pilot"
fi

# ============================================================
# Cleanup قديم (احتفظ بـ N أيام محلياً)
# ============================================================
echo ""
echo "Cleaning up backups older than $BACKUP_RETENTION_DAYS days..."
find "$BACKUP_DIR" -name "amial_*_db.sql.gz" -mtime +"$BACKUP_RETENTION_DAYS" -delete
find "$BACKUP_DIR" -name "amial_*_files.tar.gz" -mtime +"$BACKUP_RETENTION_DAYS" -delete
find "$BACKUP_DIR" -name "amial_*.sha256" -mtime +"$BACKUP_RETENTION_DAYS" -delete

echo ""
echo "============================================================"
echo "✓ Backup completed: $TIMESTAMP"
echo "  Database: $DB_SIZE | Files: $FILES_SIZE"
echo "============================================================"
