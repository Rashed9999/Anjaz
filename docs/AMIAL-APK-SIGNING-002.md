# AMIAL-APK-SIGNING-002 — هوية توقيع Android الثابتة

## السبب الجذري

كانت نسخ `release` تُوقّع بـ `debug.keystore` على GitHub-hosted runners.
هذه الأجهزة مؤقتة، ولذلك يمكن أن يتغيّر مفتاح التوقيع بين بناء وآخر.
Android لا يسمح بتحديث تطبيق يحمل نفس `applicationId` إذا اختلفت شهادة التوقيع.

## القاعدة الجديدة

- كل APK من نوع `release` يستخدم `signingConfigs.release` فقط.
- لا يوجد fallback إلى debug signing.
- المفتاح الخاص لا يدخل المستودع؛ المستودع عام.
- CI يرفض بناء APK إذا غاب سر التوقيع.
- CI يتحقق من بصمة شهادة APK بعد البناء قبل رفع Artifact.

## هوية أميال الحالية للتجربة/الإنتاج التجريبي

- Package: `amialpay.com`
- Alias: `amial`
- Certificate SHA-256:
  `1DB2799F07DAD246A5163FC5FA9CA813B47DD65B6DB55EB296632BED3BBABCC7`

البصمة ليست سراً؛ المفتاح الخاص وكلمة مروره هما السر.

## GitHub Secrets المطلوبة

سرّان فقط:

- `AMIAL_ANDROID_KEYSTORE_B64`
- `AMIAL_ANDROID_KEYSTORE_PASSWORD`

`AMIAL_ANDROID_KEYSTORE_B64` هو Base64 كامل لملف keystore الثابت.
كلمة المرور نفسها تُستخدم للـstore والـkey، والـalias ثابت `amial`.

## الانتقال من النسخ القديمة

نسخ APK القديمة كانت موقعة بمفاتيح debug متغيرة. لذلك أول انتقال
إلى الهوية الثابتة الجديدة يحتاج حذف النسخة القديمة من الجهاز مرة واحدة
ثم تثبيت APK الثابت. بعد ذلك يجب أن تعمل الترقيات فوق بعضها بلا حذف.

## النسخ الاحتياطي

احتفظ بملف keystore وكلمة المرور في مكانين مشفرين خارج GitHub.
فقدان المفتاح يعني عدم القدرة على تحديث نسخ APK المثبتة بهذه الهوية.
