# موثّق

بوابة مراجعة فتح الحساب على `muwathaq.amialpay.com`. موثّق هو مصدر إنشاء طلبات العملاء والتجار والوكلاء وقرار التحقق. يخزّن ملف الطلب وأدلته مشفّرة، ثم يرسل حدث تهيئة موقّعاً إلى أميال باي بعد الاعتماد فقط. أميال باي لا يتلقى كلمة مرور أو PIN أو صور هوية؛ يهيّئ المحفظة والملف التشغيلي ويرسل وسيلة دخول آمنة.

## النشر في Coolify

1. أنشئ مورد **MySQL** جديد باسم `muwathaq-db` داخل مشروع `production`.
2. أنشئ مورد **Application → Git Repository (with GitHub App)** من نفس
   مستودع `Rashed9999/Anjaz` والفرع `claude/project-code-review-yjagv`.
3. اضبط **Base Directory** إلى `/03_muwathaq` و**Build Pack** إلى `Dockerfile`.
4. أضف النطاق `muwathaq.amialpay.com`.
5. انسخ متغيرات `.env.example` إلى بيئة Coolify، مع بيانات قاعدة `muwathaq-db`.
   لإنشاء كلمة مرور المراجع بصورة آمنة شغّل محلياً أو داخل الحاوية:

   ```sh
   php -r 'echo password_hash("ضع-كلمة-مرور-قوية-هنا", PASSWORD_DEFAULT), PHP_EOL;'
   ```

   ضع الناتج في `MUWATHAQ_REVIEWER_PASSWORD_HASH` فقط، ولا تحفظ كلمة المرور
   الخام في أي متغير أو ملف.
6. أضف Scheduled Task كل دقيقة:

   ```sh
   php /var/www/bin/dispatch-pending-decisions.php
   ```

7. افحص الصحة ثم شغّل فحص الدخان من طرفية النشر (لا يغير قضايا أو قرارات):

   ```sh
   bash scripts/smoke.sh https://muwathaq.amialpay.com
   ```

   ثم افتح `/open-account` وتأكد من ظهور بوابتي العميل والتاجر، وسجل دخول المراجع وتحقق من «إنشاء وكيل». لا تُعتمد النشرة إن أعاد السكربت رمز خروج غير صفري.

بعد نجاح الصحة فقط، أضف إلى بيئة أميال باي:

```text
MUWATHAQ_BASE_URL=https://muwathaq.amialpay.com
MUWATHAQ_BRIDGE_SECRET=<نفس سر قناة أميال باي إلى موثّق>
MUWATHAQ_DECISION_SECRET=<سر الاتجاه العكسي>
```

لا تستخدم السرين نفسيهما.
