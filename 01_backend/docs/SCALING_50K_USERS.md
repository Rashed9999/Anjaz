# Scaling لـ 50,000 مستخدم — دليل واقعي

**AMIAL-SCALE-001 (v1.5)**

## الإجابة المختصرة

**نعم، يمكن لـ Amyal Pay التعامل مع 50,000 مستخدم مسجل بسعر $100-300/شهر** إذا اخترت البنية الصحيحة.

البنوك العالمية تنفق ملايين لأنها تخدم ملايين العملاء بـ uptime 99.99%. أنت تحتاج 50k مستخدم بـ uptime 99.5% — مختلف تماماً.

## فهم الأرقام الحقيقية

50,000 مستخدم مسجل **لا يعني** 50,000 طلب/ثانية. الواقع:

| الـ Metric | الرقم المحتمل |
|---|---|
| إجمالي المسجلين | 50,000 |
| مستخدم نشط شهرياً (MAU) | ~30,000 |
| **مستخدم نشط يومياً (DAU)** | **~5,000-10,000** |
| متزامن في الذروة | ~500-1,500 |
| طلبات/ثانية في الذروة | ~50-200 req/s |
| معاملات مالية/يوم | ~1,000-5,000 |
| رفع KYC files/يوم | ~50-200 |

**هذا حمل معقول لخادم واحد قوي.**

## البنية الموصى بها (Tier 1: $80-150/شهر)

```
┌────────────────────────────────────────────────────────────┐
│                  CloudFlare (مجاني)                          │
│  • CDN للأصول الثابتة                                          │
│  • DDoS protection أساسي                                       │
│  • SSL/TLS                                                     │
│  • WAF rules أساسية                                            │
└────────────────────────────────────────────────────────────┘
                            ↓
┌────────────────────────────────────────────────────────────┐
│         VPS واحد (Hetzner CCX23 / DO 8GB)                     │
│  • 8 GB RAM, 4 vCPU, 240 GB NVMe                              │
│  • Ubuntu 22.04                                                │
│  • Nginx + PHP 8.2-FPM (8 workers)                            │
│  • MySQL 8.0                                                   │
│  • Redis 7                                                     │
│  • Laravel queue worker (supervisor)                          │
└────────────────────────────────────────────────────────────┘
                            ↓
┌────────────────────────────────────────────────────────────┐
│   Backblaze B2 / Wasabi (للـ KYC + Receipts + Backups)        │
│  • $5-10/شهر لـ 100-500 GB                                    │
└────────────────────────────────────────────────────────────┘
```

**التكلفة:**

| البند | المزود | السعر/شهر |
|---|---|---|
| VPS 8GB/4 vCPU | Hetzner CCX23 | €17 (~$18) |
| VPS 8GB/4 vCPU (بديل) | DigitalOcean | $48 |
| VPS 8GB/4 vCPU (managed) | CloudWays | $60 |
| CDN + DDoS | CloudFlare Free | $0 |
| Backups | Backblaze B2 | $5-10 |
| Email transactional | Resend / Mailgun | $0-20 |
| SMS (OTPs) | محلي يمني | $50-150 (متغير) |
| Monitoring | Sentry Free tier | $0 |
| **مجموع** | | **$73-260/شهر** |

**ملاحظة:** SMS هو أكبر متغير. كل OTP يكلف $0.01-0.05. لـ 5,000 OTP/يوم = $50-200/شهر.

## ما لـ v1.5 يجعل هذا ممكناً؟

### 1. Redis Caching للـ AML Rules

**قبل:** كل معاملة → DB query للقواعد (~10ms × 1000 req/min = 10,000ms/دقيقة CPU)
**بعد:** Cache 5 دقائق → 1 query كل 5 دقائق
**التوفير:** ~99.8% CPU للـ rules loading

### 2. Redis Sorted Sets للـ Velocity Counters

**قبل:** `SELECT COUNT(DISTINCT ulid) FROM aml_rule_evaluations` (يبطئ مع البيانات)
**بعد:** Redis ZCOUNT < 1ms (ثابت بغض النظر عن البيانات)
**التوفير:** ~95% latency reduction للـ velocity checks

### 3. Database Indexes المحسّنة

أضفنا 11 index جديد على الجداول الأكثر استخداماً:
- `aml_rule_evaluations`: composite (user, time, amount)
- `users`: blind_index columns
- `receipts`: user + reference
- `donations`: campaign + status + time
- `safe_payments`: buyer/seller + status

**النتيجة:** queries تبقى < 50ms حتى مع ملايين الـ rows.

### 4. Queue للعمليات الثقيلة

- PDF generation للإيصالات → queue
- Notifications → queue
- Email sending → queue
- AML deep scans → queue

**النتيجة:** المعاملة المالية لا تنتظر عمليات غير حرجة.

## Optimization Checklist

### قبل الـ pilot

- [ ] `php artisan migrate` (شامل v1.5 indexes)
- [ ] `php artisan optimize` (route + config + view caching)
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `php artisan event:cache`
- [ ] في `.env`:
  ```
  APP_DEBUG=false
  CACHE_DRIVER=redis
  QUEUE_CONNECTION=redis
  SESSION_DRIVER=redis
  ```
- [ ] PHP `opcache` enabled في php.ini
- [ ] MySQL `innodb_buffer_pool_size` = 4GB (50% من الـ RAM)
- [ ] Nginx `keepalive_timeout 65`
- [ ] CloudFlare proxied (DNS → orange cloud)
- [ ] Supervisor للـ queue workers (4-8 processes)
- [ ] Cron `php artisan schedule:run` كل دقيقة

### مراقبة continuous

- [ ] Slow query log في MySQL (queries > 1s)
- [ ] PHP-FPM status page
- [ ] `redis-cli INFO stats` لمراقبة الـ hit ratio
- [ ] Queue depth (`php artisan queue:size`)
- [ ] Disk usage trends

### علامات تحتاج upgrade

| العلامة | الحل |
|---|---|
| CPU > 70% دائماً | Upgrade VPS to 16 vCPU |
| RAM > 85% | Upgrade VPS to 16 GB |
| MySQL slow queries كثيرة | راجع indexes أو split DB |
| Queue > 10k pending | زود queue workers أو افصل DB |
| Disk > 80% | Archive logs قديمة أو زود disk |

## متى نحتاج Tier 2 (10k+ DAU)؟

**علامات الحاجة:**
- DAU > 10,000 ثابت لشهر
- معاملات > 10,000/يوم
- VPS واحد لا يكفي رغم optimization

**Tier 2 ($300-700/شهر):**

```
                CloudFlare
                    ↓
            Load Balancer ($10)
            ↓           ↓
     App Server 1   App Server 2     (Hetzner CCX23 × 2 = $36)
            ↓           ↓
       ┌──────────────────────┐
       │   MySQL Primary       │       ($30 managed)
       │   + Read Replica      │       ($30)
       └──────────────────────┘
                    ↓
             Redis (separate)         ($20)
                    ↓
              S3 / B2                 ($15)
```

**النقاط الفارقة:**
- Read replicas للـ heavy reads
- Load balancer لـ traffic distribution
- Redis منفصل (HA)
- Workers على server مختلف

## أدوات مجانية تساعد على الـ scale

| الأداة | الفائدة |
|---|---|
| **Laravel Octane** | يضاعف الأداء عبر Swoole/RoadRunner (10x throughput) |
| **MariaDB ColumnStore** | للـ analytics queries |
| **Meilisearch** | Search سريع (بديل ElasticSearch المعقد) |
| **Bunny CDN** | بديل أرخص لـ CloudFlare ($1-5/شهر) |
| **UptimeRobot** | Free monitoring (50 checks) |
| **Better Stack** | Free logs + monitoring tier |
| **Pingdom Free** | uptime monitoring أساسي |
| **PHP-FPM ondemand** | اقتصاد في الـ RAM |
| **Laravel Telescope** | debugging local فقط (لا production) |
| **Spatie Backup** | backups أوتوماتيكية |
| **Predis cluster** | Redis cluster لـ HA |

## استراتيجية الـ scaling الذكية (لكل تكلفة منخفضة)

### المرحلة 1: 0-1k مستخدم
- VPS 4GB ($10-20/شهر)
- Free CloudFlare
- **الإجمالي: ~$30-50/شهر**

### المرحلة 2: 1k-10k DAU
- VPS 8GB ($30-50)
- Backups ($5)
- SMS ($30-50)
- **الإجمالي: ~$80-150/شهر**

### المرحلة 3: 10k-50k DAU (هدفك)
- VPS 16GB ($60-80)
- Backups + S3 ($15)
- SMS ($100-200)
- Sentry paid tier ($26)
- **الإجمالي: ~$200-350/شهر**

### المرحلة 4: 50k-200k DAU
- Cluster: 2 app servers + managed DB
- **الإجمالي: ~$500-1,000/شهر**

### المرحلة 5: 200k+
- Kubernetes + multi-region
- **الإجمالي: $2,000+ /شهر**

## مقارنة مع الأخطاء الشائعة

| الخيار | تكلفة شهرية لـ 50k DAU | الواقع |
|---|---|---|
| **VPS واحد محسّن (موصى به)** | $200-350 | ✓ كافٍ |
| AWS Lambda + RDS | $1,500-3,000 | overkill + vendor lock |
| Heroku (managed) | $1,000-2,000 | باهظ ومحدود |
| Kubernetes (DO/GKE) | $800-1,500 | تعقيد لا يبرر |
| Multi-region من البداية | $3,000+ | غير مبرر |

**القاعدة:** **ابدأ بسيطاً، ارفع عند الحاجة.** لا تبني لـ Netflix قبل أن يكون لديك 100 مستخدم.

## الخلاصة الواقعية

**أنت تستطيع تشغيل Amyal Pay لـ 50,000 مستخدم بـ $200-350/شهر إذا:**

1. ✅ استخدمت Hetzner أو DigitalOcean (تجنب AWS كبداية)
2. ✅ فعّلت Redis caching (v1.5 يحتوي على هذا)
3. ✅ شغّلت `php artisan optimize` في production
4. ✅ استخدمت CloudFlare مجاناً
5. ✅ MySQL مع indexes صحيحة (v1.5 شامل)
6. ✅ Queue workers للعمليات الثقيلة
7. ✅ Monitor + tune بناءً على البيانات الحقيقية

**ما يكسر الميزانية:**
- ❌ AWS Lambda لكل request
- ❌ Managed Kubernetes قبل الحاجة
- ❌ Redundancy مفرطة (3 servers قبل الحاجة)
- ❌ Premium SaaS قبل الإيرادات

**SMS هو أكبر خطر** لأنه linear مع OTPs. خفّض حاجة OTP:
- استخدم OTP فقط في login جديد (لا كل request)
- 30 يوم refresh token للأجهزة الموثوقة
- استخدم biometric للدخول السريع (Face/Touch)

## رابط مفيد

[Laravel Performance Tips (مجاني)](https://laravel.com/docs/11.x/deployment#optimization)
[Hetzner pricing](https://www.hetzner.com/cloud)
[CloudFlare Free features](https://www.cloudflare.com/plans/)
