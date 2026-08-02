{{-- AMIAL-CHARTS-001 — رسومٌ بيانيّة بـSVG خالص.

     **ولا مكتبة رسمٍ من شبكةٍ خارجيّة.** فرعٌ في سيئون قد ينقطع إنترنته
     عن العالم ويبقى موصولاً بخادمنا — ومكتبةٌ من CDN تجعل الشاشة تُفتح
     بلا رسومٍ حينها، أو لا تُفتح أصلاً. وSVG يفهمه كلّ متصفّح بلا شيء.

     **وتُطبَع كما تُعرَض**: الرسمُ عنصرٌ في الصفحة لا لوحةُ رسمٍ نقطيّة
     (canvas)، فالطابعة تُخرجه حادّاً بأيّ دقّة. --}}

@once
<style>
    .amial-chart { width: 100%; height: auto; font-family: inherit; }
    .amial-chart text { font-size: 11px; fill: #6b7280; }
    .amial-chart .grid { stroke: #e5e7eb; stroke-width: 1; }
    .amial-chart .axis { stroke: #9ca3af; stroke-width: 1; }
    .chart-legend { display: flex; gap: 1rem; flex-wrap: wrap; font-size: .85rem; }
    .chart-legend i { display: inline-block; width: .8rem; height: .8rem; border-radius: 2px; }

    /* الرسم لا يُطبَع بلا ألوان: متصفّحاتٌ تُسقط الخلفيّات افتراضياً. */
    @media print {
        .amial-chart { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const NS = 'http://www.w3.org/2000/svg';
    const C = {
        dep: '#16a34a', wdr: '#2563eb', vol: '#0f1b2d',
        short: '#dc2626', over: '#d97706', muted: '#9ca3af',
    };

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    // اختصارُ الأرقام على المحور: «١٢٠ ألف» أقرأُ من «120000».
    const short = (n) => {
        n = Number(n || 0);
        if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(1) + 'B';
        if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(1) + 'M';
        if (Math.abs(n) >= 1e3) return Math.round(n / 1e3) + 'K';
        return String(Math.round(n));
    };

    /**
     * حالةُ «لا بيانات» تُقال ولا تُرسم صفراً.
     *
     * رسمٌ مسطّحٌ على الصفر يبدو كأنّ العمل كان صفراً — والحقيقة أنّه لم
     * يكن هناك عملٌ يُقاس. والفرق بينهما هو الفرق بين خسارةٍ وتوقّف.
     */
    function empty(msg) {
        return `<div class="text-center text-muted py-4 border rounded">${esc(msg || 'لا بيانات في هذه الفترة')}</div>`;
    }

    /** رسمٌ خطّيّ لسلسلتين — الإيداعات والسحوبات عبر الأيّام. */
    window.lineChart = function (rows, opts) {
        opts = opts || {};
        if (!rows || !rows.length) return empty();

        const keys = opts.keys || ['deposits', 'withdrawals'];
        const names = opts.names || ['إيداعات', 'سحوبات'];
        const colors = opts.colors || [C.dep, C.wdr];

        const W = 720, H = 240, P = {t: 12, r: 12, b: 26, l: 52};
        const iw = W - P.l - P.r, ih = H - P.t - P.b;

        let max = 0;
        rows.forEach(r => keys.forEach(k => { max = Math.max(max, Number(r[k] || 0)); }));
        if (max <= 0) return empty('لا حركة في هذه الفترة');
        // سقفٌ مريح فوق أعلى قيمة حتى لا يلامس الخطُّ الحافّة.
        max = max * 1.1;

        const x = (i) => P.l + (rows.length === 1 ? iw / 2 : (i * iw) / (rows.length - 1));
        const y = (v) => P.t + ih - (Number(v || 0) / max) * ih;

        let grid = '';
        for (let g = 0; g <= 4; g++) {
            const gy = P.t + (g * ih) / 4;
            grid += `<line class="grid" x1="${P.l}" y1="${gy}" x2="${W - P.r}" y2="${gy}"/>`
                 + `<text x="${P.l - 6}" y="${gy + 4}" text-anchor="end">${short(max - (g * max) / 4)}</text>`;
        }

        let paths = '';
        keys.forEach((k, ki) => {
            const d = rows.map((r, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(r[k]).toFixed(1)}`).join(' ');
            paths += `<path d="${d}" fill="none" stroke="${colors[ki]}" stroke-width="2.5"
                        stroke-linejoin="round" stroke-linecap="round"/>`;
            // نقاطٌ حين تقلّ الأيّام: خطٌّ من ثلاث نقاطٍ يصعب قراءته بلا علامات.
            if (rows.length <= 31) {
                paths += rows.map((r, i) =>
                    `<circle cx="${x(i).toFixed(1)}" cy="${y(r[k]).toFixed(1)}" r="2.5" fill="${colors[ki]}"/>`).join('');
            }
        });

        // تسمياتُ المحور تتخفّف كلّما طالت الفترة — وإلّا تراكبت فلم تُقرأ.
        const step = Math.max(1, Math.ceil(rows.length / 12));
        const labels = rows.map((r, i) => (i % step === 0 || i === rows.length - 1)
            ? `<text x="${x(i).toFixed(1)}" y="${H - 8}" text-anchor="middle">${esc(r.label || r.date)}</text>` : '').join('');

        return `<svg class="amial-chart" viewBox="0 0 ${W} ${H}" role="img"
                     aria-label="${esc(opts.title || 'رسم بيانيّ')}">
            ${grid}
            <line class="axis" x1="${P.l}" y1="${P.t}" x2="${P.l}" y2="${P.t + ih}"/>
            ${paths}${labels}
        </svg>
        <div class="chart-legend mt-2 justify-content-center">
            ${keys.map((k, i) => `<span><i style="background:${colors[i]}"></i> ${esc(names[i])}</span>`).join('')}
        </div>`;
    };

    /** أعمدةٌ أفقيّة — الفروع أو الموظّفون مرتّبين. */
    window.barChart = function (items, opts) {
        opts = opts || {};
        if (!items || !items.length) return empty();

        const rows = items.slice(0, opts.limit || 12);
        const max = Math.max(...rows.map(r => Number(r.value || 0)), 0);
        if (max <= 0) return empty('لا حركة في هذه الفترة');

        const rowH = 26, W = 720, L = 150, R = 70;
        const H = rows.length * rowH + 10;
        const iw = W - L - R;

        const bars = rows.map((r, i) => {
            const w = Math.max(2, (Number(r.value || 0) / max) * iw);
            const y = i * rowH + 6;

            return `<text x="${L - 8}" y="${y + 13}" text-anchor="end" style="fill:#111">${esc(r.label)}</text>
                <rect x="${L}" y="${y}" width="${w.toFixed(1)}" height="16" rx="3"
                      fill="${r.color || C.vol}"/>
                <text x="${L + w + 6}" y="${y + 13}">${short(r.value)}</text>`;
        }).join('');

        return `<svg class="amial-chart" viewBox="0 0 ${W} ${H}" role="img"
                     aria-label="${esc(opts.title || 'رسم أعمدة')}">${bars}</svg>`;
    };

    /** حلقةٌ — النسبة بين بندين أو أكثر. */
    window.donutChart = function (parts, opts) {
        opts = opts || {};
        const total = parts.reduce((a, p) => a + Number(p.value || 0), 0);
        if (total <= 0) return empty('لا حركة في هذه الفترة');

        const S = 200, r = 70, cx = S / 2, cy = S / 2, sw = 28;
        const circ = 2 * Math.PI * r;
        let offset = 0;

        const arcs = parts.map(p => {
            const frac = Number(p.value || 0) / total;
            const seg = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none"
                stroke="${p.color}" stroke-width="${sw}"
                stroke-dasharray="${(frac * circ).toFixed(2)} ${circ.toFixed(2)}"
                stroke-dashoffset="${(-offset * circ).toFixed(2)}"
                transform="rotate(-90 ${cx} ${cy})"/>`;
            offset += frac;

            return seg;
        }).join('');

        return `<svg class="amial-chart" style="max-width:220px" viewBox="0 0 ${S} ${S}" role="img"
                     aria-label="${esc(opts.title || 'نسب')}">
            ${arcs}
            <text x="${cx}" y="${cy - 2}" text-anchor="middle" style="font-size:15px;fill:#111;font-weight:700">${short(total)}</text>
            <text x="${cx}" y="${cy + 15}" text-anchor="middle">${esc(opts.centerLabel || 'الإجمالي')}</text>
        </svg>
        <div class="chart-legend mt-2 justify-content-center">
            ${parts.map(p => `<span><i style="background:${p.color}"></i> ${esc(p.label)}
                <b>${Math.round((Number(p.value || 0) / total) * 100)}٪</b></span>`).join('')}
        </div>`;
    };

    window.CHART_COLORS = C;
})();
</script>
@endonce
