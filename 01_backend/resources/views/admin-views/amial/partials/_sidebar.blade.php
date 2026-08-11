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
    $groups = [
        [
            'title' => 'المراكز الرئيسية',
            'icon' => '🏢',
            'match' => ['admin/amial/hub/*', 'admin/amial/customer*'],
            'links' => [
                // AMIAL-CUSTOMER-CENTER-001: الشاشة الموحَّدة أوّلاً — هي ما
                // يُدار منه العميل. والقديمة تبقى للقائمة والإنشاء بالجملة.
                ['🔎 ملفّ العميل الموحَّد (١٠ تبويبات)', route('admin.amial.customer.page'), 'platform.customers.view'],
                ['👥 قائمة العملاء وإنشاء الحسابات', route('admin.amial.hub.customers'), null],
                ['🤝 مركز الوكلاء (الفروع والخزائن)', route('admin.amial.hub.agents'), null],
                // البوّابة مبنيّة منذ الالتزام السابق ولم يكن إليها بابٌ من هنا:
                // شركة الصرافة تدخل من كمبيوترها بهاتف الحساب وكلمة سرّه، وهذا
                // الرابط هو ما يُريه المدير العنوان ليمرّره لها.
                ['🏦 بوّابة الوكيل (شركات الصرافة)', route('agent.login'), null, '_blank'],
                ['🏪 مركز التجّار', route('admin.amial.hub.merchants'), null],
                ['💰 المركز المالي (بثّ حيّ)', route('admin.amial.hub.finance'), 'platform.money.move'],
                ['🪪 لوحة التحقق (الحسابات الجديدة)', route('admin.amial.hub.verification'), null],
                // AMIAL-OTP-CENTER-001 — القاعدة ١٢: يُوصل إليه من هنا.
                ['🔐 مركز التحقّق (OTP وبوّابات الإرسال)', route('admin.amial.otp.page'), 'platform.settings.update'],
            ],
        ],
        [
            'title' => 'المال والدفتر',
            'icon' => '📚',
            'match' => ['admin/amial/ledger*', 'admin/amial/fuel*', 'admin/amial/partner-settlements*', 'admin/amial/fees*', 'admin/transaction*', 'admin/emoney*', 'admin/expense*'],
            'links' => [
                ['📚 مركز الدفتر (ميزان المراجعة)', route('admin.amial.ledger.page'), 'platform.audit.view'],
                // AMIAL-FUEL-VERTICAL-001 — القاعدة ١٢: المسار المسجّل ليس
                // ظهوراً؛ لا بدّ من رابطٍ يمرّ به المستعمل.
                ['⛽ مركز محطات الوقود (فروقات المخزون)', route('admin.amial.fuel.page'), 'platform.audit.view'],
                ['🏪 مركز التجزئة (المخزون والجرد)', route('admin.amial.retail.page'), 'platform.audit.view'],
                ['🎚️ الباقات والقدرات (ماذا تفتح كل باقة)', route('admin.amial.entitlements.page'), 'platform.settings.manage'],
                ['📊 كشف المعاملات (فلاتر + تصدير)', route('admin.transaction.index'), null],
                ['🧾 تسويات الوكلاء', route('admin.amial.hub.settlements'), 'platform.money.move'],
                ['🤝 تسويات الشركاء (الموافقة المزدوجة)', route('admin.amial.partner-settlements.page'), 'platform.money.move'],
                ['🏦 رصيد المنصّة (إنشاء/شحن)', route('admin.emoney.index'), 'platform.money.move'],
                ['💸 مصاريف المنصّة', route('admin.expense.index'), null],
                ['📈 التحكّم بالرسوم والأرباح', route('admin.amial.fees.index'), 'platform.fees.update'],
            ],
        ],
        [
            'title' => 'الامتثال والرقابة',
            'icon' => '🛡️',
            'match' => ['admin/amial/kyc*', 'admin/amial/aml*', 'admin/amial/audit*', 'admin/amial/supervision*'],
            'links' => [
                ['🪪 مراجعة مستندات الهوية', route('admin.amial.kyc.page'), 'platform.customers.freeze'],
                ['🛡️ مكافحة غسل الأموال', route('admin.amial.aml.page'), null],
                ['🔍 سجلّ تدقيق النظام', route('admin.amial.audit.index'), null],
                ['👁️ لوحة الإشراف (الفريق والقرارات)', route('admin.amial.supervision.index'), 'platform.audit.view'],
            ],
        ],
        [
            'title' => 'الأمان والوصول',
            'icon' => '🔐',
            'match' => ['admin/amial/security-events*', 'admin/amial/sentinel*', 'admin/amial/recovery*', 'admin/amial/ops/roles*', 'admin/amial/surface/rbac*'],
            'links' => [
                ['⚠️ أحداث الأمان', route('admin.amial.security-events.index'), null],
                ['🔒 حارس الأمان', route('admin.amial.sentinel.index'), null],
                ['🔑 استعادة الحسابات', route('admin.amial.recovery.index'), null],
                // الصلاحيات كانت رابطين متجاورين يفعلان شيئاً متقارباً:
                // «الأدوار» تُسند، و«RBAC» تعرض المصفوفة. جُمعا هنا بأسماءٍ
                // تقول الفرق بدل أن يتركا للتخمين.
                ['👥 أدوار الموظّفين (إسناد)', route('admin.amial.ops.roles.index'), 'platform.settings.update'],
                ['🛡️ مصفوفة الصلاحيات (RBAC)', route('admin.amial.surface.rbac'), null],
            ],
        ],
        [
            'title' => 'الخدمات والعملاء',
            'icon' => '🧾',
            'match' => ['admin/support-center*', 'admin/amial/surface*', 'admin/amial/charity*', 'admin/amial/hub/subscriptions*', 'admin/amial/hub/disputes*', 'admin/amial/hub/staff*', 'admin/amial/invoices*', 'admin/amial/catalog*'],
            'links' => [
                ['🎧 مركز الدعم (بحث شامل + الأجهزة)', route('admin.support-center.index'), null],
                ['🧾 فواتير التجّار ومدفوعاتها', route('admin.amial.invoices.page'), null],
                ['📦 كتالوج المنتجات (الباركود)', route('admin.amial.catalog.page'), 'platform.settings.update'],
                ['💎 لوحة الاشتراكات', route('admin.amial.hub.subscriptions'), null],
                ['⚖️ لوحة النزاعات (دفع آمن)', route('admin.amial.hub.disputes'), null],
                ['👔 لوحة الموظفين (نقاط البيع)', route('admin.amial.hub.staff'), null],
                ['🎗️ لوحة التبرعات (الجمعيات)', route('admin.amial.charity.page'), null],
                ['⚡ مزوّدو الفواتير', route('admin.amial.surface.bill-providers'), null],
                ['👨‍👩‍👧 صناديق العائلة', route('admin.amial.surface.funds'), null],
                ['📨 طلبات الأموال', route('admin.amial.surface.payment-requests'), null],
            ],
        ],
        [
            'title' => 'المحتوى والتواصل',
            'icon' => '📣',
            'match' => ['admin/banner*', 'admin/notification*', 'admin/faq*', 'admin/business-settings/language*'],
            'links' => [
                ['🖼️ بانرات الرئيسية', route('admin.banner.index'), null],
                ['🔔 إشعارات الدفع', route('admin.notification.add-new'), null],
                ['❓ الأسئلة الشائعة', route('admin.faq.index'), null],
                ['🌐 إدارة اللغات', route('admin.business-settings.language.index'), null],
            ],
        ],
        [
            'title' => 'الإعدادات والتشغيل',
            'icon' => '⚙️',
            'match' => ['admin/maintenance*', 'admin/business-settings*', 'admin/amial/whatsapp*', 'admin/amial/zones*', 'admin/amial/hub/zones*', 'admin/amial/ops*', 'admin/amial/legal*', 'admin/amial/hub/settings*', 'admin/amial/2fa*', 'admin/withdraw*'],
            'links' => [
                ['🏢 إعدادات الأعمال (عام/رسوم/حدود)', route('admin.business-settings.business-setup'), null],
                ['⚙️ مفاتيح سريعة (تشغيل/إيقاف)', route('admin.amial.hub.settings'), null],
                ['💬 حدود بوت واتساب', route('admin.amial.whatsapp.limits.page'), 'platform.money.move'],
                // لوحتا المناطق: كلتاهما تُعيد تعيين المنطقة. تُجمعان هنا
                // بأسماءٍ تقول الفرق — «النطاق والمخالفات» مقابل «توزيع
                // المستخدمين» — بدل أن يُتركا «لوحة المناطق» و«إدارة المناطق».
                ['🗺️ نطاق التشغيل والمخالفات', route('admin.amial.hub.zones.index'), null],
                ['🗂️ توزيع المستخدمين على المناطق', route('admin.amial.zones.index'), null],
                ['🔥 إعداد Firebase', route('admin.business-settings.fcm-index'), null],
                ['📜 الشروط القانونية', route('admin.amial.legal.index'), null],
                ['🩺 حالة التشغيل (الطوابير والمستندات)', route('admin.amial.ops.index'), 'platform.ops.view'],
                ['🛠️ وضع الصيانة', url('admin/maintenance'), null],
                // AMIAL-2FA-DOOR-001 — الميزةُ مبنيّةٌ منذ v1.8 بلا مدخل.
                // **ولا صلاحيّةَ عليها**: كلُّ مديرٍ يؤمّن حسابَه هو.
                ['🔐 المصادقة الثنائية لحسابي', route('admin.amial.2fa.page'), null],
                // AMIAL-WITHDRAW-DOOR-001 — المسارُ سُجّل في الجولة السابقة
                // والرابطُ أُجّل، فبقيت صفحةً لا يُوصل إليها.
                ['💸 طلبات السحب (اعتماد ورفض)', route('admin.withdraw.index'), 'platform.audit.view'],
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
