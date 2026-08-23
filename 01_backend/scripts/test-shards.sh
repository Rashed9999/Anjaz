#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════════
# AMIAL-GATE-SHARD-001 — **مجموعةُ الاختبارات على أربعة أنوية.**
#
# **ما قِيس، ولم يُفترَض** (بعد أن صارت البوّابةُ تقيس نفسَها):
#
#     466 ث  ٩) مجموعة الاختبارات   ← **٧٤٪ من الإجمالي**
#      42 ث  ١) تركيب PHP
#      30 ث  ٦) الأزرار تُضغط
#     628 ث  الإجمالي
#
# والآلةُ أربعةُ أنوية، و`php artisan test` **خيطٌ واحد**. أي أنّ ثلاثةَ
# أرباع الوقت يمضي على ربع الآلة.
#
# و`--parallel` في Laravel يحتاج `brianium/paratest`، **وقِيس أنّ
# composer لا يصل GitHub من خلف الوكيل هنا**. فالتقسيمُ باليد: قوائمُ
# ملفّاتٍ متوازنة، كلُّ عمليّةٍ بقاعدةِ بياناتٍ خاصّةٍ بها.
#
# ══════════════════════════════════════════════════════════════════════
# **وسرعةٌ تكلّف يقيناً ليست ربحاً.** فثلاثةُ شروطٍ لا يُتنازل عنها:
#
#   ١) **كلُّ ملفٍّ يُشغَّل مرّةً واحدةً بالضبط** — يُتحقَّق بالعدّ، لا
#      بالثقة في الحلقة. فتقسيمٌ يُسقط ملفّاً يُخرج «نجح» على ما لم
#      يُشغَّل، وهو الصمتُ بثوب نجاح.
#
#   ٢) **إخفاقُ أيّ شريحةٍ يُسقط الكلَّ** — ولو كان انهياراً بلا سطر
#      نتيجة. (الطبقةُ التاسعة سقطت مرّةً بـ`✓` فارغٍ لأنّ الذاكرةَ
#      نفدت ولم يُطبع `Tests:` — فيُطلَب إثباتٌ موجب.)
#
#   ٣) **مجموعُ الناجح يُطبع** فيُقارَن بالجولة المتتابعة. ونقصٌ فيه
#      يعني تقسيماً يبتلع، لا تسريعاً.
#
# الاستعمال:
#     bash scripts/test-shards.sh [عدد_الشرائح]
#
# يُخرج سطرَ `Tests:` واحداً مجمَّعاً — بالصيغة نفسِها التي تقرؤها
# البوّابة، فلا يتغيّر ما بعدها.
# ══════════════════════════════════════════════════════════════════════
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 2

SHARDS="${1:-$(nproc 2>/dev/null || echo 4)}"
[ "$SHARDS" -lt 1 ] && SHARDS=1

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ── قاعدةُ كلِّ شريحة ──────────────────────────────────────────────────
# **ولا تتقاسم شريحتان قاعدةً**: `RefreshDatabase` يُهاجر ويُفرغ، فعمليّتان
# على قاعدةٍ واحدة تمحوان بيانات بعضهما في منتصف اختبار — وتُخرجان إخفاقاً
# لا علاقةَ له بالشيفرة، وهو أسوأُ من البطء.
BASE_DB="$(php -r 'echo (require "config/database.php")["connections"]["mysql"]["database"] ?? "forge";' 2>/dev/null || echo forge)"

# **وتُبنى من الصفر في كلّ جولة، لا `IF NOT EXISTS` وحدَها.**
#
# كان السطرُ ينشئها إن غابت ويتركها كما هي إن وُجدت. **فقاعدةُ شريحةٍ
# تركتها جولةٌ منقطعةٌ في منتصف هجرةٍ تبقى منجرفةً إلى الأبد** — وقِيس:
# `forge_s1` حملت ٢٣٦ هجرةً «تمّت» و٢٥٤ جدولاً **وينقصها `addon_settings`**،
# فسقطت ثلاثةُ اختباراتٍ سليمةٍ تماماً.
#
# **وذاك أسوأُ من البطء**: أرسلني خلف عطلٍ لا وجودَ له، وهو الصنفُ
# المسجَّل في `CLAUDE.md` («٢١٧٣ اختباراً فاشلاً ولا واحدٌ منها مكسور»).
#
# والكلفةُ صفرٌ عمليّاً: `RefreshDatabase` يُشغّل `migrate:fresh` في كلّ
# عمليّةٍ أصلاً، فالحذفُ والإنشاءُ لا يضيفان إلّا أجزاءَ ثانية.
DB_CLI=""
for c in mariadb mysql; do command -v "$c" >/dev/null 2>&1 && { DB_CLI="$c"; break; }; done

if [ -z "$DB_CLI" ]; then
  echo "⛔ لا عميلَ قاعدةِ بياناتٍ متاح — لا تقسيم."
  exit 3
fi

for i in $(seq 1 "$SHARDS"); do
  "$DB_CLI" -uroot -e "DROP DATABASE IF EXISTS \`${BASE_DB}_s${i}\`;
                       CREATE DATABASE \`${BASE_DB}_s${i}\`" 2>/dev/null \
    || { echo "⛔ تعذّر بناءُ قاعدة الشريحة ${i} — لا تقسيم."; exit 3; }
done

# ── التقسيم ───────────────────────────────────────────────────────────
# **ويُوزَّع بالحجم لا بالعدد.** ملفٌّ فيه أربعون اختباراً يعادل عشرةً
# صغيرة، فتقسيمٌ بالعدد يترك شريحةً تنتظر أختَها.
find tests -name '*Test.php' -type f -printf '%s\t%p\n' 2>/dev/null \
  | sort -rn > "$WORK/all"

TOTAL_FILES=$(wc -l < "$WORK/all")

if [ "$TOTAL_FILES" -eq 0 ]; then
  echo "⛔ لا ملفَّ اختبارٍ وُجد — لا تقسيم."
  exit 3
fi

# توزيعُ «أثقلُ ما تبقّى إلى أخفِّ شريحة» — جشعٌ بسيطٌ ويكفي.
awk -v n="$SHARDS" -v w="$WORK" '
  BEGIN { for (i = 1; i <= n; i++) load[i] = 0 }
  {
    best = 1
    for (i = 2; i <= n; i++) if (load[i] < load[best]) best = i
    load[best] += $1
    sub(/^[0-9]+\t/, "")
    print $0 >> (w "/shard." best)
  }
' "$WORK/all"

# **الشرطُ الأوّل، ويُتحقَّق قبل التشغيل لا بعده.**
ASSIGNED=$(cat "$WORK"/shard.* 2>/dev/null | wc -l)

if [ "$ASSIGNED" -ne "$TOTAL_FILES" ]; then
  echo "⛔ التقسيمُ أسقط ملفَّات: ${ASSIGNED} من ${TOTAL_FILES}. لا تشغيل."
  exit 3
fi

DUPES=$(cat "$WORK"/shard.* | sort | uniq -d | wc -l)

if [ "$DUPES" -ne 0 ]; then
  echo "⛔ التقسيمُ كرّر ${DUPES} ملفّاً — فالعدُّ سيكذب. لا تشغيل."
  exit 3
fi

# ── التشغيل ───────────────────────────────────────────────────────────
PIDS=""

for i in $(seq 1 "$SHARDS"); do
  [ -f "$WORK/shard.$i" ] || continue
  (
    DB_DATABASE="${BASE_DB}_s${i}" \
    php -d memory_limit=2048M artisan test \
      $(tr '\n' ' ' < "$WORK/shard.$i") \
      > "$WORK/out.$i" 2>&1
    echo $? > "$WORK/rc.$i"
  ) &
  PIDS="$PIDS $!"
done

for p in $PIDS; do wait "$p"; done

# ── الجمع ─────────────────────────────────────────────────────────────
BROKEN=0

PASSED=0; FAILED=0; SKIPPED=0; RISKY=0

for i in $(seq 1 "$SHARDS"); do
  [ -f "$WORK/out.$i" ] || continue
  LINE=$(grep -oE 'Tests:.*' "$WORK/out.$i" | tail -1)

  # **إثباتٌ موجب**: شريحةٌ بلا سطر نتيجةٍ انهارت، ولا تُقرأ نجاحاً.
  # (الطبقةُ التاسعة سقطت مرّةً بـ`✓` فارغٍ لهذا السبب بعينه.)
  if [ -z "$LINE" ] || ! echo "$LINE" | grep -q 'passed'; then
    BROKEN=$((BROKEN + 1))
    echo "⛔ الشريحة ${i} لم تُكمل (رمز: $(cat "$WORK/rc.$i" 2>/dev/null || echo ?)):"
    tail -12 "$WORK/out.$i" | sed 's/^/     /'
    continue
  fi

  for k in passed failed skipped risky; do
    v=$(echo "$LINE" | grep -oE "[0-9]+ $k" | grep -oE '^[0-9]+' | head -1)
    [ -z "$v" ] && v=0
    case $k in
      passed)  PASSED=$((PASSED + v));;
      failed)  FAILED=$((FAILED + v));;
      skipped) SKIPPED=$((SKIPPED + v));;
      risky)   RISKY=$((RISKY + v));;
    esac
  done

  if [ "$FAILED" -gt 0 ]; then
    grep -E 'FAILED|⨯' "$WORK/out.$i" | head -6 | sed 's/^/     /'
  fi
done

if [ "$BROKEN" -gt 0 ]; then
  echo "Tests:    ${BROKEN} shards crashed, ${FAILED} failed, ${PASSED} passed"
  exit 1
fi

PARTS=""
[ "$FAILED"  -gt 0 ] && PARTS="${PARTS}${FAILED} failed, "
[ "$RISKY"   -gt 0 ] && PARTS="${PARTS}${RISKY} risky, "
[ "$SKIPPED" -gt 0 ] && PARTS="${PARTS}${SKIPPED} skipped, "

echo "Tests:    ${PARTS}${PASSED} passed"
[ "$FAILED" -gt 0 ] && exit 1
exit 0
