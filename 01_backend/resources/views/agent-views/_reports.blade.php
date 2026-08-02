{{-- AMIAL-AGENT-REPORTS-001 — لوحة التقارير.

     **والطباعة ليست زرّاً يستدعي `window.print()`.** طباعةٌ بلا تنسيقٍ
     تُخرج الشريط الأسود وأزرار «تحديث» وقوائم التبويبات على ورقةٍ يُفترض
     أن تُقدَّم إلى بنك. فهنا قواعدُ طباعةٍ تُخفي كلّ ما ليس تقريراً،
     وتُظهر ترويسةً لا تظهر على الشاشة. --}}

<div class="tab-pane fade" id="ag-reports">

    <div class="card p-3 mb-3 rp-controls">
        <div class="d-flex gap-2 flex-wrap align-items-end">
            <div>
                <label class="form-label small mb-1">من</label>
                <input type="date" id="rp-from" class="form-control form-control-sm">
            </div>
            <div>
                <label class="form-label small mb-1">إلى</label>
                <input type="date" id="rp-to" class="form-control form-control-sm">
            </div>
            <div class="btn-group btn-group-sm" role="group">
                <button class="btn btn-outline-secondary" data-rp-range="7">٧ أيّام</button>
                <button class="btn btn-outline-secondary" data-rp-range="30">٣٠ يوماً</button>
                <button class="btn btn-outline-secondary" data-rp-range="90">٩٠ يوماً</button>
            </div>
            <button class="btn btn-primary btn-sm" id="rp-load">عرض التقرير</button>
            <div class="ms-auto d-flex gap-2">
                <button class="btn btn-outline-dark btn-sm" id="rp-print">🖨️ طباعة</button>
                <button class="btn btn-outline-success btn-sm" id="rp-csv">⤓ تصدير CSV</button>
            </div>
        </div>
    </div>

    {{-- ترويسةُ الورقة: لا تظهر على الشاشة وتظهر عند الطباعة. فورقةٌ بلا
         اسم شركةٍ ولا فترةٍ ولا تاريخِ إصدارٍ لا تصلح مستنداً. --}}
    <div id="rp-print-head" class="print-only mb-3">
        <div class="d-flex justify-content-between align-items-end border-bottom pb-2">
            <div>
                <div class="fw-bold fs-5" id="rp-h-agent">—</div>
                <div class="small" id="rp-h-period">—</div>
            </div>
            <div class="text-end small">
                <div>أميال باي — تقرير شركة صرافة</div>
                <div id="rp-h-generated">—</div>
            </div>
        </div>
    </div>

    <div id="rp-body">
        <div class="text-muted text-center py-5">اختر الفترة ثمّ اضغط «عرض التقرير».</div>
    </div>
</div>

@once
<style>
    .print-only { display: none; }

    @media print {
        /* كلّ ما ليس تقريراً يختفي: الترويسة السوداء، والتبويبات، وأزرار
           التحكّم، وبقيّة الألواح. وورقةٌ فيها زرُّ «تحديث» ليست مستنداً. */
        .topbar, .nav-tabs, .nav-pills, .rp-controls,
        #ag-totals, #ag-alerts, .modal, .modal-backdrop { display: none !important; }

        .tab-content > .tab-pane { display: none !important; }
        .tab-content > #ag-reports { display: block !important; opacity: 1 !important; }

        .print-only { display: block !important; }

        body { background: #fff !important; font-size: 12px; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; break-inside: avoid; }
        .table { font-size: 11px; }

        /* جدولٌ يُقطع بين الصفحات يفقد ترويسته — فتُكرَّر. */
        thead { display: table-header-group; }
        tr, .rp-section { break-inside: avoid; }

        @page { size: A4; margin: 12mm; }
    }
</style>
@endonce
