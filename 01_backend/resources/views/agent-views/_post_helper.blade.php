@once
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
window.__amialPost = window.__amialPost || (function () {
    const keys = new Map();
    const inflight = new Set();
    const uuid = () => (window.crypto && typeof crypto.randomUUID === 'function')
        ? crypto.randomUUID()
        : 'amial-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);

    return async function (base, path, body, csrf, opts) {
        opts = opts || {};
        const payload = JSON.stringify(body || {});
        const op = base + path + '|' + payload;

        if (inflight.has(op)) {
            if (opts.throwOnError) {
                throw new Error('الطلب قيد التنفيذ — انتظر لحظة');
            }
            return {success: false, message: 'الطلب قيد التنفيذ — انتظر لحظة'};
        }

        let key = keys.get(op);
        if (!key) {
            key = uuid();
            keys.set(op, key);
        }

        const active = document.activeElement;
        const btn = (active && (active.tagName === 'BUTTON' || (active.tagName === 'INPUT' && active.type === 'submit')))
            ? active
            : null;
        const prevDisabled = btn ? btn.disabled : false;
        if (btn) {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
        }

        inflight.add(op);
        try {
            const r = await fetch(base + path, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Idempotency-Key': key,
                },
                body: payload,
            });
            if (r.status === 401) {
                window.location.assign(base + '/login');
            }
            let j;
            try {
                j = await r.json();
            } catch {
                j = null;
            }
            if (!j || typeof j !== 'object' || r.status >= 500 || j.code === 'IDEMPOTENCY_IN_PROGRESS') {
                const message = 'نتيجة العملية غير مؤكدة. أعد التحقق من العملية نفسها قبل تسليم النقد.';
                if (opts.throwOnError) throw new Error(message);
                return {success: false, pending: true, message};
            }
            keys.delete(op);
            if (opts.throwOnError && (!r.ok || (opts.checkSuccess && j.success === false))) {
                throw new Error(j.message || opts.fallbackError || ('خطأ ' + r.status));
            }
            return j;
        } finally {
            inflight.delete(op);
            if (btn) {
                btn.disabled = prevDisabled;
                btn.removeAttribute('aria-busy');
            }
        }
    };
})();
</script>
@endonce
