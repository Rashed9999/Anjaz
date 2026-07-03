#!/usr/bin/env bash
# AMIAL-CONCURRENCY-001 — يُشغّل N عامل متوازٍ يتنافسون على سحب كامل رصيد محفظة
# تكفي سحباً واحداً فقط. يُثبت أنّ القفل الصفّي يمنع البيع المزدوج.
set -uo pipefail

cd "$(dirname "$0")/.."
export DB_DATABASE=amial_conc
WORKERS="${1:-50}"

php artisan config:clear >/dev/null 2>&1

echo "→ تهيئة قاعدة معزولة (amial_conc) …"
php scripts/conc.php migrate >/dev/null
php scripts/conc.php seed

# لحظة انطلاق مشتركة بعد 3 ثوانٍ (تكفي لإقلاع كلّ العمّال ثم الانطلاق معاً)
START=$(php -r 'echo microtime(true) + 3;')
echo "→ إطلاق ${WORKERS} عامل متوازٍ عند لحظة موحّدة …"

TMP=$(mktemp -d)
for i in $(seq 1 "$WORKERS"); do
  php scripts/conc.php worker "$i" "$START" > "$TMP/w$i.out" 2>&1 &
done
wait

OK=$(grep -h '^OK'   "$TMP"/*.out | wc -l | tr -d ' ')
FAIL=$(grep -h '^FAIL' "$TMP"/*.out | wc -l | tr -d ' ')
echo "→ النتائج: ناجح=${OK}  فاشل=${FAIL}  (من ${WORKERS})"
echo "  عيّنة أسباب الفشل:"
grep -h '^FAIL' "$TMP"/*.out | head -2 | sed 's/^/    /'
rm -rf "$TMP"

echo
php scripts/conc.php check
