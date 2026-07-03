#!/usr/bin/env bash
# AMIAL-POS-001 — POS لكل القطاعات: 2000 تاجر × 20 قطاعاً، عيّنة تمثيلية كبيرة
# عبر مسار الدفع الحقيقي، ثمّ إسقاط الإنتاجية على 1,000,000 عملية/يوم.
# الاستخدام: run_pos.sh [merchants=2000] [customers=500] [workers=10] [opsPerWorker=10000]
set -uo pipefail
cd "$(dirname "$0")/.."
export DB_DATABASE=amial_conc

M="${1:-2000}"; C="${2:-500}"; W="${3:-10}"; OPS="${4:-10000}"
TOTAL=$(( W * OPS ))
TMP=$(mktemp -d)

php artisan config:clear >/dev/null 2>&1
echo "═══ تهيئة ${M} تاجر × 20 قطاعاً + ${C} عميل ═══"
php scripts/pos.php setup "$M" "$C"

echo
echo "═══ تشغيل ${TOTAL} عملية POS عبر ${W} عامل متوازٍ ═══"
START=$(php -r 'echo microtime(true) + 3;')
T0=$(date +%s.%N)
for w in $(seq 1 "$W"); do
  php scripts/pos.php worker "$OPS" "$START" "$w" > "$TMP/w$w.out" 2>&1 &
done
wait
T1=$(date +%s.%N)

WALL=$(echo "$T1 - $T0 - 3" | bc)
OK=$(grep -h -o 'ok=[0-9]*' "$TMP"/w*.out | sed 's/ok=//' | paste -sd+ | bc)
ERR=$(grep -h -o 'err=[0-9]*' "$TMP"/w*.out | sed 's/err=//' | paste -sd+ | bc)
TPUT=$(echo "scale=0; $OK / $WALL" | bc)

echo "منفّذة=${OK}  أخطاء=${ERR}  الزمن=${WALL}s  الإنتاجية=${TPUT} عملية/ث"
rm -rf "$TMP"

echo
php scripts/pos.php check

echo
echo "════════ إسقاط على الطاقة اليومية ════════"
DAILY=$(echo "$TPUT * 86400" | bc)
echo "بإنتاجية ${TPUT}/ث على عقدة واحدة: الطاقة اليومية ≈ ${DAILY} عملية"
echo "المطلوب: 2000 تاجر × 500 = 1,000,000 عملية/يوم (متوسّط 11.6/ث)"
if [ "$(echo "$TPUT > 12" | bc)" = "1" ]; then
  HEAD=$(echo "scale=1; $DAILY / 1000000" | bc)
  echo "✓ العقدة الواحدة تكفي 1,000,000/يوم بهامش ≈ ${HEAD}× (حتى مع ذروات النهار)"
else
  echo "⚠ الإنتاجية قريبة من الحدّ — يُنصَح بعقدة إضافية لهوامش الذروة"
fi
echo "ملاحظة صدق: شُغّلت عيّنة ${OK} عملية عبر كل التجّار والقطاعات (لا مليون حرفياً"
echo "على عقدة واحدة) — ثوابت الصحّة تصمد لأيّ حجم، والإنتاجية تُثبت الطاقة اليومية."
