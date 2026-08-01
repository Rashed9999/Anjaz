#!/usr/bin/env node
/**
 * scripts/click-check.mjs — الطبقة السابعة: **الضغط**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * لماذا هذا الملفّ موجود
 *
 * زرّ «تحويل رصيد» في مركز الوكلاء كان ينقل إلى صفحة الوكيل بدل أن يفتح
 * نافذة التحويل. ولم يكشفه شيء:
 *
 *   الخدمة تعمل  ·  نقطة النهاية تعمل  ·  الاختبارات ١٥٥٧ تمرّ
 *   الصفحة تردّ ٢٠٠  ·  الجافاسكربت يُحلَّل بلا خطأ  ·  الأصول موجودة
 *
 * لأنّ العطل لم يكن في أيّ طبقةٍ من هذه. كان في **سطرٍ واحد** يقرّر أيّ
 * إجراءٍ تعنيه الضغطة:
 *
 *     const openEl = e.target.closest('[data-act="open"]');
 *
 * والصفّ نفسه يحمل `data-act="open"`، فـ`closest` تصعد من الزرّ إلى الصفّ
 * وتُطابقه — فيبتلع الصفُّ كلَّ ضغطةٍ على أزراره.
 *
 * لا يمسك هذا إلّا متصفّحٌ يضغط فعلاً. فهذا ما يفعله هذا الملفّ: يبني
 * الصفحة من القالب نفسه (نفس الجافاسكربت حرفيّاً)، ويضغط، ويتحقّق ممّا
 * حدث — هل فُتحت نافذة أم وقع انتقال.
 *
 * الاستعمال:  node scripts/click-check.mjs
 * ══════════════════════════════════════════════════════════════════════
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { createRequire } from 'node:module';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

// playwright مثبَّتٌ عامّاً في هذه البيئة ولا يُدرَج في package.json.
// فإن غاب، يُقال ذلك صراحةً ولا يُدّعى نجاح — «غير معروف» ليس صفراً.
let chromium;
try {
    const req = createRequire(import.meta.url);
    ({ chromium } = req(
        req.resolve('playwright', { paths: [process.env.NODE_PATH || '/opt/node22/lib/node_modules'] })
    ));
} catch {
    try { ({ chromium } = await import('playwright')); } catch { chromium = null; }
}

if (!chromium) {
    console.log('  — playwright غير متوفّر — تخطّي فحص الضغط');
    process.exit(0);
}

// ── تحويل القالب إلى صفحةٍ قابلةٍ للفتح ──────────────────────────────
//
// الجافاسكربت يُنقل **حرفيّاً** بلا تعديل: أيّ إعادة صياغةٍ هنا تعني أنّنا
// نختبر نصّاً غير الذي يعمل في اللوحة.
function bladeToHtml(file, vars) {
    let s = readFileSync(resolve(ROOT, file), 'utf8');

    s = s.replace(/\{\{--[\s\S]*?--\}\}/g, '');                       // تعليقات Blade
    s = s.replace(/\{\{\s*([\s\S]*?)\s*\}\}/g, (m, expr) => {          // {{ ... }}
        for (const [k, v] of Object.entries(vars)) if (expr.includes(k)) return v;
        return '';
    });
    // توجيهات Blade: تُحذف الأسطر نفسها ويُبقى ما بينها
    s = s.replace(/^\s*@(extends|section|endsection|push|endpush|include|if|elseif|else|endif|foreach|endforeach)\b.*$/gm, '');

    const scripts = [...s.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]);
    const body = s.replace(/<script[\s\S]*?<\/script>/g, '');
    const bootstrap = readFileSync(resolve(ROOT, 'public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'), 'utf8');

    return `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8">
<meta name="csrf-token" content="test-token"></head><body>
${body}
<script>${bootstrap}</script>
${scripts.map(j => `<script>${j}</script>`).join('\n')}
</body></html>`;
}

const HUB_URL = 'http://amial.test/admin/amial/hub/agents';
const COUNTER_URL = 'http://amial.test/agent/counter';

const HUB_HTML = bladeToHtml('resources/views/admin-views/amial/hub/users.blade.php', {
    '$hubSlug': 'agents',
    '$hubType': '1',
    '$hubTitle': 'الوكلاء',
    "url('admin/amial/hub')": 'http://amial.test/admin/amial/hub',
    "route('agent.login')": 'http://amial.test/agent/login',
    "$stats": '0',
});

// صفّان يشبهان ما تُرجعه AdminHubController للوكلاء: وكيلٌ أمّ، وفرعٌ تابع.
const FAKE_ROWS = [
    {
        id: 7, name: 'البسيري للصرافة', phone: '967700000007', balance: 0,
        kyc: 1, has_docs: true, is_active: true,
        agent: { is_branch_account: false, no_branches: false, branches: 2, branches_active: 2, cash_on_hand: 150000, flags: [] },
    },
    {
        id: 8, name: 'فرع المكلا', phone: '967700000008', balance: 0,
        kyc: 1, has_docs: true, is_active: true,
        agent: { is_branch_account: true, parent: { id: 7, name: 'البسيري للصرافة' } },
    },
];

// ── شبّاك الصرّاف ────────────────────────────────────────────────────
//
// يُبنى من `_counter.blade.php` وحدَه: جافاسكربته مكتفٍ بذاته وكلّ عناصره
// في ملفّه، فلا حاجة إلى بقيّة اللوحة.
const COUNTER_HTML = bladeToHtml('resources/views/agent-views/_counter.blade.php', {
    "url('agent')": 'http://amial.test/agent',
});

const SHIFT_OPEN = {
    shift: {
        status: 'open', cash_on_hand: '50000',
        deposits_total: '0', deposits_count: 0,
        withdrawals_total: '0', withdrawals_count: 0,
    },
    staff: { role: 'teller' },
    can_operate: true,
    why_not: null,
};

// كلّ نداءٍ يُسجَّل، لأنّ سؤال هذا الفحص ليس «هل ظهر خطأ» بل **هل وصل
// الطلب أصلاً**. وزرُّ الإيداع الميّت كان يسقط قبل أيّ نداء.
const STUBS = `
window.__calls = [];
window.alert = () => {};
window.prompt = () => 'سبب مكتوب';
window.confirm = () => true;
const _json = (o) => new Response(JSON.stringify(o), {status: 200, headers: {'Content-Type': 'application/json'}});
window.fetch = async (url, opts) => {
    const u = String(url);
    window.__calls.push({url: u, method: (opts && opts.method) || 'GET'});

    if (u.includes('users.json')) return _json({data: ${JSON.stringify(FAKE_ROWS)}, current_page: 1, last_page: 1, total: 2});
    if (u.includes('kyc.json'))   return _json({data: []});

    if (u.includes('/counter/state')) return _json(${JSON.stringify(SHIFT_OPEN)});
    if (u.includes('/counter/customer')) return _json({
        success: true,
        meta: {customer: {id: 42, name: 'راشد محمد عوض معرابي', phone: '783545525',
                          can_transact: true, status_label: 'نشط', severity: 'success'}},
    });
    if (u.includes('/counter/deposit')) return _json({
        success: true, message: 'أُودع 2,000 ر.ي',
        result: {reference: 'DEP-TEST', fee: '0', commission: '0'},
        shift: ${JSON.stringify(SHIFT_OPEN.shift)},
    });

    return _json({success: true, message: 'تمّ'});
};
`;

const CASES = [
    // ── شبّاك الصرّاف ────────────────────────────────────────────────
    {
        // العطل الذي أدخل هذه الحالة: سطرٌ يقرأ `$('ct-withdraw').disabled`
        // على زرٍّ أُزيل، **خارج `try`**، فيموت المعالج قبل أيّ طلب. الزرّ
        // يُضغط ولا يحدث شيء ولا رسالة.
        page: 'counter',
        name: 'زر «إيداع» يرسل الطلب فعلاً ويعرض النتيجة',
        steps: [
            ['fill', '#ct-phone', '783545525'],
            ['click', '#ct-find'],
            ['fill', '#ct-amount', '2000'],
            ['click', '#ct-deposit'],
        ],
        expectNav: null,
        dom: [
            ['وصل نداءُ الإيداع إلى الخادم',
                `window.__calls.some(c => c.method === 'POST' && c.url.includes('/counter/deposit'))`],
            ['وظهرت نتيجةُ نجاحٍ للصرّاف',
                `/alert-success/.test(document.getElementById('ct-result').innerHTML)`],
            ['وأُفرغ حقل المبلغ استعداداً للعملية التالية',
                `document.getElementById('ct-amount').value === ''`],
        ],
    },
    {
        page: 'counter',
        name: 'زر «بحث» يجد العميل ويفتح لوحة العملية',
        steps: [
            ['fill', '#ct-phone', '783545525'],
            ['click', '#ct-find'],
        ],
        expectNav: null,
        dom: [
            ['اسم العميل ظهر', `/راشد/.test(document.getElementById('ct-customer').textContent)`],
            ['ولوحة العملية فُتحت', `document.getElementById('ct-op').style.display !== 'none'`],
        ],
    },

    // ── مركز الوكلاء ────────────────────────────────────────────────
    {
        name: 'زر «تحويل رصيد» يفتح نافذة التحويل ولا ينقل إلى ملفّ الوكيل',
        click: 'button[data-act="transfer"]',
        expectModal: '#modal-transfer',
        expectNav: null,
    },
    {
        name: 'زر «التفاصيل» ينقل إلى صفحة الحساب',
        click: 'button[data-act="open"]',
        expectNav: /\/hub\/account\/7$/,
    },
    {
        name: 'الضغط على الصفّ خارج الأزرار ينقل إلى صفحة الحساب',
        click: 'tr[data-act="open"] td:nth-child(2)',
        expectNav: /\/hub\/account\/7$/,
    },
    {
        name: 'زر «تجميد» ينفّذ ولا ينقل',
        click: 'button[data-act="toggle"]',
        expectNav: null,
    },
    {
        // المنصّة ──► الوكيل الأمّ ──► الفرع. والخدمة ترفض تمويل الفرع على
        // كلّ حال؛ وهذا يتحقّق أنّ الشاشة تقول **السبب** بدل أن تَصُدّ بعد
        // الضغط.
        name: 'صفّ الفرع لا يعرض زرّ تحويل ويقول لماذا',
        click: null,
        expectNav: null,
        dom: [
            ['لا زرّ تحويل على صفّ الفرع',
                `!document.querySelector('tr[data-id="8"] button[data-act="transfer"]')`],
            ['والسبب مكتوبٌ في مكانه',
                `/يُموَّل من البسيري/.test(document.querySelector('tr[data-id="8"]').textContent)`],
            ['وزرّ التحويل قائمٌ على صفّ الوكيل الأمّ',
                `!!document.querySelector('tr[data-id="7"] button[data-act="transfer"]')`],
        ],
    },
];

const PAGES = {
    hub: { url: HUB_URL, html: HUB_HTML, ready: 'tr[data-act="open"]' },
    counter: { url: COUNTER_URL, html: COUNTER_HTML, ready: '#ct-modes' },
};

const browser = await chromium.launch({ args: ['--no-sandbox'] });
let pass = 0, fail = 0;

for (const c of CASES) {
    const target = PAGES[c.page || 'hub'];
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    let navigated = null;

    await page.route('**/*', async (route) => {
        const u = route.request().url();
        if (u === target.url) {
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: target.html });
        }
        if (route.request().resourceType() === 'document') navigated = u;   // انتقالٌ وقع
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<html><body>x</body></html>' });
    });

    await page.addInitScript(STUBS);

    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));

    try {
        await page.goto(target.url, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector(target.ready, { timeout: 5000 });

        for (const [action, sel, val] of (c.steps || [])) {
            if (action === 'fill') await page.fill(sel, val);
            else await page.click(sel);
            await page.waitForTimeout(250);
        }

        if (c.click) {
            await page.click(c.click);
            await page.waitForTimeout(700);   // مهلة الانتقال وحركة النافذة
        }

        const modalShown = c.expectModal
            ? await page.evaluate((sel) => !!document.querySelector(sel)?.classList.contains('show'), c.expectModal)
            : null;

        const problems = [];
        if (jsErrors.length) problems.push(`خطأ جافاسكربت: ${jsErrors[0]}`);
        if (c.expectNav === null && navigated) problems.push(`انتقل إلى ${navigated} والمفترض ألّا ينتقل`);
        if (c.expectNav instanceof RegExp) {
            if (!navigated) problems.push('لم ينتقل والمفترض أن ينتقل');
            else if (!c.expectNav.test(navigated)) problems.push(`انتقل إلى ${navigated} ولا يطابق المتوقَّع`);
        }
        if (c.expectModal && modalShown !== true) problems.push(`النافذة ${c.expectModal} لم تُفتح`);

        for (const [desc, js] of (c.dom || [])) {
            const okDom = await page.evaluate(`Boolean(${js})`).catch(() => false);
            if (!okDom) problems.push(`تحقُّق غير محقَّق: ${desc}`);
        }

        if (problems.length) { console.log(`  \x1b[31m✗\x1b[0m ${c.name}\n      ${problems.join('\n      ')}`); fail++; }
        else { console.log(`  \x1b[32m✓\x1b[0m ${c.name}`); pass++; }
    } catch (err) {
        console.log(`  \x1b[31m✗\x1b[0m ${c.name}\n      ${err.message.split('\n')[0]}`);
        fail++;
    } finally {
        await ctx.close();
    }
}

await browser.close();
process.exit(fail === 0 ? 0 : 1);
