# plan.md — أميال باي (`Rashed9999/Anjaz`)

> خطة هندسية وتنفيذية مبنية على فحص الشيفرة الفعلية. **توثيق فقط**: لم تُعدَّل شيفرة ولا قاعدة ولا CI ولا نشر عند إعدادها.
> مفردات الحالة: `CODE_PRESENT` · `TEST_VERIFIED` · `PRODUCTION_VERIFIED` · `PARTIAL` · `UNREACHABLE` · `LEGACY` · `MISSING` · `BLOCKED` · `UNKNOWN` · `PROPOSED`.
> الملاحق التفصيلية (كل مسار، كل شاشة، كل عنصر تفاعلي، كل جدول، كل اختبار): [`docs/plan/README.md`](docs/plan/README.md).

---

## 1. Document Control

| البند | القيمة |
|---|---|
| المنصة / الرمز | أميال باي / `PAY` |
| المستودع | `Rashed9999/Anjaz` |
| الفرع الافتراضي = فرع النشر | `claude/project-code-review-yjagv` (لا `main` ولا `develop` على origin) |
| HEAD المفحوص | `751ea393820ff0b00501e5562ca47c367ef120dd` (دمج PR #46، 2026-11-14). شجرة العمل المحلية `1a1d8c65` مطابقة المحتوى (4 التزامات صافيها صفر على فرع `ccr-ae92d4de-e1iqo6`) |
| تاريخ الفحص | 2026-11-15 |
| كاتب الخطة | Claude Code (جلسة تخطيط، قراءة فقط) |
| المالك | مالك المشروع (Rashed9999) — ملاك المجالات الفرعية **غير معيّنين** (انظر GOV-001 في `Amial-Merchant/docs/ecosystem/BACKLOG.md`) |
| حالة الثقة | **جرد ساكن عالٍ** (مسارات من `artisan route:list`، شاشات واستيراد Dart، هجرات). **تشغيل**: نتيجة CI واحدة على HEAD. **إنتاج**: `UNKNOWN` |
| آخر فحص فعلي | CI رقم 4770 على `751ea393`: ناجح (انظر §18) — <https://github.com/Rashed9999/Anjaz/actions/runs/43604957709> |

### سجل المصادر

| SOURCE | PATH/URL | BRANCH | COMMIT | LAST_VERIFIED | RELIABILITY | LIMITATION |
|---|---|---|---|---|---|---|
| جدول المسارات | `php artisan route:list --json` (1371 صفًّا) | افتراضي | `1a1d8c65`≡`751ea393` | 2026-11-15 | عالية (وقت تشغيل الإطار) | بيئة تطوير محلية؛ تتضمن 10 مسارات أدوات تطوير قد لا توجد في الإنتاج |
| الجرد الساكن | `01_backend/scripts/repo-inventory.py` (قراءة فقط، بلا `--write`) | افتراضي | نفسه | 2026-11-15 | متوسطة | محلل نمطي لا مصرّف؛ الروابط الديناميكية غير محسومة |
| وصول Flutter | BFS للاستيراد من `lib/main.dart` | افتراضي | نفسه | 2026-11-15 | عالية لنفي الوصول، متوسطة لإثباته | الاستيراد ≠ ملاحة فعلية |
| CI | GitHub Actions run 43604957709 | افتراضي | `751ea393` | 2026-11-15 | عالية لما شُغّل | APK متخطّى؛ خطاف النشر قُبل فقط |
| قواعد المشروع | `CLAUDE.md`, `01_backend/CLAUDE.md`, `.claude/skills/SKILL-GOVERNANCE.md` | افتراضي | نفسه | 2026-11-15 | عالية للقواعد | حقائق التشغيل فيها مؤرخة (أغسطس–نوفمبر) |
| خرائط سابقة | `docs/AMIAL_SYSTEM_MAP.md`, `docs/AMIAL_SCREEN_INVENTORY.md`, `docs/AMIAL_STATIC_INVENTORY.json` | افتراضي | مبنية على `b48cb9a2` | 2026-10-28 | **متقادمة** | الأعداد تغيّرت (انظر §27)؛ لم تُعدَّل هذه الملفات |
| جاهزية الفواتير | `docs/AMIAL_BILL_PROVIDER_READINESS.md` | افتراضي | حتى `0ec645f9` (14 سبتمبر) | 2026-11-15 | متقادمة جزئيًا | تقول «StubProvider وحده»؛ الشيفرة الحالية فيها `FreeSadadProvider` |
| PRs/Issues | GitHub PRs #4–#47، Issue #23 | — | — | 2026-11-15 | عالية | لم تُفحص فروع PRs المغلقة ملفًّا بملف |
| عقد قناة الأعمال | `docs/AMIAL_BUSINESS_GATEWAY_CONTRACT.md` | افتراضي | نفسه | 2026-11-15 | عالية كنص | لم يُشغَّل اختبار عقد عابر للمستودعين في هذه الجلسة |
| الإنتاج (Coolify/DB/DNS) | — | — | — | — | — | **لا وصول مقروء في هذه المهمة ⇒ `UNKNOWN`** |

---

## 2. Executive Summary

**ما هي المنصة:** الخادم المالي (Laravel 12 / PHP ^8.2 / Passport / MySQL) وتطبيق العميل (Flutter، GetX، الإصدار `1.97.0+1970`). هي **مصدر الحقيقة الوحيد للمحافظ والأرصدة والقيود والرسوم والحدود**. تاريخيًّا ضمّ المستودع كل شيء: لوحة الإدارة، بوابة شركات الصرافة، بوابة ويب للتاجر، وقطاعات تجارية كاملة.

**ما تم التثبت منه (ساكنًا على HEAD):** 1371 مسارًا، 239 Controller، 311 خدمة، 246 نموذجًا، 331 هجرة (314 اسم جدول)، 169 قالب Blade، 557 ملف Dart فيها 223 صنف شاشة، 618 ملف PHPUnit (≈4379 حالة عدًّا ساكنًا) و73 ملف اختبار Dart (≈364). CI الكامل ناجح على HEAD بما فيه رحلات العميل/التاجر-POS/الوكيل الذهبية و E2E موثّق.

**أخطر ثلاث فجوات:**
1. **`PAY-GAP-01` (P0) — الإنتاج غير مُثبَت ولا مُراقَب بدليل حديث:** آخر توثيق داخلي (`01_backend/CLAUDE.md`) يسجل «لا فحص صحة في Coolify»، نشر بلا rolling update، قناة إنذار غير مضبوطة، ونسخة خارج الخادم غير مثبتة. الحالة اليوم `UNKNOWN` لغياب الوصول.
2. **`PAY-GAP-02` (P0) — مسارات مالية غير مكتملة الإثبات:** دفع الفواتير (مزود `FreeSadadProvider` موجود شيفرةً بلا إثبات عقد/اعتمادات حقيقية، ومعالجة «النتيجة المجهولة» موثّقة كناقصة)، و OTP التجريبي الثابت للهاتف ما زال قرارًا قائمًا «طوال التجربة».
3. **`PAY-GAP-03` (P1) — ازدواج الملكية داخل المستودع:** 320 مسار API تاجر/POS + 125 مسار بوابة ويب تاجر + ≈124 جدولًا تجاريًّا تعيش في Pay بينما `Amial-Merchant` منصة مستقلة؛ و78 شاشة Flutter (138 ملفًّا) غير موصولة من `main.dart` لكن 27 ملفًّا منها محروسة بـ36 اختبارًا ساكنًا يشترط وجودها.

**الخطوة التالية:** `PAY-T-001` (جرد الإنتاج قراءة فقط = INF-001/VER-001) ثم `PAY-T-002` (قرار مصير الشاشات غير الموصولة وحرّاسها).

---

## 3. Business Scope and Non-Goals

**في النطاق (Owned here):** حساب العميل ومحفظته؛ الأرصدة المتاحة والمحجوزة؛ التحويل والدفع وطلب المال و QR؛ الإيداع والسحب عبر الوكلاء؛ دفع الفواتير؛ الدفع الآمن؛ صندوق الأسرة والتبرعات وتقسيم الفاتورة؛ محرك الرسوم والحدود والمناطق؛ الدفتر المزدوج والتسويات والمطابقة؛ AML/الاحتيال على الحركات المالية؛ الإيصالات وكشوف الحساب؛ قنوات الخادم-لخادم مع موثّق وأميال أعمال.

**خارج النطاق المستهدف (تعيش هنا اليوم — `Must migrate` أو `Legacy still active`):**
- التجارة (مخزون، بيع، مشتريات، قطاعات) ⇒ المالك المستهدف `BUS` — انظر `Amial-Merchant/plan.md`.
- تشغيل شركات الصرافة (فروع، صرّافون، ورديات، درج) ⇒ `AGT` — انظر `Amial-Agents/plan.md`. **دفتر المال وقيود التسوية تبقى في Pay.**
- لوحة الإدارة والحوكمة ⇒ `ADM`؛ مركز الدعم ⇒ `SUP`؛ هوية الدخول والجلسات ⇒ `IAM` (كلها أهداف بعد قرارات D-004/D-005/D-009).
- قرار KYC وأدلته ⇒ `MWT` (منفّذ فعليًّا لفتح الحساب وترقية الفئة 2؛ الفئة 3 للمرأة قرار محلي في Pay مع إشعار موثّق — PR #46/#48).

**يحتاج قرارًا منفصلًا:** مصير شاشات التاجر في تطبيق الهاتف (PAY-ADR-01)؛ تطبيق POS مستقل (ADR 0014 في Merchant)؛ مزود SMS/WhatsApp الحقيقي لـOTP (PAY-ADR-03)؛ مزود الفواتير (PAY-ADR-04)؛ Transfer (D-001/D-010).

---

## 4. Personas and Journeys

| الدور | السطح | الدخول | الهدف | الخروج/الفشل |
|---|---|---|---|---|
| عميل | Flutter | `UnifiedLoginScreen` → `HomeDispatcherScreen` → `NavBarScreen` | محفظة، تحويل، دفع، سحب، فواتير | انتهاء جلسة Passport؛ قفل PIN؛ 401/403/402 تُعرض كحالات لا كصفر |
| متقدم جديد | Flutter + موثّق | `AmialRegistrationWizardScreen` → `/api/v1/auth/register/*` → موثّق → بريد رمز → `/api/v1/auth/activate` | فتح حساب وتفعيله | `needs_more_info`، رفض، انتهاء رمز |
| موظف POS | Flutter | نفس الدخول؛ `actor=pos` ⇒ `PosEmployeeHomeScreen` | بيع حسب قطاع صاحبه | وردية مغلقة، جهاز غير مفعّل، 403 |
| مالك منشأة | متصفح | التطبيق يعرض `WebPortalNoticeScreen(role:'merchant')` ⇒ بوابة الويب `/merchant/*` أو أميال أعمال | إدارة المنشأة | — |
| شركة صرافة/فرع/صرّاف | متصفح | `/agent/login` (حارس `agent_staff` جلسة) | شباك، وردية، تسوية | خارج الفرع/الوردية ⇒ رفض |
| إداري | متصفح | `/admin/auth/*` (حارس `user` جلسة + 2FA/PIN منصة) | رقابة وإعدادات | صلاحية تبويب/دور |
| خدمة موثّق | خادم-لخادم | `/api/v1/internal/muwathaq/*` (`VerifyMuwathaqSignature`) | تزويد حساب، قرار، فحص هوية | توقيع/طابع زمني خاطئ ⇒ رفض |
| خدمة أميال أعمال | خادم-لخادم | `/api/v1/business/*` (`VerifyBusinessGatewaySignature`) | نيّة دفع، استرداد، مرآة دين | رابط مجمّد/توقيع خاطئ |

رحلات E2E العابرة للمنصات التي تملك Pay جزءًا منها: E2E-1 (عميل جديد)، E2E-2 (تاجر جديد)، E2E-4 (وكيل)، E2E-5 (دعم مالي)، E2E-7 (تحويل)، E2E-8 (فواتير) — التعريف في التكليف الرئيسي §12.1؛ التغطية الحالية في §18.

---

## 5. Architecture — As Is / Target

**As Is (مثبت من الشيفرة):**

```
Flutter (GetX, lib/features/*, ApiClient)
   │  HTTPS + Passport bearer + Idempotency-Key
   ▼
Laravel 12 monolith (01_backend)
   ├─ routes/api/amial.php ............ /api/v1/amial/*   (533 تعريفًا ساكنًا)
   ├─ routes/api/unified-auth.php ..... /api/v1/auth/*
   ├─ routes/api/business-gateway.php . /api/v1/business/* (HMAC)
   ├─ api/v1/internal/muwathaq/* ...... (HMAC)
   ├─ routes/admin*.php ............... /admin/*  (Blade, جلسة)
   ├─ routes/agent.php ................ /agent/*  (Blade, حارس agent_staff)
   ├─ routes/merchant-web.php ......... /merchant/* (Blade, حارس merchant_web)
   ├─ Services: MoneyService, LedgerService, FeeService, PaymentRequestService,
   │            CustomerWithdrawService, BillPayService, SafePaymentService,
   │            BusinessGateway/*, Muwathaq/*, Agent*, Reconciliation/*, AML*
   └─ MySQL واحدة (314 اسم جدول في الهجرات) + scheduler (routes/console.php) + queue
```

حرّاس المصادقة (`config/auth.php`): `api` (Passport)، `user` (جلسة إدارة)، `merchant_web` (جلسة)، `agent_staff` (جلسة بمزوّد مستقل).
الحاوية: `01_backend/Dockerfile` (ما يبنيه Coolify حسب `01_backend/CLAUDE.md`) و`Dockerfile.prod`؛ CI يبني الاثنتين.

**Target (مقترح، غير معتمد — يتبع D-004/D-005):** Pay = خدمة مال + تطبيق عميل. التجارة ⇒ Merchant عبر القناة الموقّعة القائمة. تشغيل الوكلاء ⇒ Agents بعقد حدود (مسودة `AGT-02`). الإدارة/الدعم/IAM ⇒ خدمات تطلب من Pay ولا تكتب جداوله.

**الفوارق:** لا يوجد اليوم فصل قاعدة بيانات إلا لموثّق وأميال أعمال. كل «نقل» آخر هو قرار معلّق لا واقع.

---

## 6. Functional System Catalog

| System ID | الاسم | المالك النهائي | المدخل الحالي (دليل الكود) | الحالة | دليل الاختبار | فجوة | أولوية |
|---|---|---|---|---|---|---|---|
| PAY-SYS-01 | الدخول وفتح الحساب والاستعادة | PAY (جلسة) / MWT (قرار) / IAM (هدف) | `routes/api/unified-auth.php`؛ `UnifiedAuthService`؛ `AccountOpeningBoundaryController`؛ `AccountActivationController`؛ `EmailOtpController`؛ `Services/Otp/*` | CODE_PRESENT | خطوات CI: «رحلة العميل الذهبية»، «E2E موثّق»، «دورة البريد» ✓ على `751ea393` | OTP هاتف تجريبي ثابت (PAY-GAP-04) | P0 |
| PAY-SYS-02 | ملف العميل والهوية التشغيلية | PAY (عرض) / MWT (أدلة) | `features/kyc_verification`, `setting`؛ `Services/Kyc/*`, `KycTierService`, `Muwathaq/*` (18 خدمة) | CODE_PRESENT | `kyc-biometric-guard` ✓ | ملكية الأدلة: PR #34 مغلق غير مدمج، Muwathaq PR #29 مفتوح (PAY-GAP-07) | P0 |
| PAY-SYS-03 | المحفظة والكشف | PAY | `features/home`, `me`, `history`؛ `MoneyService`, `LedgerService`؛ `/api/v1/amial/me/*` (29 مسارًا)، `/statement` | CODE_PRESENT | ضمن المجموعة الكاملة ✓ | — | — |
| PAY-SYS-04 | التحويل والدفع و QR وطلب المال | PAY | `/api/v1/amial/transfer/*` (4)، `payment-requests/*` (10)؛ `PendingTransferService` (حجز + نافذة إلغاء 60ث + PIN)، `PaymentRequestService` | CODE_PRESENT | ضمن المجموعة ✓؛ ضغط متوازٍ `scripts/load-test.php` (لم يُشغَّل هنا) | — | — |
| PAY-SYS-05 | الإيداع والسحب عبر الوكلاء | PAY (مال) / AGT (تشغيل) | `/api/v1/amial/withdraw/*`، `agent/withdraw/{lookup,execute}`؛ `CustomerWithdrawService`؛ `AgentCounterService` | CODE_PRESENT | «رحلة الوكيل الذهبية» ✓؛ PR #42/#43 (حدود الفئة 1 عند الشباك) | — | — |
| PAY-SYS-06 | دفع الفواتير | PAY | `/api/v1/amial/bill-pay/*` (6)؛ `BillPayService`؛ `Services/BillPay/{FreeSadadProvider,StubProvider}`؛ webhook `providers/free-sadad/webhook` | PARTIAL · `EXTERNAL_VERIFICATION_PENDING` | `BillPayServiceTest` ضمن بوابة CI المركّزة | نتيجة مجهولة/قفل الإنهاء/إيصال عكس (PAY-GAP-02) | P0 قبل تفعيل مزود حقيقي |
| PAY-SYS-07 | الدفع الآمن | PAY | `/api/v1/amial/safe-payments/*` (15)؛ `SafePaymentService`, `SafePaymentEvidenceService`؛ إدارة `admin/amial/safe-payments` (6) | CODE_PRESENT | ضمن المجموعة ✓ | آلة الحالات غير موثّقة هنا (PAY-T-014) | P2 |
| PAY-SYS-08 | خدمات إضافية (صندوق الأسرة، تبرعات، تقسيم، هدايا، أقساط، ديوني) | PAY | `funds/*` (10)، `donations/*` (6)، `split-bills/*` (2)، `customer/credits/*` (5) | CODE_PRESENT؛ شاشتا `gift_cards` و`installments` **UNREACHABLE** | — | شاشات عميل غير موصولة | P3 |
| PAY-SYS-09 | محرك الرسوم والحدود والمناطق | PAY (تنفيذ) / ADM (اعتماد سياسة، هدف) | `FeeService`, `FeeDiscountPolicy`, `FinancialGuardService`؛ `EnforceZonePolicy`؛ إدارة `admin/amial/fees` (10)، `zone(s)` | CODE_PRESENT | `FeeCodeReachabilityTest`, `ZonePolicyTest` | 139 موضع `(float)` في `app/Services` يحتاج تدقيق مالي (PAY-GAP-08) | P1 |
| PAY-SYS-10 | الدفتر والتسوية والمطابقة | PAY | `LedgerService`, `LedgerReportService`, `Services/Reconciliation/*`, `PlatformTreasuryService`؛ `amial:reconcile-fees` كل دقيقة | CODE_PRESENT | `LedgerCoverageGuardTest` وغيرها ✓ | — | — |
| PAY-SYS-11 | الأمان والامتثال (AML، احتيال، حدود معدل) | PAY | `Aml*Service` (5)، `SanctionScreeningService`, `InsiderWatchService`, `Services/Security/*`؛ `PerUserRateLimit`, `EnforceIdempotency` | CODE_PRESENT | حرّاس متعددة | **إثبات توصيل AML بكل مسار مالي غير منجز هنا ⇒ UNKNOWN** (PAY-T-015) | P1 |
| PAY-SYS-12 | التقارير والإيصالات والطباعة | PAY | `ReceiptService`, `ReceiptDocumentService`, `Services/Reporting/*`؛ `receipts/*` (6)، `reports/*` (4)، `api/v1/customer` (42) | CODE_PRESENT | — | — | — |
| PAY-SYS-13 | لوحة الإدارة داخل Anjaz | ADM/SUP/IAM (أهداف) — اليوم PAY | `routes/admin.php`, `routes/admin/*.php`: 493 مسار ويب + 83 مسار API إدارة | CODE_PRESENT · `Legacy still active` | «وصلات الإدارة الحرجة» ✓ | لا خطة فصل معتمدة (D-009) | P1 |
| PAY-SYS-14 | القطاعات التجارية القديمة | BUS | `/api/v1/amial/merchant/*` (301) + cashier/restaurant/pos-devices (19)؛ `/merchant/*` ويب (125)؛ ≈124 جدولًا | `Legacy still active` (POS وبوابة الويب) + `Legacy unreachable` (شاشات المالك في Flutter) | «رحلة التاجر و POS» ✓ | ازدواج مع Merchant (PAY-GAP-03) | P1 |
| PAY-SYS-15 | قنوات الرسائل | PAY | `Services/Messaging/*`, `Otp/*`, `NotificationService`؛ `wa/webhook`, `auth/email-otp/webhook` | CODE_PRESENT | «دورة البريد» ✓ | SMS/WhatsApp OTP حقيقي `BLOCKED` على مزود | P0 قبل الإطلاق |
| PAY-SYS-16 | الإصدارات والتشغيل | PAY/OPS | `Dockerfile*`, `.github/workflows/ci.yml`, `codemagic.yaml` (يدوي)، `scripts/{verify,smoke,backup,run_dr}.sh` | CODE_PRESENT؛ إنتاج `UNKNOWN` | docker ×2 ✓؛ APK **لم يُبنَ** على HEAD | PAY-GAP-01, PAY-GAP-09 | P0 |
| PAY-SYS-17 | قناة أميال أعمال (خادم لخادم) | PAY (مال) ↔ BUS | `/api/v1/business/*` (15)؛ `Services/BusinessGateway/*` (13)؛ `amial:business tick` | CODE_PRESENT | `BusinessGatewayTest` وأخواتها | لا اختبار عقد يشغّل الطرفين معًا (PAY-T-016) | P2 |
| PAY-SYS-18 | قناة موثّق (خادم لخادم) | MWT (قرار) ↔ PAY (تزويد) | `/api/v1/internal/muwathaq/*` (7)؛ `Services/Muwathaq/*` | CODE_PRESENT | E2E موثّق في CI ✓ | — | — |
| PAY-SYS-19 | بوابة شركات الصرافة | AGT (هدف) — اليوم PAY | `/agent/*` (62 مسار ويب) + `/api/v1/amial/agent/*` (10)؛ `Agent*Service` (≈20) | `Legacy still active` | «رحلة الوكيل الذهبية» ✓ | عقد الحدود مسودة فقط | P1 |

---

## 7. Screen and Navigation Inventory

الجرد الكامل صفًّا بصف: [`docs/plan/flutter-screens.md`](docs/plan/flutter-screens.md) (223 شاشة Flutter) و[`docs/plan/web-screens.md`](docs/plan/web-screens.md) (169 قالب Blade) و[`docs/plan/web-routes.md`](docs/plan/web-routes.md) (717 مسار ويب).

**مقياس Flutter:** `223 أصناف شاشة محددة ساكنًا؛ 145 في ملفات موصولة من main.dart؛ 78 UNREACHABLE (25 منها محروسة باختبارات ساكنة)؛ 0 مختبرة تفاعليًّا في هذه الجلسة (INTERACTION_UNVERIFIED)`.

| الوحدة | شاشات | غير موصولة | الدور | ملاحظة |
|---|---:|---:|---|---|
| auth, splash, onboarding, language, verification, forget_pin, camera_verification | 15 | 0 | عميل | مسار الدخول والتفعيل |
| home, me, history, notification, setting, favorite_number | 20 | 0 | عميل | الرئيسية والحساب |
| transaction_money, requested_money, payments, withdraw, receipts, reports | 19 | 0 | عميل | المال |
| bill_pay, safe_payment, family_fund, donations, kyc_verification, credit | 20 | 0 | عميل | خدمات |
| gift_cards, installments | 2 | 2 | عميل | **UNREACHABLE** |
| access, plans, entitlements, shell | 9 | 4 | توجيه/باقات | `HomeDispatcherScreen` موصول |
| merchant | 55 | 34 | POS (موصول) / مالك (غير موصول) | 21 شاشة POS/كاشير موصولة |
| wholesale | 33 | 1 | POS جملة | موصول عبر `PosEmployeeHomeScreen` |
| fuel_station | 17 | 14 | وقود | الموصول: بيع الوقود |
| retail | 10 | 7 | تجزئة | — |
| pharmacy | 6 | 5 | صيدلية | الموصول: `PharmacySaleScreen` |
| restaurant | 3 | 1 | مطعم | — |
| suppliers, corporate, branches, merchant_verification | 9 | 9 | مالك | كلها **UNREACHABLE** |
| barcode, printer, shared | 3 | 0 | مشترك | — |
| amial | 2 | 1 | عميل | شاشة واحدة غير موصولة |

**قاعدة التوجيه بعد الدخول** (`lib/features/access/screens/home_dispatcher_screen.dart:75-104`): `isPosStaff` ⇒ `PosEmployeeHomeScreen`؛ `isMerchant` ⇒ `WebPortalNoticeScreen(role:'merchant')`؛ `isAgent`/`isAdmin` ⇒ `WebPortalNoticeScreen`؛ غير ذلك ⇒ واجهة العميل. `route_helper.dart` يسجّل 71 `GetPage`؛ أسماء مسارات قدرات المالك باقية وتفتح إحالة الويب.

**الويب:** `169 قوالب؛ 133 يذكرها Controller/مسار؛ 33 جزئية/تضمين؛ 3 UNKNOWN`. الأسطح: إدارة (493 مسارًا)، وكيل (62)، بوابة تاجر (125)، عام/صحة (27).

بطاقة شاشة نموذجية (مثبتة المصدر):

```text
SCR-ID: PAY-SCR-B905B7
Name: إرسال المال (AmialSendMoneyScreen)
Persona: customer            Surface: Flutter
Entry: login → HomeDispatcher → NavBar → إرسال
Source: 02_flutter_app/lib/features/transaction_money/screens/amial_send_money_screen.dart
Owner: Pay                   Status: CODE_PRESENT · INTERACTION_UNVERIFIED
Buttons: «تأكيد التحويل» (FilledButton, onPressed) — docs/plan/ui/transaction_money.md
API: POST /api/v1/amial/transfer/verify-recipient · POST /api/v1/amial/transfer/initiate (EnforceIdempotency, RequireTermsAcceptance)
     GET /api/v1/amial/transfer/{ulid}/status · POST /api/v1/amial/transfer/{ulid}/cancel
Permissions: Authenticate:api + EnsurePosDevice + خادم يفحص PIN/الحدود/المنطقة
States: حالات loading/offline/forbidden — غير مفحوصة تفاعليًّا
Risks: ضغط مزدوج (يحميه Idempotency-Key — يُختبر)، رصيد قديم، تقريب
Acceptance: إرسال مكرر بنفس المفتاح ⇒ أثر مالي واحد؛ إلغاء داخل النافذة ⇒ استرداد كامل
```

---

## 8. UI/UX Component Register

السجل الكامل: `docs/plan/ui/<module>.md` — **2000 عنصر تفاعلي** مستخرج آليًّا (أزرار، حقول، نوافذ/أوراق سفلية، تبويبات، عناصر قابلة للنقر) لكل ملف في `lib/features` و`common/widgets`، مع مفتاح الترجمة ونصه العربي من `assets/language/ar.json`، نوع المعالج، ورقم السطر. الفهرس وأعداد كل وحدة في [`docs/plan/README.md`](docs/plan/README.md).

**حدود هذا السجل (صريحة):** الأعمدة «الشروط/التحقق/رد النجاح/رد الخطأ/loading-disabled-retry» من القالب المعياري **غير مملوءة** لأنها تتطلب تشغيل التطبيق؛ كل صف `INTERACTION_UNVERIFIED`. طريقة الاستكمال: `PAY-T-012` (جولة تفاعلية على محاكي لكل وحدة عميل موصولة، بترتيب: transaction_money → withdraw → bill_pay → auth).
عناصر الويب (Blade): العدّ لكل قالب (نماذج/حقول/أزرار/نوافذ/جداول/روابط) في `docs/plan/web-screens.md`؛ فحص الضغط الفعلي له أدوات قائمة: `01_backend/scripts/click-check.mjs` و`scripts/press-every-button.py` (لم تُشغَّلا في هذه الجلسة).

---

## 9. User Flow and State Machines

| التدفق | الحالات (من الشيفرة) | المصدر | الحالة |
|---|---|---|---|
| تحويل بنافذة إلغاء | `holding` → (cancel ⇒ استرداد كامل) \| (بعد 60ث job ⇒ `released`) | `app/Services/PendingTransferService.php` | CODE_PRESENT |
| نيّة دفع أميال أعمال | `pending` → `succeeded` \| `expired` \| `cancelled` | `app/Models/BusinessPaymentIntent.php` | CODE_PRESENT |
| وردية صرّاف | `open` → `closed` (+ مراجعة) | `app/Models/Agent/AgentShift.php` | CODE_PRESENT |
| تسوية يومية للوكيل | `draft` → `submitted` → `accepted` \| `rejected` | `app/Models/Agent/AgentDailySettlement.php` | CODE_PRESENT |
| فتح حساب | Pay يستقبل ← موثّق يراجع (`submitted/under_review/needs_more_info/approved/rejected`) ← `provisioning` ← رمز تفعيل ← `activate` | `Services/Muwathaq/*`؛ موثّق `src/portal.php` | CODE_PRESENT · E2E في CI ✓ |
| طلب سحب برمز | request → (انتهاء كل دقيقة `amial-expire-withdrawals`) \| execute عند الوكيل \| cancel | `CustomerWithdrawService`؛ `routes/console.php:100` | CODE_PRESENT؛ **قائمة الحالات الحرفية لم تُستخرج ⇒ PAY-T-014** |
| دفع فاتورة | خصم+رسم → success \| pending (مطابقة كل دقيقة، نافذة 30ث–24س) \| failure⇒refund | `BillPayService`؛ `docs/AMIAL_BILL_PROVIDER_READINESS.md` | PARTIAL (timeout يُرد فورًا — خطر موثّق) |
| دفع آمن / نزاع | — | `SafePaymentService` | **UNKNOWN (لم تُستخرج) ⇒ PAY-T-014** |
| استرداد أميال أعمال | طلب → موافقة مالك بـPIN أو تفويض بحدود → تنفيذ \| انتهاء 72س | `BusinessRefundService` (موثّق في `01_backend/CLAUDE.md`) | CODE_PRESENT |

---

## 10. Rules and Policies

مصدر القواعد الملزمة: `01_backend/CLAUDE.md` (12 قاعدة عمل + أقسام الحوادث) و`docs/AMIAL_ARCHITECTURE_RULES.md` (14 قاعدة تغيير). أهمها للمال:

- الرقم يُحسب من مصدره لا من عمود مخزَّن (ق6)؛ «غير معروف» ليس صفرًا (ق7)؛ الهوية تحدد النطاق لا القائمة المنسدلة (ق8).
- التمويل طبقتان باتجاهين: منصة↔وكيل أم، وكيل أم↔فرع؛ لا تمويل مباشر منصة→فرع (ق10؛ العقد في `tests/Feature/AgentFundingHierarchyTest.php`).
- أميال باي محرك الرسوم الوحيد؛ القناة تحمل `fee` و`net_amount` ولا يُنسخ جدول رسوم (AMIAL-BUSINESS-FEES-001).
- عملة الباقات تُقال ولا تُحوَّل (`AccessConstants::PLAN_PRICE_CURRENCY`).
- حدود الفئة تُفرض على الشباك أيضًا (PR #42).
- **إصدار السياسات وتاريخ سريانها (نسخة/اعتماد مستقل):** `MISSING` كنظام عام — الرسوم تُعدَّل من اللوحة مباشرة؛ الهدف في `Amial-Admin/plan.md` (ADM-SYS-02).

---

## 11. Database and Data Ownership

الجرد: [`docs/plan/database-tables.md`](docs/plan/database-tables.md) — 314 اسم جدول من 331 هجرة، مع تصنيف مجال **آلي بالاسم** (يحتاج مراجعة):

| المجال (آلي) | جداول | الملكية المستهدفة |
|---|---:|---|
| تجاري (merchant/pos/product/wholesale/pharmacy/fuel/…) | 124 | BUS — **لا يُنقل دفتر Pay** |
| عميل/عام | 68 | PAY |
| مال ودفتر | 44 | PAY (مصدر الحقيقة) |
| إدارة/هوية/جلسات | 28 | ADM/IAM (هدف) |
| هوية و KYC وموثّق | 14 | MWT للقرار؛ Pay يحتفظ بالمرآة التشغيلية |
| وكلاء | 13 | AGT (تشغيل) — القيود في Pay |
| امتثال | 12 | PAY |
| قناة الأعمال (`business_*`) | 7 | PAY |
| دعم | 4 | SUP (هدف) |

قيود لا تُمس: لا تعديل هجرات تاريخية؛ لا كتابة أرصدة الدين بـ`DB::table()->update()` (يتجاوز حارس التجميد على النماذج)؛ لا حذف قيد منشور. مخاطر المزامنة: مرآة ديون أميال أعمال (نسخة أعلى فقط)، outbox الأحداث (`business_outbox_events`)، `women_tier3_sync_events`.
ERD مفصّل: **غير مُنتَج في هذه الجولة** (`PAY-T-017`).

---

## 12. API and Event Catalog

الكتالوج الكامل (654 مسار API بمعرّف ثابت، Method، URI، Controller@action، Middleware، عدد مواضع نداء Flutter، قرينة الاختبار): [`docs/plan/api-catalog.md`](docs/plan/api-catalog.md). مواضع نداء التطبيق (523): [`docs/plan/flutter-api-calls.md`](docs/plan/flutter-api-calls.md).

| السطح | مسارات | المصادقة | ملاحظات |
|---|---:|---|---|
| عميل/عام `/api/v1/amial/*`, `/api/v1/customer/*` | 185 | `Authenticate:api` + `EnsurePosDevice`؛ الكتابة المالية `EnforceIdempotency` + `RequireTermsAcceptance` | — |
| مصادقة `/api/v1/auth/*`, `/oauth/*` | 34 | `PerUserRateLimit:auth_login,10,1` وحدود خاصة لكل مسار | PR #39 |
| تاجر/POS | 320 | `Authenticate:api` + قدرات/باقة | Legacy still active |
| إدارة API | 83 | حارس الإدارة | — |
| وكيل API | 10 | `EnsureAgent` + `EnforceZonePolicy:cash_out` + Idempotency | الجيل القديم `/api/v1/agent/*` حُذف في PR #46 |
| قناة الأعمال | 15 | HMAC `VerifyBusinessGatewaySignature` + `throttle:120,1` | العقد: `docs/AMIAL_BUSINESS_GATEWAY_CONTRACT.md` |
| داخلي موثّق | 7 | HMAC `VerifyMuwathaqSignature` + throttle | — |
| Webhooks واردة | 4 | `bill-pay/providers/free-sadad/webhook` (throttle فقط — **تحقق التوقيع داخل المتحكم لم يُراجَع هنا ⇒ UNKNOWN**)، `kyc/biometric/webhook/{provider}`، `auth/email-otp/webhook`، `wa/webhook` | PAY-T-018 |

**مرشحو خلل مثبتون:** 6 نداءات Flutter لا مسار لها في `route:list`: `GET merchant/pharmacy/categories`، `GET merchant/pharmacy/products/similar`، `GET`/`POST merchant/retail/attributes`، `POST merchant/wholesale/invoices/amial-payment-request`، `GET merchant/wholesale/returns` (PAY-GAP-05). و120 نداءً ديناميكيًّا غير محسوم.
**الأحداث الصادرة:** `business_link.created`، أحداث دفع/استرداد/`entitlements.changed`/`merchant_cash_out.completed` عبر `BusinessOutbox` بإعادة محاولة 60/300/900/3600/21600/43200ث ثم dead؛ إشعار الفئة 3 إلى موثّق (5 محاولات).

---

## 13. Cross-Service Contract Matrix

| الربط | المنتج → المستهلك | المالك | المصادقة | التفرد/التكرار | Retry / Unknown | المطابقة | الحالة |
|---|---|---|---|---|---|---|---|
| فتح حساب | Pay → MWT `POST /api/v1/internal/applications` (+evidence) | MWT | HMAC | مرجع الطلب ULID | Pay يعيد؛ موثّق يكتشف المكرر (نقطة غير ذرّية معلومة — انظر `MWT-GAP-02`) | — | CODE_PRESENT · E2E ✓ |
| قرار/تزويد | MWT → Pay `internal/muwathaq/{provisioning,applications/{}/decision}` | Pay ينفذ، MWT يقرر | HMAC | `applicationUlid` | outbox في موثّق (`bin/dispatch-pending-decisions.php`)؛ رفض Pay يُشرح ولا يُعاد بلا نهاية (MWT PR #22) | `/ops` في موثّق | CODE_PRESENT · E2E ✓ |
| ترقية فئة 2 | Pay ↔ MWT | MWT يقرر | HMAC | `requestUlid` | — | — | CODE_PRESENT (PR #40 أصلح حجبًا دائمًا) |
| فئة 3 للمرأة | Pay → MWT `amial-tier-decisions` | **Pay يقرر محليًّا** | HMAC | حدث فريد + معالجة 1062 | 5 محاولات | — | CODE_PRESENT (PR #46/#48/#49) |
| نيّة دفع/استرداد/دين | BUS → Pay `/api/v1/business/*` | Pay | HMAC + نافذة ساعة 300ث | مفاتيح العقد | timeout 10ث؛ استعلام `GET payment-intents/{}` و`refunds/{}` | `amial:business tick` | CODE_PRESENT |
| أحداث الدفع | Pay → BUS webhook | Pay | HMAC بسر مختلف | event id | سلّم إعادة ثم dead + راصد `business_gateway` | — | CODE_PRESENT |
| صرف/إيداع الوكيل | AGT(هدف) → Pay | Pay | اليوم داخل نفس التطبيق | — | — | تسوية يومية | عقد خارجي `MISSING` (مسودة `AGT-02`) |
| دعم/إدارة → Pay | SUP/ADM(هدف) → Pay | Pay | — | — | — | — | `MISSING/PROPOSED` |
| IAM → Pay | — | IAM(هدف) | — | — | — | — | `BLOCKED` على D-004 |
| Transfer → Pay | — | Pay للمال | — | — | — | — | `BLOCKED_REGULATORY` (D-001/D-010) |
| مزود الفواتير | Pay ↔ FreeSadad | Pay | UNKNOWN | مرجع المزود | pending/مطابقة | كشف المزود `MISSING` | `EXTERNAL_VERIFICATION_PENDING` |

---

## 14. Access Control and Security

- **المصادقة:** أربعة حرّاس (§5). PIN للمعاملات (`verify-pin`)، PIN منصة للإدارة (`PlatformLoginPinService`)، 2FA إدارة (`admin/amial/2fa` 6 مسارات).
- **التفويض:** خادمي — قدرات وباقات (`CapabilityRegistry`, `EntitlementService`)، أدوار المنصة (`PlatformRoleService`, `PlatformTabAccessService`)، أربع عيون (`FourEyesService`, `ApprovalService`).
- **عزل:** `actor` من الخادم (مالك/POS/موظف أدوار/عميل)؛ فروع الوكيل من الجلسة لا من الطلب.
- **حماية الطلب:** `EnforceIdempotency`, `PerUserRateLimit`, `EnforceZonePolicy`؛ HMAC للقنوات؛ CORS محصور (PR #39).
- **التدقيق:** `AuditService` بسلسلة تجزئة (`ok/legacy/broken`)، `PiiAccessAuditService`.
- **أُغلق حديثًا:** تخمين رمز الاستعادة وحدود الدخول (PR #39)، رفض رموز الاستعادة النصية القديمة (التزام `5b98f925` ضمن PR #46).
- **غير مُثبَت هنا (`UNKNOWN`):** مراجعة IDOR شاملة لـ1371 مسارًا؛ تحقق توقيع كل webhook وارد؛ فحص تبعيات (Snyk يظهر كفحص حالة على PRs فقط). ⇒ `PAY-T-018`.

---

## 15. Privacy and Compliance

تصنيف البيانات: مالية (أرصدة/قيود)، هوية (وثائق KYC — المصدر المستهدف موثّق)، اتصال (هاتف/بريد)، سلوك (سجل تدقيق). ضوابط قائمة: تخزين مشفّر (`EncryptedFileStorage`, `EncryptionService`)، خيار الخصوصية للتحقق الحضوري (`routes/api/kyc-privacy.php` 16 مسارًا)، تدقيق الاطلاع على PII.
**غير محسوم:** مدد الاحتفاظ والإتلاف (D-012)، المتطلبات التنظيمية اليمنية للمحافظ و AML (**تحتاج مصدرًا قانونيًّا — لا تُفترض**)، موقع نسخ وثائق الهوية بعد تسليمها لموثّق (PAY-GAP-07).

---

## 16. Financial/Operational Integrity

| المتطلب | الدليل | الحالة |
|---|---|---|
| لا خصم مزدوج عند التكرار | `EnforceIdempotency` على كتابات العميل المالية؛ قيد فريد في القاعدة (موثّق في `01_backend/CLAUDE.md`: 393 مسارًا) | CODE_PRESENT |
| قفل وسباق | فحص داخل القفل، `TX_ATTEMPTS=3` للـdeadlock، التقاط 1062 لحسابات الدفتر | CODE_PRESENT؛ `scripts/load-test.php` **لم يُشغَّل في هذه الجلسة** |
| قيد مزدوج متوازن | `LedgerService`؛ `amial:reconcile-fees`؛ مصالحة ليلية 02:00 | CODE_PRESENT |
| تمثيل عشري دقيق | 477 استعمال bcmath/BigDecimal مقابل **139 `(float)`** في `app/Services` | **PARTIAL — يحتاج تدقيقًا (PAY-GAP-08)** |
| HTTP 200 ≠ نجاح مالي | نيّة الدفع تُغلق بحدث/استعلام؛ إشعارات `after_commit` | CODE_PRESENT |
| نتيجة مجهولة ⇒ استعلام لا إعادة | متحقق لقناة الأعمال؛ **غير متحقق لدفع الفواتير** | PARTIAL |
| تعافٍ | `scripts/backup.sh`, `run_dr.sh`؛ تمرين موثّق (استعادة 3ث على قاعدة تطوير) | CODE_PRESENT؛ إنتاج `UNKNOWN` |

---

## 17. Design System & Accessibility

توكِنز موحّدة: `01_backend/public/assets/css/amial-tokens.css` (`--amial-*`) و`02_flutter_app/lib/theme/amial_colors.dart` بقيم متطابقة يحرسها `VisualIdentityGuardTest`. قاعدة «الرصيدان جنبًا إلى جنب» (نقد/إلكتروني) بلون وشكل. استثناء مكتوب: قوالب PDF/الإيصالات بألوان خام (dompdf).
RTL/عربي أساسي؛ `ar.json`/`en.json` يحرسهما `source-integrity.py`. **غير مفحوص:** تباين الألوان وأحجام اللمس وقارئ الشاشة (`INTERACTION_UNVERIFIED`) ⇒ ضمن `PAY-T-012`.

---

## 18. Testing Strategy and Coverage Matrix

الجرد: [`docs/plan/tests.md`](docs/plan/tests.md).

| الطبقة | الموجود | ما شُغِّل فعلًا وأين | النتيجة |
|---|---|---|---|
| Structural | `source-integrity.py`، php -l، كشف تعارضات | CI 4770 @`751ea393` | ✓ |
| Backend PHPUnit | 618 ملفًّا (≈4379 حالة ساكنًا) | CI 4770: خطوات مركّزة (رسوم/تدقيق، تسجيل تاجر→موثّق، E2E موثّق، رحلة العميل، رحلة التاجر و POS، وصلات الإدارة، رحلة التاجر الذهبية، رحلة الوكيل، البريد) ثم المجموعة الكاملة (≈18 دقيقة) | ✓ |
| Flutter | 73 ملفًّا (≈364) | CI 4770: `flutter analyze` + `flutter test` (Flutter 3.44.0) | ✓ |
| Docker | `Dockerfile`, `Dockerfile.prod` | CI 4770 | ✓ بناء فقط |
| APK | job `apk` | CI 4770 | **SKIPPED** |
| Deploy | نداء خطاف Coolify | CI 4770 | قُبل الطلب — **ليس إثبات نشر** |
| محلي في هذه الجلسة | `python3 -m unittest` لسكربتات الجرد (23 ✓)، `source-integrity.py` (✓ 3182 ملفًّا)، 34 اختبار حارس PHPUnit (6 أصناف ✓) @`1a1d8c65` | — | ✓ |
| ضغط مالي متوازٍ | `scripts/load-test.php` | لم يُشغَّل | — |
| تفاعل واجهة | `click-check.mjs`, `press-every-button.py` | لم يُشغَّل | — |
| تعافٍ | `run_dr.sh` | لم يُشغَّل | — |
| عقد عابر للمستودعات (Pay↔BUS) | — | `MISSING` | — |
| أمن (IDOR/SSRF/تبعيات) | حرّاس متفرقة | لم يُشغَّل مسح | `UNKNOWN` |

**تقرير الفشل الحالي:** لا فشل على HEAD الافتراضي. آخر فشل معلوم: PR #47 (حذف شاشات غير موصولة) — 14 اختبار PHPUnit و فحص `source-integrity` سقطت لأنها تشترط وجود الملفات؛ تُرك PR مغلقًا غير مدمج وأُعيدت الملفات. الدرس مدوّن في PAY-GAP-06.

---

## 19. Infrastructure, Build, CI/CD and Releases

- **CI** (`.github/workflows/ci.yml`): `structural` → (`backend`, `flutter`, `docker`) → `deploy` (يشترط structural+backend+docker وفرع النشر) ؛ `apk` بعد flutter ويدوي الطابع. `kyc-biometric-guard.yml` مستقل.
- **البناء:** PHP 8.4 في CI (المتطلب ^8.2)، Flutter 3.44.0، Dart SDK ^3.8.1. `codemagic.yaml` بلا `triggering` عمدًا (قرار كلفة موثّق) — لا يُعاد.
- **النشر:** Coolify من الفرع الافتراضي؛ بحسب `01_backend/CLAUDE.md`: المصدر يدوي، بلا فحص صحة، بلا rolling update. مسبار `GET /railway-health` (`deployProbe`) و`/health/{liveness,readiness}` موجودة شيفرةً.
- **المجدوِل:** `routes/console.php` — `amial:business tick` كل دقيقة، `amial:reconcile-fees` كل دقيقة، `amial:health-check` كل 5 دقائق، مصالحة 02:00، جاهزية 05:00، تذكيرات 10:17 (عدن).
- **الأسرار (أسماء فقط):** `AMIAL_BUSINESS_INBOUND_SECRET`, `AMIAL_BUSINESS_WEBHOOK_SECRET`, `AMIAL_BUSINESS_WEBHOOK_URL`, `MUWATHAQ_*`, `AMIAL_DEMO_OTP`, `AMIAL_PILOT_CUSTOMER_PHONE_OTP_ENABLED`, `AMIAL_RECON_ALERT_TO`.
- **حالة الإنتاج:** الفرع/الالتزام المنشور، صحة التطبيق، النسخ الاحتياطي، workers ⇒ **`UNKNOWN`** (لا وصول). لا `PRODUCTION_VERIFIED` لأي بند في هذه الخطة.

---

## 20. Existing vs Missing vs Legacy

| العنصر | الملف/الدليل | الحالة | الفجوة | المالك | الإجراء |
|---|---|---|---|---|---|
| تطبيق العميل (145 شاشة موصولة) | `docs/plan/flutter-screens.md` | CODE_PRESENT | تفاعل غير مفحوص | PAY | PAY-T-012 |
| شاشات غير موصولة في Flutter (78، أغلبها سطح مالك المنشأة) | نفس الملحق، «غير موصول» | UNREACHABLE (+GUARDED لـ25) | تناقض: غير مبنيّة لكن محروسة | BUS (هدف) | PAY-T-002 (قرار) |
| سطح POS في Flutter | `PosEmployeeHomeScreen` وما يستورده | CODE_PRESENT · Legacy still active | تطبيق POS مستقل مقترح (ADR 0014) | BUS | يبقى حتى بديل مثبت |
| بوابة ويب التاجر `/merchant/*` | `routes/merchant-web.php` (125) | Legacy still active | تكرار مع Amial-Merchant | BUS | خطة توافق §21 |
| `routes/merchant.php` (10 تعريفات) | غير محمّل من الإقلاع | LEGACY غير موصول | — | — | لا يُعاد تشغيله؛ يُوثَّق قبل أي حذف |
| بوابة الوكيل الجيل 2 | `routes/agent.php` | Legacy still active | عقد حدود | AGT | `Amial-Agents/plan.md` |
| حساب الوكيل الفردي الجيل 1 | حُذف API في PR #46 | أُزيل | جداول/نماذج باقية؟ **UNKNOWN** | PAY | PAY-T-019 |
| لوحة الإدارة | 493 مسار ويب | Legacy still active | فصل غير معتمد | ADM/SUP | D-009 |
| مزود فواتير حقيقي | `FreeSadadProvider.php` | CODE_PRESENT | عقد/اعتمادات/مطابقة | PAY | PAY-T-005 |
| OTP هاتف حقيقي | `Services/Otp/*` | BLOCKED | مزود SMS/WhatsApp | PAY | PAY-ADR-03 |
| نظام إصدار السياسات | — | MISSING | — | ADM | `ADM-SYS-02` |
| عقد عابر Pay↔BUS مختبر من الطرفين | — | MISSING | — | PAY+BUS | PAY-T-016 |

### GAP REGISTER

| GAP ID | الفجوة | النوع | الدليل | الأولوية | Dependency / Blocker | المهمة |
|---|---|---|---|---|---|---|
| PAY-GAP-01 | حالة الإنتاج (نسخة، صحة، إنذار، نسخ احتياطي) غير مثبتة بدليل حديث | تشغيل | `01_backend/CLAUDE.md` «طبقة التشغيل»؛ لا وصول في هذه الجلسة | P0 | Blocker: وصول قراءة | PAY-T-001, PAY-T-004 |
| PAY-GAP-02 | دورة الفاتورة: نتيجة مجهولة تُرد فورًا، إنهاء بلا قفل، لا إيصال عكس مرتبط، لا مطابقة كشف مزود | مال — جزئي | `docs/AMIAL_BILL_PROVIDER_READINESS.md` بنود 2–5؛ `FreeSadadProvider.php` بلا إثبات خارجي | P0 (قبل مزود حقيقي) | Dependency: PAY-ADR-04 للمحوّل فقط | PAY-T-005 |
| PAY-GAP-03 | تجارة داخل Pay: 445 مسارًا و≈124 جدولًا بالتوازي مع Amial-Merchant | حدود/تكرار | `docs/plan/api-catalog.md`, `database-tables.md` | P1 | Dependency: مصفوفة تكافؤ | PAY-T-011 |
| PAY-GAP-04 | OTP الهاتف التجريبي الثابت قائم بقرار «طوال التجربة» | هوية | `config/amial.php:505-532`؛ `01_backend/CLAUDE.md` «ما يمنع الإطلاق» | P0 (للإطلاق الحقيقي) | Blocker: مزود (PAY-ADR-03) | PAY-T-003, PAY-T-006 |
| PAY-GAP-05 | 6 نداءات Flutter بلا مسار خادم | غير موصول | `docs/plan/flutter-api-calls.md` (الصفوف الأولى) | P1 | — | PAY-T-010 |
| PAY-GAP-06 | 138 ملف Dart غير موصول، 27 منها محروسة بـ36 اختبارًا ساكنًا | Legacy unreachable + حارس متناقض | `docs/plan/flutter-screens.md`؛ PR #47 | P1 | Blocker: PAY-ADR-01 | PAY-T-002 |
| PAY-GAP-07 | ملكية أدلة KYC بين Pay وموثّق غير محسومة التنفيذ | خصوصية/حدود | PR #34 (مغلق غير مدمج)، Muwathaq PR #29 (مفتوح) | P0 بعنوان الطلبين؛ يُعاد تقييمه بعد المقارنة | Dependency: PAY-T-020 | PAY-ADR-07 |
| PAY-GAP-08 | 139 `(float)` في `app/Services` | مال — غير مدقَّق | `grep -rn "(float)" app/Services` | P1 | — | PAY-T-008 |
| PAY-GAP-09 | APK لم يُبنَ على HEAD؛ AGP/KGP على حد الخطأ | إصدار | CI 4770 (job `apk` skipped)؛ `01_backend/CLAUDE.md` «حدّ Gradle» | P2 | — | PAY-T-009 |

**PRs مغلقة غير مدمجة (Issue #23 هو الأرشيف الرسمي):** #6, #7, #8, #9, #12, #13, #15, #16 (مؤرشفة في #23)، #34 (تسليم أدلة KYC لموثّق — P0 بعنوانه)، #47 (حذف الشاشات — تراجع مقصود). **لم تُقارَن ملفًّا بملف في هذه الجولة** ⇒ `PAY-T-020`. لا يُعدّ أيٌّ منها منجزًا أو مفقودًا قبل المقارنة.

---

## 21. Migration and Compatibility Plan

مبدأ: لا إيقاف API قبل إثبات مستهلكيه وبديله؛ لا نقل لدفتر Pay؛ كل خطوة قابلة للرجوع.

| المسار | الوضع | الخطوة التالية (غير منفّذة) | حد الإيقاف |
|---|---|---|---|
| تاجر → أميال أعمال | قناة موقّعة + استيراد (`BusinessMigrationExport`) + تسليم آجل وتجميد على النموذج قائمة | مصفوفة تكافؤ لكل مسار من الـ445 (API+ويب) مقابل `Amial-Merchant/docs/plan/api-catalog.md`؛ استعمل `Amial-Merchant/docs/parity/FLUTTER_PARITY_MAP.md` كنقطة بدء | لا يُعطَّل مسار ما دام يخدم POS الحي أو بوابة الويب |
| شاشات المالك في Flutter | غير موصولة ومحروسة | قرار PAY-ADR-01 ثم (إن حُذفت) إحالة الحرّاس الـ36 في نفس التغيير | لا حذف بلا إحالة الحرّاس — CI يسقط (PR #47) |
| وكلاء → Agents | مسودة عقد `AGT-02` على فرع غير مدمج | حسم «توسيع أم فصل» | لا DNS/قاعدة قبل القرار |
| إدارة/دعم → Admin/Support | لا شيء | جرد الوظائف (منجز في خطتيهما) ثم مصفوفة مسؤوليات D-009 | — |
| هوية → IAM | لا شيء | D-004 | لا تغيير دخول العميل قبل خطة رجوع |

---

## 22. Risk Register

| ID | الخطر | الفئة | الأثر | الاحتمال | التخفيف القائم | المطلوب |
|---|---|---|---|---|---|---|
| PAY-R-01 | نشر بلا فحص صحة ⇒ نسخة معطوبة «ناجحة» | تشغيل | عالٍ | متوسط (حسب آخر توثيق) | `deployProbe` موجود | وصله في Coolify + دليل (PAY-T-001) |
| PAY-R-02 | timeout مزود الفواتير يُرد فورًا ثم ينجح المزود ⇒ خسارة | مال | عالٍ | يتحقق عند تفعيل مزود حقيقي | الحارس يمنع المحاكي في الإنتاج | PAY-T-005 |
| PAY-R-03 | OTP ثابت يبقى بعد التجربة | هوية | حرج | متوسط | قرار مكتوب بإفراغه قبل الإطلاق | بوابة إطلاق تفحصه (PAY-T-006) |
| PAY-R-04 | حذف «كود ميت» محروس ⇒ كسر CI/النشر | تشغيل | متوسط | وقع (PR #47) | الحرّاس نفسها | PAY-T-002 |
| PAY-R-05 | `(float)` في حساب مالي ⇒ فروق تقريب | مال | متوسط | UNKNOWN | bcmath غالب | PAY-T-008 |
| PAY-R-06 | ازدواج كتابة تجارية بين Pay و Merchant | بيانات | عالٍ | منخفض (تجميد الآجل قائم) | حارس النموذج | مصفوفة التكافؤ |
| PAY-R-07 | نسخة احتياطية غير مجرّبة في الإنتاج | فقد | حرج | UNKNOWN | سكربتات + تمرين تطوير | OPS-001 |
| PAY-R-08 | حدّا Gradle على حافة الخطأ ⇒ سقوط بناء APK | إصدار | متوسط | مرتفع مع تحديث stable | `GradleFloorGuardTest` + إقرار | PAY-T-009 |
| PAY-R-09 | اعتماد على مزود واحد (فواتير/رسائل) | شريك | متوسط | — | واجهات موصل | عقود |

---

## 23. Product Decisions / ADR Queue

| ID | القرار | البدائل | الأثر | المسؤول | ما يمكن قبل الحسم | مرجع |
|---|---|---|---|---|---|---|
| PAY-ADR-01 | مصير 78 شاشة غير موصولة (138 ملفًّا) | (أ) إبقاء كما هي محروسة (ب) حذف مع إحالة الحرّاس (ج) نقل مرجعي إلى Merchant ثم حذف | حجم الشيفرة، CI، خطر إحياء عرضي | مالك المشروع | لا شيء يُحذف؛ الجرد منجز | PR #47 |
| PAY-ADR-02 | سطح POS: داخل تطبيق العميل أم APK مستقل | — | تجربة الكاشير، التوقيع، المتاجر | مالك + BUS | — | Merchant ADR 0014 |
| PAY-ADR-03 | مزود OTP الهاتف (SMS/WhatsApp) | — | يفتح الإطلاق | مالك | بناء المحوّل خلف واجهة | `01_backend/CLAUDE.md` |
| PAY-ADR-04 | مزود الفواتير وعقده | FreeSadad / آخر | PAY-SYS-06 | مالك + شراكات | إكمال معالجة UNKNOWN داخليًّا | readiness doc |
| PAY-ADR-05 | فصل الإدارة/الدعم | D-005, D-009 | — | — | الجرد | Merchant DECISIONS |
| PAY-ADR-06 | IAM | D-004 | — | — | جرد المداخل (في `Amial-IAM/plan.md`) | — |
| PAY-ADR-07 | ملكية أدلة KYC ونسخها في Pay | دمج مسار #34/#29 أو بديل | خصوصية | مالك + MWT | — | PR #34, MWT PR #29 |

---

## 24. Roadmap P0–P3

| المرحلة | الحزم | يعتمد على | قابل للبدء الآن؟ |
|---|---|---|---|
| P0 — إثبات الواقع وحماية المال | PAY-T-001, 003, 004, 005, 006, 007 | وصول قراءة للإنتاج (001)؛ قرار مزود (005/006) | 003, 004, 007 نعم؛ 001 عند توفر الوصول |
| P1 — الحدود والمسارات الأساسية | PAY-T-002, 008, 010, 011, 015, 019, 020 | PAY-ADR-01 (002) | 008, 010, 015, 020 نعم |
| P2 — جودة وتكاملات | PAY-T-009, 012, 014, 016, 017, 018 | — | نعم |
| P3 — تحسينات | PAY-T-013, 021 | — | نعم |

---

## 25. Task Backlog

| Task ID | العنوان | الهدف | In / Out of scope | Files | Dependency | Risks | Acceptance criteria | Test command / ID | Evidence of done | Priority | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|
| PAY-T-001 | جرد الإنتاج قراءة فقط | تثبيت الالتزام المنشور، الصحة، workers، النسخ | In: قراءة Coolify/صحة. Out: أي تغيير | `Amial-Merchant/docs/ecosystem/inventory/INVENTORY.json` (سجل INF-001) | وصول مصرّح | كشف أسرار | كل حقول INFRASTRUCTURE مملوءة أو UNKNOWN مسبَّب | `curl /health/readiness` (غير مالي) | سجل + وقت + SHA | P0 | BLOCKED (وصول) |
| PAY-T-002 | قرار وتنفيذ مصير الشاشات غير الموصولة | إنهاء التناقض «غير مبنيّة/محروسة» | In: 138 ملفًّا + 36 حارسًا. Out: POS الحي | `docs/plan/flutter-screens.md` (القائمتان) | PAY-ADR-01 | كسر CI | `flutter analyze`=0؛ PHPUnit كامل ✓؛ لا حارس يشترط ملفًّا محذوفًا | `php artisan test` + `flutter test` | CI أخضر على SHA | P1 | READY بعد القرار |
| PAY-T-003 | بوابة إطلاق تفحص OTP التجريبي | منع بقاء الرمز الثابت في إطلاق حقيقي | In: فحص إعداد وقت الإقلاع/الجاهزية. Out: تغيير سلوك التجربة | `config/amial.php`, أمر `amial:readiness` | — | قفل التسجيل خطأً | الجاهزية تفشل صراحةً إن كان الوضع التجريبي مفعّلًا مع علم «إطلاق» | اختبار حارس جديد | اسم الاختبار ونتيجته | P0 | TODO |
| PAY-T-004 | توثيق وصل فحص الصحة ومهلة البدء | نشر لا يُعلَن ناجحًا بلا جاهزية | In: مرشد `wizard-production-blockers.sh`. Out: تغيير Coolify آليًّا | `scripts/wizard-production-blockers.sh` | مالك (خطوة بشرية) | — | لقطة/سجل يثبت health check مضبوطًا | — | دليل بشري | P0 | BLOCKED (بشري) |
| PAY-T-005 | إكمال دورة الفاتورة: UNKNOWN/قفل/عكس/إيصال | لا رد قبل تأكد الرفض؛ لا إنهاء مزدوج | In: `BillPayService`, نموذج الطلب، job المطابقة. Out: توقيع عقد | `app/Services/BillPayService.php`, `tests/Feature/BillPayServiceTest.php` | — (المحوّل الحقيقي يتبع ADR-04) | مال | timeout ⇒ pending لا refund؛ إنهاء/رد تحت قفل وبتفرد؛ إيصال عكس مرتبط | `php artisan test --filter=BillPay` | حالات جديدة ✓ | P0 | TODO |
| PAY-T-006 | محوّل OTP هاتف حقيقي خلف الواجهة | فتح الإطلاق | In: provider + اختبار وصول. Out: اختيار المزود | `app/Services/Messaging/Providers/*` | PAY-ADR-03 | — | رمز يصل هاتفًا حقيقيًّا؛ ثم تعطيل الوضع التجريبي | `php artisan amial:alert-test` نظير | دليل وصول | P0 | BLOCKED |
| PAY-T-007 | تحقق توقيع كل webhook وارد | لا حدث مزوّر يغيّر حالة مالية | In: 4 webhooks. Out: — | المتحكمات الأربعة | — | — | اختبار رفض توقيع خاطئ/مكرر/متأخر لكل واحد | PHPUnit جديد | — | P0 | TODO (تحقق أولًا) |
| PAY-T-008 | تدقيق `(float)` في الخدمات المالية | نفي أو إصلاح فروق التقريب | In: 139 موضعًا. Out: تقارير عرض بحتة | `app/Services/**` | — | تغيير حساب | جدول موضع/تصنيف (مال/عرض)؛ صفر float في مسار قيد | grep + اختبارات تقريب | — | P1 | TODO |
| PAY-T-009 | رفع AGP/KGP فوق حد التحذير | هامش بناء APK | In: gradle. Out: مُطلِق تلقائي | `02_flutter_app/android/*` | مصرّف أندرويد | سقوط بناء | `GradleFloorGuardTest` ✓ بلا إقرار؛ APK يُبنى | job `apk` يدوي | artifact + SHA | P2 | TODO |
| PAY-T-010 | إصلاح/حسم 6 نداءات بلا مسار | لا زر يضرب 404 | In: 3 repos. Out: — | `pharmacy_repo.dart`, `retail_vertical_repo.dart`, `wholesale_repo.dart` | هل الملفات موصولة؟ (انظر الملحق) | — | كل نداء له مسار أو يُزال مع زره | `flutter test` + عقد | — | P1 | TODO |
| PAY-T-011 | مصفوفة تكافؤ Pay↔Merchant للمسارات التجارية | أساس أي إيقاف | In: 445 مسارًا. Out: حذف | الملحقان | — | — | كل مسار: بديل/لا بديل/يبقى | — | ملف مصفوفة | P1 | TODO |
| PAY-T-012 | جولة تفاعلية لواجهات العميل | ملء أعمدة السجل الناقصة | In: الوحدات الموصولة. Out: شاشات غير موصولة | `docs/plan/ui/*.md` | محاكي/جهاز | — | لكل زر: loading/disabled/خطأ/نجاح مسجّلة | يدوي + integration_test | تحديث الملاحق | P2 | TODO |
| PAY-T-013 | وصل أو إزالة شاشتي الهدايا والأقساط | — | — | `features/gift_cards`, `installments` | قرار منتج | — | — | — | — | P3 | TODO |
| PAY-T-014 | استخراج آلات الحالات (سحب، دفع آمن، فاتورة، طلب مال) | توثيق انتقالات دقيقة | In: قراءة. Out: تغيير | نماذج وخدمات §9 | — | — | جدول انتقالات + حارس لكل انتقال | — | تحديث §9 | P2 | TODO |
| PAY-T-015 | إثبات توصيل AML بكل مسار مالي | نفي «موجود نصًّا فقط» | In: تتبع النداءات. Out: — | `Aml*Service`, المسارات المالية | — | — | جدول مسار→نداء فحص→اختبار | — | — | P1 | TODO |
| PAY-T-016 | اختبار عقد يشغّل Pay و Merchant معًا | كشف انجراف العقد | In: CI مشترك أو stub موقّع. Out: — | — | BUS | — | نيّة→دفع→حدث→إقفال فاتورة، مع تكرار وتأخير | job جديد | — | P2 | TODO |
| PAY-T-017 | ERD مبسّط لمجال المال | — | — | هجرات | — | — | — | — | — | P2 | TODO |
| PAY-T-018 | مراجعة أمنية مركّزة (IDOR للمسارات ذات `{id}`) | — | In: مسارات العميل أولًا | `docs/plan/api-catalog.md` | — | — | اختبار «معرّف غيري ⇒ 403/404» لكل مورد | — | — | P2 | TODO |
| PAY-T-019 | حسم بقايا الجيل 1 للوكيل (جداول/نماذج) | — | In: قراءة ثم قرار | `AgentProfile`, `AgentFloatLog`, `AgentSettlement` | — | بيانات تاريخية | لا حذف جدول؛ توثيق | — | — | P1 | TODO |
| PAY-T-020 | مقارنة PRs المغلقة غير المدمجة بالشجرة الحالية | تمييز المدمج بطريق آخر من الناقص | In: #7,#8,#9,#12,#13,#15,#16,#34 | Issue #23 | — | — | لكل PR: مدمج/جزئي/ناقص + ملفات | `git diff` مقابل الفروع | تحديث Issue #23 | P1 | TODO |
| PAY-T-021 | تحديث خرائط `docs/AMIAL_*` المتقادمة أو إحالتها إلى `docs/plan/` | — | — | — | موافقة (تعديل ملفات قديمة) | — | — | — | — | P3 | TODO |

---

## 26. Definition of Ready / Done

**Ready:** هدف ومعيار قبول مكتوبان؛ الملفات المسموح مسّها محددة؛ المهارات المنطبقة مصرَّح بها (سطران حسب `01_backend/CLAUDE.md`)؛ القرار المعتمِد صادر إن وُجد؛ بيانات اختبار اصطناعية.
**Done:** `bash 01_backend/scripts/verify.sh` بصفر فشل؛ CI أخضر على SHA مسمّى؛ كل حارس جديد جُرِّب بالعكس؛ «يظهر في / يُوصل إليه من» مكتوبان؛ للمال: تكرار/سباق/انقطاع/مطابقة؛ النشر يُتحقق منه مستقلًّا عن CI؛ تحديث هذه الخطة وملحقها.

---

## 27. Progress and Evidence Dashboard

> لا نسبة إجمالية: لم تُعتمد قائمة متطلبات `IN_SCOPE` موزونة بعد ⇒ «غير قابل للقياس بدقة بعد». الأرقام أدناه مقامها مذكور.

| المحور | القياس | الدليل |
|---|---|---|
| Backend | 1371 مسارًا مسجّلًا؛ 788 منها يرد جزؤها الثابت في ملفات الاختبار (قرينة ساكنة)؛ المجموعة الكاملة ✓ على `751ea393` | api-catalog, CI 4770 |
| Flutter | 223 شاشة؛ 145 موصولة؛ 78 UNREACHABLE؛ 0 مختبرة تفاعليًّا؛ analyze+test ✓ | flutter-screens |
| عقود API داخل المستودع | 523 موضع نداء: 255 مطابق، 142 نمط، 120 ديناميكي غير محسوم، 6 بلا مسار | flutter-api-calls |
| DB | 331 هجرة / 314 جدولًا؛ مخطط الإنتاج UNKNOWN | database-tables |
| QA | 691 ملف اختبار؛ ضغط/تعافٍ/تفاعل لم تُشغَّل في هذه الجلسة | tests |
| DevOps | docker ✓؛ APK SKIPPED؛ إنتاج UNKNOWN | CI 4770 |
| Integrations | موثّق E2E ✓ في CI؛ أعمال: اختبارات جهة Pay فقط؛ فواتير/رسائل: EXTERNAL_VERIFICATION_PENDING | §13 |
| انجراف الخرائط القديمة | `AMIAL_SYSTEM_MAP.md` يذكر 187/221/219/268/211/1134؛ الحالي 239/311/246/331/223/1330 (ساكن) | §1 |

`UNKNOWN` المفتوحة: حالة الإنتاج كاملة؛ توصيل AML؛ توقيع webhooks؛ آلات حالات السحب/الدفع الآمن؛ بقايا الجيل 1؛ محتوى PRs المغلقة؛ المتطلبات التنظيمية.

---

## 28. Handover & Next Action

- **ما تغيّر بهذه الخطة:** أُضيف `plan.md` وملاحق `docs/plan/` فقط. لا شيفرة، لا هجرات، لا CI، لا نشر.
- **آخر التزام مرجعي:** `751ea393` (الافتراضي). الخطة مُعدّة محليًّا وغير مدفوعة بانتظار موافقة المالك.
- **اختبارات متحققة:** §18.
- **تصحيح لسجل سابق:** وُصفت شاشات المالك في نقاش PR #47 بأنها «حيّة»؛ الدقيق أنها **غير موصولة من `main.dart` لكنها محروسة باختبارات ساكنة** — أي أن الحذف ممنوع إجرائيًّا لا وظيفيًّا، والقرار للمالك (PAY-ADR-01).
- **قرار مطلوب أولًا:** PAY-ADR-01، PAY-ADR-03، وصول قراءة للإنتاج.
- **المهمة التالية الجاهزة:** `PAY-T-003` ثم `PAY-T-008` و`PAY-T-010` (لا تعتمد على قرار). خطوات PAY-T-010: افتح `docs/plan/flutter-api-calls.md` (أول 6 صفوف) → حدّد هل الملف موصول → ابحث عن المسار المقصود في `routes/api/amial.php` → أضف المسار أو أزل النداء وزره → `flutter test` و`php artisan test`.
- **الخطط المرتبطة:** `Amial-Merchant/plan.md` (BUS)، `Amial-Muwathaq/plan.md` (MWT)، `Amial-Agents/plan.md` (AGT)، `Amial-Admin/plan.md`، `Amial-Support/plan.md`، `Amial-IAM/plan.md`، `Amial-HR/plan.md`، `Amial-Transfer/plan.md`.
