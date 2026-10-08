(() => {
    'use strict';

    const root = document.getElementById('ari-root');
    if (!root) return;

    const baseUrl = String(root.dataset.baseUrl || '').replace(/\/$/, '');
    const asOfReports = new Set(['balance-sheet', 'liquidity', 'safeguarded-funds']);
    const noPeriodReports = new Set(['merchant-portfolio', 'credit-control', 'reconciliation', 'aml-regulatory']);
    const reportNames = new Set([
        'trial-balance','income-statement','balance-sheet','cash-flow','liquidity','safeguarded-funds',
        'general-ledger','fees-commissions','transaction-volume','transaction-exceptions','reconciliation',
        'credit-control','merchant-portfolio','inventory-control','customer-activity','agent-liquidity',
        'subscriptions','vertical-performance','kyc-pipeline','aml-regulatory','audit-sensitive-actions',
        'rbac-changes','system-health-history','queue-operations','email-otp','auth-security','support-operations'
    ]);
    const state = { current: {}, previous: {}, currentFailures: new Set(), previousFailures: new Set() };
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>\"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[c]));
    const fmt = value => {
        if (value === null || value === undefined || value === '') return '—';
        const raw = String(value);
        if (!/^-?\d+(\.\d+)?$/.test(raw)) return raw;
        const neg = raw.startsWith('-');
        const clean = neg ? raw.slice(1) : raw;
        const [wholeRaw, fractionRaw = ''] = clean.split('.');
        const whole = (wholeRaw || '0').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const fraction = fractionRaw.replace(/0+$/, '');
        return (neg ? '-' : '') + whole + (fraction ? '.' + fraction : '');
    };
    const num = value => Number.isFinite(Number(value)) ? Number(value) : 0;
    const loading = () => '<span class="ari-loading"><i></i><i></i><i></i></span>';
    const set = (id, value) => { const node = $(id); if (node) node.textContent = value ?? '—'; };
    const status = (id, text, kind = 'neutral') => { const node = $(id); if (!node) return; node.className = `ari-status ${kind}`; node.textContent = text; };

    function parseDate(value) {
        const date = new Date(`${value}T00:00:00Z`);
        return Number.isNaN(date.getTime()) ? null : date;
    }
    const ymd = date => date.toISOString().slice(0, 10);
    function selectedPeriods() {
        const from = $('ari-from')?.value || '';
        const to = $('ari-to')?.value || '';
        const start = parseDate(from), end = parseDate(to);
        if (!start || !end || end < start) return { valid: false, current: { from, to }, previous: { from: null, to: null }, days: 0 };
        const days = Math.floor((end - start) / 86400000) + 1;
        const previousTo = new Date(start.getTime() - 86400000);
        const previousFrom = new Date(previousTo.getTime() - ((days - 1) * 86400000));
        return {
            valid: true,
            current: { from: ymd(start), to: ymd(end) },
            previous: { from: ymd(previousFrom), to: ymd(previousTo) },
            days
        };
    }
    function queryFor(name, range = 'current') {
        const periods = selectedPeriods();
        const selected = periods[range] || periods.current;
        const query = new URLSearchParams();
        if (name === 'agent-liquidity') query.set('date', selected.to || periods.current.to || '');
        else if (asOfReports.has(name)) query.set('as_of', selected.to || periods.current.to || '');
        else if (!noPeriodReports.has(name)) {
            if (selected.from) query.set('from', selected.from);
            if (selected.to) query.set('to', selected.to);
        }
        return query.toString();
    }
    async function fetchMeta(name, range = 'current') {
        if (!reportNames.has(name)) throw new Error('unknown_report');
        const query = queryFor(name, range);
        const url = `${baseUrl}/${encodeURIComponent(name)}${query ? `?${query}` : ''}`;
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        let body = {};
        try { body = await response.json(); } catch (_) {}
        if (!response.ok || body.success === false) throw new Error(body.message || `HTTP ${response.status}`);
        const meta = body.meta || {};
        if (meta.available === false) throw new Error(meta.reason || meta.source || 'source_unavailable');
        return meta;
    }

    const transactionEntries = meta => (Array.isArray(meta?.by_currency) ? meta.by_currency : []).reduce((sum, row) => sum + num(row.original_entries), 0);
    const exceptionCount = meta => num(meta?.pending_transfers?.overdue_holding) + num(meta?.rejections?.count);
    function liquidityCoverage(meta) {
        const rows = Array.isArray(meta?.by_currency) ? meta.by_currency : [];
        return { rows, total: rows.length, covered: rows.filter(row => row.external_wallets_covered === true).length };
    }
    function deltaInfo(current, previous, lowerIsBetter = false) {
        const cur = num(current), prev = num(previous);
        if (prev === 0) {
            if (cur === 0) return { text: 'بدون تغير', kind: 'neutral' };
            return { text: 'جديد مقابل صفر', kind: lowerIsBetter ? 'bad' : 'neutral' };
        }
        const pct = ((cur - prev) / Math.abs(prev)) * 100;
        const direction = pct > 0 ? 1 : (pct < 0 ? -1 : 0);
        let kind = 'neutral';
        if (lowerIsBetter && direction !== 0) kind = direction < 0 ? 'good' : 'bad';
        return { text: `${direction > 0 ? '↑' : (direction < 0 ? '↓' : '→')} ${Math.abs(pct).toFixed(1)}%`, kind };
    }
    function trendUnavailable(id, label = 'المقارنة غير متاحة') {
        set(`${id}-current`, '—'); set(`${id}-previous`, label);
        const pill = $(`${id}-delta`); if (pill) { pill.className = 'ari-delta neutral'; pill.textContent = 'غير متاح'; }
        const bar = $(`${id}-bar`); if (bar) bar.style.width = '0%';
    }
    function trendCard(id, current, previous, options = {}) {
        set(`${id}-current`, fmt(current));
        set(`${id}-previous`, `السابق: ${fmt(previous)}`);
        const delta = deltaInfo(current, previous, options.lowerIsBetter === true);
        const pill = $(`${id}-delta`); if (pill) { pill.className = `ari-delta ${delta.kind}`; pill.textContent = delta.text; }
        const bar = $(`${id}-bar`);
        if (bar) {
            const max = Math.max(Math.abs(num(current)), Math.abs(num(previous)), 1);
            bar.style.width = `${Math.min(100, Math.max(num(current) === 0 ? 0 : 4, (Math.abs(num(current)) / max) * 100))}%`;
        }
    }

    function renderVolume(meta) {
        const rows = Array.isArray(meta.by_currency) ? meta.by_currency : [];
        set('ari-kpi-transactions', fmt(transactionEntries(meta)));
        set('ari-kpi-transactions-note', rows.length ? `${rows.length} عملة ممثلة دون جمع قيمها` : 'لا توجد حركة في الفترة');
        const node = $('ari-volume-list');
        if (node) node.innerHTML = rows.length ? rows.map(row => `<div class="ari-row"><div><div class="ari-row-title">${esc(row.currency || '—')}</div><div class="ari-row-sub">${esc(fmt(row.original_entries || 0))} أصلية · ${esc(fmt(row.reversal_entries || 0))} عكسية</div></div><div class="ari-row-value">${esc(fmt(row.gross_original_volume || 0))}</div></div>`).join('') : '<div class="text-muted small">لا توجد معاملات في الفترة.</div>';
    }
    function renderLiquidity(meta) {
        const { rows, total, covered } = liquidityCoverage(meta);
        const gaps = total - covered;
        set('ari-kpi-liquidity', total ? `${covered}/${total}` : '—');
        set('ari-kpi-liquidity-note', total ? (gaps ? `${gaps} عملة بها فجوة تغطية` : 'كل العملات الممثلة مغطاة') : 'لا توجد بيانات سيولة');
        status('ari-finance-state', gaps ? 'توجد فجوات' : (total ? 'مغطى' : 'لا بيانات'), gaps ? 'bad' : (total ? 'good' : 'neutral'));
        const node = $('ari-liquidity-list');
        if (node) node.innerHTML = rows.length ? rows.map(row => `<div class="ari-row"><div><div class="ari-row-title">${esc(row.currency || '—')}</div><div class="ari-row-sub">التغطية ${esc(fmt(row.external_wallet_coverage_ratio ?? '—'))}%</div></div><span class="ari-status ${row.external_wallets_covered ? 'good' : 'bad'}">${row.external_wallets_covered ? 'مغطاة' : 'فجوة'}</span></div>`).join('') : '<div class="text-muted small">لا توجد أرصدة سيولة.</div>';
    }
    function renderExceptions(meta) {
        const pending = num(meta?.pending_transfers?.overdue_holding), rejected = num(meta?.rejections?.count), total = pending + rejected;
        set('ari-ex-pending', fmt(pending)); set('ari-ex-rejected', fmt(rejected)); set('ari-ex-total', fmt(total));
        status('ari-exception-state', total ? 'تحقيق مطلوب' : 'مستقر', total ? 'bad' : 'good');
    }
    function renderMerchant(meta) {
        set('ari-merchant-total', fmt(meta.total)); set('ari-merchant-verified', fmt(meta.verified)); set('ari-merchant-expired', fmt(meta.expired)); set('ari-merchant-expiring', fmt(meta.expiring_30d));
        const total = num(meta.total), verified = num(meta.verified);
        status('ari-merchant-state', total ? `${Math.round((verified / total) * 100)}% موثق` : 'لا بيانات', total && verified < total ? 'warn' : 'good');
    }
    function renderKyc(meta) {
        const customer = meta.customers || {}, merchant = meta.merchants || {}, aging = merchant.pending_aging || {};
        set('ari-kyc-verified', fmt(customer.verified)); set('ari-kyc-total', fmt(customer.total)); set('ari-kyc-backlog', fmt(merchant.pending_backlog)); set('ari-kyc-aged', fmt(aging['8_plus_days'] || 0));
        status('ari-kyc-state', num(aging['8_plus_days']) > 0 ? 'يوجد تأخير' : 'ضمن المتابعة', num(aging['8_plus_days']) > 0 ? 'warn' : 'good');
    }
    function renderSupport(meta) {
        set('ari-support-open', fmt(meta.open_backlog)); set('ari-support-urgent', fmt(meta.urgent_backlog)); set('ari-support-unassigned', fmt(meta.unassigned_backlog));
        const sla = meta.sla_breach_rate_pct ?? meta.sla_breach_rate;
        set('ari-support-sla', sla === null || sla === undefined ? 'غير مفعّل' : `${fmt(sla)}%`);
        status('ari-support-state', num(meta.urgent_backlog) > 0 ? 'عاجل يحتاج متابعة' : 'مستقر', num(meta.urgent_backlog) > 0 ? 'warn' : 'good');
    }
    function renderAuth(meta) {
        set('ari-auth-attempts', fmt(meta.attempts)); set('ari-auth-failed', fmt(meta.failed)); set('ari-auth-rate', `${fmt(meta.success_rate_pct ?? 0)}%`); set('ari-auth-repeated', fmt(meta.repeated_failure_sources));
        const attention = num(meta.repeated_failure_sources) > 0 || num(meta.temporary_lockouts) > 0;
        status('ari-auth-state', attention ? 'مؤشرات تستحق المراجعة' : 'مستقر', attention ? 'warn' : 'good');
    }
    function renderHealth(meta) {
        const stale = meta.heartbeat_stale === true, components = Array.isArray(meta.components) ? meta.components : [];
        const down = components.filter(component => component.latest_state === 'down').length;
        status('ari-health-state', stale ? 'نبض متأخر' : (down ? 'مكون متوقف' : 'نبض سليم'), stale || down ? 'bad' : 'good');
        set('ari-health-heartbeat', stale ? 'متأخر/مفقود' : 'سليم'); set('ari-health-components', fmt(components.length)); set('ari-health-down', fmt(down)); set('ari-health-last', meta.last_heartbeat_at || '—');
    }

    function buildActions() {
        const actions = [];
        state.currentFailures.forEach(name => actions.push({ severity:'warn', icon:'?', title:`تعذر قراءة ${name}`, sub:'المصدر غير متاح في آخر تحديث؛ لا يتم تحويل غيابه إلى صفر.', report: name }));

        const { rows: liquidityRows } = liquidityCoverage(state.current.liquidity || {});
        const gaps = liquidityRows.filter(row => row.external_wallets_covered !== true);
        if (gaps.length) actions.push({ severity:'bad', icon:'!', title:`فجوة سيولة في ${gaps.length} عملة`, sub:'افتح مركز السيولة قبل أي قرار تشغيلي متعلق بالأموال.', report:'liquidity' });

        const exceptions = state.current['transaction-exceptions'] || {};
        const overdue = num(exceptions?.pending_transfers?.overdue_holding), rejected = num(exceptions?.rejections?.count);
        if (overdue || rejected) actions.push({ severity:'bad', icon:'!', title:`${overdue + rejected} استثناء مالي يحتاج تحقيقاً`, sub:`${overdue} معلّقة متأخرة · ${rejected} مرفوضة/فاشلة.`, report:'transaction-exceptions' });

        const merchant = state.current['merchant-portfolio'] || {};
        if (num(merchant.expired) > 0) actions.push({ severity:'warn', icon:'↗', title:`${fmt(merchant.expired)} اشتراك تاجر منتهي`, sub:'راجع حالة الاشتراكات قبل استمرار مزايا الباقات المدفوعة.', report:'merchant-portfolio' });
        else if (num(merchant.expiring_30d) > 0) actions.push({ severity:'info', icon:'i', title:`${fmt(merchant.expiring_30d)} اشتراك ينتهي خلال 30 يوماً`, sub:'إشارة استباقية للتجديد وليست مشكلة مالية بحد ذاتها.', report:'subscriptions' });

        const kyc = state.current['kyc-pipeline'] || {}, aged = num(kyc?.merchants?.pending_aging?.['8_plus_days']);
        if (aged > 0) actions.push({ severity:'warn', icon:'!', title:`${aged} طلب تحقق متأخر 8 أيام أو أكثر`, sub:'تراكم التحقق يحتاج معالجة قبل أن يتحول إلى عنق زجاجة.', report:'kyc-pipeline' });

        const support = state.current['support-operations'] || {};
        if (num(support.urgent_backlog) > 0) actions.push({ severity:'warn', icon:'!', title:`${fmt(support.urgent_backlog)} تذكرة دعم عاجلة مفتوحة`, sub:`غير المسندة حالياً: ${fmt(support.unassigned_backlog || 0)}.`, report:'support-operations' });
        const sla = support.sla_breach_rate_pct ?? support.sla_breach_rate;
        if (sla !== null && sla !== undefined && num(sla) > 0) actions.push({ severity:'warn', icon:'%', title:`خرق SLA بنسبة ${fmt(sla)}%`, sub:'النسبة تظهر فقط عند وجود سياسة SLA رسمية مضبوطة.', report:'support-operations' });

        const auth = state.current['auth-security'] || {};
        if (num(auth.repeated_failure_sources) > 0 || num(auth.temporary_lockouts) > 0) actions.push({ severity:'warn', icon:'🔒', title:'نشاط مصادقة يحتاج مراجعة', sub:`مصادر فشل متكرر: ${fmt(auth.repeated_failure_sources || 0)} · أقفال مؤقتة: ${fmt(auth.temporary_lockouts || 0)}.`, report:'auth-security' });

        const health = state.current['system-health-history'] || {}, components = Array.isArray(health.components) ? health.components : [];
        if (health.heartbeat_stale === true) actions.push({ severity:'bad', icon:'!', title:'نبض المراقبة متأخر أو مفقود', sub:`آخر نبضة: ${health.last_heartbeat_at || 'غير متوفرة'}.`, report:'system-health-history' });
        const down = components.filter(component => component.latest_state === 'down');
        if (down.length) actions.push({ severity:'bad', icon:'×', title:`${down.length} مكوّن بحالة متوقف`, sub:'الحالة مرصودة من سجل الصحة ولا يتم استنتاج سبب العطل.', report:'system-health-history' });

        set('ari-kpi-actions', fmt(actions.length));
        set('ari-kpi-actions-note', actions.length ? 'إشارات مجمعة من المصادر الرقابية الحية' : 'لا توجد إشارات تدخل ضمن المؤشرات المراقبة');
        const node = $('ari-actions');
        if (!node) return;
        node.innerHTML = actions.length ? actions.map(action => `<div class="ari-action"><div class="ari-action-icon ${action.severity}">${esc(action.icon)}</div><div><div class="ari-action-title">${esc(action.title)}</div><div class="ari-action-sub">${esc(action.sub)}</div></div><button type="button" class="ari-btn ari-btn-soft ari-btn-sm" data-open-report="${esc(action.report)}">فتح التقرير</button></div>`).join('') : '<div class="ari-action-empty">لا توجد حالياً إشارات تدخل ضمن المؤشرات التي تغطيها هذه اللوحة. هذا لا يعني أن كل أنظمة المنصة خالية من المخاطر.</div>';
        node.querySelectorAll('[data-open-report]').forEach(button => button.addEventListener('click', () => openReport(button.dataset.openReport)));
    }

    function renderTrends() {
        const specs = [
            ['ari-trend-transactions','transaction-volume',transactionEntries,false],
            ['ari-trend-exceptions','transaction-exceptions',exceptionCount,true],
            ['ari-trend-support','support-operations',meta => num(meta?.created_in_period),false],
            ['ari-trend-auth','auth-security',meta => num(meta?.failed),true]
        ];
        specs.forEach(([id, name, extractor, lowerIsBetter]) => {
            if (state.currentFailures.has(name) || state.previousFailures.has(name) || !state.current[name] || !state.previous[name]) {
                trendUnavailable(id, state.previousFailures.has(name) ? 'الفترة السابقة غير متاحة' : 'المصدر غير متاح');
                return;
            }
            trendCard(id, extractor(state.current[name]), extractor(state.previous[name]), { lowerIsBetter });
        });
        const periods = selectedPeriods();
        set('ari-previous-period', periods.valid ? `${periods.previous.from} → ${periods.previous.to}` : 'الفترة غير صالحة');
    }

    async function loadExecutive() {
        const periods = selectedPeriods();
        const refresh = $('ari-refresh'), refreshState = $('ari-refresh-state');
        if (!periods.valid) {
            if (refreshState) refreshState.textContent = 'تحقق من ترتيب تاريخ البداية والنهاية';
            set('ari-previous-period', 'الفترة غير صالحة');
            return;
        }
        if (refresh) refresh.disabled = true;
        if (refreshState) refreshState.innerHTML = `${loading()}<span class="ms-2">قراءة المصادر ومقارنة الفترة السابقة</span>`;
        state.current = {}; state.previous = {}; state.currentFailures = new Set(); state.previousFailures = new Set();

        const currentNames = ['transaction-volume','liquidity','transaction-exceptions','merchant-portfolio','kyc-pipeline','support-operations','auth-security','system-health-history'];
        const previousNames = ['transaction-volume','transaction-exceptions','support-operations','auth-security'];
        const currentResults = await Promise.allSettled(currentNames.map(async name => { state.current[name] = await fetchMeta(name, 'current'); }));
        currentResults.forEach((result, index) => { if (result.status === 'rejected') state.currentFailures.add(currentNames[index]); });
        const previousResults = await Promise.allSettled(previousNames.map(async name => { state.previous[name] = await fetchMeta(name, 'previous'); }));
        previousResults.forEach((result, index) => { if (result.status === 'rejected') state.previousFailures.add(previousNames[index]); });

        if (state.current['transaction-volume']) renderVolume(state.current['transaction-volume']);
        if (state.current.liquidity) renderLiquidity(state.current.liquidity);
        if (state.current['transaction-exceptions']) renderExceptions(state.current['transaction-exceptions']);
        if (state.current['merchant-portfolio']) renderMerchant(state.current['merchant-portfolio']);
        if (state.current['kyc-pipeline']) renderKyc(state.current['kyc-pipeline']);
        if (state.current['support-operations']) renderSupport(state.current['support-operations']);
        if (state.current['auth-security']) renderAuth(state.current['auth-security']);
        if (state.current['system-health-history']) renderHealth(state.current['system-health-history']);
        renderTrends(); buildActions();

        const failures = state.currentFailures.size + state.previousFailures.size;
        if (refreshState) refreshState.textContent = failures ? `اكتمل مع ${failures} مصدر/مقارنة غير متاحة` : `محدث · ${new Date().toLocaleTimeString('ar-SA', {hour:'2-digit', minute:'2-digit'})}`;
        if (refresh) refresh.disabled = false;
        ['ari-finance-state','ari-exception-state','ari-merchant-state','ari-kyc-state','ari-support-state','ari-auth-state','ari-health-state'].forEach(id => {
            const node = $(id); if (node && node.textContent === 'تحميل') status(id, 'غير متاح', 'warn');
        });
    }

    function scalarEntries(object) {
        return Object.entries(object || {}).filter(([, value]) => value === null || ['string','number','boolean'].includes(typeof value)).slice(0, 18);
    }
    function genericRender(meta) {
        let html = '';
        const scalars = scalarEntries(meta);
        if (scalars.length) html += `<div class="ari-metrics">${scalars.map(([key,value]) => `<div class="ari-metric"><div class="k">${esc(key.replaceAll('_',' '))}</div><div class="v">${esc(typeof value === 'boolean' ? (value ? 'نعم' : 'لا') : fmt(value))}</div></div>`).join('')}</div>`;
        Object.entries(meta || {}).filter(([, value]) => Array.isArray(value) && value.length).slice(0, 5).forEach(([key, rows]) => {
            if (typeof rows[0] !== 'object' || Array.isArray(rows[0])) return;
            const heads = Object.keys(rows[0]).slice(0, 8);
            html += `<div class="ari-subsection"><h6>${esc(key.replaceAll('_',' '))}</h6><div class="ari-table-wrap"><table class="ari-table"><thead><tr>${heads.map(head => `<th>${esc(head.replaceAll('_',' '))}</th>`).join('')}</tr></thead><tbody>${rows.slice(0, 30).map(row => `<tr>${heads.map(head => `<td>${esc(typeof row[head] === 'object' ? JSON.stringify(row[head]) : fmt(row[head]))}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>`;
        });
        Object.entries(meta || {}).filter(([, value]) => value && typeof value === 'object' && !Array.isArray(value)).slice(0, 5).forEach(([key, value]) => {
            const entries = scalarEntries(value); if (!entries.length) return;
            html += `<div class="ari-subsection"><h6>${esc(key.replaceAll('_',' '))}</h6><div class="ari-metrics">${entries.map(([subKey,subValue]) => `<div class="ari-metric"><div class="k">${esc(subKey.replaceAll('_',' '))}</div><div class="v">${esc(typeof subValue === 'boolean' ? (subValue ? 'نعم' : 'لا') : fmt(subValue))}</div></div>`).join('')}</div></div>`;
        });
        return html || '<div class="ari-result-empty">أعاد المصدر تقريراً بلا حقول مناسبة للعرض المختصر. استخدم العرض التشغيلي التفصيلي.</div>';
    }
    async function openReport(name) {
        if (!reportNames.has(name)) return;
        const result = $('ari-explorer-result');
        document.querySelectorAll('.ari-report-btn').forEach(button => button.classList.toggle('active', button.dataset.report === name));
        status('ari-explorer-state', 'تحميل', 'neutral');
        if (result) result.innerHTML = `<div class="ari-result-empty">${loading()}<div class="mt-2">جارٍ قراءة التقرير من مصدر الحقيقة…</div></div>`;
        $('ari-explorer')?.scrollIntoView({behavior:'smooth', block:'start'});
        try {
            const meta = await fetchMeta(name, 'current');
            if (result) result.innerHTML = genericRender(meta);
            status('ari-explorer-state', 'تم التحديث', 'good');
        } catch (error) {
            if (result) result.innerHTML = `<div class="ari-result-empty text-danger">تعذر تحميل التقرير: ${esc(error.message)}</div>`;
            status('ari-explorer-state', 'خطأ', 'bad');
        }
    }

    document.querySelectorAll('.ari-report-btn').forEach(button => button.addEventListener('click', () => openReport(button.dataset.report)));
    $('ari-refresh')?.addEventListener('click', loadExecutive);
    ['ari-from','ari-to'].forEach(id => $(id)?.addEventListener('change', () => {
        status('ari-explorer-state', 'الفترة تغيرت', 'neutral');
        const periods = selectedPeriods(); set('ari-previous-period', periods.valid ? `${periods.previous.from} → ${periods.previous.to}` : 'الفترة غير صالحة');
    }));
    loadExecutive();
})();
