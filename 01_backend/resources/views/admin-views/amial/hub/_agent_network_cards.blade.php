{{-- AMIAL-AGENT-SUPERVISION-001 — ملخّص شبكة شركات الصرافة.

     البطاقتان الماليّتان مفصولتان عمداً ولا تُجمعان:
     الرصيد الإلكترونيّ مالٌ **تَدين به المنصّة** للوكيل، والنقد الورقيّ مالُ
     شركة الصرافة في درجها لا يمرّ بدفترنا. وجمعُهما يُنتج رقماً لا يقابله
     شيءٌ في الميزانية. --}}

<div class="row g-3 mb-3" id="agent-network-cards">
    <div class="col-md-3 col-6"><div class="card stat-card p-3 h-100">
        <small class="text-muted">الوكلاء (شركات الصرافة)</small>
        <div class="fs-3 fw-bold" data-net="agents">—</div>
        <small class="text-danger" data-net="agents_without_branch_txt"></small>
    </div></div>

    <div class="col-md-3 col-6"><div class="card stat-card p-3 h-100">
        <small class="text-muted">الفروع</small>
        <div class="fs-3 fw-bold" data-net="branches">—</div>
        <small class="text-muted" data-net="branches_active_txt"></small>
    </div></div>

    <div class="col-md-3 col-6"><div class="card stat-card p-3 h-100" style="border-inline-start:4px solid #0d6efd">
        <small class="text-muted">رصيد إلكترونيّ لدى الشبكة</small>
        <div class="fs-4 fw-bold text-primary" data-net="emoney_total">—</div>
        <small class="text-muted">التزامٌ على المنصّة</small>
    </div></div>

    <div class="col-md-3 col-6"><div class="card stat-card p-3 h-100" style="border-inline-start:4px solid #198754">
        <small class="text-muted">نقد ورقيّ في أدراج الفروع</small>
        <div class="fs-4 fw-bold text-success" data-net="cash_on_hand">—</div>
        <small class="text-muted">الخزائن + أدراج الصرّافين المفتوحة — لا يُجمع مع أعلاه</small>
    </div></div>
</div>

{{-- **شحن رصيد الوكيل — كان مبنيّاً ومخفيّاً.**

     المسار موجودٌ منذ البداية (`adminCreditAgent`) وزرّه في عمود «إجراءات»
     داخل الجدول. فقيل: «لا توجد طريقة لشحن محفظة الوكيل، لم أرها في مركز
     الوكلاء ولا التسويات» — وكان محقّاً: زرٌّ صغيرٌ في صفٍّ وسط أعمدةٍ كثيرة
     ليس «موجوداً» عملياً.

     والفرق بين ميزةٍ مبنيّةٍ وميزةٍ مستعمَلة هو أين تقع العين. --}}
<div class="alert alert-primary d-flex flex-wrap align-items-center gap-3 mb-4">
    <div>
        <div class="fw-bold">💳 شحن رصيد وكيل من محفظة المنصّة</div>
        <div class="small">
            السيولة تنزل من أعلى: <strong>المنصّة ← الوكيل ← الفرع</strong>.
            وبلا رصيدٍ لدى الفرع لا يستطيع قبول أيّ إيداع.
        </div>
    </div>
    <div class="ms-auto text-center">
        <div class="small text-muted">رصيد محفظة المنصّة</div>
        <div class="fs-5 fw-bold" data-net="platform_wallet">—</div>
    </div>
    <button class="btn btn-primary" id="agent-credit-open">اختر وكيلاً واشحنه</button>
</div>

<div class="d-flex gap-2 flex-wrap mb-4" id="agent-network-flags">
    <button class="btn btn-sm btn-outline-danger" data-flag="low_cash">
        نقدٌ منخفض: <span data-net="low_cash">—</span>
    </button>
    <button class="btn btn-sm btn-outline-warning" data-flag="overloaded">
        فوق الحدّ الأعلى: <span data-net="overloaded">—</span>
    </button>
    <button class="btn btn-sm btn-outline-secondary" data-flag="not_counted">
        لم يُجرَد اليوم: <span data-net="not_counted_today">—</span>
    </button>
    <span class="btn btn-sm btn-light disabled">
        شبابيك مفتوحة الآن: <span data-net="open_shifts">—</span>
    </span>
    <span class="btn btn-sm btn-light disabled">
        حركات نقدٍ اليوم: <span data-net="movements_today">—</span>
    </span>
</div>
