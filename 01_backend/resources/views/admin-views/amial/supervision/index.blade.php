@extends('layouts.admin.app')

@section('title', 'لوحة الإشراف')

@php
    /**
     * AMIAL-SUPERVISION-001 — الترتيب مقصود:
     * سلامة السجلّ أوّلاً (فكل ما تحته مبنيّ عليه)، ثم ما ينتظر (وهو ما
     * يُطالَب به الفريق اليوم)، ثم توزيع العمل، ثم القرارات، ثم الاطّلاع.
     */
    $chain = $snapshot['chain'];
    $lateTotal = collect($snapshot['waiting'])->sum('breaching');
@endphp

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-visible-outlined" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">لوحة الإشراف</h2>
        <span class="badge badge-soft-secondary ms-auto">
            {{ $snapshot['range']['from'] }} — {{ $snapshot['range']['to'] }}
        </span>
    </div>

    {{-- ١. سلامة السجلّ: رقابةٌ تثق بمصدرها بلا فحص ليست رقابة --}}
    <div class="card border-{{ $chain['intact'] ? 'success' : 'danger' }} mb-4">
        <div class="card-body d-flex align-items-center gap-3">
            <i class="tio-{{ $chain['intact'] ? 'shield-outlined' : 'warning' }} text-{{ $chain['intact'] ? 'success' : 'danger' }}"
               style="font-size:28px"></i>
            <div>
                <strong class="text-{{ $chain['intact'] ? 'success' : 'danger' }}">
                    {{ $chain['intact'] ? 'سلسلة السجلّ سليمة' : 'انقطعت سلسلة السجلّ' }}
                </strong>
                <br>
                <small class="text-muted">
                    @if ($chain['intact'])
                        فُحص آخر {{ number_format($chain['checked']) }} سجلّ — كل قرار مرتبط بما قبله.
                        وكل رقم في هذه الصفحة مأخوذ منه.
                    @else
                        الانقطاع عند <code>{{ $chain['broken_at'] }}</code> — عُدّل سجلّ أو حُذف.
                        <strong>لا تعتمد بقيّة هذه الصفحة قبل التحقيق.</strong>
                    @endif
                </small>
            </div>
        </div>
    </div>

    {{-- ٢. ما ينتظر: العمر لا العدد --}}
    <div class="row mb-4">
        @foreach ($snapshot['waiting'] as $w)
            <div class="col-md-6">
                <div class="card border-{{ $w['breaching'] ? 'warning' : '' }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <small class="text-muted text-uppercase">{{ $w['label'] }}</small>
                                <h3 class="mb-0">{{ number_format($w['count']) }}</h3>
                            </div>
                            @if ($w['breaching'])
                                <span class="badge badge-soft-warning">
                                    {{ $w['breaching'] }} تجاوزت ٢٤ ساعة
                                </span>
                            @endif
                        </div>
                        @if ($w['count'])
                            <small class="text-muted d-block mt-2">
                                أقدمها ينتظر منذ {{ $w['oldest_hours'] }} ساعة
                            </small>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($lateTotal)
        <div class="alert alert-warning">
            <strong>{{ $lateTotal }} بنداً تجاوز يوم عمل كامل بلا قرار.</strong>
            التأخّر في النزاعات يعني مالاً محبوساً بين طرفين، وفي الاعتمادات
            يعني عميلاً موقوفاً ينتظر.
        </div>
    @endif

    {{-- ٣. توزيع العمل --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">نشاط الموظّفين</h5>
            <small class="text-muted">
                من يقرّر ضعف الوسيط: إمّا عبءٌ غير عادل وإمّا قلّة تأنٍّ — ولا يظهر أيٌّ منهما بلا مقارنة
            </small>
        </div>
        @if (empty($snapshot['operators']))
            <div class="card-body text-muted">لا نشاط في هذه الفترة.</div>
        @else
        <div class="table-responsive">
            <table class="table table-borderless table-align-middle mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>الموظّف</th>
                        <th>الأدوار</th>
                        <th>قرارات</th>
                        <th>حسّاسة</th>
                        <th>آخر نشاط</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($snapshot['operators'] as $op)
                    <tr class="{{ $op['outlier'] ? 'table-warning' : '' }}">
                        <td>
                            <strong>{{ $op['name'] }}</strong>
                            @if ($op['outlier'])
                                <span class="badge badge-soft-warning">ضِعف الوسيط</span>
                            @endif
                        </td>
                        <td>
                            @forelse ($op['roles'] as $role)
                                <span class="badge badge-soft-info">{{ $role }}</span>
                            @empty
                                <span class="badge badge-soft-danger">بلا دور</span>
                            @endforelse
                        </td>
                        <td>{{ number_format($op['total']) }}</td>
                        <td>
                            @if ($op['critical'])
                                <span class="badge badge-soft-danger">{{ $op['critical'] }}</span>
                            @else — @endif
                        </td>
                        <td><small class="text-muted">{{ $op['last_at'] }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- ٤. القرارات الحسّاسة --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">القرارات الحسّاسة</h5>
            <small class="text-muted">من سجلّ غير قابل للحذف ولا التعديل</small>
        </div>
        @if (empty($snapshot['critical']))
            <div class="card-body text-muted">لا قرارات حسّاسة مسجّلة.</div>
        @else
        <div class="table-responsive" style="max-height:420px;overflow:auto">
            <table class="table table-borderless table-align-middle mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>الموظّف</th>
                        <th>الموضوع</th>
                        <th>الفعل</th>
                        <th>السبب</th>
                        <th>الوقت</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($snapshot['critical'] as $d)
                    <tr>
                        <td>{{ $d['actor'] }}</td>
                        <td><code>{{ $d['subject'] }}</code></td>
                        <td><span class="badge badge-soft-secondary">{{ $d['action'] }}</span></td>
                        <td><small>{{ $d['reason'] }}</small></td>
                        <td><small class="text-muted">{{ $d['at'] }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- ٥. الاطّلاع على ملفّات العملاء --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">الاطّلاع على ملفّات العملاء (٧ أيام)</h5>
            <small class="text-muted">
                القراءة لا تُغيّر شيئاً فلا تُسجَّل قراراً — وهي أكثر ما يُساء استعماله.
                والنمط يُرى في التكرار لا في الحدث الواحد.
            </small>
        </div>
        @if (empty($snapshot['pii']))
            <div class="card-body text-muted">لا اطّلاع مسجّل في هذه الفترة.</div>
        @else
        <div class="table-responsive" style="max-height:320px;overflow:auto">
            <table class="table table-borderless table-align-middle mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>الموظّف</th>
                        <th>العميل</th>
                        <th>مرّات الفتح</th>
                        <th>آخر مرّة</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($snapshot['pii'] as $p)
                    <tr class="{{ $p['suspicious'] ? 'table-warning' : '' }}">
                        <td>{{ $p['actor'] }}</td>
                        <td><code>#{{ $p['subject_id'] }}</code></td>
                        <td>
                            {{ $p['views'] }}
                            @if ($p['suspicious'])
                                <span class="badge badge-soft-warning">يستحقّ سؤالاً</span>
                            @endif
                        </td>
                        <td><small class="text-muted">{{ $p['last_at'] }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

</div>
@endsection
