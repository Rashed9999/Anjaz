#!/bin/bash
#
# AMIAL-SESSION-BOOT-001 — تهيئة الجلسة: تشغيل MariaDB وضبط مسار Node.
#
# ══════════════════════════════════════════════════════════════════════
# **الثمن الذي دفعناه بلا هذا الملفّ:**
#
# MariaDB لا تعمل عند بدء الجلسة (لا systemd في الحاوية). فأوّلُ
# `bash scripts/verify.sh` يخرج بـ«1625 failed» — رقمٌ كاذبٌ تماماً، لأنّ
# كلّ اختبارٍ يلمس القاعدة يسقط بـ`SQLSTATE[HY000] [2002]`.
#
# وقع ذلك أربع مرّاتٍ في جلسةٍ واحدة (١٥٤٠ ثمّ ١٦٢٥ ثمّ ١٥٥٤ فشلاً
# كاذباً)، وكلُّ مرّةٍ كلّفت تشغيلةَ فحصٍ كاملة **ثمّ تشخيصاً** قبل أن
# يُعرف أنّ العطل في البيئة لا في الشيفرة.
#
# وأخطرُ ما فيه أنّ الرقم يبدو ذا معنى: «١٦٢٥ اختباراً ساقطاً» تدفع إلى
# البحث في الشيفرة عن عطلٍ لا وجود له.
#
# ══════════════════════════════════════════════════════════════════════
# ويعمل في بيئة الويب وحدها: من يُشغّل محليّاً له خادمُ قاعدةٍ يُديره
# بنفسه، وبدءُ واحدٍ ثانٍ فوقه يُفسد ما يعمل.

set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

# ── ١) مسار وحدات Node — يحتاجه scripts/click-check.mjs ─────────────
# ولا يُكرَّر السطر عند كلّ استئنافٍ للجلسة: الـhook يُنادى في
# startup و resume و clear و compact، فيتضخّم الملفّ بلا معنى.
if [ -n "${CLAUDE_ENV_FILE:-}" ] && [ -d /opt/node22/lib/node_modules ]; then
    if ! grep -q 'NODE_PATH="/opt/node22/lib/node_modules"' "$CLAUDE_ENV_FILE" 2>/dev/null; then
        echo 'export NODE_PATH="/opt/node22/lib/node_modules"' >> "$CLAUDE_ENV_FILE"
    fi
fi

# ── ٢) MariaDB ──────────────────────────────────────────────────────
if ! command -v mariadbd-safe >/dev/null 2>&1; then
    echo "ℹ️  لا MariaDB في هذه الصورة — تُخطّى التهيئة."
    exit 0
fi

# قائمةٌ أصلاً؟ لا يُبدأ ثانٍ فوقها (idempotent).
if mariadb -uroot -e 'SELECT 1' >/dev/null 2>&1; then
    echo "✓ MariaDB تعمل بالفعل"
    exit 0
fi

echo "⏳ تشغيل MariaDB…"

# مجلّد المقبس يضيع مع كلّ إقلاعٍ للحاوية — يُنشأ ويُملَّك قبل البدء،
# وإلّا فشل الخادم بصمتٍ وبقيت الرسالة «Can't connect through socket».
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld

( mariadbd-safe --user=mysql >/dev/null 2>&1 & )

# **يُنتظر حتى تستجيب فعلاً، لا مدّةً ثابتة.**
# `sleep 22` كنتُ أكتبها يدويّاً — تُهدر الوقت حين تجهز في ثمانٍ، وتفشل
# حين تحتاج ثلاثين. فيُسأل الخادم حتى يردّ.
for i in $(seq 1 60); do
    if mariadb -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        echo "✓ MariaDB جاهزة (بعد ${i} ثانية)"
        exit 0
    fi
    sleep 1
done

# ولا يُقال «جاهزة» وهي ليست كذلك: الفشل يُقال صراحةً مع أثره.
echo "‼️  تعذّر تشغيل MariaDB خلال ٦٠ ثانية."
echo "    وكلُّ اختبارٍ يلمس القاعدة سيسقط بـSQLSTATE[HY000] [2002] —"
echo "    وهو فشلٌ في البيئة لا في الشيفرة. شخّصه قبل أن تقرأ الأرقام."
exit 1
