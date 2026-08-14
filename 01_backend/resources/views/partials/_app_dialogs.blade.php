{{-- AMIAL-UI-DIALOGS-001 — بديل موحّد لـ window.alert في لوحتي الإدارة والوكيل.
     التنبيه لا يملك أثراً مالياً؛ التأكيدات المالية تبقى صريحة في سياقها
     إلى أن تُنقل كلّ استدعاءات confirm غير المتزامنة بعقدٍ اختبارٍ مستقل. --}}
<style>
    .amial-dialog-backdrop{position:fixed;inset:0;z-index:2147483000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(8,20,43,.62);backdrop-filter:blur(4px)}
    .amial-dialog-backdrop.is-open{display:flex}.amial-dialog{width:min(100%,460px);background:#fff;border-radius:22px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden;animation:amial-dialog-in .18s ease-out}
    .amial-dialog-head{display:flex;align-items:center;gap:11px;padding:20px 20px 13px}.amial-dialog-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:12px;background:#edf4ff;color:#0754c7;font-size:20px}.amial-dialog-title{margin:0;font-size:17px;font-weight:800;color:#15233a}.amial-dialog-close{margin-inline-start:auto;width:34px;height:34px;border:0;border-radius:9px;background:#f1f4f8;color:#526176;font-size:24px;line-height:1;cursor:pointer}.amial-dialog-close:hover{background:#e4eaf2;color:#14233b}
    .amial-dialog-body{padding:0 20px 20px;color:#516074;line-height:1.8;font-size:14px;white-space:pre-wrap}.amial-dialog-foot{display:flex;justify-content:flex-end;padding:14px 20px 20px;border-top:1px solid #edf0f5}.amial-dialog-ok{border:0;border-radius:10px;background:#0754c7;color:#fff;font:inherit;font-weight:700;padding:10px 24px;cursor:pointer}.amial-dialog-ok:hover{background:#0648ab}@keyframes amial-dialog-in{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
</style>
<div class="amial-dialog-backdrop" id="amial-dialog" aria-hidden="true" data-testid="amial-dialog">
    <section class="amial-dialog" role="alertdialog" aria-modal="true" aria-labelledby="amial-dialog-title" aria-describedby="amial-dialog-message">
        <header class="amial-dialog-head"><span class="amial-dialog-mark" aria-hidden="true">i</span><h2 class="amial-dialog-title" id="amial-dialog-title">رسالة من أميال باي</h2><button class="amial-dialog-close" type="button" aria-label="إغلاق" data-amial-dialog-close>×</button></header>
        <div class="amial-dialog-body" id="amial-dialog-message"></div>
        <footer class="amial-dialog-foot"><button class="amial-dialog-ok" type="button" data-amial-dialog-close>حسناً</button></footer>
    </section>
</div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const root = document.getElementById('amial-dialog');
    if (!root || window.__amialDialogsReady) return;
    window.__amialDialogsReady = true;
    const message = document.getElementById('amial-dialog-message');
    const close = () => { root.classList.remove('is-open'); root.setAttribute('aria-hidden', 'true'); };
    const show = (value, title = 'رسالة من أميال باي') => {
        message.textContent = String(value ?? '');
        document.getElementById('amial-dialog-title').textContent = title;
        root.classList.add('is-open'); root.setAttribute('aria-hidden', 'false');
        root.querySelector('[data-amial-dialog-close]').focus();
    };
    root.querySelectorAll('[data-amial-dialog-close]').forEach(button => button.addEventListener('click', close));
    root.addEventListener('click', event => { if (event.target === root) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && root.classList.contains('is-open')) close(); });
    window.amialDialog = { show, close };
    window.alert = show;
})();
</script>
