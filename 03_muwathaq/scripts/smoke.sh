#!/usr/bin/env bash
# MUWATHAQ-PROD-SMOKE-001 — فحص الدخان الخارجي لبوابة موثّق.
#
# الاستعمال بعد نجاح النشر في Coolify:
#   bash 03_muwathaq/scripts/smoke.sh
#   bash 03_muwathaq/scripts/smoke.sh https://muwathaq.amialpay.com
#
# هذا الفحص لا يرسل قراراً ولا يغيّر قضية؛ يثبت فقط أن النطاق والشهادة
# والـPHP وقاعدة موثّق وباب المراجع تعمل من خارج الحاوية.
set -uo pipefail

BASE="${1:-${MUWATHAQ_SMOKE_BASE:-https://muwathaq.amialpay.com}}"
BASE="${BASE%/}"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

PASS=0; FAIL=0
ok()  { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
bad() { printf '  \033[31m✗\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }

probe() {
  local target="$1" body="$2"
  curl -sS -o "$body" -w '%{http_code}|%{time_total}' --max-time 20 "$target" 2>/dev/null || echo "000|0"
}

printf '\n\033[1mفحص دخان موثّق — %s\033[0m\n' "$BASE"
printf '%s\n' "──────────────────────────────────────────────"

# ① الصحة ليست مجرد إقلاع الحاوية: /health يستعلم قاعدة موثّق فعلاً.
R=$(probe "$BASE/health" "$TMPDIR/health.json"); CODE="${R%%|*}"; T="${R##*|}"
if [[ "$CODE" == "200" ]] && grep -qE '"status"\s*:\s*"ok"' "$TMPDIR/health.json" && grep -qE '"service"\s*:\s*"muwathaq"' "$TMPDIR/health.json"; then
  ok "الصحة: PHP وقاعدة موثّق (${T}s)"
else
  bad "الصحة: رمز ${CODE} أو استجابة غير صالحة"
fi

# ② باب المراجع صفحة عامة مقصودة، لكنه يجب أن يحمل CSRF ولا يكشف جلسةً.
R=$(probe "$BASE/login" "$TMPDIR/login.html"); CODE="${R%%|*}"
if [[ "$CODE" == "200" ]] && grep -q 'name="_token"' "$TMPDIR/login.html" && grep -q 'type="password"' "$TMPDIR/login.html"; then
  ok "باب المراجع يعرض نموذجاً محمياً بـ CSRF"
else
  bad "باب المراجع: رمز ${CODE} أو نموذج CSRF مفقود"
fi

# ③ الكوكي آمن في HTTPS؛ لا نطلب Secure عند تشغيل محلي عبر HTTP.
H=$(curl -sSI --max-time 20 "$BASE/login" 2>/dev/null || true)
if grep -qi '^set-cookie:.*httponly' <<<"$H"; then
  if [[ "$BASE" != https://* ]] || grep -qi '^set-cookie:.*secure' <<<"$H"; then
    ok "جلسة المراجع محمية (HttpOnly$([[ "$BASE" == https://* ]] && printf ' + Secure'))"
  else
    bad "جلسة HTTPS بلا علامة Secure"
  fi
else
  bad "استجابة الدخول لا تضبط كوكي HttpOnly"
fi

# ④ مسار غير موجود لا يكشف أسراراً. بوابة موثّق محمية؛ الزائر غير
# المسجل يُعاد عمداً إلى /login برمز 302/303 قبل عرض صفحة 404.
R=$(probe "$BASE/__muwathaq_smoke_no_such_path__" "$TMPDIR/error.html"); CODE="${R%%|*}"
ERROR_HEADERS=$(curl -sSI --max-time 20 "$BASE/__muwathaq_smoke_no_such_path__" 2>/dev/null || true)
if grep -qiE 'stack trace|vendor/|DB_PASSWORD|MUWATHAQ_.*SECRET|Fatal error' "$TMPDIR/error.html"; then
  bad "خطأ المسار يفشي تفاصيل للتنقيح"
elif [[ "$CODE" == "404" ]]; then
  ok "خطأ 404 لا يفشي أسراراً"
elif [[ "$CODE" =~ ^30[23]$ ]] && grep -qiE '^location:[[:space:]]*/login/?[[:space:]]*

printf '%s\n' "──────────────────────────────────────────────"
if [[ $FAIL -eq 0 ]]; then
  printf '\033[32m  نجح %d فحوص · موثّق جاهز\033[0m\n\n' "$PASS"
  exit 0
fi
printf '\033[31m  فشل %d فحوص (ونجح %d)\033[0m\n' "$FAIL" "$PASS"
printf '  لا تعتمد النشرة قبل أن يصير الفشل صفراً.\n\n'
exit 1
 <<<"$ERROR_HEADERS"; then
  ok "المسار المحمي يعيد الزائر غير المسجل إلى الدخول بأمان"
else
  bad "خطأ المسار: رمز ${CODE} أو توجيه غير آمن"
fi

printf '%s\n' "──────────────────────────────────────────────"
if [[ $FAIL -eq 0 ]]; then
  printf '\033[32m  نجح %d فحوص · موثّق جاهز\033[0m\n\n' "$PASS"
  exit 0
fi
printf '\033[31m  فشل %d فحوص (ونجح %d)\033[0m\n' "$FAIL" "$PASS"
printf '  لا تعتمد النشرة قبل أن يصير الفشل صفراً.\n\n'
exit 1
