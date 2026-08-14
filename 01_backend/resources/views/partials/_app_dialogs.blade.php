{{-- AMIAL-UI-DIALOGS-002 — **نافذةُ المشروع تحلّ محلّ نافذة المتصفّح كاملةً.**

     ══════════════════════════════════════════════════════════════════
     **الثمن الذي دُفع:** رأى صاحبُ المشروع نافذتين مختلفتين في عمليّةٍ
     واحدة: يضغط «فكّ التجميد» فتظهر **نافذةُ المتصفّح** البيضاء تسأل عن
     السبب («يعرض موقع amialpay.com»)، ثمّ بعد النجاح تظهر **نافذةُ
     المشروع** المصمَّمة. نافذتان في ضغطتين متتاليتين.

     والسببُ أنّ هذا الملفّ كان يستبدل `window.alert` وحدَه. أمّا
     `confirm` و`prompt` فبقيتا نافذتَي المتصفّح في **٦٣ موضعاً**.

     **ولمَ تُركتا:** `confirm` و`prompt` **متزامنتان** — تُوقفان الصفحة
     وتُرجعان قيمةً في الحال. ونافذةٌ مصمَّمةٌ لا تستطيع ذلك: لا سبيل في
     المتصفّح لإيقاف التنفيذ بانتظار ضغطة. فبديلُها **وعدٌ يُنتظر**،
     وذلك يعني تحويلَ كلّ نداءٍ من متزامنٍ إلى `await`.

     فبُني هنا العقدُ كاملاً — `alert` و`confirm` و`prompt` — وتُحوَّل
     المواضعُ إليه تباعاً. **والقديمُ يبقى عاملاً حتّى يُحوَّل آخرُها**:
     `window.confirm` و`window.prompt` لا تُستبدلان قسراً، فاستبدالٌ
     يُرجع وعداً مكان قيمةٍ يجعل كلَّ نداءٍ قديمٍ يقرأ «صحيح» دائماً —
     أي **زرَّ حذفٍ لا يسأل**. (وذاك أسوأ من نافذتين.)
     ══════════════════════════════════════════════════════════════════ --}}
<style>
    .amial-dialog-backdrop{position:fixed;inset:0;z-index:2147483000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(8,20,43,.62);backdrop-filter:blur(4px)}
    .amial-dialog-backdrop.is-open{display:flex}.amial-dialog{width:min(100%,460px);background:#fff;border-radius:22px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden;animation:amial-dialog-in .18s ease-out}
    .amial-dialog-head{display:flex;align-items:center;gap:11px;padding:20px 20px 13px}.amial-dialog-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:12px;background:#edf4ff;color:#0754c7;font-size:20px}.amial-dialog-title{margin:0;font-size:17px;font-weight:800;color:#15233a}.amial-dialog-close{margin-inline-start:auto;width:34px;height:34px;border:0;border-radius:9px;background:#f1f4f8;color:#526176;font-size:24px;line-height:1;cursor:pointer}.amial-dialog-close:hover{background:#e4eaf2;color:#14233b}
    .amial-dialog-body{padding:0 20px 20px;color:#516074;line-height:1.8;font-size:14px;white-space:pre-wrap}.amial-dialog-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 20px 20px;border-top:1px solid #edf0f5}.amial-dialog-ok{border:0;border-radius:10px;background:#0754c7;color:#fff;font:inherit;font-weight:700;padding:10px 24px;cursor:pointer}.amial-dialog-ok:hover{background:#0648ab}
    .amial-dialog-cancel{border:1px solid #d7dee8;border-radius:10px;background:#fff;color:#48566a;font:inherit;font-weight:700;padding:10px 20px;cursor:pointer}.amial-dialog-cancel:hover{background:#f3f6fa}
    .amial-dialog-ok.is-danger{background:#c62828}.amial-dialog-ok.is-danger:hover{background:#a71d1d}
    .amial-dialog-input{width:100%;margin-top:12px;padding:11px 13px;border:1px solid #d7dee8;border-radius:10px;font:inherit;color:#15233a}.amial-dialog-input:focus{outline:0;border-color:#0754c7;box-shadow:0 0 0 3px rgba(7,84,199,.12)}
    @keyframes amial-dialog-in{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
</style>
<div class="amial-dialog-backdrop" id="amial-dialog" aria-hidden="true" data-testid="amial-dialog">
    <section class="amial-dialog" role="alertdialog" aria-modal="true" aria-labelledby="amial-dialog-title" aria-describedby="amial-dialog-message">
        <header class="amial-dialog-head"><span class="amial-dialog-mark" id="amial-dialog-mark" aria-hidden="true">i</span><h2 class="amial-dialog-title" id="amial-dialog-title">رسالة من أميال باي</h2><button class="amial-dialog-close" type="button" aria-label="إغلاق" data-amial-dialog-dismiss>×</button></header>
        <div class="amial-dialog-body" id="amial-dialog-message"></div>
        <div style="padding:0 20px"><input class="amial-dialog-input" id="amial-dialog-input" style="display:none" data-testid="amial-dialog-input"></div>
        <footer class="amial-dialog-foot">
            <button class="amial-dialog-cancel" type="button" id="amial-dialog-cancel" style="display:none" data-amial-dialog-dismiss data-testid="amial-dialog-cancel">إلغاء</button>
            <button class="amial-dialog-ok" type="button" id="amial-dialog-ok" data-testid="amial-dialog-ok">حسناً</button>
        </footer>
    </section>
</div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const root = document.getElementById('amial-dialog');
    if (!root || window.__amialDialogsReady) return;
    window.__amialDialogsReady = true;

    const message = document.getElementById('amial-dialog-message');
    const titleEl = document.getElementById('amial-dialog-title');
    const markEl  = document.getElementById('amial-dialog-mark');
    const inputEl = document.getElementById('amial-dialog-input');
    const okEl    = document.getElementById('amial-dialog-ok');
    const cancelEl= document.getElementById('amial-dialog-cancel');

    // **قرارُ النافذة المفتوحة** — يُستدعى بالإغلاق أيّاً كان سببه.
    // وبلا هذا يبقى الوعدُ معلّقاً أبداً حين يضغط المستعمل «×» أو Esc،
    // فيتجمّد الزرُّ الذي ينتظره: لا رسالة، ولا فعل. (القاعدة التاسعة.)
    let settle = null;

    const close = (value) => {
        root.classList.remove('is-open');
        root.setAttribute('aria-hidden', 'true');
        const done = settle; settle = null;
        if (done) done(value);
    };

    const open = ({ text, title, kind, withInput, initial, okLabel, danger }) => {
        message.textContent = String(text ?? '');
        titleEl.textContent = title || 'رسالة من أميال باي';
        markEl.textContent  = kind === 'ask' ? '؟' : (danger ? '!' : 'i');

        inputEl.style.display = withInput ? 'block' : 'none';
        inputEl.value = withInput ? String(initial ?? '') : '';

        cancelEl.style.display = kind === 'tell' ? 'none' : 'inline-block';
        okEl.textContent = okLabel || 'حسناً';
        okEl.classList.toggle('is-danger', !!danger);

        root.classList.add('is-open');
        root.setAttribute('aria-hidden', 'false');
        (withInput ? inputEl : okEl).focus();
    };

    /** رسالةٌ تُقرأ — لا جواب لها. */
    const show = (value, title) => new Promise((resolve) => {
        settle = () => resolve();
        open({ text: value, title, kind: 'tell' });
    });

    /** سؤالُ نعم/لا — يُرجع `true` أو `false`. */
    const ask = (value, opts = {}) => new Promise((resolve) => {
        settle = (v) => resolve(v === true);
        open({ text: value, title: opts.title, kind: 'ask',
               okLabel: opts.okLabel || 'تأكيد', danger: opts.danger });
    });

    /**
     * سؤالٌ بجواب — يُرجع النصّ، أو `null` إن أُلغي.
     *
     * **والفراغُ ليس إلغاءً**: من ضغط «تأكيد» وترك الحقل فارغاً قصد
     * الفراغَ، ومن ضغط «إلغاء» قصد التراجع. وخلطُهما يجعل زرَّ الإلغاء
     * يُنفّذ العمليّة بسببٍ فارغ.
     */
    const request = (value, opts = {}) => new Promise((resolve) => {
        settle = (v) => resolve(v === true ? inputEl.value : null);
        open({ text: value, title: opts.title, kind: 'ask', withInput: true,
               initial: opts.initial, okLabel: opts.okLabel || 'تأكيد',
               danger: opts.danger });
    });

    okEl.addEventListener('click', () => close(true));
    root.querySelectorAll('[data-amial-dialog-dismiss]')
        .forEach(b => b.addEventListener('click', () => close(false)));
    root.addEventListener('click', e => { if (e.target === root) close(false); });
    document.addEventListener('keydown', e => {
        if (!root.classList.contains('is-open')) return;
        if (e.key === 'Escape') close(false);
        // Enter يؤكّد من داخل الحقل — وإلّا وجب على المستعمل أن يترك
        // لوحة المفاتيح ليضغط زرّاً.
        if (e.key === 'Enter' && document.activeElement === inputEl) close(true);
    });

    window.amialDialog = { show, ask, request, close: () => close(false) };

    // `alert` وحدَها تُستبدل: لا قيمةَ لها تُقرأ، فاستبدالُها آمن.
    window.alert = (v) => { show(v); };
})();
</script>
