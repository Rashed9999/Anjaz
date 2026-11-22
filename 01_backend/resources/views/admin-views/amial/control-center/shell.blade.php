@extends('layouts.admin.app')
@section('title', 'Amial Control Center — مركز التحكم في أميال')

@section('content')
@php
    $total = count($features);
    $unconnected = $total - $connectedCount;
    $unknownBalances = count(array_filter($features, fn($f) => !($f['balance_verified'] ?? false)));
    $byKey = collect($features)->keyBy('key');
    $quickKeys = ['safe_payment', 'send_money', 'cash_out', 'cash_in', 'merchant_payment', 'receipts'];
    $emergencyKeys = ['send_money', 'cash_out', 'safe_payment', 'merchant_payment'];
    $activeTab = $section ?? 'home';
@endphp

<style nonce="{{ request()->attributes->get('csp_nonce') }}">
.acc-root {font-family:Tahoma,Arial,sans-serif; color:#182b49; direction:rtl; padding:14px 0 28px}
.acc-shell {display:grid;grid-template-columns:minmax(0,1fr) 196px; gap:17px;direction:ltr;align-items:start}
.acc-body {direction:rtl;min-width:0}.acc-side{direction:rtl;position:sticky;top:15px;background:#fff;border:1px solid #e5ebf4;border-radius:16px;min-height:530px;padding:18px 11px;box-shadow:0 4px 22px #172d4a08}
.acc-brand{font-weight:800;font-size:16px;white-space:nowrap;direction:ltr;text-align:center;padding-bottom:22px;border-bottom:1px solid #edf1f6;margin-bottom:17px}
.acc-brand span{color:#0a88c6}.acc-nav-link{display:flex;align-items:center;gap:10px;text-decoration:none;color:#42516c;padding:12px 10px;margin:4px 0;border-radius:10px;font-size:13px;font-weight:700}
.acc-nav-link:hover,.acc-nav-link.active{color:#1661ce;background:#eaf2ff;text-decoration:none}
.acc-disabled-link{opacity:.56;cursor:not-allowed}
.acc-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:17px;flex-wrap:wrap}
.acc-top h1{font-size:25px;margin:0;font-weight:800;line-height:1.4}.acc-sub{margin:4px 0 0;color:#65748f;font-size:12px}
.acc-head-tag{border:1px solid #dce6f2;border-radius:8px;padding:8px 12px;background:#fff;color:#4a5c77;font-size:12px}
.acc-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.acc-card,.acc-panel{background:white;border:1px solid #dfe7f1;border-radius:14px;box-shadow:0 4px 18px #19345507}
.acc-card{padding:15px;min-width:0}.acc-card .acc-k{font-size:12px;color:#65748f;font-weight:600}
.acc-card .acc-v{font-size:25px;font-weight:800;margin:7px 0 5px}
.acc-card.green{background:#f0fbf6;border-color:#ceefdf}.acc-card.orange{background:#fff8ed;border-color:#f5e6c9}
.acc-card.blue{background:#f2f7ff;border-color:#d7e8ff}.acc-card.red{background:#fff5f5;border-color:#f8dddd}
.acc-panel{padding:17px;margin-bottom:14px}.acc-panel-title{font-weight:800;font-size:15px;display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:15px}
.acc-panel-title small{font-weight:400;color:#72819a;font-size:11px}
.acc-platforms{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.acc-platform{border:1px solid #e3eaf3;background:#fbfcff;border-radius:11px;padding:14px;min-width:0}
.acc-platform strong{font-size:13px;display:block}.acc-platform p{font-size:11px;color:#6e7c93;line-height:1.7;margin:8px 0 13px;min-height:37px}
.acc-tag{display:inline-flex;align-items:center;justify-content:center;border-radius:100px;padding:4px 9px;font-size:10px;font-weight:800;white-space:nowrap}
.acc-tag.ok{color:#00885a;background:#e1f6ed}.acc-tag.warn{color:#ad6200;background:#fff0d5}.acc-tag.muted{color:#586783;background:#edf1f7}.acc-tag.bad{color:#c1273f;background:#ffebee}
.acc-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.acc-switch-card{border:1px solid #e2e9f2;border-radius:11px;background:#fbfcff;padding:13px;display:flex;justify-content:space-between;align-items:center;gap:8px;min-height:82px}
.acc-switch-card strong{font-size:12px;display:block}.acc-switch-card small{font-size:10px;color:#63748f;display:block;margin-top:5px}
.acc-btn{border:0;border-radius:9px;padding:9px 13px;font-size:12px;font-weight:800;cursor:pointer;background:#236cda;color:white}
.acc-btn:hover{filter:brightness(.94)}.acc-btn:disabled{cursor:not-allowed;opacity:.5}
.acc-btn.light{background:#edf4ff;color:#225ab7}.acc-btn.danger{background:#fff1f1;color:#c9273e;border:1px solid #ffcdd5}
.acc-btn.green{background:#16a477}.acc-btn.sm{padding:6px 9px;font-size:11px}
.acc-btn.outline{background:#fff;color:#2e66bc;border:1px solid #d4e3fa}
.acc-split{display:grid;grid-template-columns:1fr 1fr;gap:14px}.acc-bars{background:#f3f6fc;border-radius:8px;overflow:hidden;height:8px;margin:9px 0}
.acc-bars>span{height:100%;display:block;background:#23b484}
.acc-table-scroll{overflow:auto}.acc-table{width:100%;border-collapse:separate;border-spacing:0 7px;min-width:640px;font-size:12px}
.acc-table th{font-size:11px;color:#63738d;padding:9px}.acc-table td{background:#fbfcff;padding:11px 9px;vertical-align:middle;border-top:1px solid #e5eaf2;border-bottom:1px solid #e5eaf2}
.acc-table td:first-child{border-right:1px solid #e5eaf2;border-radius:0 9px 9px 0}.acc-table td:last-child{border-left:1px solid #e5eaf2;border-radius:9px 0 0 9px}
.acc-table tr.acc-selected td{background:#f2f7ff;border-color:#96bfff}
.acc-mute{color:#77859a;font-size:11px}.acc-toolbar{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:15px}
.acc-field{border:1px solid #dce4f1;background:white;border-radius:9px;padding:9px 11px;font-size:12px;color:#34445f;min-width:140px}
.acc-flex{display:grid;grid-template-columns:minmax(0,2fr) minmax(250px,1fr);gap:13px}
.acc-details{padding:14px;border-radius:12px;background:#fafcff;border:1px solid #dfe8f6}
.acc-detail-line{display:flex;justify-content:space-between;align-items:center;gap:7px;padding:11px 0;border-bottom:1px solid #e9eef6;font-size:12px}
.acc-detail-line:last-child{border-bottom:0}.acc-notice{padding:12px 14px;border-radius:10px;background:#ecf5ff;border:1px solid #d7eaff;color:#2c527f;font-size:12px;line-height:1.8}
.acc-warning{background:#fff8ec;border-color:#f8e4c7;color:#935c18}.acc-danger{background:#fff1f1;border-color:#f4d4d8;color:#943c46}
.acc-audit{border-right:2px solid #e0e7f4;margin:8px 3px;padding:4px 14px 4px 0}
.acc-audit-row{position:relative;margin:0 0 15px;padding-bottom:12px;border-bottom:1px solid #eef0f6;font-size:12px}
.acc-audit-row:before{content:'';position:absolute;right:-20px;top:4px;width:9px;height:9px;border-radius:100%;background:#236cda}
.acc-audit-row strong{font-size:12px}.acc-audit-row p{font-size:11px;color:#65748d;margin:4px 0}
.acc-legend{margin-top:5px;color:#74829a;font-size:10px}
.acc-dialog{border:1px solid #dbe6f3;border-radius:16px;padding:22px;max-width:520px;width:calc(100% - 32px);box-shadow:0 18px 55px #152a4a26;direction:rtl}
.acc-dialog::backdrop{background:#1227478c}.acc-dialog h3{margin:0 0 10px;font-size:18px;font-weight:800}
.acc-dialog textarea{width:100%;min-height:105px;resize:vertical;border:1px solid #cad7e8;border-radius:9px;padding:12px;font-size:13px}
.acc-dialog .acc-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}
.acc-dialog label{font-size:12px;color:#42536f;font-weight:700;display:block;margin-bottom:9px}
.acc-status{padding:11px;margin-top:8px;display:none;border-radius:8px;font-size:12px}
.acc-status.visible{display:block}.acc-status.error{background:#ffedf0;color:#bd233c}.acc-status.success{background:#e3f7ed;color:#167851}
@media(max-width:1100px){.acc-shell{grid-template-columns:minmax(0,1fr)}.acc-side{position:static;grid-row:1;min-height:auto;display:flex;overflow:auto;gap:5px;padding:9px}.acc-brand{border:0;margin:0;padding:9px}.acc-nav-link{white-space:nowrap}.acc-grid,.acc-platforms{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.acc-grid,.acc-platforms,.acc-split,.acc-flex,.acc-list{grid-template-columns:1fr}.acc-top h1{font-size:21px}.acc-shell{gap:8px}.acc-panel{padding:12px}}
</style>

<div class="content container-fluid acc-root" data-testid="amial-control-center">
<div class="acc-shell">
<main class="acc-body">
    <header class="acc-top">
        <div>
            <h1>{{ $section === 'home' ? 'مركز التحكم في أميال' : ($section === 'services' ? 'التحكم التشغيلي' : 'الطوارئ والموافقات') }}</h1>
            <p class="acc-sub">إدارة مركزية للخدمات والخصائص والقرارات التشغيلية — الحالات الحقيقية فقط</p>
        </div>
        <span class="acc-head-tag">● البيئة: {{ app()->environment('production') ? 'الإنتاج' : 'غير الإنتاج' }} · لا تغييرات تلقائية</span>
    </header>

    @if(! $ready)
        <div class="acc-notice acc-danger mb-3" role="alert">
            قاعدة الموافقات غير مهيأة. تعرض اللوحة الحالات للقراءة فقط حتى يُطبّق الترحيل المعتمد.
        </div>
    @endif

    <div class="acc-grid">
        <div class="acc-card green"><div class="acc-k">الخدمات الفعالة المرتبطة</div><div class="acc-v">{{ $activeCount }}</div><span class="acc-tag ok">من أصل {{ $connectedCount }} خدمة مرتبطة</span></div>
        <div class="acc-card blue"><div class="acc-k">الخصائص المسجّلة</div><div class="acc-v">{{ $total }}</div><span class="acc-tag muted">كتالوج أميال</span></div>
        <div class="acc-card orange"><div class="acc-k">الموافقات المعلّقة</div><div class="acc-v">{{ $pendingCount }}</div><span class="acc-tag warn">قرارات تحتاج موظفًا ثانيًا</span></div>
        <div class="acc-card red"><div class="acc-k">خدمات لم تربط بالتحكم</div><div class="acc-v">{{ $unconnected }}</div><span class="acc-tag bad">لا يظهر لها زر تشغيل مضلّل</span></div>
    </div>

    @if($section === 'home')
        <section class="acc-panel">
            <div class="acc-panel-title">▤ المنصات <small>حالة الربط التشغيلي، لا مجرد حالة موقع الويب</small></div>
            <div class="acc-platforms">
                <div class="acc-platform"><strong>💳 أميال باي</strong><p>المحفظة والتحويلات وواجهات الدفع الأساسية.</p><span class="acc-tag {{ $connectedCount ? 'ok' : 'warn' }}">{{ $connectedCount ? $connectedCount.' تحكم متصل' : 'يحتاج ربطًا' }}</span></div>
                <div class="acc-platform"><strong>🏪 أميال أعمال</strong><p>التجار ونقاط البيع والعمليات التجارية.</p><span class="acc-tag muted">تكامل التحكم غير متصل</span></div>
                <div class="acc-platform"><strong>🛡️ موثق</strong><p>الهوية والتحقق والموافقات.</p><span class="acc-tag muted">تكامل التحكم غير متصل</span></div>
                <div class="acc-platform"><strong>👥 أميال الوكلاء</strong><p>الوكلاء والفروع والخزائن.</p><span class="acc-tag muted">تكامل التحكم غير متصل</span></div>
            </div>
        </section>
        <section class="acc-panel">
            <div class="acc-panel-title">⚡ أزرار التحكم السريع <a class="acc-btn outline sm" href="{{ route('admin.amial.control-center.services') }}">جميع الخدمات</a></div>
            <div class="acc-list">
                @foreach($quickKeys as $key)
                    @php $f = $byKey->get($key); @endphp
                    @if($f)
                        <div class="acc-switch-card" data-testid="control-{{ $key }}">
                            <div><strong>{{ $f['name_ar'] }}</strong><small>{{ $f['connected'] ? ($f['key'] === 'safe_payment' ? ($f['enabled'] ? 'استقبال دفعات جديدة مسموح' : 'استقبال جديد موقوف — الدفعات القائمة مستمرة') : ($f['enabled'] ? 'يعمل ضمن النطاق المحروس' : 'متوقف بالنطاق المحروس')) : 'غير مربوط بحارس API فعّال' }}</small></div>
                            @if($f['connected'] && $ready && $canRequest)
                                <button class="acc-btn {{ $f['enabled'] ? 'danger' : 'green' }} sm js-change"
                                        data-key="{{ $f['key'] }}" data-name="{{ $f['name_ar'] }}"
                                        data-enabled="{{ $f['enabled'] ? '0' : '1' }}"
                                        @if($f['enabled'] && !$f['can_disable']) disabled title="توجد أموال محتجزة أو تعذر التحقق" @endif>
                                    {{ $f['key'] === 'safe_payment' ? ($f['enabled'] ? 'وقف استقبال الجديد' : 'استئناف استقبال الجديد') : ($f['enabled'] ? 'إيقاف' : 'تشغيل') }}
                                </button>
                            @else
                                <span class="acc-tag muted">{{ $f['connected'] ? 'عرض فقط' : 'غير متصل' }}</span>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
        <section class="acc-panel">
            <div class="acc-panel-title">🚀 الإطلاق التدريجي <small>لا يتم عرض نسب افتراضية بلا تنفيذ فعلي</small></div>
            <div class="acc-platforms" style="grid-template-columns:repeat(3,minmax(0,1fr))">
                <div class="acc-platform"><strong>ساهر AutoFix</strong><p>إدارة الإصلاحات عبر طلبات مراجعة.</p><span class="acc-tag muted">تجهيز الربط</span></div>
                <div class="acc-platform"><strong>SSO التجار</strong><p>التحقق من جاهزية الإطلاق التدريجي.</p><span class="acc-tag muted">النسبة غير موصولة</span></div>
                <div class="acc-platform"><strong>أميال أعمال المحمول</strong><p>قواعد التفعيل على مستوى المستخدم.</p><span class="acc-tag muted">النسبة غير موصولة</span></div>
            </div>
        </section>
        <div class="acc-split">
            @include('admin-views.amial.control-center.partials.events')
            <section class="acc-panel">
                <div class="acc-panel-title">🛡️ مؤشرات سلامة التغيير</div>
                <div class="acc-detail-line"><strong>التحقق من الأموال</strong><span class="acc-tag {{ $unknownBalances ? 'warn' : 'ok' }}">{{ $unknownBalances ? $unknownBalances.' حالة غير مؤكدة' : 'متاح' }}</span></div>
                <div class="acc-detail-line"><strong>الموافقة الثانية</strong><span class="acc-tag ok">مطلوبة للتغييرات الحساسة</span></div>
                <div class="acc-detail-line"><strong>الواجهات غير المربوطة</strong><span class="acc-tag muted">لا يُسمح بتغييرها</span></div>
                <div class="acc-detail-line"><strong>أثر التحكم</strong><span class="acc-tag ok">سجل تغيير ومراجعة</span></div>
                <div class="acc-notice mt-3">مفتاح الدفع الآمن هنا يتحكم في استقبال طلبات جديدة فقط؛ لا يوقف التسويات القائمة. لا يساوي لون الزر حالة النظام؛ لا يُعلن النجاح إلا بعد قبول الخادم للقرار وتسجيله.</div>
            </section>
        </div>
    @elseif($section === 'services')
        <section class="acc-panel">
            <div class="acc-panel-title">▤ الخدمات والخصائص <small>{{ $total }} خاصية مسجلة</small></div>
            <div class="acc-toolbar">
                <input id="acc-search" class="acc-field" style="flex:1" placeholder="ابحث عن خدمة أو خاصية..." aria-label="بحث">
                <select id="acc-category" class="acc-field" aria-label="الفئة">
                    <option value="">الفئة: الكل</option><option value="financial">مالية</option><option value="merchant">تجارية</option><option value="channel">قنوات</option><option value="general">عامة</option>
                </select>
                <select id="acc-filter" class="acc-field" aria-label="حالة الربط">
                    <option value="">الحالة: الكل</option><option value="connected">مرتبطة</option><option value="unconnected">غير مرتبطة</option>
                </select>
            </div>
            <div class="acc-flex">
                <div class="acc-table-scroll">
                    <table class="acc-table" data-testid="control-services-table">
                        <thead><tr><th>الخاصية / الخدمة</th><th>الفئة</th><th>الحالة</th><th>المخاطر</th><th>التحكم</th></tr></thead>
                        <tbody>
                        @foreach($features as $f)
                            <tr data-feature-row tabindex="0" data-key="{{ $f['key'] }}" data-text="{{ mb_strtolower($f['name_ar'].' '.$f['key']) }}" data-category="{{ $f['category'] }}" data-connection="{{ $f['connected'] ? 'connected' : 'unconnected' }}">
                                <td><strong>{{ $f['name_ar'] }}</strong><div class="acc-mute">{{ $f['description_ar'] }}</div></td>
                                <td>{{ ['financial'=>'مالية','merchant'=>'تجارية','general'=>'عامة','channel'=>'قنوات'][$f['category']] ?? $f['category'] }}</td>
                                <td><span class="acc-tag {{ $f['connected'] ? ($f['enabled'] ? 'ok' : 'bad') : 'muted' }}">{{ $f['connected'] ? ($f['key'] === 'safe_payment' ? ($f['enabled'] ? 'استقبال جديد متاح' : 'استقبال الجديد موقوف') : ($f['enabled'] ? 'تشغيل' : 'إيقاف')) : 'غير مربوط' }}</span></td>
                                <td><span class="acc-tag {{ $f['approval_required'] ? 'warn' : 'ok' }}">{{ $f['approval_required'] ? 'موافقة مزدوجة' : 'منخفض' }}</span></td>
                                <td>
                                    @if($f['connected'] && $canRequest && $ready)
                                        <button class="acc-btn sm {{ $f['enabled'] ? 'danger' : 'green' }} js-change"
                                            data-key="{{ $f['key'] }}" data-name="{{ $f['name_ar'] }}"
                                            data-enabled="{{ $f['enabled'] ? '0' : '1' }}"
                                            @if($f['enabled'] && !$f['can_disable']) disabled title="لا يمكن التحقق من سلامة الإيقاف" @endif>
                                            {{ $f['key'] === 'safe_payment' ? ($f['enabled'] ? 'وقف استقبال الجديد' : 'استئناف استقبال الجديد') : ($f['enabled'] ? 'إيقاف' : 'تشغيل') }}
                                        </button>
                                    @else
                                        <span class="acc-tag muted">غير متاح</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <aside class="acc-details">
                    <h4 id="acc-selected-name" style="font-size:15px;font-weight:800">تفاصيل الخدمة</h4>
                    <p class="acc-sub" id="acc-selected-desc">اختر خدمة من الجدول لمراجعة خصائصها.</p>
                    <div class="acc-detail-line"><strong>الوضع الحالي</strong><span id="acc-selected-state" class="acc-tag muted">غير محدد</span></div>
                    <div class="acc-detail-line"><strong>الربط الفعلي</strong><span id="acc-selected-link">—</span></div>
                    <div class="acc-detail-line"><strong>السياسة</strong><span id="acc-selected-policy">—</span></div>
                    <div class="acc-detail-line"><strong>التحقق المالي</strong><span id="acc-selected-balance">—</span></div>
                    <div class="acc-detail-line"><strong>آخر ملاحظة</strong><span id="acc-selected-note">—</span></div>
                    <div class="acc-detail-line"><strong>المراقبة/الإطلاق التدريجي</strong><span class="acc-tag muted">يحتاج حارسًا مخصصًا</span></div>
                    <div class="acc-notice mt-3">
                        <strong>لماذا ليس زرًا فقط؟</strong>
                        <div>الخدمات المالية قد تحتاج إلى منع عمليات جديدة مع الاستمرار في تسوية العمليات القائمة؛ لا يظهر هذا الوضع قبل بناء حارس API مستقل واختبار أثره.</div>
                    </div>
                </aside>
            </div>
        </section>
    @else
        <div class="acc-notice mb-3">الأزرار تغيّر السلوك <strong>بعد التحقق والموافقة</strong>، وليس عند النقر. إيقاف عمليات مالية جديدة لا يعني إلغاء التسويات أو الاستردادات المستحقة.</div>
        <section class="acc-panel">
            <div class="acc-panel-title">⚠️ إجراءات الطوارئ <small>لا توجد أزرار وهمية للوظائف غير المتصلة</small></div>
            <div class="acc-platforms">
                @foreach($emergencyKeys as $key)
                    @php $f = $byKey->get($key); @endphp
                    @if($f)
                    <div class="acc-platform">
                        <strong>{{ $f['name_ar'] }}</strong>
                        <p>{{ $f['description_ar'] }}</p>
                        <div class="mb-2"><span class="acc-tag {{ $f['connected'] ? ($f['enabled'] ? 'ok' : 'bad') : 'muted' }}">{{ $f['connected'] ? ($f['enabled'] ? 'تشغيل' : 'متوقف') : 'غير مرتبط' }}</span></div>
                        @if($f['connected'] && $ready && $canRequest)
                            <button class="acc-btn danger sm js-change" data-key="{{ $key }}" data-name="{{ $f['name_ar'] }}" data-enabled="{{ $f['enabled'] ? '0' : '1' }}"
                                @if($f['enabled'] && !$f['can_disable']) disabled @endif>
                                {{ $f['key'] === 'safe_payment' ? ($f['enabled'] ? 'طلب وقف استقبال الجديد' : 'طلب استئناف الاستقبال') : ($f['enabled'] ? 'طلب الإيقاف' : 'طلب التشغيل') }}
                            </button>
                        @else
                            <span class="acc-mute">لا يمكن التحكم إلى حين ربط API</span>
                        @endif
                    </div>
                    @endif
                @endforeach
            </div>
        </section>
        <section class="acc-panel">
            <div class="acc-panel-title">👥 إجراءات تتطلب موافقة مزدوجة <small>طالب القرار لا يستطيع اعتماد طلبه</small></div>
            <div class="acc-table-scroll">
                <table class="acc-table" data-testid="control-approval-table">
                    <thead><tr><th>الطلب</th><th>الإجراء</th><th>طالب التنفيذ</th><th>الحالة</th><th>الموافق الثاني</th><th>الإجراء</th></tr></thead>
                    <tbody>
                    @forelse($requests as $req)
                        <tr>
                            <td>#{{ $req->id }}<div class="acc-mute">{{ $req->created_at }}</div></td>
                            <td><strong>{{ $byKey->get($req->feature_key)['name_ar'] ?? $req->feature_key }}</strong><div class="acc-mute">{{ $req->feature_key === 'safe_payment'
    ? ($req->desired_enabled ? 'استئناف استقبال الدفعات الجديدة' : 'تعليق استقبال الدفعات الجديدة')
    : ($req->desired_enabled ? 'تشغيل' : 'إيقاف') }}</div></td>
                            <td>الموظف #{{ $req->requested_by }}<div class="acc-mute">{{ \Illuminate\Support\Str::limit($req->reason, 65) }}</div></td>
                            <td><span class="acc-tag {{ $req->status === 'pending' ? 'warn' : ($req->status === 'approved' ? 'ok' : 'bad') }}">{{ $req->status }}</span></td>
                            <td>{{ $req->reviewed_by ? 'الموظف #'.$req->reviewed_by : '—' }}</td>
                            <td>
                                @if($req->status === 'pending' && now()->lessThan($req->expires_at) && $canApprove && (int)$req->requested_by !== (int)auth('user')->id())
                                    <button class="acc-btn green sm js-decide" data-request="{{ $req->id }}" data-decision="approve">اعتماد</button>
                                    <button class="acc-btn danger sm js-decide" data-request="{{ $req->id }}" data-decision="reject">رفض</button>
                                @else
                                    <span class="acc-tag muted">{{ $req->status === 'pending' ? 'بانتظار موظف ثانٍ' : 'محفوظ' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;color:#73829a">لا توجد طلبات قرارات مسجّلة.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <div class="acc-split">
            @include('admin-views.amial.control-center.partials.events')
            <section class="acc-panel">
                <div class="acc-panel-title">🛡️ سلامة التنفيذ</div>
                <div class="acc-detail-line"><strong>التحقق من الأرصدة</strong><span class="acc-tag {{ $unknownBalances ? 'warn' : 'ok' }}">{{ $unknownBalances ? 'يتطلب تحققًا' : 'متاح' }}</span></div>
                <div class="acc-detail-line"><strong>توقيع القرار</strong><span class="acc-tag ok">موظفان مختلفان</span></div>
                <div class="acc-detail-line"><strong>فحص آخر حالة قبل الاعتماد</strong><span class="acc-tag ok">إلزامي</span></div>
                <div class="acc-detail-line"><strong>السجلات</strong><span class="acc-tag ok">مرتبطة بالموظف والسبب</span></div>
                <div class="acc-notice acc-warning mt-3">لا يثبت هذا المركز سلامة تكاملات موثق أو الوكلاء حتى تُربط بيانات الصحة الفعلية من كل خدمة.</div>
            </section>
        </div>
    @endif
</main>
<aside class="acc-side">
    <div class="acc-brand"><span>▥</span> Amial Control Center</div>
    <a href="{{ route('admin.amial.control-center.home') }}" class="acc-nav-link {{ $section === 'home' ? 'active' : '' }}">⌂ الرئيسية</a>
    <a href="{{ route('admin.amial.control-center.services') }}" class="acc-nav-link {{ $section === 'services' ? 'active' : '' }}">▤ الخدمات والخصائص</a>
    <a href="{{ route('admin.amial.control-center.emergency') }}" class="acc-nav-link {{ $section === 'emergency' ? 'active' : '' }}">⚠ الطوارئ والموافقات</a>
    @if(auth('user')->user()?->hasPlatformPermission('saher.view'))
        <a href="{{ route('admin.amial.saher.index') }}" class="acc-nav-link">♢ ساهر</a>
    @endif
    @if(auth('user')->user()?->hasPlatformPermission('platform.ops.view'))
        <a href="{{ route('admin.amial.ops.index') }}" class="acc-nav-link">◉ مراقبة العمليات</a>
    @endif
    <a href="{{ route('admin.maintenance.index') }}" class="acc-nav-link">⚙ الصيانة والإعدادات القديمة</a>
    <div class="acc-notice acc-warning" style="margin-top:22px;font-size:11px">
        <strong>مصدر الحقيقة:</strong> حالة Feature Flags في أميال باي. بقية المنصات تُعرض كتكامل غير مكتمل حتى تُربط ويُختبر التحكم فيها.
    </div>
</aside>
</div>
</div>

<dialog class="acc-dialog" id="acc-control-dialog" aria-labelledby="acc-dialog-title">
    <form id="acc-form" method="dialog">
        <h3 id="acc-dialog-title">طلب قرار تشغيلي</h3>
        <p class="acc-sub" id="acc-dialog-desc">يُفحص أثر القرار قبل تطبيقه.</p>
        <label for="acc-reason">سبب القرار (20 حرفًا على الأقل)</label>
        <textarea id="acc-reason" required minlength="20" maxlength="1000" placeholder="اكتب السبب التشغيلي والأثر المتوقع بوضوح"></textarea>
        <div class="acc-notice acc-warning mt-3">قد يحتاج القرار إلى موافقة ثانية. لن تظهر الحالة مفعّلة حتى يؤكد الخادم التنفيذ.</div>
        <div id="acc-result" class="acc-status" role="status"></div>
        <div class="acc-actions">
            <button type="button" class="acc-btn outline" id="acc-cancel">إلغاء</button>
            <button type="submit" class="acc-btn" id="acc-confirm">إرسال القرار</button>
        </div>
    </form>
</dialog>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    'use strict';
    const modal = document.getElementById('acc-control-dialog');
    const form = document.getElementById('acc-form');
    const label = document.getElementById('acc-dialog-title');
    const desc = document.getElementById('acc-dialog-desc');
    const reason = document.getElementById('acc-reason');
    const result = document.getElementById('acc-result');
    const submit = document.getElementById('acc-confirm');
    const base = @json(url('admin/amial/control-center'));
    const token = @json(csrf_token());
    const features = @json($features);
    let action = null;

    function openDialog(next) {
        action = next;
        reason.value = '';
        result.className = 'acc-status';
        result.textContent = '';
        label.textContent = next.type === 'approve'
            ? (next.decision === 'approve' ? 'اعتماد طلب رقم ' : 'رفض طلب رقم ') + next.id
            : (next.key === 'safe_payment'
                ? (next.enabled ? 'طلب استئناف دفعات جديدة: ' : 'طلب تعليق دفعات جديدة: ') + next.name
                : (next.enabled ? 'طلب تشغيل: ' : 'طلب إيقاف: ') + next.name);
        desc.textContent = next.type === 'approve'
            ? 'الموافق الثاني يجب أن يكون مختلفًا عن طالب التغيير.'
            : (next.key === 'safe_payment'
                ? 'هذا القرار يخص استقبال دفعات آمنة جديدة فقط؛ لا يوقف التسويات والإلغاءات والنزاعات القائمة.'
                : 'سيُتحقق من الربط والأرصدة والصلاحيات، ثم يطبّق القرار أو يُسجل للموافقة.');
        submit.disabled = false;
        modal.showModal();
        reason.focus();
    }

    document.querySelectorAll('.js-change').forEach(button => {
        button.addEventListener('click', () => openDialog({
            type: 'change', key: button.dataset.key,
            name: button.dataset.name, enabled: button.dataset.enabled === '1'
        }));
    });
    document.querySelectorAll('.js-decide').forEach(button => {
        button.addEventListener('click', () => openDialog({
            type: 'approve', id: button.dataset.request, decision: button.dataset.decision
        }));
    });
    document.getElementById('acc-cancel').addEventListener('click', () => modal.close());

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!action || reason.value.trim().length < 20) {
            result.textContent = 'يجب توضيح السبب في 20 حرفًا على الأقل.';
            result.className = 'acc-status visible error';
            return;
        }
        const url = action.type === 'approve'
            ? base + '/approvals/' + encodeURIComponent(action.id)
            : base + '/features/' + encodeURIComponent(action.key);
        const payload = action.type === 'approve'
            ? {decision: action.decision, reason: reason.value.trim()}
            : {enabled: action.enabled ? 1 : 0, reason: reason.value.trim()};
        submit.disabled = true;
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},
                body: JSON.stringify(payload)
            });
            const body = await response.json();
            if (!response.ok || !body.success) throw new Error(body.message || 'تعذّر تنفيذ الطلب.');
            result.textContent = body.message;
            result.className = 'acc-status visible success';
            setTimeout(() => window.location.reload(), 900);
        } catch (err) {
            result.textContent = err.message;
            result.className = 'acc-status visible error';
            submit.disabled = false;
        }
    });

    const featureByKey = new Map(features.map(f => [f.key, f]));
    const selectedName = document.getElementById('acc-selected-name');
    function showDetails(key) {
        const f = featureByKey.get(key);
        if (!f || !selectedName) return;
        document.getElementById('acc-selected-name').textContent = f.name_ar;
        document.getElementById('acc-selected-desc').textContent = f.description_ar || 'بدون وصف';
        const state = document.getElementById('acc-selected-state');
        state.textContent = f.connected
            ? (f.key === 'safe_payment' ? (f.enabled ? 'استقبال جديد متاح' : 'استقبال الجديد موقوف')
                : (f.enabled ? 'تشغيل' : 'إيقاف'))
            : 'غير مرتبط';
        state.className = 'acc-tag ' + (f.connected ? (f.enabled ? 'ok' : 'bad') : 'muted');
        document.getElementById('acc-selected-link').textContent = f.connected ? 'حارس API متصل' : 'بحاجة إلى عقد تكامل واختبارات';
        document.getElementById('acc-selected-policy').textContent = f.approval_required ? 'موافقة مزدوجة' : 'تغيير مباشر بصلاحية الإدارة';
        document.getElementById('acc-selected-balance').textContent = f.balance_verified
            ? 'القيمة المحتجزة: ' + f.outstanding_amount : 'غير مؤكد — يمنع الإيقاف';
        document.getElementById('acc-selected-note').textContent = f.last_note || 'لا توجد';
        document.querySelectorAll('[data-feature-row]').forEach(row =>
            row.classList.toggle('acc-selected', row.dataset.key === key));
    }
    document.querySelectorAll('[data-feature-row]').forEach(row => {
        row.addEventListener('click', () => showDetails(row.dataset.key));
        row.addEventListener('keydown', event => {
            if (event.key === 'Enter') showDetails(row.dataset.key);
        });
    });
    if (document.querySelector('[data-feature-row]')) {
        showDetails(document.querySelector('[data-feature-row]').dataset.key);
    }

    const q = document.getElementById('acc-search');
    const category = document.getElementById('acc-category');
    const connection = document.getElementById('acc-filter');
    function filter() {
        const term = (q?.value || '').trim().toLowerCase();
        document.querySelectorAll('[data-feature-row]').forEach(row => {
            row.hidden = !(
                row.dataset.text.includes(term)
                && (!category?.value || category.value === row.dataset.category)
                && (!connection?.value || connection.value === row.dataset.connection)
            );
        });
    }
    [q,category,connection].filter(Boolean).forEach(el => el.addEventListener('input', filter));
})();
</script>
@endsection
