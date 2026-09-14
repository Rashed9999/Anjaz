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
    const state = { current: {}, previous: {}, failed: new Set() };
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
    const set = (id, value) => { const n = $(id); if (n) n.textContent = value ?? '—'; };
    const status = (id, text, kind = 'neutral') => { const n = $(id); if (!n) return; n.className = `ari-status ${kind}`; n.textContent = text; };

    function parseDate(value) {
        const d = new Date(`${value}T00:00:00Z`);
        return Number.isNaN(d.getTime()) ? null : d;
    }
    function ymd(date) { return date.toISOString().slice(0, 10); }
    function selectedPeriods() {
        const from = $('ari-from')?.value;
        const to = $('ari-to')?.value;
        const f = parseDate(from), t = parseDate(to);
        if (!f || !t || t < f) return { current: { from, to }, previous: { from: null, to: null }, days: 0 };
        const days = Math.floor((t - f) / 86400000) + 1;
        const prevTo = new Date(f.getTime() - 86400000);
        const prevFrom = new Date(prevTo.getTime() - ((days - 1) * 86400000));
        return { current: { from: ymd(f), to: ymd(t) }, previous: { from: ymd(prevFrom), to: ymd(prevTo) }, days };
    }
    function queryFor(name, range = 'current') {
        const periods = selectedPeriods();
        const selected = periods[range] || periods.current;
        const q = new URLSearchParams();
        if (name === 'agent-liquidity') q.set('date', selected.to || periods.current.to || '');
        else if (asOfReports.has(name)) q.set('as_of', selected.to || periods.current.to || '');
        else if (!noPeriodReports.has(name)) {
            if (selected.from) q.set('from', selected.from);
            if (selected.to) q.set('to', selected.to);
        }
        return q.toString();
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
        return body.meta || {};
    }

    function transactionEntries(meta) {
        return (Array.isArray(meta?.by_currency) ? meta.by_currency : []).reduce((sum, row) => sum + num(row.original_entries), 0);
    }
    function exceptionCount(meta) {
        return num(meta?.pending_transfers?.overdue_holding) + num(meta?.rejections?.count);
    }
    function ratioCovered(meta) {
        const rows = Array.isArray(meta?.by_currency) ? meta.by_currency : [];
        return { total: rows.length, covered: rows.filter(row => row.external_wallets_covered === true).length };
    }
    function deltaInfo(current, previous, lowerIsBetter = false) {
        const cur = num(current), prev = num(previous);
        if (prev === 0) {
            if (cur === 0) return { text: 'بدون تغير', kind: 'neutral', pct: 0, direction: 0 };
            return { text: 'جديد مقابل صفر', kind: lowerIsBetter ? 'bad' : 'neutral', pct: null, direction: 1 };
        }
        const pct = ((cur - prev) / Math.abs(prev)) * 100;
        const direction = pct > 0 ? 1 : (pct < 0 ? -1 : 0);
        let kind = 'neutral';
        if (lowerIsBetter && direction !== 0) kind = direction < 0 ? 'good' : 'bad';
        const arrow = direction > 0 ? '↑' : (direction < 0 ? '↓' : '→');
        return { text: `${arrow} ${Math.abs(pct).toFixed(1)}%`, kind, pct, direction };
    }
    function trendCard(id, current, previous, options = {}) {
        set(`${id}-current`, fmt(current));
        set(`${id}-previous`, `السابق: ${fmt(previous)}`);
        const d = deltaInfo(current, previous, options.lowerIsBetter === true);
        const pill = $(`${id}-delta`);
        if (pill) { pill.className = `ari-delta ${d.kind}`; pill.textContent = d.text; }
        const bar = $(`${id}-bar`);
        if (bar) {
            const max = Math.max(Math.abs(num(current)), Math.abs(num(previous)), 1);
            const width = Math.min(100, Math.max(4, (Math.abs(num(current)) / max) * 100));
            bar.style.width = `${width}%`;
        }
    }

    function renderVolume(meta) {
        const rows = Array.isArray(meta.by_currency) ? meta.by_currency : [];
        const entries = transactionEntries(meta);
        set('ari-kpi-transactions', fmt(entries));
        set('ari-kpi-transactions-note', rows.length ? `${rows.length} عملة ممثلة دون جمع قيمها` : 'لا توجد حركة في الفترة');
        const node = $('ari-volume-list');
        if (node) node.innerHTML = rows.length ? rows.map(row => `
            <div class="ari-row"><div><div class="ari-row-title">${esc(row.currency || '—')}</div><div class="ari-row-sub">${esc(fmt(row.original_entries || 0))} أصلية · ${esc(fmt(row.reversal_entries || 0))} عكسية</div></div><div class="ari-row-value">${esc(fmt(row.gross_original_volume || 0))}</div></div>`).join('') : '<div class="text-muted small">لا توجد معاملات في الفترة.</div>';
    }
    function renderLiquidity(meta) {
        const rows = Array.isArray(meta.by_currency) ? meta.by_currency : [];
        const { total, covered } = ratioCovered(meta);
        const gaps = total - covered;
        set('ari-kpi-liquidity', total ? `${covered}/${total}` : '—');
        set('ari-kpi-liquidity-note', total ? (gaps ? `${gaps} عملة بها فجوة تغطية` : 'كل العملات الممثلة مغطاة') : 'لا توجد بيانات سيولة');
        status('ari-finance-state', gaps ? 'توجد فجوات' : (total ? 'مغطى' : 'لا بيانات'), gaps ? 'bad' : (total ? 'good' : 'neutral'));
        const node = $('ari-liquidity-list');
        if (node) node.innerHTML = rows.length ? rows.map(row => `
            <div class="ari-row"><div><div class="ari-row-title">${esc(row.currency || '—')}</div><div class="ari-row-sub">التغطية ${esc(fmt(row.external_wallet_coverage_ratio ?? '—'))}%</div></div><span class="ari-status ${row.external_wallets_covered ? 'good' : 'bad'}">${row.external_wallets_covered ? 'مغطاة' : 'فجوة'}</span></div>`).join('') : '<div class="text-muted small">لا توجد أرصدة سيولة.</div>';
    }
    function renderExceptions(meta) {
        const pending = num(meta?.pending_transfers?.overdue_holding);
        const rejected = num(meta?.rejections?.count);
        const total = pending + rejected;
        set('ari-ex-pending', fmt(pending)); set('ari-ex-rejected', fmt(rejected)); set('ari-ex-total', fmt(total));
        set('ari-kpi-actions', fmt(total));
        set('ari-kpi-actions-note', total ? 'إشارات مالية أولية قبل بقية الرقابة' : 'لا توجد استثناءات مالية حرجة ضمن الفترة');
        status('ari-exception-state', total ? 'تحقيق مطلوب' : 'مستقر', total ? 'bad' : 'good');
    }
    function renderMerchant(meta) {
        set('ari-merchant-total', fmt(meta.total)); set('ari-merchant-verified', fmt(meta.verified)); set('ari-merchant-expired', fmt(meta.expired)); set('ari-merchant-expiring', fmt(meta.expiring_30d));
        const total = num(meta.total), verified = num(meta.verified);
        status('ari-merchant-state', total ? `${Math.round((verified / total) * 100)}% موثق` : 'لا بيانات', total && verified < total ? 'warn' : 'good');
    }
    function renderKyc(meta) {
        const c = meta.customers || {}, m = meta.merchants || {}, a = m.pending_aging || {};
        set('ari-kyc-verified', fmt(c.verified)); set('ari-kyc-total', fmt(c.total)); set('ari-kyc-backlog', fmt(m.pending_backlog)); set('ari-kyc-aged', fmt(a['8_plus_days'] || 0));
        status('ari-kyc-state', num(a['8_plus_days']) > 0 ? 'يوجد تأخير' : 'ضمن المتابعة', num(a['8_plus_days']) > 0 ? 'warn' : 'good');
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
        const stale = meta.heartbeat_stale === true;
        const components = Array.isArray(meta.components) ? meta.components : [];
        status('ari-health-state', stale ? 'نبض متأخر' : 'نبض سليم', stale ? 'bad' : 'good');
        set('ari-health-heartbeat', stale ? 'متأخر/مفقود' : 'سليم'); set('ari-health-components', fmt(components.length));
        const down = components.filter(c => c.latest_state === 'down').length;
        set('ari-health-down', fmt(down)); set('ari-health-last', meta.last_heartbeat_at || '—');
    }

    function buildActions() {
        const actions = [];
        const liquidity = state.current['liquidity'] || {};
        const liquidityRows = Array.isArray(liquidity.by_currency) ? liquidity.by_currency : [];
        const gaps = liquidityRows.filter(row => row.external_wallets_covered !== true);
        if (gaps.length) actions.push({severity:'bad', icon:'!', title:`فجوة سيولة في ${gaps.length} عملة`, sub:'افتح مركز السيولة قبل أي قرار تشغيلي متعلق بالأموال.', report:'liquidity'});

        const ex = state.current['transaction-exceptions'] || {};
        const overdue = num(ex?.pending_transfers?.overdue_holding), rejected = num(ex?.rejections?.count);
        if (overdue || rejected) actions.push({severity:'bad', icon:'!', title:`${overdue + rejected} استثناء مالي يحتاج تحقيقاً`, sub:`${overdue} معلّقة متأخرة · ${rejected} مرفوضة/فاشلة.`, report:'transaction-exceptions'});

        const merchant = state.current['merchant-portfolio'] || {};
        if (num(merchant.expired) > 0) actions.push({severity:'warn', icon:'↗', title:`${fmt(merchant.expired)} اشتراك تاجر منتهي`, sub:'راجع حالة الاشتراكات قبل استمرار مزايا الباقات المدفوعة.', report:'merchant-portfolio'});
        else if (num(merchant.expiring_30d) > 0) actions.push({severity:'info', icon:'i', title:`${fmt(merchant.expiring_30d)} اشتراك ينتهي خلال 30 يوماً`, sub:'إشارة استباقية للتجديد وليست مشكلة مالية بحد ذاتها.', report:'subscriptions'});

        const kyc = state.current['kyc-pipeline'] || {}, aged = num(kyc?.merchants?.pending_aging?.['8_plus_days']);
        if (aged > 0) actions.push({severity:'warn', icon:'!', title:`${aged} طلب تحقق متأخر 8 أيام أو أكثر`, sub:'تراكم التحقق يحتاج معالجة قبل أن يتحول إلى عنق زجاجة.', report:'kyc-pipeline'});

        const support = state.current['support-operations'] || {};
        if (num(support.urgent_backlog) > 0) actions.push({severity:'warn', icon:'!', title:`${fmt(support.urgent_backlog)} تذكرة دعم عاجلة مفتوحة`, sub:`غير المسندة حالياً: ${fmt(support.unassigned_backlog || 0)}.`, report:'support-operations'});
        const sla = support.sla_breach_rate_pct ?? support.sla_breach_rate;
        if (sla !== null && sla !== undefined && num(sla) > 0) actions.push({severity:'warn', icon:'%', title:`خرق SLA بنسبة ${fmt(sla)}%`, sub:'النسبة تظهر فقط لأن سياسة SLA الرسمية مضبوطة في الخادم.', report:'support-operations'});

        const auth = state.current['auth-security'] || {};
        if (num(auth.repeated_failure_sources) > 0 || num(auth.temporary_lockouts) > 0) actions.push({severity:'warn', icon:'🔒', title:'نشاط مصادقة يحتاج مراجعة', sub:`مصادر فشل متكرر: ${fmt(auth.repeated_failure_sources || 0)} · أقفال مؤقتة: ${fmt(auth.temporary_lockouts || 0)}.`, report:'auth-security'});

        const health = state.current['system-health-history'] || {};
        if (health.heartbeat_stale === true) actions.push({severity:'bad', icon:'!', title:'نبض المراقبة متأخر أو مفقود', sub:`آخر نبضة: ${health.last_heartbeat_at || 'غير متوفرة'}.`, report:'system-health-history'});
        const down = (Array.isArray(health.components) ? health.components : []).filter(c => c.latest_state === 'down');
        if (down.length) actions.push({severity:'bad', icon:'×', title:`${down.length} مكوّن بحالة متوقف`, sub:'اللوحة تعرض الحالة المرصودة فقط ولا تستنتج سبب العطل.', report:'system-health-history'});

        const node = $('ari-actions');
        if (!node) return;
        set('ari-kpi-actions', fmt(actions.length));
        set('ari-kpi-actions-note', actions.length ? 'إشارات مجمعة من المصادر الرقابية الحية' : 'لا توجد إشارات تدخل ضمن المؤشرات المراقبة');
        node.innerHTML = actions.length ? actions.map(action => `
            <div class="ari-action">
                <div class="ari-action-icon ${action.severity}">${esc(action.icon)}</div>
                <div><div class="ari-action-title">${esc(action.title)}</div><div class="ari-action-sub">${esc(action.sub)}</div></div>
                <button type="button" class="ari-btn ari-btn-soft ari-btn-sm" data-open-report="${esc(action.report)}">فتح التقرير</button>
            </div>`).join('') : '<div class="ari-action-empty">لا توجد حالياً إشارات تدخل ضمن المؤشرات التي تغطيها هذه اللوحة. هذا لا يعني أن كل أنظمة المنصة خالية من المخاطر.</div>';
        node.querySelectorAll('[data-open-report]').forEach(button => button.addEventListener('click', () => openReport(button.dataset.openReport)));
    }

    function renderTrends() {
        trendCard('ari-trend-transactions', transactionEntries(state.current['transaction-volume']), transactionEntries(state.previous['transaction-volume']));
        trendCard('ari-trend-exceptions', exceptionCount(state.current['transaction-exceptions']), exceptionCount(state.previous['transaction-exceptions']), { lowerIsBetter: true });
        trendCard('ari-trend-support', num(state.current['support-operations']?.created_in_period), num(state.previous['support-operations']?.created_in_period));
        trendCard('ari-trend-auth', num(state.current['auth-security']?.failed), num(state.previous['auth-security']?.failed), { lowerIsBetter: true });
        const periods = selectedPeriods();
        set('ari-previous-period', periods.previous.from && periods.previous.to ? `${periods.previous.from} → ${periods.previous.to}` : '—');
    }

    async function loadExecutive() {
        const refresh = $('ari-refresh');
        if (refresh) refresh.disabled = true;
        const refreshState = $('ari-refresh-state');
        if (refreshState) refreshState.innerHTML = `${loading()}<span class="ms-2">قراءة المصادر ومقارنة الفترة السابقة</span>`;
        state.current = {}; state.previous = {}; state.failed = new Set();

        const currentNames = ['transaction-volume','liquidity','transaction-exceptions','merchant-portfolio','kyc-pipeline','support-operations','auth-security','system-health-history'];
        const previousNames = ['transaction-volume','transaction-exceptions','support-operations','auth-security'];
        const currentResults = await Promise.allSettled(currentNames.map(async name => {
            const meta = await fetchMeta(name, 'current');
            if (meta.available === false) throw new Error(meta.reason || 'source_unavailable');
            state.current[name] = meta;
        }));
        currentResults.forEach((result, index) => { if (result.status === 'rejected') state.failed.add(currentNames[index]); });

        const previousResults = await Promise.allSettled(previousNames.map(async name => {
            const meta = await fetchMeta(name, 'previous');
            if (meta.available === false) throw new Error(meta.reason || 'source_unavailable');
            state.previous[name] = meta;
        }));
        previousResults.forEach((result, index) => { if (result.status === 'rejected') state.failed.add(`${previousNames[index]}:previous`); });

        if (state.current['transaction-volume']) renderVolume(state.current['transaction-volume']);
        if (state.current['liquidity']) renderLiquidity(state.current['liquidity']);
        if (state.current['transaction-exceptions']) renderExceptions(state.current['transaction-exceptions']);
        if (state.current['merchant-portfolio']) renderMerchant(state.current['merchant-portfolio']);
        if (state.current['kyc-pipeline']) renderKyc(state.current['kyc-pipeline']);
        if (state.current['support-operations']) renderSupport(state.current['support-operations']);
        if (state.current['auth-security']) renderAuth(state.current['auth-security']);
        if (state.current['system-health-history']) renderHealth(state.current['system-health-history']);
        renderTrends(); buildActions();

        const failedCount = state.failed.size;
        if (refreshState) refreshState.textContent = failedCount ? `اكتمل مع ${failedCount} مصدر/مقارنة غير متاحة` : `محدث · ${new Date().toLocaleTimeString('ar-SA', {hour:'2-digit', minute:'2-digit'})}`;
        if (refresh) refresh.disabled = false;
        const monitoredStates = ['ari-finance-state','ari-exception-state','ari-merchant-state','ari-kyc-state','ari-support-state','ari-auth-state','ari-health-state'];
        monitoredStates.forEach(id => { const n = $(id); if (n && n.textContent === 'تحميل') status(id, 'غير متاح', 'warn'); });
    }

    function scalarEntries(object) {
        return Object.entries(object || {}).filter(([, value]) => value === null || ['string','number','boolean'].includes(typeof value)).slice(0, 18);
    }
    function genericRender(meta) {
        if (meta.available === false) return `<div class="ari-result-empty">المصدر غير متاح: ${esc(meta.reason || meta.source || 'غير محدد')}</div>`;
        let html = '';
        const scalars = scalarEntries(meta);
        if (scalars.length) html += `<div class="ari-metrics">${scalars.map(([key,value]) => `<div class="ari-metric"><div class="k">${esc(key.replaceAll('_',' '))}</div><div class="v">${esc(typeof value === 'boolean' ? (value ? 'نعم' : 'لا') : fmt(value))}</div></div>`).join('')}</div>`;
        Object.entries(meta || {}).filter(([, value]) => Array.isArray(value) && value.length).slice(0, 5).forEach(([key, rows]) => {
            if (typeof rows[0] !== 'object' || Array.isArray(rows[0])) return;
            const heads = Object.keys(rows[0]).slice(0, 8);
            html += `<div class="ari-subsection"><h6>${esc(key.replaceAll('_',' '))}</h6><div class="ari-table-wrap"><table class="ari-table"><thead><tr>${heads.map(h => `<th>${esc(h.replaceAll('_',' '))}</th>`).join('')}</tr></thead><tbody>${rows.slice(0, 30).map(row => `<tr>${heads.map(h => `<td>${esc(typeof row[h] === 'object' ? JSON.stringify(row[h]) : fmt(row[h]))}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>`;
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
        const p = selectedPeriods(); set('ari-previous-period', p.previous.from && p.previous.to ? `${p.previous.from} → ${p.previous.to}` : '—');
    }));
    loadExecutive();
})();
