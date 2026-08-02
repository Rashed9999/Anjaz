#!/usr/bin/env bash
#
# scripts/update.sh — تحديث الخادم بأمرٍ واحد.
#
# ══════════════════════════════════════════════════════════════════════
# لماذا هذا الملفّ موجود
#
# قلتُ لصاحب المشروع بعد نشرةٍ: «شغّل php artisan migrate، وامسح
# view:clear، وإن كنت على دوكر فـ deploy.sh». فسأل بحقّ: **وما المطلوب
# منّي؟**
#
# وكان السؤال في محلّه: من ينشر لا يجب أن يعرف بأيّ طريقةٍ يعمل خادمه
# ليختار الأمر الصحيح. الطريقة تُكتشَف، ولا تُسأل.
#
# فهذا الملفّ يفعل كلّ شيء: يجلب الشيفرة، ويكتشف دوكر أو تشغيلاً مباشراً،
# ويُرحّل قاعدة البيانات، ويمسح ذواكر القوالب، ويتحقّق أنّ الترحيل نفذ
# فعلاً — ويقول ما فعله سطراً سطراً.
#
# الاستعمال:  bash scripts/update.sh
# ══════════════════════════════════════════════════════════════════════

set -uo pipefail
cd "$(dirname "$0")/.."

G='\033[32m'; R='\033[31m'; Y='\033[33m'; B='\033[1m'; N='\033[0m'
ok()   { printf "  ${G}✓${N} %s\n" "$1"; }
bad()  { printf "  ${R}✗${N} %s\n" "$1"; }
warn() { printf "  ${Y}⚠${N} %s\n" "$1"; }
head_(){ printf "\n${B}%s${N}\n" "$1"; }

FAIL=0

printf "${B}╔══════════════════════════════════════════╗\n"
printf "║   أميال باي — تحديث الخادم               ║\n"
printf "╚══════════════════════════════════════════╝${N}\n"

# ── ١) جلب الشيفرة ───────────────────────────────────────────────────
head_ "١) الشيفرة"

BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '')"

if [[ -z "$BRANCH" ]]; then
  warn "هذا المجلّد ليس مستودع git — تخطّي الجلب"
elif [[ -n "$(git status --porcelain --untracked-files=no 2>/dev/null)" ]]; then
  # تعديلاتٌ محلّيّة على ملفّاتٍ متتبَّعة تُقال ولا تُداس: من عدّل ملفّاً
  # على الخادم يعرف لماذا.
  #
  # والملفّات **غير المتتبَّعة** لا تمنع: أوّل تشغيلٍ لهذا الملفّ رفض
  # التحديث بسبب سجلٍّ عابرٍ في المجلّد. وحارسٌ يمنع العمل الصحيح يُعطَّل
  # بعد مرّتين، فلا يحرس شيئاً.
  bad "توجد تعديلاتٌ محلّيّة غير محفوظة — لن أسحب فوقها"
  git status --short --untracked-files=no | head -10
  echo "     احفظها (git stash) أو ألغِها (git checkout -- .) ثمّ أعد التشغيل."
  exit 1
else
  BEFORE="$(git rev-parse --short HEAD)"
  if git pull --ff-only origin "$BRANCH" >/tmp/amial_pull.log 2>&1; then
    AFTER="$(git rev-parse --short HEAD)"
    [[ "$BEFORE" == "$AFTER" ]] \
      && ok "الشيفرة محدَّثة أصلاً ($AFTER)" \
      || ok "جُلبت الشيفرة: $BEFORE ← $AFTER"
  else
    bad "فشل الجلب:"; tail -5 /tmp/amial_pull.log
    exit 1
  fi
fi

# ── ٢) اكتشاف طريقة التشغيل ──────────────────────────────────────────
#
# **الطريقة تُكتشَف ولا تُسأل.** ومعيارُ الاكتشاف حاويةٌ **تعمل الآن** لا
# وجودُ ملفّ compose: المستودع يحمل ملفّات دوكر سواء استُعملت أو لا.
head_ "٢) طريقة التشغيل"

MODE="plain"
APP_CONTAINER=""

if command -v docker >/dev/null 2>&1; then
  APP_CONTAINER="$(docker ps --format '{{.Names}}' 2>/dev/null \
    | grep -E 'amial-app|amial_app' | head -1)"
  if [[ -n "$APP_CONTAINER" ]]; then
    MODE="docker"
  fi
fi

if [[ "$MODE" == "docker" ]]; then
  ok "دوكر — الحاوية «$APP_CONTAINER»"
else
  ok "تشغيل مباشر (php-fpm / artisan serve)"
fi

# `run` تُخفي الفرق بين الطريقتين عن بقيّة الملفّ.
run() {
  if [[ "$MODE" == "docker" ]]; then
    docker exec "$APP_CONTAINER" "$@"
  else
    "$@"
  fi
}

# ── ٣) الاعتماديّات ──────────────────────────────────────────────────
head_ "٣) الاعتماديّات"

if [[ "$MODE" == "plain" ]] && command -v composer >/dev/null 2>&1; then
  if composer install --no-dev --optimize-autoloader --no-interaction >/tmp/amial_composer.log 2>&1; then
    ok "composer install"
  else
    warn "فشل composer install — تابع، وقد لا تعمل حزمةٌ جديدة:"
    tail -3 /tmp/amial_composer.log
  fi
else
  ok "تُبنى مع الصورة — لا شيء هنا"
fi

# ── ٤) الترحيل ───────────────────────────────────────────────────────
#
# **الشيفرة الجديدة تقرأ أعمدةً جديدة.** فنشرٌ بلا ترحيلٍ لا يترك النظام
# على حاله — يكسره: الشاشة تُفتح فتسأل عن عمودٍ غير موجود فتردّ ٥٠٠.
head_ "٤) قاعدة البيانات"

# `grep -c` تطبع صفراً **وتخرج بفشل** حين لا تجد. فـ`|| echo 0` كانت
# تُضيف صفراً ثانياً فيصير الناتج «0\n0» ويسقط الشرط الحسابيّ بعده.
PENDING_BEFORE="$(run php artisan migrate:status 2>/dev/null | grep -c 'Pending' || true)"
PENDING_BEFORE="${PENDING_BEFORE:-0}"

if run php artisan migrate --force >/tmp/amial_migrate.log 2>&1; then
  ok "نُفّذ الترحيل (${PENDING_BEFORE} ترحيلاً كان معلَّقاً)"
else
  bad "فشل الترحيل:"; tail -10 /tmp/amial_migrate.log
  FAIL=1
fi

# ── ٥) الذواكر ───────────────────────────────────────────────────────
#
# Blade يخزّن القوالب مترجَمةً. وبلا مسحها تبقى الشاشة القديمة تُعرَض
# والملفُّ تغيّر — وهو أكثر ما يُوهم أنّ «النشر لم يصل».
head_ "٥) الذواكر"

for c in view:clear config:clear route:clear; do
  run php artisan "$c" >/dev/null 2>&1 && ok "$c" || warn "$c لم ينجح"
done

run php artisan config:cache >/dev/null 2>&1 && ok "config:cache" || true

# ── ٦) التحقّق: هل نفذ فعلاً ─────────────────────────────────────────
#
# «شغّلتُ الأمر» ليست «تمّ». فيُقرأ الأثر لا يُفترَض.
head_ "٦) التحقّق"

STATUS="$(run php artisan migrate:status 2>/dev/null || echo '')"
PENDING_AFTER="$(printf '%s' "$STATUS" | grep -c 'Pending' || true)"
PENDING_AFTER="${PENDING_AFTER:-0}"

if [[ -z "$STATUS" ]]; then
  bad "تعذّرت قراءة حالة الترحيل — راجع اتّصال قاعدة البيانات"
  FAIL=1
elif [[ "$PENDING_AFTER" -eq 0 ]]; then
  ok "لا ترحيلَ معلَّقاً — القاعدة مطابقةٌ للشيفرة"
else
  bad "ما زال $PENDING_AFTER ترحيلاً معلَّقاً:"
  echo "$STATUS" | grep 'Pending' | head -5
  FAIL=1
fi

if [[ "$MODE" == "docker" ]]; then
  docker restart "$APP_CONTAINER" >/dev/null 2>&1 \
    && ok "أُعيد تشغيل الحاوية" || warn "تعذّرت إعادة تشغيل الحاوية"
fi

# ── الخلاصة ──────────────────────────────────────────────────────────
printf "\n══════════════════════════════════════════\n"
if [[ $FAIL -eq 0 ]]; then
  printf "${G}  اكتمل التحديث.${N}\n"
  printf "  يبقى شيءٌ واحدٌ لا أستطيعه من هنا:\n"
  printf "  ${B}أعد تحميل الصفحة في متصفّحك تحميلاً قسريّاً${N}\n"
  printf "  (Ctrl+Shift+R على الحاسوب · على الهاتف: اسحب لأسفل لإعادة التحميل)\n"
  printf "  — وإلّا بقي الجافاسكربت القديم في المتصفّح ولو صحّ الخادم.\n"
  exit 0
fi
printf "${R}  لم يكتمل التحديث — راجع السطور الحمراء أعلاه.${N}\n"
exit 1
