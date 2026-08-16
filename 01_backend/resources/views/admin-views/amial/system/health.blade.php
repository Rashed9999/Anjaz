@extends('layouts.admin.app')

@section('title', 'صحّة النظام')

@push('css_or_js')
<style>
    .h-tile { border-radius: var(--amial-radius); border: 1px solid var(--amial-border);
              background: var(--amial-surface); padding: 14px; }
    .h-dot  { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
    .h-up   { background: var(--amial-success); }
    .h-deg  { background: var(--amial-warning); }
    .h-down { background: var(--amial-danger); }
    .h-unk  { background: transparent; border: 1.5px solid var(--amial-text-muted); }
    .h-ms   { font-variant-numeric: tabular-nums; color: var(--amial-text-secondary); font-size: .8rem; }
    .h-err  { border-inline-start: 3px solid var(--amial-danger); }
    .h-ack  { border-inline-start: 3px solid var(--amial-warning); }
    .h-mono { font-family: ui-monospace, monospace; font-size: .78rem; direction: ltr;
              unicode-bidi: isolate; display: inline-block; }
</style>
@endpush

@section('content')
<div class="content container-fluid">

    {{-- ══════════════════════════════════════════════════════════════
         الحالةُ الآن — تُحسب لحظةَ فتح الصفحة لا تُقرأ من آخر نبضة.
         (القاعدة السادسة: الرقم يُحسب من مصدره.)
    ══════════════════════════════════════════════════════════════ --}}
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
        <h1 class="page-header-title mb-0">صحّة النظام</h1>
        @php
            $lbl = ['up' => 'سليم', 'degraded' => 'يعمل بتعثّر', 'down' => 'ساقط'][$overall];
            $cls = ['up' => 'h-up', 'degraded' => 'h-deg', 'down' => 'h-down'][$overall];
        @endphp
        <span class="badge" style="background:var(--amial-{{ $overall === 'up' ? 'success' : ($overall === 'degraded' ? 'warning' : 'danger') }});font-size:.85rem">
            {{ $lbl }}
        </span>
        <span class="text-muted small">قِيست الآن — {{ now()->format('H:i:s') }}</span>
    </div>

    {{-- **هل الراصدُ نفسُه يعمل؟** صفحةٌ خضراءُ فوق راصدٍ ميّتٍ أسوأ من
         صفحةٍ حمراء — وهي قاعدةُ المشروع: حارسٌ يكذب أسوأ من غيابه. --}}
    @php
        $beatAge = $lastBeat ? \Carbon\Carbon::parse($lastBeat)->diffInMinutes(now()) : null;
    @endphp
    @if ($lastBeat === null)
        <div class="alert alert-danger py-2 small">
            ⚠ <strong>لا نبضةَ واحدةٌ في السجلّ.</strong> المهمّةُ المجدولة
            <code>amial:health-check</code> لا تجري — فتاريخُ التوفّر فارغٌ
            ولن يُعرف انقطاعٌ وقع ليلاً. تأكّد أنّ <code>schedule:run</code>
            مضبوطٌ في cron على الخادم.
        </div>
    @elseif ($beatAge > 15)
        <div class="alert alert-warning py-2 small">
            ⚠ آخرُ نبضةٍ قبل <strong>{{ $beatAge }}</strong> دقيقة — والمفترض كلَّ خمس.
            المجدوِلُ متوقّفٌ أو متعثّر.
        </div>
    @endif

    {{-- **هل يخرج الإنذارُ من الخادم؟** (AMIAL-PROD-READINESS-001)
         كلُّ ما في هذه الصفحة يُكتب في جدولين ويُقرأ من هنا. وبلا قناةٍ
         خارجيّةٍ يكون المشرفُ هو جهازَ الرصد — وهو ما بُنيت هذه الصفحةُ
         لإنهائه. فتُقال الفجوةُ حيث تُقرأ. --}}
    @unless ($hasAlertChannel)
        <div class="alert alert-warning py-2 small">
            ⚠ <strong>لا قناةَ إنذارٍ خارجيّةٌ مضبوطة.</strong>
            الأعطالُ وسقوطُ القطع وفروقُ المصالحة الليليّة تُسجَّل أدناه
            <em>ولا يصل بها إشعارٌ إلى أحد</em> — فلا تُعرَف إلّا بفتح هذه
            الصفحة. اضبط إحدى القناتين ثمّ أثبِت وصولَها بـ
            <code>php artisan amial:alert-test</code>:
            <br>• <code>AMIAL_ALERT_EMAIL</code> — بريدٌ إلكترونيّ (يحتاج SMTP فقط)
            <br>• <code>AMIAL_RECON_ALERT_TO</code> — واتساب (يحتاج مزوّداً مُفعَّلاً في اللوحة)
        </div>
    @endunless

    {{-- ── القطع ─────────────────────────────────────────────────── --}}
    <div class="row g-3 mb-4">
        @php
            $names = ['database' => 'قاعدة البيانات', 'cache' => 'الكاش', 'queue' => 'الطابور',
                      'storage' => 'التخزين', 'disk' => 'القرص', 'php' => 'PHP'];
        @endphp
        @foreach ($now as $c)
            @php
                $dot = ['up' => 'h-up', 'degraded' => 'h-deg', 'down' => 'h-down'][$c['state']];
                $h = $history[$c['component']] ?? collect();
                $downs = (int) ($h->firstWhere('state', 'down')->n ?? 0);
                $total = (int) $h->sum('n');
            @endphp
            <div class="col-6 col-lg">
                <div class="h-tile">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="h-dot {{ $dot }}"></span>
                        <strong style="font-size:.9rem">{{ $names[$c['component']] ?? $c['component'] }}</strong>
                    </div>
                    @if ($c['latency_ms'] !== null)
                        <div class="h-ms">{{ $c['latency_ms'] }} مللي</div>
                    @endif
                    @if ($c['detail'])
                        <div class="small text-muted mt-1">{{ $c['detail'] }}</div>
                    @endif
                    <div class="small mt-2" style="color:var(--amial-text-muted)">
                        {{-- **«غير معروف» ليس صفراً**: بلا نبضاتٍ تُقال الحالةُ
                             غيرَ معروفة، ولا تُعرض «٠ انقطاع». --}}
                        @if ($total === 0)
                            آخر ٢٤ ساعة: غير معروف — لا نبضات
                        @else
                            آخر ٢٤ ساعة: {{ $downs }} انقطاعاً من {{ $total }} فحصاً
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mb-4 small">
        @if ($lastDown)
            <span style="color:var(--amial-danger)">آخرُ انقطاع:</span>
            <strong>{{ $names[$lastDown->component] ?? $lastDown->component }}</strong>
            — {{ \Carbon\Carbon::parse($lastDown->checked_at)->diffForHumans() }}
            <span class="text-muted">({{ $lastDown->detail }})</span>
        @else
            <span style="color:var(--amial-success)">لا انقطاعَ مسجَّلٌ إطلاقاً.</span>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         مركزُ الأخطاء — «# ERROR CENTER» في وثيقة اللوحات.
    ══════════════════════════════════════════════════════════════ --}}
    <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
        <h2 class="h5 mb-0">الأعطال</h2>
        <span class="badge" style="background:var(--amial-danger)">مفتوح {{ $counts['open'] }}</span>
        <span class="badge" style="background:var(--amial-warning)">مُقَرٌّ به {{ $counts['ack'] }}</span>
        <span class="badge" style="background:var(--amial-success)">محلول {{ $counts['resolved'] }}</span>
        <span class="text-muted small">ظهر اليوم: {{ $counts['today'] }}</span>
    </div>

    <p class="small text-muted">
        {{-- شرحُ ما لا يُرى: لماذا الجدولُ قصيرٌ ولو كان الخطأُ متكرّراً. --}}
        العطلُ يُبصَم بموضعه لا برسالته — فما وقع ألفَ مرّةٍ سطرٌ واحدٌ بعدّاد.
        ولا يُسجَّل ما ليس عطلاً: رفضُ صلاحيّةٍ أو تحقّقُ مدخلاتٍ سلوكٌ صحيح.
    </p>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>العطل</th>
                        <th>الموضع</th>
                        <th>المسار</th>
                        <th class="text-center">مرّات</th>
                        <th>آخر ظهور</th>
                        <th class="text-center">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($defects as $e)
                        <tr class="{{ $e->status_flag === 'open' ? 'h-err' : 'h-ack' }}">
                            <td>
                                <div class="fw-bold" style="font-size:.85rem">{{ class_basename($e->exception) }}</div>
                                <div class="small text-muted">{{ \Illuminate\Support\Str::limit($e->message, 120) }}</div>
                            </td>
                            <td class="h-mono">{{ basename((string) $e->file) }}:{{ $e->line }}</td>
                            <td class="h-mono">
                                {{ $e->method }} /{{ \Illuminate\Support\Str::limit((string) $e->path, 40) }}
                                {{-- AMIAL-PROD-READINESS-005 — الخيطُ يظهر حيث يُقرأ.
                                     بدونه يبقى مخزَّناً ولا يُوصَل إليه: يبحث
                                     المشرفُ في السجلّ بالوقت والتخمين. --}}
                                @if ($e->request_id)
                                    <div class="small" style="color:var(--amial-text-muted)">
                                        طلب: {{ $e->request_id }}
                                    </div>
                                @endif
                            </td>
                            <td class="text-center fw-bold" style="font-variant-numeric:tabular-nums">{{ $e->occurrences }}</td>
                            <td class="small">{{ \Carbon\Carbon::parse($e->last_seen_at)->diffForHumans() }}</td>
                            <td class="text-center">
                                <select class="form-select form-select-sm err-state" data-id="{{ $e->id }}"
                                        style="min-width:120px">
                                    <option value="open" @selected($e->status_flag === 'open')>مفتوح</option>
                                    <option value="acknowledged" @selected($e->status_flag === 'acknowledged')>مُقَرٌّ به</option>
                                    <option value="resolved" @selected($e->status_flag === 'resolved')>حُلّ</option>
                                </select>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                لا أعطالَ مفتوحة.
                                @if ($counts['resolved'] === 0 && $counts['open'] === 0)
                                    <div class="small mt-1">
                                        {{-- الصفرُ الأوّلُ غامض: أهو نظافةٌ أم أنّ التتبّعَ
                                             لم يبدأ؟ يُقال صراحةً. --}}
                                        (وليس في السجلّ عطلٌ واحدٌ بعد — التتبّعُ حديثُ التركيب.)
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

@push('script_2')
<script>
document.querySelectorAll('.err-state').forEach(function (sel) {
    sel.addEventListener('change', function () {
        var id = this.dataset.id, val = this.value, el = this;
        el.disabled = true;

        fetch('{{ url("admin/amial/system/errors") }}/' + id, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ status_flag: val }),
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            // **النتيجةُ تُقال** — لا تغييرٌ صامتٌ يُظنّ أنّه نجح.
            toastr[j.success ? 'success' : 'error'](j.message || 'تعذّر الحفظ');
        })
        .catch(function () { toastr.error('تعذّر الاتّصال بالخادم'); })
        .finally(function () { el.disabled = false; });
    });
});
</script>
@endpush
