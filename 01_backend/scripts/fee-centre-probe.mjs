#!/usr/bin/env node
/**
 * AMIAL-FEE-TRUTH-023 — **مسبارُ مركز الرسوم: عرضٌ حقيقيٌّ ومقاسٌ حقيقيّ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * تقيس اختباراتُ الخادم أنّ الصفحةَ تردّ ٢٠٠. **ولا تقيس أنّها تُقرأ**:
 * جدولٌ بتسعة أعمدة يفيض على هاتفٍ بعرض ٣٦٠، فتخرج الصفحةُ كلُّها عن
 * الشاشة ويصير التمريرُ الأفقيُّ شرطاً لبلوغ الأزرار — **والردُّ ٢٠٠**.
 *
 * ولا تقيس أنّ قائمةَ الإجراءات تُفتح، ولا أنّ نافذةَ التعطيل تظهر بحقل
 * السبب، ولا أنّ حقلَ «حصّة الوكيل» يختفي حيث لا وكيل.
 * (‏القاعدة التاسعة: زرٌّ لم يُضغط ليس مبنيّاً.)
 *
 * التشغيل:
 *
 *     AMIAL_DISABLE_ADMIN_CAPTCHA=true php artisan serve --port 8123 &
 *     node scripts/fee-centre-probe.mjs
 */

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
    console.log('  — playwright غير متوفّر — تخطّي مسبار مركز الرسوم');
    process.exit(0);
}

const BASE = process.env.PROBE_BASE || 'http://127.0.0.1:8123';
const PHONE = process.env.PROBE_ADMIN_PHONE || '967700000000';
const PASSWORD = process.env.PROBE_ADMIN_PASSWORD || 'Admin@2026';

/** العروضُ المطلوبة — أصغرُها هاتفٌ قديمٌ شائعٌ في اليمن. */
const WIDTHS = [
    [360, 'هاتفٌ صغير'],
    [390, 'هاتفٌ حديث'],
    [430, 'هاتفٌ كبير'],
    [768, 'لوح'],
    [1024, 'لوحٌ أفقيّ'],
];

const PAGES = [
    ['/admin/amial/fees', 'نظرة عامّة'],
    ['/admin/amial/fees/operations', 'سجلّ العمليّات'],
    ['/admin/amial/fees/policies', 'الخصومات والسياسات'],
    ['/admin/amial/fees/profit', 'الأرباح'],
    ['/admin/amial/fees/drill', 'التنقّل إلى الأصل'],
    ['/admin/amial/fees/history', 'سجلّ التغييرات'],
    ['/admin/amial/fees/create', 'نسخةٌ جديدة'],
];

const problems = [];
let checks = 0;

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();

try {
    // ── الدخول ───────────────────────────────────────────────────────
    await page.goto(`${BASE}/admin/auth/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="phone"]', PHONE);
    await page.fill('input[name="password"]', PASSWORD);

    // **وحقلُ الكابتشا `required` في HTML.**
    //
    // `AMIAL_DISABLE_ADMIN_CAPTCHA` تُلغي **الفحصَ في الخادم** لا العرضَ في
    // الصفحة. فالمتصفّحُ يمنع الإرسالَ أصلاً ما لم يُملأ الحقل — **ولا يصل
    // طلبٌ إلى الخادم**، فيبدو كأنّ الحسابَ خاطئ. وقيمةُ الحقل هنا لا
    // تُفحَص، فأيُّ نصٍّ يكفي ليُرسَل النموذج.
    const captcha = page.locator('input[name="default_captcha_value"]');
    if (await captcha.count() > 0) {
        await captcha.fill('probe');
    }

    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('button[type="submit"]'),
    ]);

    if (page.url().includes('/auth/login')) {
        // ══════════════════════════════════════════════════════════════
        // **ويُقال أيُّ بابٍ أُغلق — لا يُخمَّن.**
        //
        // كان المسبارُ يقول «السببُ بياناتُ الحساب» في كلّ حال. وحين سقطت
        // قاعدةُ البيانات قال ذلك أيضاً، **فأرسل من يصدّقه خلف حسابٍ سليم**.
        // (‏«حارسٌ يكذب أسوأ من غيابه».)
        const body = await page.innerText('body').catch(() => '');
        let why = 'السببُ غيرُ معروف — افتح الصفحةَ يدويّاً وانظر';

        if (/SQLSTATE|Connection refused|QueryException/i.test(body)) {
            why = 'السببُ قاعدةُ البيانات — شغّل bash scripts/ensure-db.sh';
        } else if (/كابتشا|captcha/i.test(body)) {
            why = 'السببُ الكابتشا — شغّل الخادمَ بـAMIAL_DISABLE_ADMIN_CAPTCHA=true';
        } else if (/صحيح|credential|بيانات/i.test(body)) {
            why = 'السببُ بياناتُ الحساب — راجع PROBE_ADMIN_PHONE/PASSWORD';
        }

        console.error(`تعذّر الدخول. ${why}\n  ما ظهر: `
            + body.replace(/\s+/g, ' ').slice(0, 200));
        await browser.close();
        process.exit(2);
    }

    // ── ① الفيضانُ الأفقيّ ─────────────────────────────────────────────
    for (const [width, wlabel] of WIDTHS) {
        await page.setViewportSize({ width, height: 900 });

        for (const [path, label] of PAGES) {
            await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(120);
            checks++;

            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth - window.innerWidth);

            // **هامشُ بكسلٍ يُتسامَح معه** — تقريبُ المتصفّح للحدود يُنتج
            // فرقاً لا يراه أحد، ورفضُه يُسقط تصميماً سليماً.
            if (overflow > 1) {
                problems.push(`فيضانٌ أفقيّ: ${label} (${path}) عند ${width}px `
                    + `[${wlabel}] — زيادةُ ${overflow}px`);
            }
        }
    }

    await page.setViewportSize({ width: 390, height: 900 });

    // ── ② قائمةُ الإجراءات تُفتح فعلاً ──────────────────────────────────
    await page.goto(`${BASE}/admin/amial/fees`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(250);

    const menus = page.locator('button[data-bs-toggle="dropdown"]');
    const menuCount = await menus.count();
    checks++;

    if (menuCount === 0) {
        problems.push('لا قائمةَ إجراءاتٍ واحدة في «نظرة عامّة» — الجدولُ بلا أزرار');
    } else {
        await menus.nth(0).click();
        await page.waitForTimeout(300);
        checks++;

        if (await page.locator('.dropdown-menu.show').count() === 0) {
            problems.push('قائمةُ الإجراءات لا تُفتح عند الضغط — زرٌّ يُضغط ولا يحدث شيء');
        }
    }

    // ── ③ نافذةُ التعطيل ومعها حقلُ السبب ───────────────────────────────
    const trigger = page.locator('[data-bs-target^="#deactivate-"]');
    checks++;

    if (await trigger.count() === 0) {
        problems.push('لا زرَّ تعطيلٍ واحد — الجدولُ بلا تسعيراتٍ أو الزرُّ مفقود');
    } else {
        await trigger.nth(0).click({ force: true });
        await page.waitForTimeout(450);

        const modal = page.locator('.modal.show');

        if (await modal.count() === 0) {
            problems.push('نافذةُ التعطيل لا تظهر — والزرُّ معروض');
        } else {
            checks++;
            if (await modal.locator('textarea[name="reason"]').count() === 0) {
                problems.push('نافذةُ التعطيل بلا حقل سبب — **والسببُ إلزاميٌّ في '
                    + 'الخادم**، فالضغطُ يردّ خطأً لا يفهمه أحد');
            }
        }
    }

    // ── ④ المحاكي يردّ رقماً صحيحاً ──────────────────────────────────────
    await page.goto(`${BASE}/admin/amial/fees/create?code=CASH_OUT&applies_to=customer`,
        { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(250);

    await page.fill('#f-percent', '2');
    await page.fill('#sim-amount', '1000');
    await page.click('#sim-run');
    await page.waitForTimeout(1200);

    const out = (await page.innerText('#sim-out')).trim();
    checks++;

    if (out === '' || /تعذّر|غيرُ صالح/.test(out)) {
        problems.push(`المحاكي لا يردّ رقماً: «${out.slice(0, 120)}»`);
    } else if (!out.replace(/,/g, '').includes('20')) {
        problems.push('المحاكي ردّ رقماً غيرَ متوقَّع (‏٢٪ من ١٠٠٠ = ٢٠): '
            + `«${out.slice(0, 120)}»`);
    }

    // ── ⑤ وحصّةُ الوكيل تختفي حيث لا وكيل، ويُقال لماذا ──────────────────
    await page.selectOption('#f-code', 'SEND_MONEY');
    await page.waitForTimeout(300);
    checks++;

    if (await page.locator('#agent-block').isVisible()) {
        problems.push('حقلُ «حصّة الوكيل» ظاهرٌ في تسعيرة التحويل — **ولا وكيلَ '
            + 'فيه**، فرقمٌ هناك يُقتطع من ربح المنصّة ويُقيَّد لمن لم يعمل');
    }

    checks++;
    if (!await page.locator('#agent-na').isVisible()) {
        problems.push('اختفى الحقلُ بلا أن يُقال لماذا — فحقلٌ يختفي صامتاً '
            + 'يُقرأ عطلاً في الشاشة');
    }
} finally {
    await browser.close();
}

if (problems.length) {
    console.log(`مركز الرسوم — ${problems.length} مشكلةً من ${checks} قياساً:\n`);
    for (const p of problems) console.log('  ✗ ' + p);
    process.exit(1);
}

console.log(`مركز الرسوم — ${checks} قياساً · لا فيضانَ ولا زرَّ ميّت `
    + `(٥ عروض × ${PAGES.length} شاشات)`);
