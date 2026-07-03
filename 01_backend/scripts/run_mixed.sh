#!/usr/bin/env bash
# AMIAL-MIXED-001 — يشغّل التدفّقات الثلاثة (transfer/topup/settle) بالتوازي على
# نفس الدفتر ويتحقّق أنّه يبقى متوازناً عبرها مجتمعةً.
# الاستخدام: run_mixed.sh [transferWorkers=4] [opsEach=150]
set -uo pipefail
cd "$(dirname "$0")/.."
export DB_DATABASE=amial_conc
TW="${1:-4}"; OPS="${2:-150}"
TMP=$(mktemp -d)

php artisan config:clear >/dev/null 2>&1
echo "═══ تهيئة بيئة متكاملة (أدمن/وكلاء/شريك/محافظ) ═══"
php scripts/mixed.php setup

echo
echo "═══ تشغيل متزامن: ${TW} تحويل + 3 شحن وكلاء + 3 تسويات (كلٌّ ${OPS} عملية) ═══"
START=$(php -r 'echo microtime(true) + 3;')
# تحويلات
for w in $(seq 1 "$TW"); do
  php scripts/mixed.php transfer "$OPS" "$START" > "$TMP/tr$w.out" 2>&1 &
done
# شحن وكلاء
for w in 1 2 3; do
  php scripts/mixed.php topup "$OPS" "$START" > "$TMP/tu$w.out" 2>&1 &
done
# تسويات
for w in 1 2 3; do
  php scripts/mixed.php settle "$OPS" "$START" > "$TMP/se$w.out" 2>&1 &
done
wait

echo "نتائج العمّال:"
cat "$TMP"/tr*.out "$TMP"/tu*.out "$TMP"/se*.out | sed 's/^/   /'

echo
php scripts/mixed.php check
rm -rf "$TMP"
