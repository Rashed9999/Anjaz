// AMIAL-SIM-QUICKSALE-001 — **مسبارُ محاكي البيع السريع.**
//
// ══════════════════════════════════════════════════════════════════════
// يثبت أخصَّ ما في هذا القطاع: **أصغرُ مربّعٍ في المنتج** — ثلاثُ قدراتٍ
// فقط (بيعٌ ودَينٌ ومرتجع)، **وحتّى `F_CASHIER` ليست منها**: البسطةُ
// تبيع بالمبلغ لا بالسلّة. والتعليقُ في السجلّ: «بساطةٌ قصوى».
//
// وESM لا يقرأ `NODE_PATH` — يقرؤه `require` وحدَه.
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

let chromium;
const CANDIDATES = [
  process.env.NODE_PATH || '/opt/node22/lib/node_modules',
  '/opt/node22/lib/node_modules',
  '/usr/lib/node_modules',
];
for (const pkg of ['playwright-core', 'playwright']) {
  if (chromium) break;
  try {
    const req = createRequire(import.meta.url);
    ({ chromium } = req(req.resolve(pkg, { paths: CANDIDATES })));
  } catch {
    try { ({ chromium } = await import(pkg)); } catch { /* التالي */ }
  }
}
if (!chromium) {
  console.log('  — تخطّي: playwright غير متوفّر — ولا تُعدّ الطبقةُ نجاحاً');
  process.exit(2);
}

const FILE = new URL('./محاكي-البيع-السريع.html', import.meta.url).href;

let pass = 0, fail = 0;
const errs = [];
function ok(cond, label, extra) {
  if (cond) { pass++; console.log('  ✓ ' + label); }
  else { fail++; console.log('  ✗ ' + label + (extra ? '  → ' + extra : '')); }
}

const browser = await chromium.launch({
  executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
});
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
page.on('pageerror', e => errs.push('pageerror: ' + e.message));
page.on('console', m => { if (m.type() === 'error') errs.push('console: ' + m.text()); });

await page.goto(FILE);
await page.waitForTimeout(300);

const title = () => page.textContent('#title');
const viewText = () => page.textContent('#view');
const toastText = () => page.textContent('#toast');
const setPlan = async p => { await page.click(`#segPlan button[data-plan="${p}"]`); await page.waitForTimeout(90); };
const setRole = async r => { await page.click(`#segRole button[data-role="${r}"]`); await page.waitForTimeout(90); };
const setPerm = async p => { await page.click(`#segPerms button[data-perm="${p}"]`); await page.waitForTimeout(90); };
const home = async () => {
  while (await page.locator('#back').isVisible()) { await page.click('#back'); await page.waitForTimeout(70); }
};
const tap = async (k) => { await page.click(`.keypad button[data-key="${k}"]`); await page.waitForTimeout(60); };

console.log('\n① الإقلاع');
ok((await title()).includes('أبو سامي'), 'الرئيسية تفتح باسم البسطة');
ok((await viewText()).includes('رصيد المحفظة'), 'البائعُ يرى رصيده');
ok(!(await page.locator('#back').isVisible()), 'لا زرَّ رجوعٍ في الجذر');
ok((await viewText()).includes('وثلاثُ قدراتٍ فقط في المربّع'),
   'ويُقال إنّ المربّعَ ثلاثٌ لا غير');
ok((await viewText()).includes('ولا كاشيرَ ولا أصنافَ ولا مخزون'),
   'ويُسمّى ما ليس فيه');
ok(await page.locator('.tile').count() === 4, '**وأربعُ بلاطاتٍ فقط** — أصغرُ رئيسيّة');

console.log('\n② الدرج — أقسامُ التطبيق');
await page.click('#menu'); await page.waitForTimeout(260);
for (const s of ['البيع السريع', 'العملاء والفريق', 'الوردية والتقارير']) {
  ok((await page.textContent('#dbody')).includes(s), `قسم «${s}»`);
}
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(260);

console.log('\n③ **حتّى الكاشيرُ ليس من المربّع**');
await page.click('#menu'); await page.waitForTimeout(240);
await page.click('.ditem:has-text("مزايا باقتي")'); await page.waitForTimeout(180);
ok((await viewText()).includes('وثلاثٌ من المربّع لا غير'), 'ويُقال ذلك صراحةً');
ok((await viewText()).includes('البسطةُ تبيع بالمبلغ لا بالسلّة'),
   'ويُعلَّل غيابُ الكاشير');
const v0 = await page.textContent('#vList');
ok(v0.includes('حتّى الكاشيرُ ليس في المربّع'), 'واللوحةُ تقولها أيضاً');

console.log('\n④ البيع — بالمبلغ لا بالسلّة');
await home();
await page.click('.tile:has-text("بيع سريع")'); await page.waitForTimeout(150);
ok((await title()) === 'بيع سريع', 'شاشةُ البيع تفتح');
ok(await page.locator('.keypad button').count() === 12, 'واللوحةُ اثنتا عشرةَ خانة');
ok((await viewText()).includes('بلا أصنافٍ ولا سلّة'), 'ويُقال إنّه بلا سلّة');
ok(await page.locator('.btn:has-text("تسجيل البيع")').isDisabled(), 'ولا بيعَ بلا مبلغ');

await tap('3'); await tap('5'); await tap('0'); await tap('0');
ok((await viewText()).includes('3,500'), 'الإدخالُ يظهر');
await tap('⌫');
ok((await viewText()).includes('350 ر.ي'), 'والمسحُ يعمل');
await page.click('.quick button:has-text("5k")'); await page.waitForTimeout(120);
ok((await viewText()).includes('5,000'), 'والزرُّ السريع يملأ');
ok(!(await page.locator('.btn:has-text("تسجيل البيع")').isDisabled()), 'والبيعُ انفتح');

console.log('\n⑤ أميال باي');
await page.click('button[data-pay="amial_pay"]'); await page.waitForTimeout(130);
ok(await page.locator('.btn:has-text("بانتظار دفع العميل")').isDisabled(),
   'لا بيعَ قبل وصول الحركة');
await page.click('.btn:has-text("عرض رمز الدفع")'); await page.waitForTimeout(150);
ok(await page.locator('.qr').count() === 1, 'رمزُ الدفع مرسوم');
ok(await page.locator('input').count() === 0, 'ولا حقلَ مرجعٍ يكتبه البائع');
await page.click('.btn:has-text("محاكاة: دفع العميل")'); await page.waitForTimeout(170);
ok((await viewText()).includes('دُفعت'), 'ووصلت الحركة');

console.log('\n⑥ **والدَّينُ لا يُقيَّد بلا اسم**');
await page.click('button[data-pay="credit"]'); await page.waitForTimeout(140);
ok(await page.locator('.btn:has-text("اختر العميل")').isDisabled(), 'ولا بيعَ آجلٍ بلا عميل');
ok((await viewText()).includes('لا يُقيَّد بلا اسم'), 'ويُقال ذلك');
ok((await viewText()).includes('فدفترٌ بلا أسماءٍ ليس دفتراً'), 'ويُعلَّل');
ok(await page.locator('[data-cust]').count() === 3, 'وثلاثةُ عملاء');
// **والفهرسُ صفريُّ الأساس** — الأوّلُ يجب أن يُختار.
await page.click('[data-cust="0"]'); await page.waitForTimeout(150);
ok(!(await page.locator('.btn:has-text("تسجيل البيع")').isDisabled()),
   '**والعميلُ الأوّل (الفهرس صفر) يُختار فعلاً**');

console.log('\n⑦ الإتمام');
await page.click('.btn:has-text("تسجيل البيع")'); await page.waitForTimeout(170);
ok((await title()) === 'تمّ البيع', 'شاشةُ النجاح');
ok((await viewText()).includes('أبو نبيل'), 'واسمُ من عليه الدَّين');

console.log('\n⑧ الديونُ والمرتجعُ من المربّع');
await home();
await page.click('.tile:has-text("الديون")'); await page.waitForTimeout(150);
ok((await viewText()).includes('من المربّع'), 'الدَّينُ من النشاط لا الباقة');
await home();
await page.click('.tile:has-text("مرتجع")'); await page.waitForTimeout(150);
ok((await viewText()).includes('ثلاثُ قدراتٍ لا غير'), 'والمرتجعُ كذلك');

console.log('\n⑨ التقاريرُ تبدأ من الأعمال');
await home();
ok(await page.locator('.tile.lock:has-text("التقارير")').count() === 1, 'مقفلةٌ في المجّانيّة');
await page.click('.tile.lock:has-text("التقارير")'); await page.waitForTimeout(150);
ok((await toastText()).includes('باقة الأعمال'), 'وتقول أيّ باقةٍ تفتحها');
await setPlan('business'); await page.waitForTimeout(130);
ok(await page.locator('.tile.lock:has-text("التقارير")').count() === 0, 'والترقيةُ تفتحها');
await setPlan('free');

console.log('\n⑩ المساعد');
await setRole('pos');
ok((await title()).includes('مساعد'), 'شاشتُه باسمه');
ok((await viewText()).includes('لا يظهر لك رصيدُ المحفظة'), 'والرصيدُ غائبٌ ومُعلَّل');
const t0 = await page.locator('.tile').count();
await setPerm('refunds'); await page.waitForTimeout(120);
ok((await page.locator('.tile').count()) > t0, 'منحُ صلاحيّةٍ يزيد بلاطةً');
await page.click('#menu'); await page.waitForTimeout(250);
const db = await page.textContent('#dbody');
ok(!db.includes('محفظتي') && !db.includes('مزايا باقتي'), 'ودرجُه بلا محفظةٍ ولا باقة');
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(230);

console.log('\n⑪ لوحةُ «ما يقوله الخادم»');
await setRole('owner'); await setPlan('free');
const v = await page.textContent('#vList');
ok(v.includes('مربّع البيع السريع'), 'المربّعُ يُنسب إليه ما يمنحه');
const on0 = await page.locator('#vList .dot.on').count();
ok(on0 === 3, `**ثلاثُ نقاطٍ مضاءةٍ فقط في المجّانيّة** — أصغرُ مربّع (${on0})`);
await setPlan('enterprise');
const on2 = await page.locator('#vList .dot.on').count();
ok(on2 > on0, `الترقيةُ تُشعل نقاطاً (${on0} → ${on2})`);

console.log('\n⑫ لا خطأ في أيّ سجلّ');
const fontHost = errs.filter(e => e.includes('Failed to load resource'));
const real = errs.filter(e => !e.includes('Failed to load resource'));
ok(real.length === 0, 'صفرُ أخطاءٍ في الصفحة', real.join(' | '));
if (fontHost.length) {
  console.log('  ⓘ مُخطّى: مضيفُ الخطّ لا يُبلَغ من هذه الحاوية — والبديلُ العربيُّ يعمل.');
}

try {
  await page.screenshot({ path: fileURLToPath(new URL('./لقطة-محاكي-البيع-السريع.png', import.meta.url)) });
} catch { /* اللقطةُ مساعدةٌ لا شرط */ }

await browser.close();
console.log(`\n══════════════════\n  نجح ${pass} · فشل ${fail}\n`);
process.exit(fail ? 1 : 0);
