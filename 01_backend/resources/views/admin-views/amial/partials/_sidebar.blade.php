{{--
    AMIAL-ADMIN-MENU-002 — القائمة الجانبية، مجموعاتٍ لا قائمةً مسطّحة.

    **ما كانت عليه:** أربعون رابطاً في عمودٍ واحد بلا تجميع، نمت واحداً
    واحداً كلّما بُنيت لوحة. والنتيجة أنّ الرابط يُوجَد بالتمرير والبحث
    البصريّ لا بالمعرفة: من يريد «ميزان المراجعة» لا يعرف أين يبدأ النظر.

    **وثلاثة تكراراتٍ حقيقية كانت فيها:**

    • `admin/support-center` مرّتين — «مركز العمليات» من القالب الأمّ
      و«مركز الدعم» من هنا. وجهةٌ واحدة باسمين، فيظنّ المستخدم أنّهما
      شاشتان ويجرّب الاثنتين.
    • `admin/maintenance` مرّتين للسبب نفسه.
    • لوحتا مناطق تتداخلان: كلتاهما تُعيد تعيين منطقة المستخدم. وهذه
      **لم تُحذف** — الحذف يُضيّع عملاً قائماً — بل جُمعتا تحت مجموعةٍ
      واحدة بأسماءٍ تقول الفرق: «نطاق التشغيل» و«توزيع المستخدمين».

    **ولماذا مقودةً ببيانات:** القائمة السابقة كانت HTML مكرّراً، فكلّ
    إضافةٍ نسخُ سطرٍ وتعديله — ومن هنا جاء التكرار أصلاً. والمصفوفة تجعل
    الإضافة سطراً واحداً، وتجعل «هل هذا الرابط مكرَّر؟» سؤالاً يُجاب بالنظر.

    **والصلاحية تُفحص للرابط لا للمجموعة:** رابطٌ يُفتح فيُردّ ٤٠٣ يُربك
    أكثر ممّا يفيد. ومجموعةٌ خلت من كلّ روابطها لا تُعرَض أصلاً.
--}}

@php
    $u = auth('user')->user();
    $can = fn (?string $perm) => $perm === null || ($u && $u->hasPlatformPermission($perm));

    // المجموعة: [العنوان، الأيقونة، أنماط المسار للفتح التلقائيّ، الروابط]
    //
    // والأنماط **مصفوفة** لا نصّاً واحداً: `Request::is()` لا يفهم صيغة
    // الأقواس `{a,b}` — يُهرَّب القوسان حرفيّاً فلا يُطابق النمط شيئاً أبداً.
    // (كُشف ذلك بتشغيل `Str::is()` مباشرةً قبل الاختبار: أوّل صياغةٍ كتبتُها
    // استعملت الأقواس، وكانت المجموعات المتعدّدة البادئات ستبقى مطويّة دائماً
    // بلا أن يسقط أيّ اختبار.)
    // والرابط: [التسمية، اسم المسار أو العنوان، الصلاحية المطلوبة (أو null)]
    // ══════════════════════════════════════════════════════════════════
    //  AMIAL-SIDEBAR-SUBJECT-001 — **التجميع بالموضوع لا بالنظام.**
    //
    //  **الثمن الذي دُفع:** قال صاحبُ المشروع: «هذه القائمة بها الكثير من
    //  التكرار». وقِيس فلم يكن تكراراً حرفيّاً — **٥١ رابطاً و٥١ وجهةً
    //  فريدة، ولا وجهةَ مكرّرة**. لكنّ شكواه كانت في محلّها من وجهٍ آخر:
    //
    //  كان التجميعُ **بالنظام** (مراكز · مال · امتثال · أمان · خدمات)،
    //  فتتوزّع بيانات الموضوع الواحد على مجموعاتٍ عدّة:
    //
    //    · العميل يُبلَغ من ثلاثة مداخل في مجموعتين.
    //    · التاجرُ في أربعة: «مركز التجّار» و«الاشتراكات» و«الفواتير»
    //      و«الموظفون» — وثلاثةٌ منها تحت «الخدمات والعملاء».
    //    · لوحتا قطاعِ التاجر (وقود · تجزئة) كانتا تحت «المال والدفتر».
    //
    //  فمن يريد شيئاً عن تاجرٍ يفتح مجموعتين ويخمّن. **وذاك ما يُقرأ
    //  تكراراً** — لا الروابطُ نفسُها.
    //
    //  فصار التجميعُ **بمن تُدير**: كلُّ ما يخصّ العميل تحت «العملاء»،
    //  وكلُّ ما يخصّ التاجر تحت «التجّار»، وهكذا.
    //
    //  **ولم يُحذف رابطٌ واحد.** والحارسُ `AdminSidebarCoverageGuardTest`
    //  يُثبت ذلك: يقارن مجموعةَ الوجهات بقائمةٍ مرجعيّة، فأيُّ رابطٍ
    //  يسقط في إعادة ترتيبٍ لاحقة يُكشف. (وصفحةٌ لا يُوصل إليها ليست
    //  مبنيّة — القاعدة ١٢.)
    // ══════════════════════════════════════════════════════════════════
    $groups = [
        [
            'title' => 'العملاء',
            'icon' => '👤',
            'match' => ['admin/amial/customer*', 'admin/amial/hub/customers*',
                        'admin/support-center*', 'admin/amial/recovery*',
                        'admin/amial/hub/verification*', 'admin/amial/otp*'],
            'links' => [
                ['🔎 ملفّ العميل الموحَّد (١٠ تبويبات)', route('admin.amial.customer.page'), 'platform.customers.view'],
                ['👥 قائمة العملاء وإنشاء الحسابات', route('admin.amial.hub.customers'), 'platform.customers.view'],
                ['🎧 مركز الدعم (بحث شامل + الأجهزة)', route('admin.support-center.index'), 'platform.tickets.manage'],
                ['🪪 لوحة التحقق (الحسابات الجديدة)', route('admin.amial.hub.verification'), 'platform.approvals.decide'],
                ['🔐 مركز التحقّق (OTP وبوّابات الإرسال)', route('admin.amial.otp.page'), 'platform.settings.update'],
                ['🔑 استعادة الحسابات', route('admin.amial.recovery.index'), 'platform.approvals.decide'],
            ],
        ],
        [
            'title' => 'التجّار',
            'icon' => '🏪',
            'match' => ['admin/amial/hub/merchants*', 'admin/amial/hub/subscriptions*',
                        'admin/amial/invoices*', 'admin/amial/catalog*',
                        'admin/amial/entitlements*'],
            'links' => [
                ['🏪 مركز التجّار', route('admin.amial.hub.merchants'), 'platform.merchants.compliance'],
                ['💎 لوحة الاشتراكات', route('admin.amial.hub.subscriptions'), 'platform.settings.manage'],
                ['🎚️ الباقات والقدرات (ماذا تفتح كل باقة)', route('admin.amial.entitlements.page'), 'platform.settings.manage'],
                ['🧾 فواتير التجّار ومدفوعاتها', route('admin.amial.invoices.page'), 'platform.money.view'],
                ['📦 كتالوج المنتجات (الباركود)', route('admin.amial.catalog.page'), 'platform.settings.update'],
            ],
        ],
        [
            // ══════════════════════════════════════════════════════════
            //  AMIAL-SIDEBAR-SPLIT-001 — **إدارةُ التاجر ≠ رقابةُ عمله.**
            //
            //  **الثمن الذي دُفع:** قال صاحبُ المشروع وهو ينظر إلى ثمانية
            //  روابطَ تحت «التجّار»: «لماذا يجب أن تكون كلُّ هذه القوائم؟
            //  المتّفق سابقاً للإدارة هو رؤية الأمور المالية».
            //
            //  وقياسُ الثلاثة أثبت أنّها **ليست ERP**: كلٌّ منها شاشةُ
            //  رقابةٍ عابرةٌ للتجّار تكشف نمطاً لا يُرى من داخل متجرٍ
            //  واحد (مخزونٌ سالبٌ مستمرّ = بيعٌ يتجاوز المسجَّل). فطيُّها
            //  داخل ملفّ تاجرٍ بعينه **يُتلف غرضَها**، وحذفُها يُفقد
            //  المنصّةَ عينَها على الاحتيال.
            //
            //  **فُصلت ولم تُحذف**: مجموعةُ «التجّار» صارت خمسةً — حساباتٌ
            //  ومالٌ وباقاتٌ وكتالوج — وهي بعينها ما اتُّفق عليه. والرقابةُ
            //  مجموعةٌ مطويّةٌ بجانبها.
            //
            //  **والعطلُ الحقيقيّ كان غيرَ ما اشتُكي منه:** ملفُّ التاجر
            //  ٣٦٠ لم يكن يحمل رابطاً واحداً إلى أيٍّ منها — فمن يحقّق في
            //  تاجرٍ يعود إلى القائمة ويبحث عن اسمه من جديد في كلّ مركز.
            //  فأُضيفت الروابطُ من الملفّ إلى شريحة هذا التاجر بعينه.
            // ══════════════════════════════════════════════════════════
            'title' => 'رقابة عمل التجّار',
            'icon' => '🔬',
            'match' => ['admin/amial/hub/staff*', 'admin/amial/fuel*', 'admin/amial/retail*'],
            'links' => [
                ['👔 لوحة الموظفين (نقاط البيع)', route('admin.amial.hub.staff'), 'platform.merchants.compliance'],
                ['⛽ مركز محطات الوقود (فروقات المخزون)', route('admin.amial.fuel.page'), 'platform.audit.view'],
                ['🏪 مركز التجزئة (المخزون والجرد)', route('admin.amial.retail.page'), 'platform.audit.view'],
            ],
        ],
        [
            'title' => 'الوكلاء',
            'icon' => '🤝',
            'match' => ['admin/amial/hub/agents*', 'admin/amial/hub/settlements*'],
            'links' => [
                ['🤝 مركز الوكلاء (الفروع والخزائن)', route('admin.amial.hub.agents'), 'platform.customers.view'],
                ['🧾 تسويات الوكلاء', route('admin.amial.hub.settlements'), 'platform.money.view'],
                // البوّابة يدخلها الوكيلُ من كمبيوتره؛ وهذا الرابطُ يُري
                // المديرَ العنوانَ ليمرّره لشركة الصرافة.
                ['🏦 بوّابة الوكيل (شركات الصرافة)', route('agent.login'), null, '_blank'],
            ],
        ],
        [
            'title' => 'المال والدفتر',
            'icon' => '📚',
            'match' => ['admin/amial/ledger*', 'admin/amial/partner-settlements*',
                        'admin/amial/fees*', 'admin/transaction*', 'admin/emoney*',
                        'admin/expense*', 'admin/withdraw*', 'admin/amial/hub/finance*'],
            'links' => [
                ['💰 المركز المالي (بثّ حيّ)', route('admin.amial.hub.finance'), 'platform.money.view'],
                ['📚 مركز الدفتر (ميزان المراجعة)', route('admin.amial.ledger.page'), 'platform.audit.view'],
                ['📊 كشف المعاملات (فلاتر + تصدير)', route('admin.transaction.index'), 'platform.transactions.view'],
                ['🤝 تسويات الشركاء (الموافقة المزدوجة)', route('admin.amial.partner-settlements.page'), 'platform.money.view'],
                ['🏦 رصيد المنصّة (إنشاء/شحن)', route('admin.emoney.index'), 'platform.money.view'],
                ['💸 مصاريف المنصّة', route('admin.expense.index'), 'platform.money.view'],
                // AMIAL-FEE-TRUTH-010 — **الرابطُ بصلاحيّة القراءة لا الكتابة.**
                //
                // كان مشروطاً بـ`platform.fees.update`، فمن يملك `fees.view`
                // **لا يرى الرابطَ أصلاً** وإن كانت الصفحةُ تُفتح له. وهو نمطُ
                // العطل الأكثرُ تكراراً في هذا المشروع: مبنيٌّ ولا يُوصَل إليه.
                ['📈 مركز الرسوم والأرباح', route('admin.amial.fees.index'), 'platform.fees.view'],
                // **والرابطُ بصلاحيّة القراءة لا القرار.** فالصفحةُ طابورُ طلباتٍ
                // يُقرأ بـ`audit.view`، والاعتمادُ والرفضُ داخلها بصلاحيّتهما.
                // ورابطٌ أشدُّ من صفحته يُخفيها عمّن يملكها — مبنيٌّ ولا يُوصَل إليه.
                ['💸 طلبات السحب (اعتماد ورفض)', route('admin.withdraw.index'), 'platform.audit.view'],
            ],
        ],
        [
            'title' => 'الامتثال والمخاطر',
            'icon' => '🛡️',
            'match' => ['admin/amial/kyc*', 'admin/amial/aml*', 'admin/amial/audit*',
                        'admin/amial/supervision*', 'admin/amial/security-events*',
                        'admin/amial/sentinel*', 'admin/amial/system*'],
            'links' => [
                ['🪪 مراجعة مستندات الهوية', route('admin.amial.kyc.page'), 'platform.customers.freeze'],
                ['🛡️ مكافحة غسل الأموال', route('admin.amial.aml.page'), 'platform.audit.view'],
                ['🔍 سجلّ تدقيق النظام', route('admin.amial.audit.index'), 'platform.audit.view'],
                ['👁️ لوحة الإشراف (الفريق والقرارات)', route('admin.amial.supervision.index'), 'platform.audit.view'],
                ['⚠️ أحداث الأمان', route('admin.amial.security-events.index'), 'platform.audit.view'],
                ['🔒 حارس الأمان', route('admin.amial.sentinel.index'), 'platform.audit.view'],
                // AMIAL-OBSERVABILITY-001 — **صفحةٌ لا يُوصل إليها ليست مبنيّة.**
                // وموضعُها هنا لا في «الإعدادات»: الصحّةُ رقابةٌ على التشغيل،
                // ومن يفتح «أحداث الأمان» هو من يسأل «هل النظامُ سليم؟».
                ['💓 صحّة النظام والأعطال', route('admin.amial.system.health'), 'platform.audit.view'],
                // SAHER-FOUNDATION-008 — **صفحةٌ لا يُوصل إليها ليست مبنيّة.**
                //
                // وموضعُها هنا لا في «الإعدادات»: ساهر رقابةٌ على النظام
                // نفسِه، ومن يفتح «صحّة النظام» هو من يسأل «هل البنيةُ
                // سليمة؟». والفرقُ بينهما: الصحّةُ تقول أيعمل، وساهرٌ
                // يقول أهو مبنيٌّ صحيحاً.
                ['📡 ساهر — رادار النظام', route('admin.amial.saher.index'), 'saher.view'],
            ],
        ],
        [
            'title' => 'الصلاحيات',
            'icon' => '🔐',
            'match' => ['admin/amial/ops/roles*', 'admin/amial/surface/rbac*', 'admin/amial/2fa*'],
            'links' => [
                // «الأدوار» تُسند، و«RBAC» تعرض المصفوفة — والاسمان يقولان
                // الفرقَ بدل أن يُترك للتخمين.
                ['👥 أدوار الموظّفين (إسناد)', route('admin.amial.ops.roles.index'), 'platform.settings.update'],
                ['🛡️ مصفوفة الصلاحيات (RBAC)', route('admin.amial.surface.rbac'), 'platform.settings.update'],
                ['🔐 المصادقة الثنائية لحسابي', route('admin.amial.2fa.page'), null],
            ],
        ],
        [
            'title' => 'خدمات المنصّة',
            'icon' => '🧩',
            'match' => ['admin/amial/hub/disputes*', 'admin/amial/charity*', 'admin/amial/surface*'],
            'links' => [
                ['⚖️ لوحة النزاعات (دفع آمن)', route('admin.amial.hub.disputes'), 'platform.transactions.view'],
                ['🎗️ لوحة التبرعات (الجمعيات)', route('admin.amial.charity.page'), 'platform.transactions.view'],
                ['⚡ مزوّدو الفواتير', route('admin.amial.surface.bill-providers'), 'platform.settings.update'],
                ['👨‍👩‍👧 صناديق العائلة', route('admin.amial.surface.funds'), 'platform.transactions.view'],
                ['📨 طلبات الأموال', route('admin.amial.surface.payment-requests'), 'platform.transactions.view'],
            ],
        ],
        [
            'title' => 'المحتوى والتواصل',
            'icon' => '📣',
            'match' => ['admin/banner*', 'admin/notification*', 'admin/faq*', 'admin/business-settings/language*'],
            'links' => [
                ['🖼️ بانرات الرئيسية', route('admin.banner.index'), 'platform.settings.update'],
                ['🔔 إشعارات الدفع', route('admin.notification.add-new'), 'platform.settings.update'],
                ['❓ الأسئلة الشائعة', route('admin.faq.index'), 'platform.settings.update'],
                ['🌐 إدارة اللغات', route('admin.business-settings.language.index'), 'platform.settings.update'],
            ],
        ],
        [
            'title' => 'الإعدادات والتشغيل',
            'icon' => '⚙️',
            'match' => ['admin/maintenance*', 'admin/business-settings*', 'admin/amial/whatsapp*',
                        'admin/amial/zones*', 'admin/amial/hub/zones*', 'admin/amial/ops*',
                        'admin/amial/legal*', 'admin/amial/hub/settings*'],
            'links' => [
                ['🏢 إعدادات الأعمال (عام/رسوم/حدود)', route('admin.business-settings.business-setup'), 'platform.settings.update'],
                ['⚙️ مفاتيح سريعة (تشغيل/إيقاف)', route('admin.amial.hub.settings'), 'platform.settings.update'],
                ['💬 حدود بوت واتساب', route('admin.amial.whatsapp.limits.page'), 'platform.settings.update'],
                ['🗺️ نطاق التشغيل والمخالفات', route('admin.amial.hub.zones.index'), 'platform.zones.view'],
                ['🔥 إعداد Firebase', route('admin.business-settings.fcm-index'), 'platform.settings.update'],
                ['📜 الشروط القانونية', route('admin.amial.legal.index'), 'platform.ops.view'],
                ['🩺 حالة التشغيل (الطوابير والمستندات)', route('admin.amial.ops.index'), 'platform.ops.view'],
            ],
        ],
    ];
@endphp

{{-- لوحة القيادة التنفيذية تبقى خارج المجموعات: هي ما يُفتح أوّلاً كلّ صباح،
     ودفنُها داخل مجموعةٍ مطويّة يجعل أكثر الروابط استعمالاً أبعدها. --}}
<li class="nav-item">
    <a class="nav-link {{ Request::is('admin/amial/executive*') ? 'active' : '' }}"
       href="{{ route('admin.amial.executive.index') }}" data-testid="nav-executive">
        <i class="tio-chart-bar-1 nav-icon"></i>
        <span class="text-truncate">📊 لوحة القيادة التنفيذية</span>
    </a>
</li>

@foreach ($groups as $i => $g)
    @php
        $links = array_values(array_filter($g['links'], fn ($l) => $can($l[2])));
        // مجموعةٌ خلت من روابطها لا تُعرَض: عنوانٌ يُفتح على فراغ يُربك.
        if ($links === []) continue;

        $open = Request::is(...$g['match']);
        $id = 'amial-grp-' . $i;
    @endphp

    <li class="nav-item">
        <a class="nav-link d-flex align-items-center {{ $open ? '' : 'collapsed' }}"
           data-bs-toggle="collapse" href="#{{ $id }}" role="button"
           aria-expanded="{{ $open ? 'true' : 'false' }}"
           data-testid="nav-group-{{ $i }}">
            <span class="text-truncate fw-bold">{{ $g['icon'] }} {{ $g['title'] }}</span>
            <span class="badge badge-soft-secondary ms-auto">{{ count($links) }}</span>
        </a>

        <div class="collapse {{ $open ? 'show' : '' }}" id="{{ $id }}">
            <ul class="nav flex-column ms-3 mt-1 gap-1">
                @foreach ($links as $l)
                    <li class="nav-item">
                        {{-- العنصر الرابع الاختياريّ: `_blank` للبوّابات التي
                             ليست جزءاً من لوحة الإدارة، كي لا يخرج المدير من
                             جلسته حين يفتحها. --}}
                        <a class="nav-link py-1 {{ Request::url() === $l[1] ? 'active' : '' }}"
                           href="{{ $l[1] }}" title="{{ $l[0] }}"
                           @if(($l[3] ?? null) === '_blank') target="_blank" rel="noopener" @endif>
                            <span class="text-truncate small">{{ $l[0] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </li>
@endforeach
