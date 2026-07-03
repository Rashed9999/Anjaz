#!/usr/bin/env bash
# AMIAL-WA-STRESS-001 — ضغط بوت واتساب: <messages> رسالة عبر <workers> عملية متوازية
# في نفس اللحظة + تكرارات متزامنة + توقيعات فاسدة. يقيس الضياع وidempotency والكمون.
# الاستخدام: run_wa_stress.sh [messages=5000] [workers=8]
set -uo pipefail
cd "$(dirname "$0")/.."
# هذا المشروع يقرأ CACHE_DRIVER (config قديم) — نضبط الاسمين معاً احتياطاً
export DB_DATABASE=amial_conc CACHE_DRIVER=database CACHE_STORE=database SESSION_DRIVER=array

MSGS="${1:-5000}"
WORKERS="${2:-8}"
PER=$(( MSGS / WORKERS ))
TMP=$(mktemp -d)

php artisan config:clear >/dev/null 2>&1
echo "═══ (0) تهيئة نظيفة ═══"
php scripts/wa_stress.php setup

echo
echo "═══ (1) توقيعات فاسدة ×50 — يجب: 200 يُعاد (بلا كشف) لكن صفر معالجة ═══"
php scripts/wa_stress.php badsig 50
php scripts/wa_stress.php seen   # يجب SEEN=0

echo
echo "═══ (2) Idempotency تحت تزامن: 20 عملية متوازية لنفس معرّف الرسالة ═══"
START=$(php -r 'echo microtime(true) + 2;')
for i in $(seq 1 20); do
  php scripts/wa_stress.php dup "wamid.SAME.MSG" "$START" > "$TMP/d$i.out" 2>&1 &
done
wait
grep -h '^DUP' "$TMP"/d*.out | sort | uniq -c
php scripts/wa_stress.php seen   # يجب SEEN=1 (الرسالة المكرّرة عُولجت مرّة واحدة)

echo
echo "═══ (3) العاصفة: ${MSGS} رسالة فريدة عبر ${WORKERS} عملية متوازية ═══"
BASE_SEEN=$(php scripts/wa_stress.php seen | grep -o '[0-9]*')
START=$(php -r 'echo microtime(true) + 3;')
T0=$(date +%s.%N)
for w in $(seq 1 "$WORKERS"); do
  OFF=$(( (w - 1) * PER ))
  php scripts/wa_stress.php burst "$PER" "$OFF" "$TMP/lat$w.txt" "$START" > "$TMP/b$w.out" 2>&1 &
done
wait
T1=$(date +%s.%N)

OK=$(grep -h '^STATS' "$TMP"/b*.out | sed 's/.*ok=\([0-9]*\).*/\1/'        | paste -sd+ | bc)
THR=$(grep -h '^STATS' "$TMP"/b*.out | sed 's/.*throttled=\([0-9]*\).*/\1/' | paste -sd+ | bc)
OTH=$(grep -h '^STATS' "$TMP"/b*.out | sed 's/.*other=\([0-9]*\).*/\1/'     | paste -sd+ | bc)
SEEN=$(php scripts/wa_stress.php seen | grep -o '[0-9]*')
PROCESSED=$(( SEEN - BASE_SEEN ))
WALL=$(echo "$T1 - $T0 - 3" | bc)

# كمون الردّ (ما تراه Meta) مجمَّعاً من كل العمّال
cat "$TMP"/lat*.txt | tr ' ' '\n' | grep -v '^$' > "$TMP/all_lat.txt"
read -r P50 P95 MAX <<< "$(php -r '
  $l = array_map("floatval", file($argv[1], FILE_IGNORE_NEW_LINES));
  sort($l); $n = count($l);
  printf("%.1f %.1f %.1f", $l[(int)($n*0.50)], $l[(int)($n*0.95)], end($l));
' "$TMP/all_lat.txt")"

echo "── النتائج ──"
echo "أُرسلت: ${MSGS} | قُبلت 200: ${OK} | رُفضت 429 (throttle): ${THR} | أخرى: ${OTH}"
echo "عُولجت فعلاً (wa_msg_seen): ${PROCESSED}"
echo "الزمن الكلّي: ${WALL}s | معدّل: $(echo "$OK / $WALL" | bc) رسالة/ثانية"
echo "كمون ردّ الويبهوك: p50=${P50}ms  p95=${P95}ms  max=${MAX}ms"

echo
LOST=$(( OK - PROCESSED ))
if [ "$LOST" -eq 0 ] && [ "$OTH" -eq 0 ]; then
  echo "✓ لا رسالة ضائعة: كل رسالة قُبلت بـ200 عُولجت (${OK}=${PROCESSED})"
else
  echo "✗ ضاعت ${LOST} رسالة (قُبلت لكن لم تُعالَج) أو أخطاء=${OTH}"
fi
if [ "$THR" -gt 0 ]; then
  echo "⚠ THROTTLE: ${THR} رسالة رُفضت 429 — Meta ستعيد المحاولة لكن هذا تأخير/فقد محتمل"
fi
rm -rf "$TMP"
[ "$LOST" -eq 0 ] && [ "$OTH" -eq 0 ] && [ "$THR" -eq 0 ] && echo "VERDICT: PASS ✓" || echo "VERDICT: راجع أعلاه"
