// AMIAL-LEDGER-FLOW-COVERAGE-001 — **تبويبٌ لم يُضغط ليس مبنيّاً.**
//
// ══════════════════════════════════════════════════════════════════════
// المسارُ مسجَّلٌ والخدمةُ محروسةٌ بخمسة اختبارات — **ولا شيءَ من ذلك
// يُثبت أنّ التبويبَ يعمل في متصفّح**. القاعدةُ التاسعة، وقد دُفع ثمنُها
// مراراً: زرٌّ يعمل ويفعل الشيء الخطأ، ومعرِّفٌ يُطلب من الشاشة ولا وجودَ
// له فيموت المعالجُ **صامتاً**.
//
// وأخصُّ ما يُقاس هنا **CSP**: سكربتٌ مضمَّنٌ بلا `nonce` يمنعه المتصفّحُ
// بلا كلمة، فتردّ الصفحةُ ٢٠٠ ويُضغط التبويبُ ولا يحدث شيء. ولا يكشفه
// اختبارُ خادمٍ ولا بناءُ قالبٍ مباشرةً — **لا يُرى إلّا عبر الخادم**.
// Playwright مركَّبٌ عامّاً في هذه البيئة لا في `node_modules` المشروع،
// فيُحلّ مسارُه صراحةً — كما في `fee-centre-probe.mjs`.
import { createRequire } from 'node:module';

let chromium;
try {
    const req = createRequire(import.meta.url);
    ({ chromium } = req(req.resolve('playwright', {
        paths: [process.env.NODE_PATH || '/opt/node22/lib/node_modules'],
    })));
} catch {
    try { ({ chromium } = await import('playwright')); } catch { chromium = null; }
}

if (!chromium) {
    // **الغيابُ يُقال مُخطّىً ولا يُعدّ نجاحاً.**
    console.log('تخطّي: Playwright غيرُ مركَّب — التبويبُ لم يُضغط في متصفّح');
    process.exit(2);
}

const BASE = process.env.PROBE_BASE || 'http://127.0.0.1:8137';
// **الأسماءُ نفسُها التي يستعملها `fee-centre-probe.mjs` و`amial:probe-admin`.**
// واختلافُ اسمِ متغيّرٍ بينهما يُخرج «تعذّر الدخول» على بيئةٍ سليمة —
// ويُرسل من يصدّقه خلف حسابٍ ليس فيه عطل.
const PHONE = process.env.PROBE_ADMIN_PHONE || '967700000000';
const PASSWORD = process.env.PROBE_ADMIN_PASSWORD || 'Admin@2026';

const problems = [];
const errors = [];
const cspHashes = [];
let checks = 0;

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();

page.on('pageerror', e => errors.push('pageerror: ' + e.message));
// ══════════════════════════════════════════════════════════════════════
// **ما يُتجاهَل، ولماذا — مقيساً لا مُفترَضاً.**
//
// شريطُ التنقيح (`barryvdh/laravel-debugbar`) يحقن ثلاثةَ سكربتاتٍ بلا
// `nonce` فيمنعها CSP: `jQuery.noConflict` و`Sfdump` و`phpdebugbar`.
// وقِيس أنّها **لا تصل الإنتاجَ إطلاقاً**: الحزمةُ في `require-dev`،
// و`Dockerfile` يبني بـ`--no-dev`. ويحرس ذلك `DebugbarStaysDevOnlyTest` —
// فإن نُقلت يوماً إلى `require` صارت هذه الثلاثةُ عطلاً حقيقيّاً **ومعها
// شريطُ تنقيحٍ يكشف الاستعلاماتِ في الإنتاج**.
//
// **ولا يُتجاهَل غيرُها.** مسبارٌ يبتلع كلَّ خطأ ليمرّ ليس مسباراً.
const DEV_ONLY_NOISE = /jQuery\.noConflict|Sfdump|phpdebugbar|PhpDebugBar/;

page.on('console', m => {
    const t = m.text();

    if (m.type() !== 'error') return;
    if (/favicon|404 \(Not Found\)/i.test(t)) return;

    // **رسالةُ CSP تُلتقط بالاسم** — وهي التي تقتل السكربتَ صامتاً.
    // ورسالةُ الحجب تحمل بصمةَ السكربت لا نصَّه، فيُقرأ نصُّه من الصفحة
    // ويُطابَق: التجاهلُ بالبصمة أدقُّ من التجاهل بنمطٍ في الرسالة.
    if (/Content Security Policy/i.test(t)) {
        cspHashes.push(...(t.match(/sha256-[A-Za-z0-9+/=]+/g) || []));
        return;
    }

    if (DEV_ONLY_NOISE.test(t)) return;

    errors.push('console: ' + t);
});


try {
    await page.goto(`${BASE}/admin/auth/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="phone"]', PHONE);
    await page.fill('input[name="password"]', PASSWORD);

    // الكابتشا `required` في HTML وإن أُلغي فحصُها في الخادم.
    const captcha = page.locator('input[name="default_captcha_value"]');
    if (await captcha.count() > 0) {
        await captcha.fill('probe');
    }

    // **ورمزُ PIN حلّ محلَّ الكابتشا** (AMIAL-AUTH-PIN).
    //
    // ولا يُستبدَل أحدُهما بالآخر بل يُملآن معاً: النموذجُ قد يحمل
    // أيَّهما بحسب النشرة، **ومسبارٌ يعرف صيغةً واحدةً يعمى عند أوّل
    // تغيير**. ويُصدَر الرمزُ لحساب المسبار في `amial:ensure-probe-admin`.
    const pin = page.locator('input[name="login_pin"]');
    if (await pin.count() > 0) {
        await pin.fill(process.env.PROBE_ADMIN_PIN || '4321');
    }
    if (await captcha.count() > 0) await captcha.fill('probe');

    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('button[type="submit"]'),
    ]);

    if (page.url().includes('/auth/login')) {
        const body = await page.innerText('body').catch(() => '');
        let why = 'السببُ غيرُ معروف — افتح الصفحةَ يدويّاً وانظر';
        if (/SQLSTATE|Connection refused|QueryException/i.test(body)) {
            why = 'السببُ قاعدةُ البيانات — شغّل bash scripts/ensure-db.sh';
        } else if (/رمز|captcha/i.test(body)) {
            why = 'السببُ الكابتشا';
        }
        console.log(`تخطّي: تعذّر الدخول — ${why}`);
        await browser.close();
        process.exit(2);
    }

    // ── الصفحة ───────────────────────────────────────────────────────
    await page.goto(`${BASE}/admin/amial/ledger`, { waitUntil: 'domcontentloaded' });

    const tab = page.locator('[data-testid="lg-tab-flows"]');
    if (await tab.count() === 0) {
        problems.push('لا تبويبَ «تغطية التدفّقات» في الصفحة — **مبنيٌّ ولا يُوصَل إليه**');
        throw new Error('no-tab');
    }
    checks++;

    await tab.click();
    await page.waitForTimeout(3000);

    // ── ① هل خرج قياسٌ فعلاً، أم بقي «جارٍ القياس»؟ ──────────────────
    //
    // وهذا هو الفرقُ بين «الصفحةُ ردّت ٢٠٠» و«التبويبُ يعمل». سكربتٌ ميّتٌ
    // يترك النصَّ الأوّليَّ كما هو، **ولا خطأَ في أيّ سجلّ خادم**.
    const listText = await page.innerText('[data-testid="lg-flows-list"]').catch(() => '');
    checks++;
    if (/جارٍ القياس/.test(listText)) {
        problems.push('التبويبُ بقي على «جارٍ القياس» — السكربتُ لم يُنفَّذ '
            + '(‏غالباً `nonce` مفقود فمنعه CSP) أو الطلبُ لم يعد');
    }

    // ── ② البطاقاتُ الأربع تُبنى من الخادم ──────────────────────────
    const tiles = await page.locator('#lg-flows-summary .card').count();
    checks++;
    if (tiles === 0) {
        problems.push('لا بطاقةَ ملخّصٍ واحدة — الردُّ لم يُقرأ أو سقط البناء');
    }

    // ── ③ والشارةُ تتغيّر عن «—» ────────────────────────────────────
    const badge = (await page.textContent('#lg-flows-count').catch(() => '') || '').trim();
    checks++;
    if (badge === '—' || badge === '') {
        problems.push(`شارةُ التبويب ما زالت «${badge || 'فارغة'}» — لم يصلها رقم`);
    }

    // ── ④ ولا فيضانَ أفقيّ على شاشة هاتف ───────────────────────────
    //
    // جدولٌ بستّة أعمدةٍ يُخرج الصفحةَ عن ٣٦٠، فيصير التمريرُ الأفقيُّ
    // شرطاً لبلوغ الحالة — والحالةُ آخرُ عمود.
    await page.setViewportSize({ width: 360, height: 780 });
    await page.waitForTimeout(400);
    const overflow = await page.evaluate(() =>
        document.documentElement.scrollWidth - document.documentElement.clientWidth);
    checks++;
    if (overflow > 2) {
        problems.push(`فيضانٌ أفقيٌّ ${overflow}px على عرض ٣٦٠ — الحالةُ آخرُ عمودٍ فلا تُرى`);
    }
    // ── ⑤ وأيُّ سكربتٍ حجبه CSP: أهو لنا أم لشريط التنقيح؟ ──────────
    //
    // **ولا يُقرأ من نصّ الرسالة**: المتصفّحُ يذكر البصمةَ لا المصدر.
    // فتُحسب بصمةُ كلّ سكربتٍ في الصفحة وتُطابَق — فيُقال بالضبط أيُّ
    // شيفرةٍ ماتت. وتجاهلٌ بنمطٍ في الرسالة يبتلع سكربتَنا لو تشابه.
    if (cspHashes.length) {
        const crypto = await import('node:crypto');
        const texts = await page.evaluate(() =>
            [...document.querySelectorAll('script')].filter(s => !s.src).map(s => s.textContent));

        for (const text of texts) {
            const h = 'sha256-' + crypto.createHash('sha256').update(text, 'utf8').digest('base64');

            if (! cspHashes.includes(h)) continue;
            if (DEV_ONLY_NOISE.test(text)) continue;   // شريطُ التنقيح — لا يصل الإنتاج

            problems.push('سكربتٌ **من شيفرتنا** حجبه CSP (‏`nonce` مفقود): '
                + text.trim().replace(/\s+/g, ' ').slice(0, 90));
        }
    }
    checks++;
} catch (e) {
    if (e.message !== 'no-tab') problems.push('انهيارُ المسبار: ' + e.message);
}

if (errors.length) problems.push('أخطاءُ متصفّح: ' + errors.slice(0, 3).join(' | '));

await browser.close();

console.log(`تغطية التدفّقات — ${checks} قياساً`
    + (problems.length ? '' : ' · التبويبُ يُضغط ويُخرج رقماً · لا فيضان'));

for (const p of problems) console.log('  ✗ ' + p);

process.exit(problems.length ? 1 : 0);
