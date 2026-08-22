@extends('layouts.admin.app')

@section('title', 'طلبات تحديث بيانات العملاء')

@push('css_or_js')
<style>
    .pcr-diff { font-family: var(--amial-font-mono, monospace); font-size: 13px; }
    .pcr-old { color: var(--amial-danger, #C62828); text-decoration: line-through; }
    .pcr-new { color: var(--amial-success, #2E7D32); font-weight: 600; }
    .pcr-note { border-right: 3px solid var(--amial-warning, #CFA300);
                background: var(--amial-surface-2, #FFF8E1); padding: 12px 14px;
                border-radius: 8px; line-height: 1.9; }
</style>
@endpush

@section('content')
<div class="content container-fluid" data-testid="pcr-page">

    <div class="d-flex align-items-center mb-3">
        <h2 class="h1 mb-0">🔄 طلبات تحديث بيانات العملاء</h2>
    </div>

    {{--
        AMIAL-PROFILE-CHANGE-003 — **ولمَ لا يوجد زرُّ «تعديل» في هذه
        الشاشة.** هذا ليس نقصاً يُستدرَك: من يستطيع كتابةَ رقم الهويّة
        مباشرةً يستطيع تحويلَ حسابٍ موثَّقٍ إلى شخصٍ آخرَ ثمّ سحبَ رصيده.
        فتُقال العلّةُ في الشاشة نفسِها، لئلّا يُطلَب الزرُّ كلَّ شهر.
    --}}
    <div class="pcr-note mb-4">
        <strong>الدعمُ يفتح الطلبَ ويتابعه — ولا يكتب قيمةً.</strong>
        القيمةُ الجديدةُ يملؤها <strong>صاحبُ الحساب من التطبيق</strong> ومعها
        وثيقةٌ داعمةٌ حين يلزم، ويعتمدها <strong>مراجعٌ غيرُ من فتح الطلب</strong>.
        <br>
        وتغييرُ الاسم أو رقم الهويّة <strong>يُعيد الحسابَ إلى طابور التوثيق</strong>
        — فالوثيقةُ المعتمَدةُ تخصّ البيانَ القديم.
    </div>

    {{-- ① فتحُ طلبٍ نيابةً عن عميل --}}
    <div class="card mb-4">
        <div class="card-header"><h4 class="mb-0">فتحُ طلبٍ نيابةً عن عميل</h4></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.amial.kyc.changes.open') }}"
                  class="row g-3" data-testid="pcr-open-form">
                @csrf
                <div class="col-md-3">
                    <label class="form-label">رقمُ العميل (ID) *</label>
                    <input type="number" name="user_id" class="form-control" required
                           data-testid="pcr-user-id">
                </div>
                <div class="col-md-3">
                    <label class="form-label">الحقلُ المطلوب تغييره *</label>
                    <select name="field" class="form-control" required data-testid="pcr-field">
                        @foreach($fields as $f)
                            <option value="{{ $f }}">
                                {{ $f }}@if(in_array($f, $needsDocument, true)) — يلزمه وثيقة @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">سببُ الطلب *</label>
                    <input type="text" name="reason" class="form-control" required minlength="5"
                           placeholder="مثال: اتّصل العميل — تغيّر عنوان سكنه"
                           data-testid="pcr-reason">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100" data-testid="pcr-open">
                        فتحُ الطلب
                    </button>
                </div>
            </form>

            <hr class="my-4">

            {{--
                AMIAL-KYC-EXPIRY-004 — **فحصُ صلاحيّة الهويّة.**

                قِيس قبل بناء هذا: `identification_expiry_date` كان يُملأ في
                التسجيل و**لا يقرؤه سطرٌ واحدٌ في المشروع كلِّه**، و
                `document_expires_at` لا يُقرأ إلّا عند اعتمادٍ جديد. فمن
                وُثِّق مرّةً وُثِّق للأبد وإن انتهت وثيقتُه قبل سنتين.

                **وحقلٌ يُطلَب ولا يُقرأ أسوأ من غيابه**: يُوهم بأنّ الأمرَ
                مضبوطٌ فلا يبحث أحد.
            --}}
            <h5>فحصُ صلاحيّة الهويّة</h5>
            <p class="text-muted small mb-3">
                يقرأ تاريخَ الملفّ وتواريخَ الوثائق المعتمَدة معاً <strong>ويأخذ
                الأقرب</strong> — فوثيقةٌ منتهيةٌ لا يحجبها تاريخٌ بعيدٌ في الملفّ.
            </p>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">رقمُ العميل (ID)</label>
                    <input type="number" id="idch-user" class="form-control"
                           data-testid="idch-user">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-primary w-100"
                            id="idch-run" data-testid="idch-run">افحص</button>
                </div>
                <div class="col-md-7">
                    <div id="idch-out" class="small" data-testid="idch-out">—</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ② الطابور --}}
    <div class="card">
        <div class="card-header">
            <h4 class="mb-0">بانتظار المراجعة <span class="badge bg-warning">{{ count($queue) }}</span></h4>
        </div>
        <div class="card-body p-0">
            @if(count($queue) === 0)
                {{-- **وفراغُ الطابور يُقال فراغاً** — لا جدولٌ خالٍ بلا كلمة. --}}
                <div class="p-4 text-center text-muted" data-testid="pcr-empty">
                    لا طلباتٍ بانتظار المراجعة الآن.
                </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover mb-0" data-testid="pcr-table">
                    <thead>
                        <tr>
                            <th>#</th><th>العميل</th><th>الحقل</th>
                            <th>من → إلى</th><th>السبب</th><th>وثيقة</th>
                            <th>منذ</th><th>القرار</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($queue as $r)
                        <tr>
                            <td>{{ $r['id'] }}</td>
                            <td>
                                {{ trim(($r['f_name'] ?? '') . ' ' . ($r['l_name'] ?? '')) ?: '—' }}
                                <div class="text-muted small money">{{ $r['phone'] ?? '' }}</div>
                            </td>
                            <td>
                                <code>{{ $r['field'] }}</code>
                                @if(in_array($r['field'], $resetsVerification, true))
                                    <span class="badge bg-danger">يُعيد التوثيق</span>
                                @endif
                            </td>
                            <td class="pcr-diff">
                                {{-- **«قبل» تُعرَض مع «بعد»** — وإلّا قال السجلُّ
                                     «غُيّر» ولم يقل من ماذا. --}}
                                <span class="pcr-old">{{ $r['old_value'] ?? '(فارغ)' }}</span>
                                <br>
                                <span class="pcr-new">{{ $r['new_value'] ?? '(لم يُملأ بعد)' }}</span>
                            </td>
                            <td class="small">{{ $r['reason'] ?? '—' }}</td>
                            <td>
                                @if($r['supporting_document_id'])
                                    <span class="badge bg-success">مرفقة</span>
                                @else
                                    <span class="badge bg-secondary">لا</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $r['created_at'] }}</td>
                            <td>
                                <form method="POST"
                                      action="{{ route('admin.amial.kyc.changes.decide', $r['id']) }}"
                                      class="d-flex gap-1">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
                                    <button type="submit" class="btn btn-sm btn-success"
                                            data-testid="pcr-approve-{{ $r['id'] }}"
                                            onclick="return confirm('اعتمادُ التغيير يكتبه في ملفّ العميل. متابعة؟')">
                                        اعتماد
                                    </button>
                                </form>
                                <form method="POST"
                                      action="{{ route('admin.amial.kyc.changes.decide', $r['id']) }}"
                                      class="mt-1">
                                    @csrf
                                    <input type="hidden" name="decision" value="reject">
                                    {{-- **ورفضٌ بلا سببٍ يجعل العميلَ يعيد الطلبَ نفسَه.** --}}
                                    <input type="text" name="reason" class="form-control form-control-sm"
                                           placeholder="سبب الرفض" required minlength="5">
                                    <button type="submit" class="btn btn-sm btn-outline-danger mt-1"
                                            data-testid="pcr-reject-{{ $r['id'] }}">رفض</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('script')
{{--
    **و`script_2` ليست مكدَّساً معروضاً** — القالبُ الأساسُ يعرض `script`
    وحدَه. فدفعٌ إلى مكدَّسٍ لا `@stack` له **يبتلع السكربتَ صامتاً**:
    الصفحةُ تردّ ٢٠٠ والزرُّ لا يفعل شيئاً، ولا خطأَ في أيّ سجلّ.

    **و`nonce` إلزاميّة** — بدونها يمنعه المتصفّحُ بسياسة المحتوى، وهو
    الصمتُ نفسُه من بابٍ آخر. (أمسك الاثنين `InlineScriptDeliveryGuardTest`.)
--}}
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
    // **وزرٌّ لم يُضغط ليس مبنيّاً** — فالنداءُ ها هنا لا في نيّة.
    (function () {
        var btn = document.getElementById('idch-run');
        var out = document.getElementById('idch-out');
        var inp = document.getElementById('idch-user');
        if (!btn || !out || !inp) { return; }   // معرّفٌ غائبٌ يقتل المعالجَ بصمت

        var LABEL = {
            VALID: ['سارية', 'success'],
            DUE: ['تقترب من الانتهاء', 'warning'],
            EXPIRED: ['منتهية', 'danger'],
            // **و«غير معروف» ليس «سارية»** — يُقال صراحةً مع سببه.
            UNKNOWN: ['لا تاريخَ مسجَّلٌ لهذا الحساب — لا يُقرأ هذا سلامة', 'secondary']
        };

        btn.addEventListener('click', function () {
            var id = (inp.value || '').trim();
            if (!id) { out.textContent = 'أدخل رقمَ العميل.'; return; }

            out.textContent = 'جارٍ الفحص…';

            fetch('{{ url('admin/amial/kyc/identity-state') }}/' + encodeURIComponent(id),
                  { headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (!r.ok) { throw new Error('تعذّر الفحص (' + r.status + ')'); }
                    return r.json();
                })
                .then(function (j) {
                    var d = (j && j.data) || {};
                    var l = LABEL[d.state] || ['حالةٌ غيرُ معروفة', 'secondary'];
                    var extra = d.expires_at
                        ? ' — تنتهي في ' + d.expires_at
                          + ' (' + (d.days < 0 ? 'مضى ' + Math.abs(d.days) : 'بقي ' + d.days) + ' يوماً'
                          + ', المصدر: ' + (d.source === 'document' ? 'وثيقةٌ معتمَدة' : 'ملفُّ الحساب') + ')'
                        : '';
                    out.innerHTML = '<span class="badge bg-' + l[1] + '">' + l[0] + '</span>'
                        + '<span class="ms-2">' + extra + '</span>';
                })
                .catch(function (e) {
                    // **ورسالةٌ لا تدلّ على سببها تُنتج تذكرةَ دعمٍ لا إجراء.**
                    out.textContent = e.message;
                });
        });
    })();
</script>
@endpush
