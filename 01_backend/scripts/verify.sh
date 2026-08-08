#!/usr/bin/env bash
#
# scripts/verify.sh — الفحص الواجب بعد **كلّ** تغيير، قبل أيّ التزام.
#
# ══════════════════════════════════════════════════════════════════════
# لماذا هذا الملفّ موجود
#
# «١٥٤٠ اختباراً يمرّ» كانت صحيحةً بينما لوحة الإدارة كلّها معطَّلة بحلقة
# إعادة توجيه. فالاختبارات وحدها لم تكن كافية، ولم يكن النقص فيها بل في
# **الطبقات التي لا تراها**:
#
#   العطل                     لماذا لم يُكشف
#   ─────────────────────     ──────────────────────────────────────────
#   ملفّ CSS غير موجود         الصفحة تردّ 200 كاملةً؛ الفشل في المتصفّح
#   جافاسكربت بقوسٍ زائد       لا Blade ولا PHP يفحصان الجافاسكربت
#   $request->user() = null    الاختبار دخل بالحارس الآخر
#   حلقة إعادة التوجيه         لا اختبار يجمع البوّابتين في جلسةٍ واحدة
#   زرٌّ يفتح الصفحة الخطأ      لا شيء كان يضغط الأزرار
#
# وكلّ فحصٍ هنا وُلد من عطلٍ وقع فعلاً ووصل إلى صاحب المشروع. فمن يحذف
# سطراً منه يُعيد فتح بابٍ أُغلق بثمن.
#
# الاستعمال:  bash scripts/verify.sh          (كامل)
#             bash scripts/verify.sh --fast   (بلا مجموعة الاختبارات)
# ══════════════════════════════════════════════════════════════════════

set -uo pipefail
cd "$(dirname "$0")/.."

FAST=0
[[ "${1:-}" == "--fast" ]] && FAST=1

PASS=0; FAIL=0
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# ── ١) تركيب PHP ─────────────────────────────────────────────────────
head_ "١) تركيب PHP"
ERR=$(find app config routes database/migrations tests -name '*.php' -newermt '-14 days' -print0 2>/dev/null \
      | xargs -0 -r -n1 php -l 2>&1 | grep -v '^No syntax errors' || true)
[[ -z "$ERR" ]] && ok "كلّ ملفّات PHP المعدَّلة تُحلَّل" || { bad "خطأ تركيبيّ:"; echo "$ERR" | head -5; }

# ── ٢) تركيب الجافاسكربت داخل القوالب ────────────────────────────────
#
# Blade لا يفحصها وPHP لا يفحصها والاختبارات لا تفتح متصفّحاً. وقوسٌ
# زائدٌ واحد يُعطّل الصفحة كلّها وتبقى تردّ 200.
head_ "٢) الجافاسكربت داخل القوالب"
if command -v node >/dev/null 2>&1; then
  JS_BAD=0
  while IFS= read -r f; do
    python3 - "$f" <<'PY' || JS_BAD=1
import re, subprocess, sys, tempfile, os, pathlib
f = sys.argv[1]
s = re.sub(r'\{\{[^}]*\}\}', 'X', pathlib.Path(f).read_text(encoding='utf-8', errors='replace'))
for i, js in enumerate(re.findall(r'<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>', s, re.S)):
    if not js.strip():
        continue
    t = tempfile.NamedTemporaryFile('w', suffix='.js', delete=False, encoding='utf-8')
    t.write(js); t.close()
    r = subprocess.run(['node', '--check', t.name], capture_output=True, text=True)
    os.unlink(t.name)
    if r.returncode != 0:
        print(f"      {f} (كتلة #{i}): {r.stderr.strip().splitlines()[-1] if r.stderr else 'خطأ'}")
        sys.exit(1)
PY
  done < <(grep -rl '<script' resources/views --include='*.blade.php')
  [[ $JS_BAD -eq 0 ]] && ok "كلّ كتل الجافاسكربت تُحلَّل" || bad "جافاسكربت لا يُحلَّل — الصفحة ستُحمَّل وأزرارُها ميّتة"
else
  printf '  \033[33m—\033[0m node غير متوفّر — تخطّي\n'
fi

# ── ٢.٢) معرّفٌ يُطلب من الشاشة ولا وجود له ──────────────────────────
#
# التركيب السليم لا يعني عنصراً موجوداً. `$('ct-withdraw').disabled` على
# زرٍّ أُزيل يقرأ `null.disabled` فيموت المعالج **بصمت**: الزرّ يُضغط ولا
# يحدث شيء، ولا رسالة، ولا طلبٌ يصل إلى الخادم. وهو ما عطّل زرّ «إيداع».
DOMREF=$(python3 scripts/dom-refs.py 2>&1)
[[ -z "$DOMREF" ]] && ok "كلّ معرّفٍ تطلبه الشيفرة موجودٌ في الشاشة" \
  || { bad "مراجعُ عناصرٍ ميّتة:"; echo "$DOMREF" | head -8; }

# ── ٣) الأصول التي تَعِد القوالب بتحميلها ────────────────────────────
head_ "٣) الأصول"
MISS=$(python3 - <<'PY'
import re, pathlib
bad = []
for f in pathlib.Path('resources/views').rglob('*.blade.php'):
    s = re.sub(r'\{\{--.*?--\}\}', ' ', f.read_text(encoding='utf-8', errors='replace'), flags=re.S)
    for m in re.findall(r"asset\(\s*'([^']+)'\s*\)", s):
        if any(x in m for x in ('$', '{{', '://')):
            continue
        cands = [pathlib.Path(m), pathlib.Path('public') / m,
                 pathlib.Path('public') / re.sub(r'^public/', '', m)]
        if not any(c.exists() for c in cands):
            bad.append(f"{f}: {m}")
print('\n'.join(bad))
PY
)
[[ -z "$MISS" ]] && ok "لا قالب يطلب ملفّاً غير موجود" || { bad "أصولٌ مفقودة:"; echo "$MISS" | head -5; }

CDN=$(grep -rlE '(href|src)="https?://[^"]*(bootstrap|jquery|cdn\.)' resources/views --include='*.blade.php' || true)
[[ -z "$CDN" ]] && ok "لا إطار واجهةٍ من شبكةٍ خارجيّة" || { bad "اعتمادٌ على CDN (يتوقّف الشبّاك عند أوّل انقطاع):"; echo "$CDN"; }

# ── ٤) المسارات تُبنى ────────────────────────────────────────────────
head_ "٤) المسارات"
if php artisan route:list >/dev/null 2>&1; then
  ok "جدول المسارات يُبنى ($(php artisan route:list 2>/dev/null | grep -cE '^\s*(GET|POST)') مساراً)"
else
  bad "جدول المسارات لا يُبنى — راجع اتّصال قاعدة البيانات أو مسارٍ مكسور"
fi

# ── ٥) الصفحات تُفتح ولا تدور ────────────────────────────────────────
#
# **الفحص الذي كان غائباً حين تعطّلت لوحة الإدارة كلّها.** حلقةُ إعادة
# توجيهٍ لا تُنتج خطأً في أيّ سجلّ: كلّ ردٍّ فيها 302 سليم، والعطل في
# تكرارها. فتُتتبَّع التحويلات ويُرفض ما تجاوز حدّاً معقولاً.
head_ "٥) الصفحات لا تدور ولا تنهار"
PORT=8911
(php artisan serve --port=$PORT >/dev/null 2>&1 &) ; sleep 6

probe() {  # probe <مسار> <وصف>
  local code redirs
  read -r code redirs < <(curl -s -o /dev/null -L --max-redirs 6 \
      -w '%{http_code} %{num_connects}' "http://127.0.0.1:$PORT/$1" 2>/dev/null \
      || echo "000 0")
  if [[ "$code" == "000" ]]; then
    # فشل المتابعة = حلقة أو تعذّر الاتّصال
    local direct
    direct=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/$1")
    if [[ "$direct" == "30"* ]]; then
      bad "$2 — حلقة إعادة توجيه (ERR_TOO_MANY_REDIRECTS)"
    else
      bad "$2 — لا يستجيب"
    fi
  elif [[ "$code" -ge 500 ]]; then
    bad "$2 — انهيار ($code)"
  else
    ok "$2 ($code)"
  fi
}

probe "admin"            "لوحة الإدارة"
probe "admin/auth/login" "دخول الإدارة"
probe "agent/login"      "دخول بوّابة الوكيل"
probe "agent"            "بوّابة الوكيل"

# AMIAL-SITE-001 — الموقع العامّ: أوّلُ ما يراه الزائر.
# وقد كان الجذر يردّ 404 شهراً بلا أن يُلاحَظ، لأنّ لا مسبارَ يفتحه.
probe ""                 "الموقع — الرئيسيّة"
probe "login"            "الدخول الموحّد"
for p_ in personal business exchange about security faq contact terms privacy; do
  probe "$p_"            "الموقع — /$p_"
done

# ── ملاحظةٌ صريحة على حدّ هذا الفحص ──────────────────────────────────
#
# الفحوص أعلاه تفتح الصفحات **بجلسةٍ نظيفة**. وحلقةُ إعادة التوجيه التي
# عطّلت لوحة الإدارة كلّها لا تقع إلّا حين تكون في المتصفّح **جلسةٌ
# مصادَقة** على الحارس الخطأ — وهو ما لا يستطيع مسبارُ curl إنشاءه بلا
# كلمة سرٍّ حقيقيّة.
#
# وقد كتبتُ هنا مسباراً يفتح كوكيز جلسةٍ ثمّ يطلب /admin، فمرّ. ثمّ
# أُعيد العطل عمداً **فمرّ أيضاً** — لأنّ كوكيز جلسةٍ بلا مستخدمٍ مصادَق
# لا تُنتج الحلقة. فحُذف: حارسٌ يمرّ والعطل قائم أسوأ من غيابه، لأنّه
# يُطمئن ولا يحرس.
#
# وهذا الصنف مُغطّىً في مجموعة الاختبارات حيث تُفتح جلسةٌ حقيقيّة:
#
#   AgentPortalContractTest::using_the_agent_portal_never_breaks_the_admin_panel
#   AgentPortalContractTest::the_admin_panel_does_not_loop_on_a_non_admin_session
#
# فلا تُشغّل `--fast` قبل التزامٍ يمسّ المصادقة أو الوسائط.

pkill -f "artisan serve --port=$PORT" >/dev/null 2>&1 || true

# ── ٦) الأزرار تُضغط فعلاً ───────────────────────────────────────────
#
# الطبقة التي سقط فيها زرّ «تحويل رصيد»: كان ينقل إلى ملفّ الوكيل بدل فتح
# نافذة التحويل، والخدمة سليمة ونقطة النهاية سليمة والجافاسكربت يُحلَّل بلا
# خطأ والصفحة تردّ ٢٠٠. لا يمسك هذا إلّا متصفّحٌ يضغط.
head_ "٦) الأزرار تُضغط"
if command -v node >/dev/null 2>&1; then
  CLICK=$(NODE_PATH="${NODE_PATH:-/opt/node22/lib/node_modules}" node scripts/click-check.mjs 2>&1)
  CLICK_RC=$?
  echo "$CLICK" | sed 's/^/  /' | sed 's/^  \(  \)/\1/'
  if [[ $CLICK_RC -eq 0 ]]; then
    echo "$CLICK" | grep -q 'تخطّي' || PASS=$((PASS+1))
  else
    FAIL=$((FAIL+1))
  fi
else
  printf '  \033[33m—\033[0m node غير متوفّر — تخطّي\n'
fi

# ── ٧) تصريف Dart ────────────────────────────────────────────────────
#
# **الطبقة التي كانت غائبة، وثمنُها بناءٌ ساقطٌ على شاشة صاحب المشروع.**
#
# ٢٣ فحصاً كانت تمرّ بصفر فشل، ومعها ١٩٦٢ اختباراً — **وكلُّها من جهة
# الخادم**. فشيفرة Dart لم يكن يمسّها مُصرِّفٌ واحدٌ في هذه البيئة، وأوّلُ
# من يقرؤها هو Codemagic بعد ثلاث دقائق بناء.
#
# فسقط البناء على `Undefined name 'code'`: دمجُ طريقَي الدفع في دالّةٍ
# واحدة ترك نداءً يشير إلى مُعامِل الدالّة القديمة. خطأُ نطاقٍ يمسكه أيُّ
# مُصرِّفٍ في جزءٍ من الثانية — ولم يكن في البيئة مُصرِّف.
#
# **والمرشّحاتُ هنا مطابقةٌ لِما في `codemagic.yaml` حرفاً بحرف** — فبوّابةٌ
# محلّيّةٌ ألينُ من بوّابة البناء تُطمئن ثمّ يسقط البناء، وأشدُّ منها تمنع
# التزاماً سليماً. وقد جُرِّبت بإعادة العطل عمداً فأمسكته بنفس الرسالة.
FLUTTER_BIN="${FLUTTER_BIN:-/opt/flutter/bin/flutter}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../02_flutter_app" 2>/dev/null && pwd)"

head_ "٧) تصريف Dart"
if [[ -x "$FLUTTER_BIN" && -d "$APP_DIR" ]]; then
  DART_OUT=$("$FLUTTER_BIN" analyze --no-pub --suppress-analytics \
             "$APP_DIR" 2>&1 || true)

  # لا يُعتمد على رمز خروج flutter analyze: دلالته تختلف بين الإصدارات،
  # وبعضها يُفشل على الملاحظات الأسلوبية. يُقرأ التقرير وتُفصل الأخطاء
  # وحدها. وأخطاءُ `build/` تُستثنى — مصادرُ حزمٍ خارجيّةٍ لا نملكها.
  DART_ERR=$(echo "$DART_OUT" | grep -E '^[[:space:]]*error •' | grep -v '• build/' || true)

  if [[ -n "$DART_ERR" ]]; then
    bad "أخطاء تصريف Dart — البناء سيسقط في Codemagic:"
    echo "$DART_ERR" | head -10 | sed 's/^/    /'
  else
    W=$(echo "$DART_OUT" | grep -cE '^[[:space:]]*warning •' || true)
    I=$(echo "$DART_OUT" | grep -cE '^[[:space:]]*info •' || true)
    ok "صفر خطأ تصريف (تحذيرات: $W · ملاحظات: $I)"
  fi
else
  # **والغيابُ يُقال صراحةً ولا يُعدّ نجاحاً.** فسطرٌ أصفرُ يُقرأ «لم يُفحص»،
  # وغيابُه يُقرأ «فُحص فسلِم» — وهو ما جعل هذه الطبقة تغيب صامتةً حتّى
  # سقط البناء. (القاعدة السابعة: «غير معروف» ليس صفراً.)
  printf '  \033[33m—\033[0m Flutter غير متوفّر (%s) — شيفرة Dart لم تُفحص\n' "$FLUTTER_BIN"
  printf '    التثبيت: راجع scripts/install-flutter.sh\n'
fi

# ── ٨) مجموعة الاختبارات ─────────────────────────────────────────────
if [[ $FAST -eq 0 ]]; then
  head_ "٨) مجموعة الاختبارات"
  OUT=$(php artisan test 2>&1 | tail -30)
  if echo "$OUT" | grep -qE '^\s*Tests:.*(failed|error)'; then
    bad "اختبارات ساقطة:"; echo "$OUT" | grep -E '⨯|Tests:' | head -10
  else
    ok "$(echo "$OUT" | grep -oE 'Tests:.*' | head -1)"
  fi
else
  printf '\n\033[33m—\033[0m تخطّي مجموعة الاختبارات (--fast)\n'
fi

# ── الخلاصة ──────────────────────────────────────────────────────────
printf '\n══════════════════════════════════\n'
if [[ $FAIL -eq 0 ]]; then
  printf '\033[32m  نجح %d فحصاً · لا فشل\033[0m\n' "$PASS"
  exit 0
fi
printf '\033[31m  فشل %d فحصاً (ونجح %d)\033[0m\n' "$FAIL" "$PASS"
printf '  لا يُلتزَم قبل أن يصير الفشل صفراً.\n'
exit 1
