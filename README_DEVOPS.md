# 🛠️ أميال باي — دليل DevOps

هذا الدليل يوثّق كل ما يخصّ النشر والتشغيل والصيانة، بمعزل تامّ عن
المنطق المالي/التجاري للمشروع (موثَّق في `PRODUCTION_READINESS.md`
و`TESTING.md`).

---

## 📁 خريطة الملفّات

| الملف | الغرض | البيئة |
|---|---|---|
| `Dockerfile` | صورة بسيطة | الديمو المحلّي |
| `Dockerfile.prod` | صورة Multi-stage محسَّنة | الإنتاج |
| `docker-compose.yml` | Backend + MySQL + ngrok اختياري | الديمو المحلّي |
| `docker-compose.prod.yml` | Backend + MySQL + Redis، بلا seeders | الإنتاج |
| `.env.demo` | قيم تجريبية جاهزة | الديمو |
| `.env.production.example` | قالب فارغ آمن | الإنتاج (يُنسَخ لـ `.env.production`) |
| `demo.sh` | تشغيل/إيقاف الديمو بأمر واحد | الديمو |
| `deploy.sh` | نشر إنتاجي مع فحص صحّة + rollback | الإنتاج |
| `docker/backup.sh` / `restore.sh` | نسخ احتياطي/استعادة | الإنتاج |

---

## 🔴 3 إصلاحات DevOps حرجة أُجريت (بلا لمس أيّ منطق مالي)

### 1. `TrustProxies` — كان الأخطر

**المشكلة**: `PerUserRateLimit` و`SecuritySentinelService` (5 مواضع)
يعتمدان على `$request->ip()`. بلا تهيئة `TrustProxies`، كل الطلبات
خلف أيّ reverse proxy (Nginx الداخلي، Docker، Cloudways) تظهر بعنوان
IP واحد — فتنهار حماية Rate Limiting وكشف الاحتيال بالـ IP **بصمت
تام**، بلا أيّ رسالة خطأ تُنبّهك.

**الإصلاح**: `bootstrap/app.php` الآن يُهيّئ
`$middleware->trustProxies(at: '*', headers: ...)`، ويُضاف لذلك تمرير
`X-Forwarded-For` صراحةً من Nginx إلى PHP-FPM في كل من إعداد الديمو
والإنتاج.

**لماذا `'*'` (كل الوكلاء) لا IP محدّد؟** لأنّ التطبيق لا يُفتَح مباشرة
للإنترنت في أيّ سيناريو نشر متوقّع (Docker محلّي، أو خلف طبقة Cloudways
غير معروفة الـ IP الثابت). هذا نمط معتمد قياسياً لتطبيقات خلف PaaS/CDN،
**بشرط** أن يكون المنفذ الوحيد المكشوف للإنترنت هو عبر تلك الطبقة (لا
وصول مباشر لمنفذ التطبيق من الخارج) — تأكّد من هذا في جدار حماية
خادمك الفعلي عند النشر.

### 2. `.gitignore` — لم يكن موجوداً إطلاقاً

قبل هذه الجولة، لا يوجد أيّ `.gitignore` في المشروع. أيّ `git add .`
كان سيرفع `.env` بكل أسراره (كلمة سرّ DB، مفاتيح WhatsApp) مباشرة.
أُضيف `.gitignore` شامل يستثني كل الأسرار والملفّات المولَّدة، مع
الإبقاء المتعمَّد على `.env.example`/`.env.demo` (قوالب آمنة بلا أسرار
حقيقية).

### 3. `HealthCheckController::readiness()` كان يفرض Redis إلزامياً

كان الفحص يُسقط دائماً بخطأ 503 لأنّ إعداد `CACHE_STORE=database`
(الافتراضي الموثَّق للمشروع) لا يستخدم Redis. عُدِّل الفحص ليتحقّق أوّلاً
هل `cache`/`queue`/`session` تعتمد Redis فعلاً قبل اختبار الاتصال به —
كود مراقبة بحت، صفر تغيير في أيّ منطق أعمال.

---

## 🐳 التشغيل — الديمو المحلّي

```bash
cd 01_backend
./demo.sh start          # بدون واتساب
./demo.sh start-wa       # مع واتساب عبر ngrok
./demo.sh stop
./demo.sh reset          # إعادة ضبط كاملة
```

راجع `DEMO_GUIDE.md` (في جذر المشروع) لسيناريو عرض كامل على مستثمر.

---

## 🚀 التشغيل — الإنتاج

### أوّل نشر

```bash
cd 01_backend

# 1. إعداد البيئة
cp .env.production.example .env.production
# عدِّل: DB_PASSWORD, REDIS_PASSWORD, META_WA_*, MAIL_*

# 2. توليد APP_KEY (مرّة واحدة فقط، لا تُكرِّر لاحقاً)
docker run --rm -v "$(pwd)":/app -w /app composer:2.7 \
    sh -c "composer install --no-dev --quiet && php artisan key:generate --show"
# انسخ الناتج إلى APP_KEY= في .env.production

# 3. النشر
chmod +x deploy.sh
./deploy.sh
```

### تحديثات لاحقة

```bash
git pull
./deploy.sh   # يعمل نسخة احتياطية تلقائياً + يفحص الصحّة + rollback عند الفشل
```

### نسخ احتياطي

```bash
# يدوي
./docker/backup.sh

# آلي (على خادم فعلي، أضف لـ crontab)
0 3 * * * cd /path/to/01_backend && ./docker/backup.sh >> storage/logs/backup.log 2>&1
```

### استعادة

```bash
./docker/restore.sh backups/amial_pay_20260630_030000.sql.gz
```

---

## 🩺 نقاط فحص الصحّة (Health Checks)

| المسار | الغرض | يُستخدَم في |
|---|---|---|
| `GET /health/liveness` | "هل العملية حيّة؟" — بلا فحص تبعيّات | Docker `HEALTHCHECK`، مراقبة uptime خارجية |
| `GET /health/readiness` | "هل جاهز لاستقبال حركة؟" — يفحص DB/Redis/Storage/Queue | فحص يدوي بعد النشر، مراقبة داخلية |
| `GET /api/v1/amial/ping` | ping بسيط بديل | أدوات مراقبة خارجية (UptimeRobot) |

**لماذا فصلنا liveness عن readiness؟** لأنّ استخدام فحص كامل التبعيّات
(`readiness`) كـ Docker healthcheck خطأ شائع — لو تعطّل Redis مؤقّتاً،
Docker سيُعيد تشغيل حاوية التطبيق بأكملها رغم أنّ التطبيق نفسه يعمل
تماماً. `liveness` يفحص فقط "هل العملية تردّ؟" وهذا الصحيح لهذا الغرض.

---

## 📊 السجلّات (Logs)

| الموقع (داخل الحاوية) | المحتوى |
|---|---|
| `storage/logs/laravel.log` أو `laravel-{date}.log` | سجلّ التطبيق (LOG_CHANNEL=daily في الإنتاج) |
| `storage/logs/queue.out` / `queue.err` | مخرجات Queue Workers |
| `storage/logs/scheduler.log` | مخرجات `schedule:run` (كل دقيقة) |
| `storage/logs/nginx.err` / `php-fpm.err` | أخطاء الخادم نفسه |

```bash
# عرض مباشر (ديمو)
./demo.sh logs

# عرض مباشر (إنتاج)
docker compose -f docker-compose.prod.yml logs -f amial-app
```

**دوران السجلّات**: `LOG_CHANNEL=daily` مع `LOG_DAILY_DAYS=14` في
`.env.production.example` — يحذف Laravel نفسه الملفّات الأقدم من 14
يوماً تلقائياً. سجلّات Supervisor محدودة بـ `logfile_maxbytes` لمنع
امتلاء القرص.

---

## 🔒 قائمة تحقّق أمنية قبل أوّل نشر حقيقي

- [ ] `.env.production` **غير** مرفوع لـ Git (تحقّق: `git status`)
- [ ] `APP_DEBUG=false` (سكريبت النشر يرفض العمل لو كانت `true`)
- [ ] `APP_KEY` مُولَّد ومحفوظ في مكان آمن (فقدانه = فقدان تشفير البيانات الحسّاسة)
- [ ] `DB_PASSWORD` و`REDIS_PASSWORD` كلمات مرور قويّة فعلياً (لا القيم الافتراضية)
- [ ] منفذ MySQL (3306) **غير** مكشوف للإنترنت (`docker-compose.prod.yml` لا يكشفه أصلاً — لا تُضِف `ports:` له يدوياً)
- [ ] SSL/HTTPS مفعَّل على طبقة الاستضافة (Cloudways/CDN) أمام منفذ 8000
- [ ] `SESSION_SECURE_COOKIE=true` (مفعَّل افتراضياً في القالب)
- [ ] جدولة `docker/backup.sh` عبر cron فعلياً، لا نسيانها

---

## ❓ استكشاف الأخطاء الشائعة

**"كل الطلبات تُحظَر من IP واحد رغم أنّهم مستخدمون مختلفون"**
→ تأكّد أنّ `TrustProxies` مُهيَّأ (راجع القسم أعلاه) وأنّ Nginx يُمرّر
`X-Forwarded-For` فعلياً (موجود في `nginx.conf`/`nginx.prod.conf`).

**"health/readiness يُرجع 503 دائماً"**
→ افحص أيّ فحص فرعي فشل: `curl http://localhost:8000/health/readiness | python3 -m json.tool`

**"queue worker لا يُعالج WhatsApp أو التحويلات المعلَّقة"**
→ تحقّق أنّ scheduler يعمل: `docker compose logs amial-app | grep scheduler`
→ تحقّق من `storage/logs/queue.err`

**"نسيت APP_KEY وفقدت الوصول للبيانات المشفَّرة"**
→ لا حلّ استرجاعي. هذا سبب وجود القائمة الأمنية أعلاه — احفظ
`APP_KEY` في مدير أسرار (1Password، Vault) فور توليده.
