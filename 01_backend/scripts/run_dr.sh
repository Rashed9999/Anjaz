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
#
# ══════════════════════════════════════════════════════════════════════
# AMIAL-PROD-READINESS-001 — **هذا التمرينُ كان يكذب.**
#
# قِيس في تدقيق الجاهزيّة فأخرج `VERDICT: PASS ✓ التعافي كامل` بينما:
#
#   · قاعدةُ `amial_conc` **غيرُ موجودة**، فالتعبئةُ لم تُنشئ شيئاً
#   · البصمةُ قبل = سلسلةٌ فارغة
#   · `DROP DATABASE` أخرج `ERROR 1008 … doesn't exist`
#   · خطوةُ الاستعادة لم تُخرج سطراً واحداً
#   · البصمةُ بعد = سلسلةٌ فارغة
#   · فقيل «✓ تطابق تامّ» لأنّ `"" = ""`
#   · وملفُّ الـsha256 غيرُ موجود، فسقط `cp` وسقط `sed`، وسقطت
#     `restore.sh` لأنّ الملفَّ مفقودٌ لا لأنّه مفسود — فقيل
#     «✓ رفضت النسخةَ المفسودة، الحمايةُ تعمل»
#
# **والتمرينُ الذي يُفترض أن يُثبت التعافيَ أثبت لا شيء، وقال إنّه أثبت
# كلَّ شيء.** وهو نصُّ قاعدة المشروع: حارسٌ يكذب أسوأ من غيابه.
#
# فأُضيفت ثلاثةُ حدود:
#   ① **شروطٌ مسبقة** — القاعدةُ تُنشأ، والتعبئةُ تُتحقَّق، وبصمةٌ فارغةٌ
#      تُوقف التمرينَ فوراً. **فلا شيءَ يُقارَن بلا شيءٍ ويُسمّى نجاحاً.**
#   ② **كلُّ خطوةٍ تُفحص بنتيجتها** لا بمجرّد جريانها.
#   ③ **رمزُ خروجٍ حقيقيّ** — كان يخرج بصفرٍ مهما وقع، فلا تستطيع بوّابةٌ
#      أن تقرأه. وهو الآن في `verify.sh` طبقةً حادية عشرة.
# ══════════════════════════════════════════════════════════════════════
set -uo pipefail
cd "$(dirname "$0")/.."

DB=amial_conc
ROOT="mysql -uroot"
BK=/tmp/dr_backups
rm -rf "$BK"; mkdir -p "$BK"

# **ويُنظَّف ما يُنتَج، في كلّ مخرج.**
#
# AMIAL-PROD-READINESS-001 — `backup.sh` يضغط `storage/app` كاملاً، وهو
# **٢٫٨ جيجا**. وكان التنظيفُ في أوّل التمرين وحدَه (`rm -rf $BK`)، فيبقى
# الأرشيفُ بعد كلّ جولة. **وحين أُدرج التمرينُ في البوّابة صار ذلك يملأ
# قرصَ الجلسة** — ووقع فعلاً في أوّل جولةٍ بعد الإدراج.
#
# و`trap` لا سطرٌ في النهاية: التمرينُ يخرج من عشرة مواضعَ عبر `die`.
cleanup_dr() { rm -rf "$BK" /tmp/amial_pre_restore_*.sql.gz 2>/dev/null || true; }
trap cleanup_dr EXIT

die() { echo; echo "════════════════════════════════════════"; \
        echo "VERDICT: FAIL ✗ $*"; exit 1; }

# **الشرطُ الأوّل: القاعدةُ موجودة.** غيابُها هو ما جعل التمرينَ يمرّ على
# فراغ — فتُنشأ هنا صراحةً بدل افتراض وجودها.
$ROOT -e "CREATE DATABASE IF NOT EXISTS ${DB} CHARACTER SET utf8mb4;" 2>/dev/null \
  || die "تعذّر الاتّصال بـMySQL كـroot — لا تمرينَ بلا قاعدة"

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

# **بصمةٌ فارغةٌ ليست بصمة.** كانت `""` تُقارَن بـ`""` بعد الاستعادة
# فتتطابق، ويُعلَن التعافي كاملاً وما جرى شيء.
[ -n "${FP_BEFORE//|/}" ] || die "البصمةُ قبل الكارثة فارغة — التعبئةُ لم تُنتج بيانات، فلا شيءَ يُستعاد"
[ "${ROWS_BEFORE:-0}" -gt 0 ] 2>/dev/null \
  || die "صفرُ قيودٍ قبل الكارثة — تمرينٌ على قاعدةٍ خاوية لا يُثبت تعافياً"

echo
echo "═══ (2) نسخ احتياطي حقيقي عبر backup.sh ═══"
BACKUP_DIR=$BK DB_DATABASE=$DB BACKUP_SKIP_FILES=1 bash scripts/backup.sh 2>&1 \
  | grep -E "Database:|Files:|Backup completed|SHA256" | sed 's/^/  /'
DBFILE=$(ls -t "$BK"/*_db.sql.gz 2>/dev/null | head -1)

# **النسخةُ تُفحص قبل الاعتماد عليها.** و`scripts/backup.sh` كان **ملفّاً
# فارغاً (٠ بايت)** منذ التزام `3691383` في ٨ أغسطس — أُفرغ عرضاً في
# التزامٍ عن حرّاس الإملاء — **فلم يكن يُنتج نسخةً إطلاقاً، ولم يلحظ أحد
# لأنّ هذا التمرينَ لم يكن في البوّابة.**
[ -n "$DBFILE" ] && [ -s "$DBFILE" ] \
  || die "backup.sh لم يُنتج نسخةً — راجِع أنّ scripts/backup.sh ليس فارغاً"
[ -s "${DBFILE}.sha256" ] \
  || die "لا بصمةَ sha256 مع النسخة — فلا يُكشَف فسادُها عند الاستعادة"
gzip -t "$DBFILE" 2>/dev/null \
  || die "النسخةُ مضغوطةٌ فاسدة — gzip -t يرفضها"

echo "  ملفّ النسخة: $DBFILE ($(du -h "$DBFILE" | cut -f1))"

echo
echo "═══ (3) 💥 كارثة: حذف قاعدة البيانات بالكامل ═══"
$ROOT -e "DROP DATABASE ${DB}; CREATE DATABASE ${DB} CHARACTER SET utf8mb4;"
$ROOT -e "GRANT ALL ON ${DB}.* TO 'amial'@'127.0.0.1'; GRANT ALL ON ${DB}.* TO 'amial'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null
AFTER_DROP=$($ROOT -N -B "$DB" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB}';" 2>/dev/null)
echo "  جداول بعد الحذف: ${AFTER_DROP} (يجب=0)"

# **والكارثةُ تُتحقَّق أنّها وقعت.** قاعدةٌ لم تُحذَف تجعل «الاستعادة»
# تنجح بلا استعادةٍ — البياناتُ لم تغادر أصلاً.
[ "${AFTER_DROP:-x}" = "0" ] \
  || die "لم تُحذف القاعدة (جداول=${AFTER_DROP}) — فما يليه ليس استعادة"

echo
echo "═══ (4) استعادة عبر restore.sh (تحقّق checksum + استيراد) ═══"
RESTORE_START=$(date +%s)
RESTORE_ASSUME_YES=1 DB_DATABASE=$DB bash scripts/restore.sh "$DBFILE" > "$BK/restore.log" 2>&1
RESTORE_RC=$?
RESTORE_SECONDS=$(( $(date +%s) - RESTORE_START ))
grep -E "Checksum|Restoring|restored|Restore completed|Verifying" "$BK/restore.log" | sed 's/^/  /'

# **ونتيجةُ الاستعادة تُقرأ.** كانت تُمرَّر إلى `grep` عبر أنبوب، فيبتلع
# الأنبوبُ رمزَ خروجها ولا يُعرف أسقطت أم نجحت — وخطوةٌ لم تُخرج سطراً
# واحداً كانت تمرّ صامتة.
[ "$RESTORE_RC" -eq 0 ] \
  || die "سقطت restore.sh (رمز ${RESTORE_RC}) — راجِع ${BK}/restore.log"
echo "  زمن الاستعادة: ${RESTORE_SECONDS} ثانية"

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

# **تحضيرُ المشهد يُفحص.** كان `cp` يسقط (لا ملفَّ sha256) ثمّ تسقط
# `restore.sh` لأنّ الملفَّ **مفقود** لا لأنّه **مفسود** — ويُقرأ سقوطُها
# «الحمايةُ تعمل». فحُكم على غيابِ الملفّ بأنّه كشفُ فساد.
cp "$DBFILE" "$CORRUPT"           || die "تعذّر تحضيرُ نسخةٍ مفسودة"
cp "${DBFILE}.sha256" "${CORRUPT}.sha256" || die "تعذّر نسخُ ملفّ البصمة"
sed -i "s#$(basename "$DBFILE")#$(basename "$CORRUPT")#" "${CORRUPT}.sha256"
printf '\xDE\xAD' | dd of="$CORRUPT" bs=1 seek=200 count=2 conv=notrunc 2>/dev/null

# **ويُثبَت أنّ المشهد صحيح قبل قراءة نتيجته:** الملفُّ موجودٌ، وبصمتُه
# موجودةٌ، **وهي لا تطابقه فعلاً**. بلا هذا يُقاس سقوطٌ لسببٍ آخر.
[ -s "$CORRUPT" ] && [ -s "${CORRUPT}.sha256" ] \
  || die "مشهدُ الإفساد لم يُحضَّر — لا يُقاس رفضٌ لسببٍ آخر"
if ( cd "$BK" && sha256sum -c "$(basename "${CORRUPT}").sha256" >/dev/null 2>&1 ); then
  die "الإفسادُ لم يُغيّر البصمة — المشهدُ نفسُه باطل"
fi

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
  # **زمنُ التعافي يُقاس لا يُقدَّر** — وهو رقمُ RTO الوحيدُ الذي يُوثَق به.
  echo "زمن الاستعادة المقيس: ${RESTORE_SECONDS:-?} ثانية (${ROWS_BEFORE} سطر قيد)"
  exit 0
else
  echo "VERDICT: FAIL ✗"
  # **ورمزُ خروجٍ حقيقيّ.** كان يخرج بصفرٍ مهما وقع — فلا بوّابةَ تقرؤه،
  # ولا أحدَ يعلم أنّ تمرينَ التعافي ساقط.
  exit 1
fi
