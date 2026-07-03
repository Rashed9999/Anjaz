#!/usr/bin/env bash
# AMIAL-BENCH-001 — منحنى نقطة الانهيار: يرفع التزامن تدريجياً ويقيس الإنتاجية
# والكمون ومعدّل الأخطاء حتى تظهر «الركبة» (تشبّع العقدة).
# الاستخدام: run_bench.sh   (المستويات ثابتة أدناه)
set -uo pipefail
cd "$(dirname "$0")/.."
export DB_DATABASE=amial_conc

LEVELS=(1 2 4 8 16 32 64)   # مستويات التزامن
OPS_PER_WORKER=400          # عمليات لكل عامل في كل مستوى
TMP=$(mktemp -d)

php artisan config:clear >/dev/null 2>&1
echo "═══ تهيئة 200 محفظة ═══"
php scripts/bench.php seed 200 >/dev/null
echo "المعالجات المتاحة: $(nproc) | max_connections: $(mysql -uroot -N -e "SHOW VARIABLES LIKE 'max_connections'" | awk '{print $2}')"
echo

printf "%-8s %-12s %-12s %-10s %-10s %-10s %-8s\n" "تزامن" "إجمالي/ث" "p50(ms)" "p95(ms)" "p99(ms)" "max(ms)" "أخطاء"
echo "──────────────────────────────────────────────────────────────────────────────"

PREV_TPUT=0
KNEE=""
for C in "${LEVELS[@]}"; do
  START=$(php -r 'echo microtime(true) + 2;')
  T0=$(date +%s.%N)
  for w in $(seq 1 "$C"); do
    php scripts/bench.php worker "$OPS_PER_WORKER" "$START" "$TMP/lat_${C}_${w}.txt" "$w" > "$TMP/out_${C}_${w}.txt" 2>&1 &
  done
  wait
  T1=$(date +%s.%N)

  WALL=$(echo "$T1 - $T0 - 2" | bc)
  TOTAL_OPS=$(( C * OPS_PER_WORKER ))
  ERR=$(grep -h -o 'err=[0-9]*' "$TMP"/out_${C}_*.txt | sed 's/err=//' | paste -sd+ | bc)
  TPUT=$(echo "scale=0; $TOTAL_OPS / $WALL" | bc)

  cat "$TMP"/lat_${C}_*.txt > "$TMP/all_${C}.txt"
  read -r P50 P95 P99 MAX <<< "$(php -r '
    $l = array_map("floatval", file($argv[1], FILE_IGNORE_NEW_LINES));
    sort($l); $n = count($l);
    printf("%.2f %.2f %.2f %.2f",
      $l[(int)($n*0.50)], $l[(int)($n*0.95)], $l[(int)($n*0.99)], end($l));
  ' "$TMP/all_${C}.txt")"

  printf "%-8s %-12s %-12s %-10s %-10s %-10s %-8s\n" "$C" "$TPUT" "$P50" "$P95" "$P99" "$MAX" "$ERR"

  # كشف الركبة: أوّل مستوى تتوقّف فيه الإنتاجية عن الصعود بأكثر من 5%
  if [ -z "$KNEE" ] && [ "$PREV_TPUT" != "0" ]; then
    GAIN=$(echo "scale=3; ($TPUT - $PREV_TPUT) / $PREV_TPUT" | bc)
    if (( $(echo "$GAIN < 0.05" | bc -l) )); then
      KNEE="$C"
    fi
  fi
  PREV_TPUT=$TPUT
done

echo
echo "════════════════════════════════════════"
if [ -n "$KNEE" ]; then
  echo "الركبة (نقطة تشبّع الإنتاجية) عند تزامن ≈ ${KNEE}"
  echo "أي: أقصى إنتاجية فعّالة على هذه العقدة قرب هذا المستوى؛ الزيادة بعده"
  echo "ترفع الكمون بلا مكسب إنتاجية — إشارة سعة واضحة للتخطيط."
else
  echo "لم تظهر ركبة ضمن المستويات المختبَرة (الإنتاجية ما زالت تصعد عند $C)."
fi
echo "ملاحظة: أرقام عقدة واحدة — تكشف الاتجاه ونقطة التشبّع، لا سعة إنتاج مطلقة."
rm -rf "$TMP"
