// ══════════════════════════════════════════════════════════════════════
// **ESM لا يقرأ `NODE_PATH`** — يقرؤه `require` وحدَه. فاستيرادٌ مجرّدٌ
// ينجح حيث صودف `node_modules` مجاورٌ ويسقط في البوّابة، وهو ما وقع:
// المسبارُ يمرّ من مجلّدٍ مؤقّتٍ ويسقط `ERR_MODULE_NOT_FOUND` من موضعه
// في المستودع. والنمطُ هنا هو نفسُه في `flow-coverage-probe.mjs`.
// ══════════════════════════════════════════════════════════════════════
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

// **والغيابُ يُقال ولا يُمرَّر نجاحاً.** الخروجُ بالرمز ٢ هو ما تقرؤه
// البوّابةُ «مُخطّاة» بدل «نجحت». (القاعدة السابعة.)
if (!chromium) {
  console.log('  — تخطّي: playwright غير متوفّر — ولا تُعدّ الطبقةُ نجاحاً');
  process.exit(2);
}

// المسارُ يُشتقّ من موضع المسبار — لا يُكتب مطلقاً، فينتقل المستودعُ
// ويبقى المسبارُ يعمل.
const FILE = new URL('./محاكي-تاجر-الجملة.html', import.meta.url).href;

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
const setPlan = async p => { await page.click(`#segPlan button[data-plan="${p}"]`); await page.waitForTimeout(80); };
const setRole = async r => { await page.click(`#segRole button[data-role="${r}"]`); await page.waitForTimeout(80); };
const openDrawer = async () => { await page.click('#menu'); await page.waitForTimeout(250); };

console.log('\n① الإقلاع');
ok((await title()).includes('الرشيد'), 'الرئيسية تفتح باسم التاجر');
ok((await viewText()).includes('رصيد المحفظة'), 'المالك يرى رصيده');
ok(!(await page.locator('#back').isVisible()), 'لا زرَّ رجوعٍ في الجذر');

console.log('\n② الدرج — أقسامُ دليل الجملة');
await openDrawer();
for (const s of ['فواتير الجملة والتحصيل', 'العملاء والديون', 'الأصناف ومخزون الجملة',
                 'التسعير', 'التقارير والمالية', 'الفريق والأجهزة']) {
  ok((await page.textContent('#dbody')).includes(s), `قسم «${s}»`);
}
ok(await page.locator('#drawer.open').isVisible(), 'الدرج يُفتح فعلاً');
await page.click('#scrim',{position:{x:20,y:300}}); await page.waitForTimeout(250);
ok(!(await page.locator('#drawer.open').count()), 'الدرج يُغلق بالخلفيّة');

console.log('\n③ المجّانيّة تقفل ما لا تبيعه');
await setPlan('free');
for (const [tile, why] of [['التسعير', 'wholesale_multi_pricing'],
                           ['الأصناف والمخزون', 'products'],
                           ['التقارير', 'daily_reports'],
                           ['الفريق والأجهزة', 'employees']]) {
  const locked = await page.locator(`.tile.lock:has-text("${tile}")`).count();
  ok(locked === 1, `«${tile}» مقفلٌ في المجّانيّة (${why})`);
}
await page.click('.tile.lock:has-text("التسعير")');
await page.waitForTimeout(150);
ok((await page.textContent('#toast')).includes('باقة الأعمال'), 'الضغطُ على المقفل يقول أيّ باقةٍ تفتحه');
ok((await title()).includes('الرشيد'), 'ولا ينتقل — المقفلُ لا يفتح شاشة');

console.log('\n④ الفاتورة — بيعُ الجملة فاتورةٌ لا سلّة');
await page.click('.tile:has-text("فاتورة جديدة")'); await page.waitForTimeout(120);
ok((await title()) === 'فاتورة جديدة', 'شاشةُ الفاتورة تفتح');
ok((await viewText()).includes('قائمةٌ واحدة: سعر التجزئة'), 'المجّانيّة: قائمةُ سعرٍ واحدة');
ok(await page.locator('.btn:has-text("إصدار الفاتورة")').isDisabled(), 'الإصدارُ معطَّلٌ بلا أصناف');

await page.click('.btn.ghost:has-text("إضافة صنف")'); await page.waitForTimeout(120);
ok((await title()) === 'اختيار صنف', 'قائمةُ الأصناف تفتح');
ok((await viewText()).includes('قارب النفاد'), 'الصنفُ القاربُ نفادُه موسوم');
await page.click('.row[data-add="0"]'); await page.waitForTimeout(150);
ok((await title()) === 'فاتورة جديدة', 'الإضافةُ ترجع إلى الفاتورة');
ok((await viewText()).includes('سكر'), 'السطرُ ظهر في الفاتورة');
ok(!(await page.locator('.btn:has-text("إصدار الفاتورة")').isDisabled()), 'الإصدارُ صار متاحاً');

const totalBefore = await viewText();
await page.click('.row[data-add="0"]').catch(() => {});
await page.click('.btn.ghost:has-text("إضافة صنف")'); await page.waitForTimeout(100);
await page.click('.row[data-add="1"]'); await page.waitForTimeout(150);
ok((await viewText()).includes('أرز'), 'صنفٌ ثانٍ يُضاف');
ok((await viewText()) !== totalBefore, 'الإجماليُّ تغيّر — لا رقمَ جامد');

await page.click('.row[data-act="pickCustomer"]'); await page.waitForTimeout(120);
ok((await page.textContent('#toast')).includes('العميل'), 'تبديلُ العميل يعمل');

await page.click('button[data-pay="cash"]'); await page.waitForTimeout(100);
ok(await page.locator('button[data-pay="cash"][aria-pressed="true"]').count() === 1, 'السدادُ نقداً يُختار');

await page.click('.row[data-line="0"]'); await page.waitForTimeout(120);
ok((await page.textContent('#toast')).includes('حُذف'), 'حذفُ سطرٍ يعمل');

await page.click('.btn:has-text("إصدار الفاتورة")'); await page.waitForTimeout(150);
ok((await title()) === 'تمّ الإصدار', 'الإصدارُ يقود إلى شاشة النجاح');
ok((await viewText()).includes('ف-٤٨٢٢'), 'رقمُ الفاتورة يظهر');

console.log('\n④ب الدفع عبر أميال باي — العقدُ في الخادم');
// createInvoice: payment_type in cash·amial_pay·credit
// paid_transaction_id required_if: payment_type,amial_pay
while (await page.locator('#back').isVisible()) { await page.click('#back'); await page.waitForTimeout(80); }
await page.click('.tile:has-text("فاتورة جديدة")'); await page.waitForTimeout(120);
ok(await page.locator('button[data-pay]').count() === 3, 'ثلاثُ طرقِ سدادٍ — لا اثنتان');
ok(await page.locator('button[data-pay="amial_pay"]').count() === 1, 'وفيها «أميال باي»');

await page.click('.btn.ghost:has-text("إضافة صنف")'); await page.waitForTimeout(100);
await page.click('.row[data-add="0"]'); await page.waitForTimeout(120);
await page.click('button[data-pay="amial_pay"]'); await page.waitForTimeout(120);
ok((await viewText()).includes('يُدفع الـQR أوّلاً'), 'يُقال إنّ الـQR يُدفع أوّلاً');
ok(await page.locator('.btn:has-text("بانتظار دفع العميل")').isDisabled(),
   '**لا تُصدَر الفاتورةُ قبل الدفع** — كما يشترط `required_if`');

await page.click('.btn:has-text("عرض رمز الدفع")'); await page.waitForTimeout(150);
ok((await title()).includes('أميال باي'), 'شاشةُ الدفع تفتح');
ok(await page.locator('.qr').count() === 1, 'رمزُ الدفع مرسوم');
const code = (await viewText()).match(/\b\d{6}\b/);
ok(!!code, 'ورمزٌ قصيرٌ من ستّ خانات', code ? code[0] : 'لا رمز');
ok((await viewText()).includes('لا يُكتب رقمُ مرجعٍ هنا'), 'ولا حقلَ مرجعٍ يكتبه الموظّف');
ok(await page.locator('input').count() === 0, 'ولا حقلَ إدخالٍ إطلاقاً في شاشة الدفع');

await page.click('.btn:has-text("محاكاة: دفع العميل")'); await page.waitForTimeout(180);
ok((await title()) === 'فاتورة جديدة', 'الدفعُ يعيد إلى الفاتورة');
ok((await viewText()).includes('دُفعت عبر أميال باي'), 'وتُقال مدفوعةً');
ok((await viewText()).includes('TX-'), 'ومعها رقمُ الحركة');
ok(!(await page.locator('.btn:has-text("إصدار الفاتورة")').isDisabled()),
   'والإصدارُ انفتح بعد وصول الحركة');

// تبديلُ الطريقة يُسقط الحركة — فلا تُصدَر فاتورةُ آجلٍ بمعرّفٍ من طريقةٍ أخرى
await page.click('button[data-pay="credit"]'); await page.waitForTimeout(120);
await page.click('button[data-pay="amial_pay"]'); await page.waitForTimeout(120);
ok(await page.locator('.btn:has-text("بانتظار دفع العميل")').isDisabled(),
   'وتبديلُ الطريقة يُسقط الحركةَ السابقة');

await page.click('.btn:has-text("عرض رمز الدفع")'); await page.waitForTimeout(150);
await page.click('.btn.ghost:has-text("إلغاء الطلب")'); await page.waitForTimeout(150);
ok((await title()) === 'فاتورة جديدة', 'الإلغاءُ يرجع');
ok(await page.locator('.btn:has-text("بانتظار دفع العميل")').isDisabled(), 'ولا يُصدِر شيئاً');

console.log('\n④ج التحصيل — أربعُ طرقٍ كما يقبل الخادم');
while (await page.locator('#back').isVisible()) { await page.click('#back'); await page.waitForTimeout(80); }
await page.click('.tile:has-text("التحصيلات")'); await page.waitForTimeout(140);
ok(await page.locator('button[data-collect]').count() === 4,
   'أربعُ طرقٍ: نقد · تحويل · أميال · شيك');
await page.click('button[data-collect="amial_pay"]'); await page.waitForTimeout(120);
ok((await viewText()).includes('ليس رقمَ مرجعٍ يكتبه الموظّف'),
   'و«أميال» تُعلَّل: لا يكتبها الموظّف');
await page.click('.btn:has-text("عرض رمز الدفع")'); await page.waitForTimeout(150);
ok((await title()).includes('تحصيل دين'), 'وتفتح شاشةَ تحصيلٍ لا شاشةَ فاتورة');
await page.click('.btn:has-text("محاكاة: دفع العميل")'); await page.waitForTimeout(180);
ok((await page.textContent('#toast')).includes('بعد حركةٍ مدفوعة'),
   'ويُسجَّل التحصيلُ بعد حركةٍ مدفوعة');
await page.click('button[data-collect="cash"]'); await page.waitForTimeout(120);
// المطابقةُ على «رقمُ المرجع» و«النقد» — لا على كلمةٍ مشكولةٍ يختلف
// فيها التنوينُ عن الشدّة، فيسقط الفحصُ على فرقِ حركةٍ لا على عطل.
const cashNote = await viewText();
ok(cashNote.includes('رقمُ المرجع') && cashNote.includes('النقد'),
   'والنقدُ مرجعُه اختياريّ');

console.log('\n⑤ الترقية تفتح التسعير');
await setPlan('business');
await page.click('#back').catch(() => {});
await page.waitForTimeout(100);
while (await page.locator('#back').isVisible()) { await page.click('#back'); await page.waitForTimeout(80); }
ok(await page.locator('.tile.lock:has-text("التسعير")').count() === 0, 'التسعيرُ انفتح في الأعمال');
await page.click('.tile:has-text("التسعير")'); await page.waitForTimeout(120);
ok((await viewText()).includes('شرائح الكمّيّة'), 'شرائحُ الكمّيّة تظهر');
ok((await viewText()).includes('سعر الشركات'), 'سعرُ الشركات يظهر');
ok((await viewText()).includes('لا يعدّل السعر'), 'قيدُ الكاشير مكتوبٌ في الشاشة');

console.log('\n⑥ ثلاثُ قوائمَ في الفاتورة بعد الترقية');
while (await page.locator('#back').isVisible()) { await page.click('#back'); await page.waitForTimeout(80); }
await page.click('.tile:has-text("فاتورة جديدة")'); await page.waitForTimeout(120);
ok(await page.locator('button[data-tier]').count() === 3, 'ثلاثُ قوائمِ أسعار');
await page.click('.btn.ghost:has-text("إضافة صنف")'); await page.waitForTimeout(100);
const retail = await viewText();
await page.click('.row[data-add="0"]'); await page.waitForTimeout(120);
await page.click('button[data-tier="2"]'); await page.waitForTimeout(120);
ok((await viewText()) !== retail, 'تبديلُ القائمة يغيّر السعر فعلاً');

console.log('\n⑦ المؤسسة');
await setPlan('enterprise');
while (await page.locator('#back').isVisible()) { await page.click('#back'); await page.waitForTimeout(80); }
await page.click('.tile:has-text("العملاء والديون")'); await page.waitForTimeout(120);
ok(!(await viewText()).includes('باقة المؤسسة'), 'حساباتُ الشركات مفتوحةٌ في المؤسسة');
await setPlan('business');
await page.waitForTimeout(120);
ok((await viewText()).includes('حسابات الشركات'), 'وفي الأعمال تُقال مقفلةً بسببها');

console.log('\n⑧ موظّفُ الفواتير');
await setRole('pos');
ok((await title()).includes('أحمد'), 'شاشةُ الموظّف تفتح باسمه');
ok((await viewText()).includes('لا يظهر لك رصيدُ المحفظة'), 'الرصيدُ غائبٌ ومُعلَّلٌ');
ok(!(await viewText()).includes('رصيد المحفظة</div>'), 'ولا بطاقةَ رصيدٍ في شاشته');
ok(await page.locator('.tile:has-text("فاتورة جديدة")').count() === 1, 'بلاطةُ الإصدار حاضرة');
ok(await page.locator('.tile:has-text("الفريق")').count() === 0, 'لا بلاطةَ فريقٍ للموظّف');

const before = await page.locator('.tile').count();
await page.click('#segPerms button[data-perm="products"]'); await page.waitForTimeout(120);
ok((await page.locator('.tile').count()) > before, 'منحُ صلاحيّةٍ يزيد بلاطةً');
await page.click('#segPerms button[data-perm="products"]'); await page.waitForTimeout(120);
ok((await page.locator('.tile').count()) === before, 'ونزعُها يُنقصها');

await page.click('#segPerms button[data-perm="sell"]'); await page.waitForTimeout(120);
ok((await page.textContent('#toast')).includes('لا تُنزع'), 'الإصدارُ صلاحيّةٌ لا تُنزع');

console.log('\n⑨ درجُ الموظّف لا يحمل قائمةَ المالك');
await openDrawer();
const db = await page.textContent('#dbody');
ok(!db.includes('محفظتي'), 'لا محفظةَ في درج الموظّف');
ok(!db.includes('مزايا باقتي'), 'ولا باقة');
ok(!db.includes('الفريق والأجهزة'), 'ولا فريق');
ok(db.includes('إغلاق الورديّة'), 'وفيه إغلاقُ الورديّة');
await page.click('#scrim',{position:{x:20,y:300}}); await page.waitForTimeout(200);

console.log('\n⑩ لوحةُ «ما يقوله الخادم»');
await setRole('owner');
await setPlan('free');
const v = await page.textContent('#vList');
ok(v.includes('مربّع الجملة'), 'المربّعُ يُنسب إليه ما يمنحه');
ok(v.includes('لا تُشترى ولا تُنزَع بالباقة'), 'ويُقال إنّه لا يُنزع بالباقة');
ok(v.includes('باقة الأعمال'), 'وما تفتحه الباقةُ منسوبٌ إليها');
const dots0 = await page.locator('#vList .dot.on').count();
await setPlan('enterprise');
const dots2 = await page.locator('#vList .dot.on').count();
ok(dots2 > dots0, `الترقيةُ تُشعل نقاطاً (${dots0} → ${dots2})`);

console.log('\n⑪ لا خطأ في أيّ سجلّ');
// **وسقوطُ مضيف الخطّ يُقال ولا يُحسب عطلاً في الصفحة.** المتصفّحُ
// في هذه الحاوية بلا منفذٍ خارجيّ، و`fonts.googleapis.com` مسموحٌ في
// الأرتفاكت. والبدائلُ في `font-family` تكتب العربيّة، فلا يسقط النصّ.
// (القاعدة السابعة: الغيابُ يُقال بسببه ولا يُمرَّر صفراً صامتاً.)
const fontHost = errs.filter(e => e.includes('Failed to load resource'));
const real = errs.filter(e => !e.includes('Failed to load resource'));
ok(real.length === 0, 'صفرُ أخطاءٍ في الصفحة', real.join(' | '));
if (fontHost.length) {
  console.log('  ⓘ مُخطّى: مضيفُ الخطّ لا يُبلَغ من هذه الحاوية — ' +
    'والبديلُ العربيُّ يعمل: ' +
    await page.evaluate(() => getComputedStyle(document.body).fontFamily));
}

// اللقطةُ مساعدةٌ لا شرط — ومجلّدٌ غائبٌ لا يُسقط فحصاً نجح.
try {
  await page.screenshot({
    path: fileURLToPath(new URL('./لقطة-محاكي-الجملة.png', import.meta.url)) });
} catch { /* لا تُعدّ فشلاً */ }
await browser.close();

console.log(`\n══════════════════\n  نجح ${pass} · فشل ${fail}\n`);
process.exit(fail ? 1 : 0);
