# تنظيف آثار 6cash وبوّابات الدفع الأجنبية

إزالة الكود الميت الموروث من قاعدة 6cash المتعلّق ببوّابات الدفع الأجنبية والعلامة
التجارية. **حُذف ~1350 سطراً من الكود الميت، مع بقاء 768 اختباراً أخضر / 0 فشل**،
والتطبيق يُقلع و`route:cache` ينجح.

> أميال باي نظام **محفظة داخلي + شبكة وكلاء** (اليمن)؛ لا يستخدم بوّابات دفع أجنبية
> (Stripe/PayPal/Razorpay/Paystack/Flutterwave/SSLCommerz…). كانت هذه آثاراً ميتة.

## ما حُذف

| الملفّ | ما أُزيل | سطور |
|---|---|---|
| `app/Lib/Constant.php` | `GATEWAYS_PAYMENT_METHODS/CURRENCIES/COUNTRIES/LANGUAGES` (0 استخدام) | −632 |
| `Admin/BusinessSettingsController.php` | `paymentIndex/paymentUpdate/paymentConfigUpdate` (صفحتها محذوفة أصلاً) | −410 |
| `database/partial/addon_settings.sql` | ملفّ بذر بوّابات الدفع (غير مُشار إليه) | حُذف (−110) |
| `Providers/ConfigServiceProvider.php` | تهيئة sslcommerz/paypal/razorpay/paystack/flutterwave | −80 |
| `UpdateController.php` | `insertNewAddonData()` (بذر instamojo/phonepe/cashfree) | −90 |
| `Api/V1/ConfigController.php` | `getPaymentMethods()` + بناء قائمة البوّابات | −24 |
| `Http/Middleware/VerifyCsrfToken.php` | استثناءات CSRF لعناوين paypal/razorpay الميتة | −10 |
| `routes/admin.php` | مسارا `payment-method` / `payment-method-update` | −2 |
| `Modules/` | مجلّد فارغ (بقايا نظام إضافات 6cash) | حُذف |

## آثار العلامة التجارية 6cash المُصلَحة

- `LandingPageController`: افتراضي اسم النشاط `'6Cash'` → `'Amial Pay'`.
- `InstallController` / `UpdateController`: كتابة `APP_NAME=6cash…` → `amial_pay`.

## التحقّق

- ✅ `php artisan route:list` و`route:cache` ينجحان.
- ✅ `composer dump-autoload` نظيف.
- ✅ **768 اختبار أخضر / 0 فشل** (40279 تأكيدة) — لا انحدار.
- ✅ لا تبعيات بوّابات في `composer.json` (كانت أُزيلت سابقاً).
- ✅ نقطة `/api/v1/config` تُعيد `active_payment_method_list: []` (التطبيق يتوقّع المفتاح).

## آثار 6cash أعمق (لم تُلمَس — تحتاج قراراً)

بقيت أنظمة 6cash المُوجَّهة بمسارات وقد تكون حمولة تشغيلية — تركتُها لتفادي كسر شيء
يعمل، وأنصح بمراجعتها معاً قبل حذفها:
- **نظام التثبيت/التحديث/التفعيل** (`InstallController`/`UpdateController` + مساراتهما):
  يتضمّن منطق ترخيص 6amtech (`SOFTWARE_ID`/`PURCHASE_CODE`/رابط تفعيل خارجي). لا يُستدعى
  إلا يدوياً عبر `/install` و`/update`. يُنصَح بإزالته كاملاً إن لم يُستخدَم.
- **صفحة الهبوط** (`LandingPageController` + blades): موقع تعريفي موروث؛ قرار منتَج.
- **نظام الإضافات/الـAddonHelper** و`addon_admin_routes`: بنية إضافات 6cash؛ يُقيَّم لاحقاً.
