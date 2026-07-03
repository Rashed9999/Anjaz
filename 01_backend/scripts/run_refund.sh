#!/usr/bin/env bash
# AMIAL-REFUND-001 — N طلب استرداد جزئي متزامن على نفس البيع.
# قيمة البيع 10000، كل عامل يطلب 2500 → يجب أن ينجح 4 فقط (=10000) والبقيّة تُرفَض.
# الاستخدام: run_refund.sh [workers=8] [amountEach=2500]
set -uo pipefail
cd "$(dirname "$0")/.."
export DB_DATABASE=amial_conc
W="${1:-8}"; AMT="${2:-2500}"
TMP=$(mktemp -d)

php artisan config:clear >/dev/null 2>&1
echo "═══ تهيئة: بيع 10000، تاجر مموّل، عميل مسجّل ═══"
php scripts/refund.php setup

echo
echo "═══ ${W} طلب استرداد متزامن × ${AMT} (المجموع المطلوب $(( W * AMT )) > 10000) ═══"
START=$(php -r 'echo microtime(true) + 2;')
for i in $(seq 1 "$W"); do
  php scripts/refund.php worker "$AMT" "$START" > "$TMP/w$i.out" 2>&1 &
done
wait
echo "النتائج:"
cat "$TMP"/w*.out | sort | uniq -c | sed 's/^/   /'
rm -rf "$TMP"

echo
php scripts/refund.php check
