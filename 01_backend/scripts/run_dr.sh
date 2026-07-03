#!/usr/bin/env bash
# AMIAL-DR-001 — Disaster Recovery + Backup Validation (#5 + #6)
#
# دورة كارثة كاملة على قاعدة معزولة (amial_conc) باستخدام سكربتات الإنتاج
# الحقيقية backup.sh / restore.sh:
#   1) تعبئة بيانات مالية + بصمة (عدد الصفوف + توازن الدفتر)
#   2) نسخ احتياطي حقيقي (mysqldump + gzip + sha256)  ← backup.sh
#   3) 💥 كارثة: DROP DATABASE (فقد كامل)
#   4) استعادة من النسخة  ← restore.sh (مع تحقّق checksum)
#   5) مقارنة البصمة: يجب أن تتطابق تماماً (صفر معاملة مفقودة)
#   6) Backup Validation: إفساد بايت في النسخة → يجب أن ترفض restore.sh
set -uo pipefail
cd "$(dirname "$0")/.."

DB=amial_conc
ROOT="mysql -uroot"
BK=/tmp/dr_backups
rm -rf "$BK"; mkdir -p "$BK"

fingerprint() {
  $ROOT -N -B "$DB" -e "
    SELECT
      (SELECT COUNT(*) FROM ledger_accounts),
      (SELECT COUNT(*) FROM ledger_journal_entries),
      (SELECT COUNT(*) FROM ledger_entry_lines),
      (SELECT COALESCE(SUM(amount),0) FROM ledger_entry_lines WHERE direction='debit'),
      (SELECT COALESCE(SUM(amount),0) FROM ledger_entry_lines WHERE direction='credit');
  " 2>/dev/null | tr '\t' '|'
}

echo "═══ (1) تعبئة بيانات مالية في ${DB} ═══"
DB_DATABASE=$DB php artisan config:clear >/dev/null 2>&1
DB_DATABASE=$DB php scripts/chaos.php seed >/dev/null
DB_DATABASE=$DB php scripts/chaos.php run 3000 >/dev/null 2>&1
FP_BEFORE=$(fingerprint)
ROWS_BEFORE=$($ROOT -N -B "$DB" -e "SELECT COUNT(*) FROM ledger_entry_lines;" 2>/dev/null)
echo "البصمة قبل الكارثة: ${FP_BEFORE}"

echo
echo "═══ (2) نسخ احتياطي حقيقي عبر backup.sh ═══"
BACKUP_DIR=$BK DB_DATABASE=$DB bash scripts/backup.sh 2>&1 \
  | grep -E "Database:|Files:|Backup completed|SHA256" | sed 's/^/  /'
DBFILE=$(ls -t "$BK"/*_db.sql.gz | head -1)
echo "  ملفّ النسخة: $DBFILE ($(du -h "$DBFILE" | cut -f1))"

echo
echo "═══ (3) 💥 كارثة: حذف قاعدة البيانات بالكامل ═══"
$ROOT -e "DROP DATABASE ${DB}; CREATE DATABASE ${DB} CHARACTER SET utf8mb4;"
$ROOT -e "GRANT ALL ON ${DB}.* TO 'amial'@'127.0.0.1'; GRANT ALL ON ${DB}.* TO 'amial'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null
AFTER_DROP=$($ROOT -N -B "$DB" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB}';" 2>/dev/null)
echo "  جداول بعد الحذف: ${AFTER_DROP} (يجب=0)"

echo
echo "═══ (4) استعادة عبر restore.sh (تحقّق checksum + استيراد) ═══"
RESTORE_ASSUME_YES=1 DB_DATABASE=$DB bash scripts/restore.sh "$DBFILE" 2>&1 \
  | grep -E "Checksum|Restoring|restored|Restore completed|Verifying" | sed 's/^/  /'

echo
echo "═══ (5) مقارنة البصمة بعد الاستعادة ═══"
FP_AFTER=$(fingerprint)
echo "البصمة بعد الاستعادة: ${FP_AFTER}"
if [ "$FP_BEFORE" = "$FP_AFTER" ]; then
  echo "✓ تطابق تامّ — صفر معاملة مفقودة، الدفتر متطابق للملّيم"
  DR_OK=1
else
  echo "✗ اختلاف! قبل=${FP_BEFORE} بعد=${FP_AFTER}"
  DR_OK=0
fi

echo
echo "═══ (6) Backup Validation: إفساد النسخة → يجب أن ترفض restore.sh ═══"
CORRUPT="$BK/corrupt_db.sql.gz"
cp "$DBFILE" "$CORRUPT"; cp "${DBFILE}.sha256" "${CORRUPT}.sha256"
# نعدّل sha256 ليشير للملفّ المفسود، ثمّ نفسد بايتاً في وسط الملفّ
sed -i "s#$(basename "$DBFILE")#$(basename "$CORRUPT")#" "${CORRUPT}.sha256"
printf '\xDE\xAD' | dd of="$CORRUPT" bs=1 seek=200 count=2 conv=notrunc 2>/dev/null
if RESTORE_ASSUME_YES=1 DB_DATABASE=$DB bash scripts/restore.sh "$CORRUPT" >/dev/null 2>&1; then
  echo "✗ خطر: قبلت restore.sh نسخة مفسودة!"
  VAL_OK=0
else
  echo "✓ رفضت restore.sh النسخة المفسودة (checksum mismatch) — الحماية تعمل"
  VAL_OK=1
fi

echo
echo "════════════════════════════════════════"
if [ "$DR_OK" = 1 ] && [ "$VAL_OK" = 1 ]; then
  echo "VERDICT: PASS ✓ التعافي كامل (${ROWS_BEFORE} سطر) + التحقّق من السلامة يعمل"
else
  echo "VERDICT: FAIL ✗"
fi
