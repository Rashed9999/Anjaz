#!/usr/bin/env bash
#
# AMIAL-LOADTEST-002 — تشغيل المراحل بالتتابع مع تقرير منفصل لكل مستوى.
#
# يشغّل staged_all_features.js عند كل مستوى VU (20 → 10000) كمرحلة مفردة ثابتة،
# ويحفظ تقرير JSON + ملخّص نصّي لكل مستوى في loadtests/reports/.
#
# الاستخدام:
#   export BASE_URL=https://staging.amialpay.com
#   ./loadtests/run_stages.sh                  # كل المستويات، مدّة 2m لكل مرحلة
#   STAGE_DURATION=1m POS_WRITES=1 ./loadtests/run_stages.sh
#   LEVELS="20 100 500" ./loadtests/run_stages.sh   # مستويات مخصّصة
#
# المتطلّبات: k6 مثبّت، و tokens.json/merchants.json بجانب السكربت.

set -euo pipefail

cd "$(dirname "$0")"

BASE_URL="${BASE_URL:-http://localhost:8000}"
STAGE_DURATION="${STAGE_DURATION:-2m}"
POS_WRITES="${POS_WRITES:-0}"
LEVELS="${LEVELS:-20 100 500 1000 4000 10000}"

if ! command -v k6 >/dev/null 2>&1; then
  echo "✗ k6 غير مثبّت. ثبّته أولاً: https://k6.io/docs/get-started/installation/" >&2
  exit 1
fi
for f in tokens.json merchants.json; do
  [ -f "$f" ] || { echo "✗ مفقود: loadtests/$f — شغّل LoadTestSeeder وانسخه هنا." >&2; exit 1; }
done

mkdir -p reports
echo "BASE_URL=$BASE_URL | duration/level=$STAGE_DURATION | POS_WRITES=$POS_WRITES"
echo "المستويات: $LEVELS"
echo "==============================================="

SUMMARY="reports/_summary.csv"
echo "level,p95_ms,http_fail_rate,checks_ok" > "$SUMMARY"

for level in $LEVELS; do
  echo ""
  echo ">>> المرحلة: $level VU (مدّة $STAGE_DURATION)"
  out="reports/report_${level}.json"

  k6 run \
    -e BASE_URL="$BASE_URL" \
    -e STAGE="$level" \
    -e STAGE_DURATION="$STAGE_DURATION" \
    -e POS_WRITES="$POS_WRITES" \
    --summary-export "$out" \
    staged_all_features.js || echo "⚠️ المرحلة $level أنهت بثresholds فاشلة (راجع $out)"

  # استخراج مؤشّرات أساسية للملخّص (إن توفّر jq)
  if command -v jq >/dev/null 2>&1 && [ -f "$out" ]; then
    p95=$(jq -r '.metrics.http_req_duration["p(95)"] // "—"' "$out")
    fail=$(jq -r '.metrics.http_req_failed.rate // "—"' "$out")
    checks=$(jq -r '.metrics.checks.rate // "—"' "$out")
    echo "$level,$p95,$fail,$checks" >> "$SUMMARY"
    echo "    p95=${p95}ms  http_fail=${fail}  checks=${checks}"
  fi
done

echo ""
echo "==============================================="
echo "✓ انتهى. التقارير في loadtests/reports/ — الملخّص: $SUMMARY"
