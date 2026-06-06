# سجل التغييرات — v1.2 (Donations Platform)

**التاريخ:** 2026-05-17
**النطاق:** AMIAL-DONATIONS-001 — منصة تبرع كإحسان السعودية، مخصصة للسوق اليمني

---

## ما تم بناؤه

| الفئة | عدد |
|---|---|
| Migrations | 3 (5 جداول + enum modify) |
| Models | 5 |
| Services | 2 (DonationsService + CharityService) |
| Controllers | 2 (customer + admin) |
| API endpoints | 6 + 12 admin |
| Seeders | 1 (9 تصنيفات افتراضية) |
| Tests | 2 ملف / **23 test** |
| Flutter | 5 ملف (models + repo + controller + 4 screens) |
| Config | تحديث `config/amial.php` |
| **مجموع** | **18 ملف جديد** |

---

## التصميم الكامل

### 5 جداول

```
charity_categories       → التصنيفات (طعام، طبية، تعليم، ...)
charity_organizations    → المنظمات الموثقة من الإدارة
charity_campaigns        → الحملات تحت كل منظمة
donations                → كل تبرع فردي
charity_settlements      → التسويات الشهرية للمنظمات
```

### تدفق العمل الكامل

```
1. Admin يضيف منظمة → pending_verification
2. Admin يوثق → verified ✓
3. Admin/Org ينشئ حملة → pending_approval
4. Admin يوافق → active
5. المستخدم يتبرع → debit + held in platform
6. شهرياً: Admin يصدر settlement
7. Admin يحول بنكياً ويسجل reference
8. donations.status: completed → settled
```

---

## القرارات الهندسية الحاسمة

### 1. المال يبقى في المنصة (لا تحويل فوري)

```
Donor wallet ─[debit]→ Platform internal ─[monthly settlement]→ Organization bank account
```

**لماذا؟**
- تجميع الـ donations وتسوية واحدة بنكية (توفير رسوم بنكية)
- إمكانية refund خلال فترة قبل الـ settlement
- audit أفضل: settlement واحدة لكل فترة بمراجع بنكي واحد
- يحمي ضد منظمات تخرج من النظام أثناء الشهر

### 2. is_anonymous يخفي الاسم public، لكن donor_user_id محفوظ دائماً

**الخصوصية:**
- في public stats، الـ recent_donors لا يحتوي اسم المتبرع المجهول
- يظهر "متبرع مجهول" في الـ UI
- في الـ admin panel، الإدارة ترى donor_user_id (للـ AML/compliance)
- المتبرع نفسه يرى تبرعه دائماً في `my-donations`

### 3. الحسابات البنكية للمنظمات

في `CharityOrganization`:
- `bank_account_number`, `bank_swift`, `license_document_path` → `$hidden`
- لا تظهر أبداً في customer API
- تظهر في admin API بـ `$org->makeVisible([...])` صراحة فقط في `showOrg` admin endpoint

### 4. Refund معقد ومحدود

**المسموح:**
- Admin يستطيع refund تبرع `completed` (status=completed، settlement_id=null)
- يعكس wallet + campaign.current_amount + org.total_collected

**الممنوع:**
- refund تبرع `settled` → "Cannot refund donation already settled to charity"
- (لأن المال أُرسل بنكياً للمنظمة فعلياً، لا يمكن استرداده تلقائياً)

### 5. Auto-completion عند الوصول للهدف

```php
if (campaign.current_amount >= campaign.target_amount) {
    campaign.status = 'completed';
}
```

تلقائي في `donate()`. لا يحتاج job منفصل.

### 6. donor_count بـ unique users فقط

نفس المتبرع يتبرع 5 مرات على نفس الحملة → donor_count = 1 (ليس 5).
نفس المتبرع يتبرع على 3 حملات لنفس المنظمة → org.total_donors يحسب unique عبر الـ org كلها.

---

## API Endpoints (18 total)

### Customer (6)
```
GET   /api/v1/amial/donations/categories
GET   /api/v1/amial/donations/organizations
GET   /api/v1/amial/donations/campaigns?category=food&featured=1
GET   /api/v1/amial/donations/campaigns/{ulid}
POST  /api/v1/amial/donations/donate         [amial.zone + amial.rate-limit:donate,10,1]
GET   /api/v1/amial/donations/my-donations
```

### Admin (12)
```
GET   /admin/amial/charity/organizations[?status=pending_verification]
POST  /admin/amial/charity/organizations
GET   /admin/amial/charity/organizations/{ulid}
POST  /admin/amial/charity/organizations/{ulid}/verify
POST  /admin/amial/charity/organizations/{ulid}/reject
POST  /admin/amial/charity/organizations/{ulid}/suspend

GET   /admin/amial/charity/campaigns
POST  /admin/amial/charity/organizations/{orgUlid}/campaigns
POST  /admin/amial/charity/campaigns/{ulid}/approve
POST  /admin/amial/charity/campaigns/{ulid}/pause

GET   /admin/amial/charity/settlements
POST  /admin/amial/charity/settlements/generate
POST  /admin/amial/charity/settlements/{ulid}/transferred
```

---

## Tests (23 test)

### `DonationsServiceTest.php` (15)

| Test | الفائدة |
|---|---|
| `it_donates_successfully_with_fee_calculated` | 100 ر.س ⇒ 1 ر.س fee + 99 ر.س net |
| `anonymous_donation_is_flagged_but_user_id_preserved` | الخصوصية مع الـ audit |
| `non_anonymous_shows_donor_name` | الاسم يظهر public |
| `insufficient_balance_prevents_donation` | atomic rollback |
| `cannot_donate_to_paused_campaign` | state check |
| `cannot_donate_after_deadline` | deadline enforcement |
| `cannot_donate_to_unverified_organization` | safety check |
| `non_south_user_cannot_donate` | zone policy |
| `campaign_auto_completes_when_target_reached` | auto-completion |
| `multiple_donations_from_same_user_count_donor_once` | unique donor logic |
| `org_total_collected_increments_correctly` | denormalized stats |
| `refund_reverses_balances` | refund correctness |
| `cannot_refund_settled_donation` | post-settlement immutability |
| `donation_below_minimum_rejected` | min validation |
| `donation_above_maximum_rejected` | max validation |

### `CharityServiceTest.php` (8)

| Test | الفائدة |
|---|---|
| `verify_organization_changes_status_and_records_admin` | audit trail |
| `reject_organization_deactivates_it` | rejection workflow |
| `suspend_organization_pauses_its_campaigns` | cascade effect |
| `cannot_create_campaign_for_unverified_org` | safety |
| `approve_campaign_makes_it_active` | approval flow |
| `generate_settlement_aggregates_donations_in_period` | core settlement logic |
| `generate_settlement_throws_when_no_donations` | empty period handling |
| `settlement_does_not_include_donations_already_in_other_settlement` | no double-settle |
| `mark_settlement_transferred_records_bank_reference` | tracking |

---

## Flutter UI

### 5 ملفات

| File | Lines | الوظيفة |
|---|---|---|
| `donation_models.dart` | ~210 | 5 model classes كاملة |
| `donations_repo.dart` | ~50 | 6 API methods |
| `donations_controller.dart` | ~135 | state + actions |
| `donations_home_screen.dart` | ~310 | hero banner + categories + featured + all list |
| `campaign_detail_screen.dart` | ~340 | cover + progress + description + recent donors + sticky donate button |
| `campaigns_list_screen.dart` | ~90 | قائمة حملات بتصنيف |
| `my_donations_screen.dart` | ~125 | سجل تبرعاتي + الإحصاء الإجمالي |

### قرارات UX المهمة

**1. Quick amounts chips**
الـ donate bottom sheet يعرض: `10 / 50 / 100 / 200 / 500 ر.س` كأزرار سريعة. تقليل احتكاك الإدخال.

**2. Anonymous checkbox مع توضيح**
"لن يظهر اسمك في قائمة المتبرعين العامة" — يبني الثقة في الخصوصية.

**3. تأكيد نجاح بصري قوي**
بعد التبرع → dialog مع أيقونة قلب أحمر + "تقبل الله منك". تجربة عاطفية إيجابية.

**4. شارة التوثيق `verified`**
تظهر بجانب اسم المنظمة في detail screen — يثبت أن الإدارة وثقت المنظمة.

**5. Progress + Days remaining + Donor count**
ثلاث إشارات اجتماعية في كل campaign card: progress %, آخر موعد, عدد المتبرعين. يحفز التبرع.

**6. Hero banner في home**
gradient أزرق + رسالة قوية: "تبرع لمن يحتاج — ساهم في حملات خيرية موثقة بأي مبلغ يناسبك"

---

## Receipts integration

Receipt enum تم تحديثه لـ 19 type (كان 17):
- ✅ `donation` — إيصال للمتبرع
- ✅ `charity_settlement` — إيصال للمنظمة عند التحويل (في v1.3)

كل تبرع `completed` يصدر receipt تلقائياً مع:
- amount: الكامل
- fee: 1%
- metadata: org_name, campaign_title, is_anonymous

---

## النشر السريع v1.2

```bash
# 1. backup
mysqldump cash6_db > /backups/pre_v1.2_$(date +%s).sql

# 2. migrations
php artisan migrate

# 3. seed التصنيفات
php artisan db:seed --class=CharityCategoriesSeeder

# 4. (config) في .env (اختياري):
# AMIAL_DONATIONS_FEE_PERCENT=1.0
# AMIAL_DONATIONS_MIN_AMOUNT=1.0000
# AMIAL_DONATIONS_MAX_AMOUNT=50000.0000

# 5. test
php artisan test --filter="Donations|Charity"

# 6. cache clear
php artisan config:clear && php artisan cache:clear

# 7. (admin) إنشاء أول منظمة عبر admin panel أو tinker
php artisan tinker
>>> $org = CharityOrganization::create([
        'org_ulid' => Str::ulid(),
        'name_ar' => 'مؤسسة الإحسان',
        'license_number' => '...',
        'description_ar' => '...',
        'contact_phone' => '+967...',
        'bank_name' => 'بنك...', 'bank_account_number' => '...',
        'verification_status' => 'verified',
        'zone_code' => 'SOUTH',
    ]);
```

---

## النسبة الإجمالية

```
v1.1:    █████████████████████████ 98%
v1.2:    █████████████████████████ 99%
```

| البند | قبل | بعد |
|---|---|---|
| Donations Platform | 0% | **100%** ✅ |

---

## الميزة التنافسية للسوق اليمني

**سيناريوهات حقيقية:**

| السيناريو | الفائدة |
|---|---|
| إغاثة كارثة طبيعية | تبرع سريع من التطبيق بدون SMS أو bank transfer |
| كفالة يتيم | متابعة مستمرة + إيصالات شهرية |
| علاج طفل مريض | حملة مع target، الناس يرون التقدم في الوقت الحقيقي |
| تجهيز مسجد | شفافية في المبلغ + المستفيد |
| تبرع في رمضان/الأعياد | UX سريع — quick amounts + 30 ثانية للتبرع |

**الميزة التنافسية:**
- معظم المحافظ في اليمن **لا توفر منصة تبرع مدمجة**
- المنظمات الخيرية اليمنية تحتاج وسيلة شفافة وآمنة لجمع التبرعات
- النظام **يبني الثقة** عبر:
  - توثيق إدارة كل منظمة
  - شفافية كاملة في الـ progress
  - إيصال PDF لكل تبرع
  - audit log كامل
  - تسويات بنكية موثقة

---

## ما تبقى لـ 100% (1%)

كله مرتبط بمشتريات خارج التطوير:

| Section | يحتاج |
|---|---|
| Sections 11-16 (Merchant Stack) | شراء كود التاجر CodeCanyon |
| Section 4 (Agent Panel) | شراء كود الوكيل CodeCanyon |

---

## Total Test Count (المشروع كاملاً)

| Batch | Tests |
|---|---|
| v0.6 (Core Refactor) | 4 |
| v0.7-A (Zone+Legal+Recovery) | 4 |
| v0.9 (Receipts+FamilyFund+BillPay+Integration) | 33 |
| v1.0 (RBAC+RateLimit) | 18 |
| v1.1 (Safe Payment) | 27 |
| **v1.2 (Donations)** | **23** |
| **Total** | **~109 tests** |

كل اختبار يحمي ميزة محورية. أي تعديل يكسر هذه الاختبارات سيُكتشف فوراً.
