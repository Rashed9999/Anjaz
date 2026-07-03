#!/usr/bin/env bash
# AMIAL-IDEM-001 — exactly-once: N عامل يرسلون نفس مفتاح idempotency معاً.
# الاستخدام: run_idem.sh [workers=30]
set -uo pipefail
cd "$(dirname "$0")/.."
export DB_DATABASE=amial_conc
WORKERS="${1:-30}"
TMP=$(mktemp -d)

php artisan config:clear >/dev/null 2>&1
echo "═══ (0) تهيئة: محفظة تكفي خصماً واحداً ═══"
php scripts/idem.php setup

echo
echo "═══ (1) تزامن: ${WORKERS} عامل بنفس مفتاح idempotency في لحظة موحّدة ═══"
START=$(php -r 'echo microtime(true) + 2;')
for i in $(seq 1 "$WORKERS"); do
  php scripts/idem.php worker "$START" > "$TMP/w$i.out" 2>&1 &
done
wait
echo "توزيع النتائج:"
cat "$TMP"/w*.out | sort | uniq -c | sed 's/^/   /'

echo
echo "═══ (2) إعادة تتابعية (نفس المفتاح مرّتين) — لا خصم مزدوج ═══"
php scripts/idem.php replay

echo
echo "═══ (3) نفس المفتاح بجسم مختلف → 409 conflict، لا خصم ═══"
php scripts/idem.php conflict

echo
php scripts/idem.php check
rm -rf "$TMP"
