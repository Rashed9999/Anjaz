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

---

# المرحلة 2 — إزالة نظام التثبيت/التحديث/تفعيل 6amtech

حُذف نظام ترخيص 6amtech والمُثبِّت/المُحدِّث بالكامل — كان **يحجب دخول الأدمن ويُحوّله
لـ `6amtech.com/software-activation`** إن لم يكن «مُفعَّلاً»، ويتّصل بـ `check.6amtech.com`.
أميال باي يُنشَر عبر Docker لا عبر معالج تثبيت 6cash. **768 اختبار أخضر بعدها.**

## ما حُذف

| العنصر | الوصف |
|---|---|
| `AppServiceProvider` | بوّابة تفعيل 6amtech على `admin/auth/login` + إعادة التوجيه الخارجية |
| `Traits/ActivationClass.php` | ملفّ التفعيل (`actch`/`dmvf` → يتّصل بـ check.6amtech.com) — حُذف |
| `InstallController.php` + `routes/install.php` | معالج تثبيت 6cash (5 خطوات) — حُذف |
| `UpdateController.php` + `routes/update.php` | مُحدِّث 6cash (SOFTWARE_ID/PURCHASE_CODE) — حُذف |
| `ActivationCheckMiddleware.php` + `InstallationMiddleware.php` | وسطاء تفعيل/تثبيت (غير مطبّقين) — حُذفا |
| `RouteServiceProvider.txt` | نسخة نصّية تُبدَّل أثناء التحديث — حُذف |
| `Helpers::requestSender()` | استدعاء `LaravelchkController` (غير موجود) — كود ميت مكسور |
| `RouteServiceProvider` / `bootstrap/app.php` | إلغاء تسجيل install.php + إزالة alias `actch`/`installation-check` |

**التحقّق:** `route:cache` ينجح، التطبيق يُقلع، **768 اختبار أخضر / 0 فشل**. لم يعد
التطبيق يتّصل بـ 6amtech ولا يعتمد على «تفعيل» خارجي.

## آثار 6cash متبقّية (لم تُلمَس — حمولة تشغيلية أو قرار منتَج)

- **صفحة الهبوط** (`LandingPageController` + blades): موقع تعريفي موروث؛ قرار منتَج.
- **نظام الإضافات** (`SystemAddonController` + `AddonHelper` + `addon_admin_routes`):
  ما زال مستخدَماً — `SMSModuleController` يقرأ `addon_admin_routes`. إزالته تتطلّب
  إعادة هيكلة إعدادات SMS أولاً. يُقيَّم لاحقاً.
