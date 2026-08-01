# Bootstrap 5.3.3 (RTL) — نسخة محلّيّة لا من CDN

نُقلت هذه الملفّات إلى المستودع عمداً بدل تحميلها من `cdn.jsdelivr.net`.

**السبب تشغيليّ لا تفضيليّ.** بوّابة شركات الصرافة يعمل عليها صرّافٌ طول
اليوم على شبكةٍ يمنيّة. واعتمادُ الواجهة على شبكةٍ خارجيّة يعني أنّ الشبّاك
يتوقّف كلّما تعذّر الوصول إلى CDN — انقطاعُ خطٍّ دوليّ، أو حجبٌ، أو بطءٌ في
الساعة التي يكثر فيها العمل. والصرّاف حينها يرى صفحةً بلا تنسيقٍ وأزراراً
لا تعمل، ولا يعرف أنّ العلّة في الشبكة لا في النظام.

(وقد تحقّق هذا فعلاً أثناء التطوير: `cdn.jsdelivr.net` ردّ 403 من خلف
وسيطٍ، فظهرت اللوحة خاماً تماماً كما ظهرت لصاحب المشروع.)

وأثرٌ ثانٍ: `ViewAssetIntegrityTest` يفحص وجود الملفّات المحلّيّة على القرص.
والعنوان الخارجيّ لا يُفحَص — فبقاؤه يعني حارساً لا يرى أهمّ ملفَّين في
الواجهة.

## التحديث

```
npm pack bootstrap@<version>
tar xzf bootstrap-<version>.tgz
cp package/dist/css/bootstrap.rtl.min.css* public/assets/vendor/bootstrap/css/
cp package/dist/js/bootstrap.bundle.min.js* public/assets/vendor/bootstrap/js/
```

MIT — https://github.com/twbs/bootstrap
