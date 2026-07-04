#!/usr/bin/env bash
# AMIAL-REPORT-001 — يشغّل كل الاختبارات ثمّ يولّد تقرير PDF مفصّلاً.
set -uo pipefail
cd "$(dirname "$0")/../.."
export DB_DATABASE="${DB_DATABASE:-amial_conc}"
OUT=/tmp/report; mkdir -p "$OUT"

echo "→ تشغيل كل الاختبارات (junit)…"
./vendor/bin/phpunit --log-junit "$OUT/junit.xml" > "$OUT/run.log" 2>&1 || true
echo "→ توليد HTML…"
php scripts/report/gen_test_report.php "$OUT/junit.xml" "$OUT/report.html"
echo "→ تحويل لـ PDF عبر Chromium…"
CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome
PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers "$CHROME" --headless --no-sandbox --disable-gpu \
  --no-pdf-header-footer --print-to-pdf="$OUT/amial_test_report.pdf" \
  "file://$OUT/report.html" 2>/dev/null
echo "✓ التقرير: $OUT/amial_test_report.pdf"
