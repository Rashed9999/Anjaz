#!/usr/bin/env bash
#
# AMIAL-APK-SIGNING-002 — مرشد إنشاء/حفظ هوية توقيع ثابتة للتطبيق.
#
# **لماذا هذا مرشدٌ بشريٌّ لا شيفرة:** المفتاحُ سرٌّ لا يُرفَع إلى
# المستودع أبداً (فمن ملكه زيّف تحديثاً باسمك في جوجل بلاي). فيُنشأ على
# جهازك، ويُحفَظ عندك، ولا يمرّ بأيّ آلة. وهذه خطوةٌ لا يفعلها إلّا إنسانٌ
# يملك الجهازَ والقرار.
#
# **الفجوة التي عولجت:** release كان يستخدم debug.keystore المؤقت على
# GitHub runners. كل تشغيل كان قد يوقّع بهوية مختلفة، فيرفض Android
# التحديث فوق النسخة السابقة. لذلك يجب استخدام مفتاح ثابت لكل APK.
#
#   bash 02_flutter_app/android/wizard-signing-key.sh

set -euo pipefail

if [[ -t 1 ]] && command -v tput >/dev/null 2>&1; then
  B=$(tput bold); D=$(tput dim); R=$(tput sgr0)
  BL=$(tput setaf 4); GR=$(tput setaf 2); YL=$(tput setaf 3); RD=$(tput setaf 1)
else
  B=""; D=""; R=""; BL=""; GR=""; YL=""; RD=""
fi

hr(){ printf '%s\n' "────────────────────────────────────────────────────────"; }
step(){ echo; hr; echo "${B}${BL}$1${R}"; hr; }

ANDROID_DIR="$(cd "$(dirname "$0")" && pwd)"
KEYSTORE="$ANDROID_DIR/amial-release.jks"
PROPS="$ANDROID_DIR/key.properties"

step "مرشدُ توقيع أميال باي — هوية ثابتة لكل APK"
cat <<EOF

هذا المرشدُ ينشئ ${B}هوية توقيع ثابتة${R}. إذا تغير المفتاح لاحقاً فلن يقبل Android التحديث فوق النسخة السابقة.

${YL}⚠ ثلاث حقائق قبل أن تبدأ:${R}
  1. المفتاحُ ${B}لا يُرفَع إلى git أبداً${R} — وهو محميٌّ في .gitignore.
  2. إن ${B}فقدتَه${R}، لا تستطيع تحديثَ تطبيقك في جوجل بلاي ${B}إلى الأبد${R}.
     فاحفظ نسخةً منه في مكانٍ آمنٍ خارج الجهاز (لا في المستودع).
  3. كلمةُ مروره ${B}لا تُكتَب في أيّ ملفٍّ يُرفَع${R} — فقط في key.properties
     المحلّيّ (وهو gitignored).

EOF

if [[ -f "$KEYSTORE" ]]; then
  echo "${YL}يوجد مفتاحٌ بالفعل: $KEYSTORE${R}"
  echo "لا تُنشئ غيرَه — تغييرُ المفتاح يعني تطبيقاً جديداً في جوجل بلاي."
  exit 0
fi

if ! command -v keytool >/dev/null 2>&1; then
  echo "${RD}❌ keytool غير موجود. ثبّت JDK 17 أوّلاً (نفسُ ما يطلبه البناء).${R}"
  exit 1
fi

step "الخطوة ١/٣ — إنشاء المفتاح"
echo "سيَسألك keytool أسئلةً (اسمك، مؤسّستك، بلدك). أجبها، واختر"
echo "${B}كلمةَ مرورٍ قويّةً واحفظها${R} — ستحتاجها في كلّ نشرة."
echo
keytool -genkey -v \
  -keystore "$KEYSTORE" \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -alias amial

step "الخطوة ٢/٣ — ربطُ المفتاح بالبناء"
read -rsp "${B}أعد كتابة كلمة مرور المفتاح (لتُكتَب في key.properties المحلّيّ): ${R}" pw; echo
cat > "$PROPS" <<EOF
storePassword=$pw
keyPassword=$pw
keyAlias=amial
storeFile=amial-release.jks
EOF
echo "${GR}✓ كُتب $PROPS (وهو gitignored — لن يُرفَع).${R}"

step "الخطوة ٣/٣ — حفظُ الهوية وربطُ GitHub Actions"
cat <<EOF
${YL}ملف build.gradle.kts يستخدم release signing بالفعل.${R}

لـ GitHub Actions تحتاج سرّين فقط:
  AMIAL_ANDROID_KEYSTORE_B64
  AMIAL_ANDROID_KEYSTORE_PASSWORD

الاسم الثابت للمفتاح:
  amial

أخرج القيمة الأولى:
  base64 -w 0 "$KEYSTORE"

وبصمة الشهادة:
  keytool -list -v -keystore "$KEYSTORE" -alias amial | grep SHA256

احفظ نسخةً احتياطيةً مشفّرة من $KEYSTORE وكلمة المرور خارج GitHub.
EOF
echo
echo "${GR}${B}تمّ. هوية التوقيع الثابتة جاهزة.${R}"
