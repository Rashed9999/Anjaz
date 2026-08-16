#!/bin/bash
#
# AMIAL-BACKUP-001 (v1.0-C)
#
# نسخة احتياطية يومية كاملة لقاعدة بيانات Amial Pay + الملفات الحساسة.
#
# ══════════════════════════════════════════════════════════════════════
# AMIAL-PROD-READINESS-001 — **هذا الملفُّ كان صفرَ بايت، وهذا السطرُ سببُه.**
#
# التزام `3691383` (٨ أغسطس) اسمُه «حارس الإملاء يُوسَّع». وُسّع الحارسُ
# فأمسك السطرَ أعلاه — كان يكتب اسمَ العلامة بالياء —
# **وأُفرغ الملفُّ بدل أن تُصحَّح الكلمة**.
#
# (ولا تُقتبَس هنا الصيغةُ القديمةُ حرفاً: هذا الملفُّ يمرّ على الحارس
# نفسِه، فاقتباسُها يُسقطه على شرحِ نفسِه — وهو فخٌّ وقع في هذا المشروع
# ثلاثَ مرّاتٍ من قبل، ووقعتُ فيه هنا في المحاولة الأولى.)
#
# فماتت سلسلةُ التعافي كلُّها ثمانيةَ أيّام: `run_dr.sh` يستدعي هذا
# السكربت، ولا نسخةَ تُنتَج، **ولا أحدَ يعلم** لأنّ التمرينَ لم يكن في أيّ
# بوّابة. وأُعيد من `be2062c`، وأُدرج التمرينُ طبقةً حاديةَ عشرةَ في
# `verify.sh`، وحُرس الملفُّ بـ`BackupIntegrityGuardTest`.
#
# **والدرسُ في التحرير لا في الإملاء** (القاعدة الخامسة): تعبيرٌ نمطيٌّ
# جشعٌ على ملفٍّ كامل لا يُصحّح كلمةً — يمحو ما حولها. ولو كان الملفُّ
# محروساً لسقطت البوّابةُ يومَها بدل أن يُكتشَف بعد ثمانية أيّام في تدقيق.
# ══════════════════════════════════════════════════════════════════════
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

# اقرأ متغيرات من .env — لكن متغيّرات البيئة المضبوطة مسبقاً لها الأولوية
# (سلوك 12-factor: ENV > .env؛ يسمح أيضاً بالاختبار المعزول على قاعدة أخرى)
if [ -f "$ENV_FILE" ]; then
    while IFS='=' read -r k v; do
        [[ "$k" =~ ^(DB_|BACKUP_|APP_) ]] || continue
        [[ -z "${!k+x}" ]] && export "$k=$v"
    done < <(grep -v '^#' "$ENV_FILE" | grep -E '^(DB_|BACKUP_|APP_)')
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
# ══════════════════════════════════════════════════════════════════════
# AMIAL-PROD-READINESS-001 — **منفذُ تخطّي الملفّات، ولمَ وُجد.**
#
# `storage/app` يبلغ **٢٫٨ جيجا** (وثائقُ هويّةٍ وإيصالاتٌ ومرفوعات).
# وحين أُدرج `run_dr.sh` في البوّابة صار هذا الأرشيفُ يُبنى في **كلّ
# جولةِ فحص** — فامتلأ قرصُ الجلسة، وامتلأ في أثناء كتابة InnoDB،
# **فتُركت صفحاتُ الجداول أصفاراً وتلفت قاعدةُ التطوير**:
#
#   InnoDB: Header page consists of zero bytes in datafile:
#           ./amial_pay/account_recovery_requests.ibd
#
# وتمرينُ التعافي **تمرينُ قاعدةِ بيانات**: يحذفها ويستعيدها ويقارن
# البصمة. وملفّاتُ المستخدمين لا تُقاس فيه ولا تُستعاد — فنسخُها كلفةٌ
# بلا فائدةٍ في هذا السياق.
#
# **والنسخُ الحقيقيّةُ على الخادم تبقى كاملة** — الافتراضُ هو النسخ،
# والتخطّي يُطلَب صراحةً. فلا يُفقَد شيءٌ بالسهو.
# ══════════════════════════════════════════════════════════════════════
if [ "${BACKUP_SKIP_FILES:-0}" = "1" ]; then
    echo "[2/4] Skipping files backup (BACKUP_SKIP_FILES=1)"
    FILES_BACKUP_FILE=""
    FILES_SIZE="skipped"
else

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

fi

# ============================================================
# 3. Checksum (للتحقق من السلامة)
# ============================================================
echo "[3/4] Computing checksums..."
sha256sum "$DB_BACKUP_FILE" > "${DB_BACKUP_FILE}.sha256"
[ -n "$FILES_BACKUP_FILE" ] && sha256sum "$FILES_BACKUP_FILE" > "${FILES_BACKUP_FILE}.sha256"
echo "    ✓ SHA256 sums recorded"

# ============================================================
# 4. Off-site sync (اختياري — لـ S3/Wasabi/BackBlaze)
# ============================================================
if [ -n "${BACKUP_S3_BUCKET:-}" ] && command -v aws &> /dev/null; then
    echo "[4/4] Syncing to S3..."
    aws s3 cp "$DB_BACKUP_FILE" "s3://${BACKUP_S3_BUCKET}/backups/" --storage-class STANDARD_IA
    [ -n "$FILES_BACKUP_FILE" ] && aws s3 cp "$FILES_BACKUP_FILE" "s3://${BACKUP_S3_BUCKET}/backups/" --storage-class STANDARD_IA
    aws s3 cp "${DB_BACKUP_FILE}.sha256" "s3://${BACKUP_S3_BUCKET}/backups/"
    [ -n "$FILES_BACKUP_FILE" ] && aws s3 cp "${FILES_BACKUP_FILE}.sha256" "s3://${BACKUP_S3_BUCKET}/backups/"
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
