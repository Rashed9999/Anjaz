# خريطة نظام أميال باي

## طبيعة الدليل

الخريطة تخص شجرة العمل المبنية على `b48cb9a2843467c3fbe311482cbbf8fe6b97e7e0` بعد إصلاح المصادر. هي جرد ساكن، لا شهادة تشغيل أو تدقيق مالي مكتمل.

الأدلة التفصيلية:

- `docs/AMIAL_STATIC_INVENTORY.json`: كل تعريف مسار مقاس، مصدره، حواجزه، controller/action، اعتماديات PHP وDart، نداءات API، الهجرات وأسماء الجداول.
- `docs/AMIAL_SCREEN_INVENTORY.md`: صف لكل شاشة مقاسة مع مداخلها المرشحة ونداءاتها المباشرة.
- `docs/AMIAL_AUDIT_REPORT.md`: قبل/بعد، أسباب الملفات المتغيرة، نتائج الفحوص والاستثناءات.

يعاد توليد الجرد من جذر المستودع:

```bash
python3 -B 01_backend/scripts/repo-inventory.py --write
```

## حجم المصدر المقاس

| العنصر | العدد | معنى العدد |
|---|---:|---|
| Controllers | 187 | ملفات ضمن `01_backend/app/Http/Controllers` |
| Services | 221 | ملفات ضمن `01_backend/app/Services`؛ تشمل العقود والمزوّدين |
| Models | 219 | ملفات ضمن `01_backend/app/Models` |
| Migrations | 268 | تاريخ الهجرات، لم يُحذف أو يُعدّل |
| أسماء جداول في الهجرات | 273 | أسماء مرجعية، لا إثبات وجودها في قاعدة الإنتاج |
| قوالب Blade | 139 | ملفات قوالب، لا صفحات ثبت عرضها |
| ملفات مكتبة Flutter | 535 | كل ملفات Dart داخل `lib` |
| أصناف شاشات مقاسة | 211 | أسماء تنتهي بـScreen وتشتق مباشرة من Widget معروف |
| تعريفات مسارات موسّعة حسب HTTP method | 1134 | تشمل 14 تعريفاً في ملفات غير محمّلة من المداخل المقاسة |
| تعريفات مسارات ضمن مداخل الإقلاع المقاسة | 1120 | ليست ناتج `artisan route:list` |
| مواضع استدعاء API | 487 | طلبات مكررة من أكثر من مستهلك تُعد أكثر من مرة |

من النداءات: 235 تطابقاً ساكناً، 145 تطابق نمط بمعرّفات ديناميكية، و107 غير محسومة ديناميكياً. لا مرشح مسار مفقود في النداءات التي حُسمت ساكناً، ولا استيراد Dart محلي مفقود. هذا لا يحسم النداءات الديناميكية أو أخطاء HTTP وقت التشغيل.

## مداخل التطبيق وملكية الطبقات

| الطبقة | الموقع القائم | الوظيفة والاعتماد |
|---|---|---|
| إقلاع Laravel | `01_backend/bootstrap/app.php` و`bootstrap/providers.php` | تركيب الوسائط والمزوّدين ومداخل routes |
| API أميال | `01_backend/routes/api/amial.php` | `/api/v1/amial`؛ controllers داخل `Api/V1/Amial` |
| المصادقة الموحدة | `01_backend/routes/api/unified-auth.php` | مدخل API تحت `/api/v1`؛ `UnifiedAuthService` |
| API المتوافق القديم | `01_backend/routes/api/v1/api.php` | يحمّله `RouteServiceProvider`؛ لا يُحذف لمجرد قِدم الاسم |
| لوحة الإدارة | `01_backend/routes/admin.php` و`routes/admin/amial.php` | web/session وadmin/permissions؛ قوالب Blade وخدمات الإدارة |
| بوابة الوكيل | `01_backend/routes/agent.php` | جلسة الوكيل، الصناديق والورديات والتسوية |
| الويب والصحة | `01_backend/routes/web.php` و`routes/api/health.php` | صفحات الويب ونقاط الصحة؛ نجاح الصحة لا يثبت رحلات التطبيق |
| سياسة الجملة | `01_backend/app/Providers/WholesaleAccessServiceProvider.php` | يضيف حارس الجملة لمجموعة api ويسجل `/api/v1/amial/merchant/wholesale/access` مباشرة |
| واجهة Flutter | `02_flutter_app/lib/features` | شاشات GetX ومتحكمات وrepositories القائمة؛ لا طبقة حالة موازية |
| النقل في Flutter | `02_flutter_app/lib/data/api/api_client.dart` | المصادقة ونقل HTTP؛ الشكل الدلالي للرد يجب فحصه عند المستهلك |
| اللغات | `02_flutter_app/assets/language/ar.json` و`en.json` | JSON نصّي صالح، مفتاح واحد لكل معنى ومتغيرات ترجمة متوافقة |

`routes/merchant.php` غير موصول في `RouteServiceProvider` الحالي عمداً؛ لا يعاد تشغيل بوابة تاجر قديمة تلقائياً. الملفات غير المحمّلة محفوظة في الجرد مع `registered_from_bootstrap=false`، ولا تصنّف DEAD دون تتبع إضافي.

## الوحدات الوظيفية ومصادر الحقيقة

المواقع التالية نقاط دخول للمراجعة وليست ادعاء اكتمال كل زر فيها. الربط على مستوى كل ملف موجود في حقول `php_dependencies` و`dart_imports` و`routes` في الجرد.

| الوحدة | مدخل Flutter / الويب | المصدر الخلفي القائم | الحد المهم |
|---|---|---|---|
| الدخول والتحقق والاستعادة | `features/auth`, `verification`, `forget_pin`, `amial` | `UnifiedAuthService`, `AccountRecoveryService`, سياسات OTP | فصل جلسة العميل عن المالك وموظف POS؛ شاشتا الاستعادة والشروط بلا مدخل مباشر مقاس |
| الهوية والبيانات | `kyc_verification`, `merchant_verification`, `setting` | `KycDocumentService`, `MerchantVerificationService`, `RegistrationDossierService` | لا دمج أعمى لالتزام KYC المحلي القديم؛ إصدارات الفرع الأحدث محفوظة |
| العميل ومحفظته | `home`, `me`, `transaction_money`, `payments`, `withdraw` | `MoneyService`, `LedgerService`, `CustomerWithdrawService`, `PaymentRequestService` | لا تعديل رصيد من واجهة، ولا اعتبار HTTP 200 نجاحاً مالياً وحده |
| الخدمات المشتركة | `safe_payment`, `family_fund`, `donations`, `bill_pay` | `SafePaymentService`, `FamilyFundService`, `CharityService`, `BillPayService` | الحواجز من الخادم؛ لا اختراع capability لسد فراغ في الواجهة |
| التاجر العام | `features/merchant`؛ shell وdashboard وaccount وservices hub | Merchant controllers والخدمات التجارية القائمة | المالك ≠ الموظف ≠ الجهاز؛ المركز الجديد نافذة إلى المصادر القائمة |
| الأدوار والموظفون والأجهزة | مركز التشغيل وشاشتا staff/pos-devices | `MerchantRole`, `PosUser`, `PosDevice`, `PosDeviceSession`, `CashierShift` | ملكية المنشأة، خطة الموظفين، مقاعد الأجهزة، وردية الجهاز |
| الباقات والحدود | `features/access`, `plans`, `entitlements` | `CapabilityRegistry`, `AccessPresets`, `EntitlementService`, `EnforceUsageLimit` | الخادم مصدر الصلاحيات؛ 402 ترقية و403 رفض صلاحية ليسا قائمة فارغة |
| التجزئة والمخزون | `features/retail`, cashier/inventory | `Services/Retail`, `CashierService`, `HeldSaleService` | المخزون والحجز والإرجاع والبيع لها مصادر مستقلة؛ لا خلط الرصيد بالمخزون |
| محطات الوقود | `features/fuel_station` | `Services/Fuel`, `FuelShiftService`, `FuelCompanyCardService` | المضخة والخزان والتوريد والوردية؛ dashboard قديم مرشح للمراجعة |
| الصيدلية | `features/pharmacy` | `PharmacyService`, `PharmacySaleService`, `PharmacyAlertService` | نشاط الصيدلية يحدد مجال الأفعال؛ لا نقل صلاحيات الوقود إليه |
| الجملة | `features/wholesale`؛ `WholesalePolicy*` | `WholesaleInvoiceService`, `WholesaleCollectionService`, `WholesaleReportsService` وسياسة الجملة | شاشة Pro الداخلية ليست مدخلاً بديلاً يتخطى policy |
| المطعم | `features/restaurant` | `RestaurantService` | فصل نشاط المطعم وواجهة التشغيل عن عمومية الباقة |
| الموردون والفروع والشركات | `suppliers`, `branches`, `corporate` | `BranchService`, `BranchResolverService` وcontrollers المختصة | فرع من منشأة أخرى مرفوض؛ إدارة الفروع تخضع للخطة |
| الآجل والأقساط والهدايا | `credit` وشاشات التاجر و`installments`, `gift_cards` | `CustomerCreditSettleService`, `InstallmentService`, `GiftCardService` | شاشتا العميل غير موصولتين في القياس؛ السداد يتطلب تحققاً مالياً قبل تفعيله |
| التقارير والطباعة | `reports`, `receipts`, `printer` | `MerchantFinancialTruthReportService`, `ReceiptService` وخدمات PDF القطاعية | التقرير يعرض المصدر ولا يعيد حساب حقيقة مالية محلية |
| الوكيل والمنصة | Blade/بوابة agent/admin | `AgentSettlementEngine`, `AgentShiftService`, `PlatformTreasuryService`, `FourEyesService` | فصل المنفذ والمراجع والصندوق؛ لم تُختبر دورة تسوية فعلية |
| الدعم والتدقيق والأمن | الدعم والسجل ولوحات الإدارة | `AuditService`, `Services/Support`, `Services/Security`, خدمات AML | الأحداث المسجّلة فقط هي الدليل؛ لا وعد بأن كل إجراء مغطى لمجرد وجود السجل |

## مصفوفة الربط التي أُصلحت: مركز التشغيل

كل المسارات التالية تحت `/api/v1/amial` وتتطلب المصادقة. الثلاثة الأولى أعيد ربطها في هذه الجولة؛ البقية كانت موجودة واستُخدمت دون إنشاء خدمات مكررة.

| الإجراء | الزر/المستهلك | API | مصدر البيانات / النتيجة | التحقق المتاح |
|---|---|---|---|---|
| فتح المركز | بطاقة المالك في services hub | GET `merchant/operations-center` | ملخص مصادر الأدوار/الموظفين/الأجهزة/الورديات | عقد ساكن + اختبار HTTP مضاف غير منفذ |
| عرض الأدوار | تبويب الأدوار | GET `merchant/operations-center/roles` | `MerchantRole` + كتالوج أفعال النشاط | فقد الرد أو كتالوج الأفعال يظهر خطأ |
| إنشاء دور | «دور جديد» | POST `merchant/operations-center/roles` | معاملة DB للأدوار/الصلاحيات ثم `AuditService` | مالك فقط، تحقق المدخلات، حد المعدّل، نجاح مع role.id |
| إنشاء موظف | «موظف جديد» | POST `merchant/staff` | خدمة الموظفين/الدور/الفرع القائمة | الأدوار النشطة فقط، نجاح مع id، منع تكرار الضغط أثناء الطلب |
| تفعيل جهاز | «رمز تفعيل» | POST `merchant/pos-devices/activation-codes` | رمز مؤقت، وليس مقعداً نشطاً بحد ذاته | لا تعرض خانة نجاح دون رمز، لا تسجيل للرمز في التقرير |
| اختيار فرع | قائمتا إنشاء الموظف والجهاز | GET `merchant/branches` | فروع المنشأة | يرسل الطلب فقط عند `has('branches')`؛ الفشل لا يختزل إلى اختيار افتراضي |
| مراجعة الورديات | تبويب الورديات | من ملخص المركز | total مستقل عن preview لأحدث 12 | اختبار 13 وردية + منشأة أخرى مضاف غير منفذ |
| مراجعة السجل | «فتح سجل التدقيق» | المسار القائم لـmerchant/audit-log | أحداث الخادم المتاحة للحساب | تم تتبع المدخل؛ لا ادعاء اختبار عرض السجل فعلياً |

## ما لا تثبته الخريطة

- `NESTED` يعني وجود استدعاء constructor مقاس، لا مساراً ناجحاً من تسجيل الدخول ولا صلاحية صحيحة لكل باقة.
- 14 `ORPHAN_CANDIDATE` موثّقة؛ لا يجوز عدّها أخطاء مؤكدة أو حذفها جميعاً. `SecureScreen` أداة أمنية وليست شاشة، واستُبعدت من المقياس.
- الفاحص يفهم الصياغة المستخدمة في معظم التعريفات لكنه ليس محلّل PHP/Dart رسمياً. الوراثة، أسماء Widgets المختلفة، التسجيل الشرطي، والروابط الديناميكية تحتاج فحصاً تشغيلياً.
- الجرد لا ينفذ SQL، ولا يقرأ بيانات العملاء، ولا يثبت أن migration مطبقة في الإنتاج.
- دفع Git لا يثبت نشر Coolify أو سلامة التطبيق المنشور. تحافظ هذه الجولة على بوابات CI وشرط فرع النشر وكون بناء APK يدوياً.
