// AMIAL-SIM-RETAIL-001 — **مسبارُ محاكي متجر التجزئة.**
//
// ══════════════════════════════════════════════════════════════════════
// يثبت أخصَّ ما في هذا القطاع: **خمسُ طرقِ دفع**، وفيها «مختلط» —
// نقدٌ ومحفظةٌ معاً، ومجموعُهما يجب أن يساوي الفاتورة. **والأصنافُ
// ليست في مربّع التجزئة**: تبيعها الباقةُ لا يمنحها النشاط.
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

const FILE = new URL('./محاكي-متجر-التجزئة.html', import.meta.url).href;

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

console.log('\n① الإقلاع');
ok((await title()).includes('الأمانة'), 'الرئيسية تفتح باسم المتجر');
ok((await viewText()).includes('رصيد المحفظة'), 'صاحبُ المتجر يرى رصيده');
ok(!(await page.locator('#back').isVisible()), 'لا زرَّ رجوعٍ في الجذر');
ok((await viewText()).includes('ليست في مربّع التجزئة'),
   'والرئيسيّةُ تقول إنّ الأصنافَ ليست من النشاط');

console.log('\n② الدرج — أقسامُ التطبيق');
await page.click('#menu'); await page.waitForTimeout(260);
for (const s of ['نقطة البيع والمرتجعات', 'الأصناف والباركود',
                 'المخزون والموردون', 'العملاء والفريق', 'التقارير والمالية']) {
  ok((await page.textContent('#dbody')).includes(s), `قسم «${s}»`);
}
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(260);

console.log('\n③ المجّانيّةُ تبيع بسطرٍ حرّ — لا كتالوج');
ok(await page.locator('.tile.lock:has-text("الأصناف والباركود")').count() === 1,
   'الأصنافُ مقفلةٌ في المجّانيّة');
await page.click('.tile:has-text("الكاشير")'); await page.waitForTimeout(140);
ok((await title()) === 'الكاشير', 'الكاشير يفتح');
ok((await viewText()).includes('إضافة سطر حرّ'), 'والإضافةُ سطرٌ حرٌّ لا كتالوج');
await page.click('.btn.ghost:has-text("إضافة سطر حرّ")'); await page.waitForTimeout(140);
ok((await title()) === 'سطر حرّ', 'شاشةُ السطر الحرّ تفتح');
ok((await viewText()).includes('ولا كتالوجَ ولا باركود'), 'وتقول ما ينقصها');
await page.click('.btn:has-text("إضافة السطر")'); await page.waitForTimeout(160);
ok((await viewText()).includes('سطر حرّ'), 'والسطرُ ظهر موسوماً في السلّة');
ok(!(await page.locator('.btn:has-text("إتمام البيع")').isDisabled()), 'والبيعُ متاح');

console.log('\n④ خمسُ طرقِ دفعٍ — كما يقبل `CashierController`');
ok(await page.locator('button[data-pay]').count() === 5, 'خمسٌ لا أربع');
for (const m of ['cash', 'amial_pay', 'credit', 'corporate', 'mixed']) {
  ok(await page.locator(`button[data-pay="${m}"]`).count() === 1, `وفيها «${m}»`);
}

console.log('\n⑤ أميال باي');
await page.click('button[data-pay="amial_pay"]'); await page.waitForTimeout(130);
ok(await page.locator('.btn:has-text("بانتظار دفع العميل")').isDisabled(),
   'لا بيعَ قبل وصول الحركة');
await page.click('.btn:has-text("عرض رمز الدفع")'); await page.waitForTimeout(150);
ok(await page.locator('.qr').count() === 1, 'رمزُ الدفع مرسوم');
ok(await page.locator('input').count() === 0, 'ولا حقلَ مرجعٍ يكتبه الكاشير');
await page.click('.btn:has-text("محاكاة: دفع العميل")'); await page.waitForTimeout(170);
ok((await viewText()).includes('دُفعت'), 'ووصلت الحركة');
ok(!(await page.locator('.btn:has-text("إتمام البيع")').isDisabled()), 'والبيعُ انفتح');

console.log('\n⑥ **المختلط — والمجموعُ يساوي الفاتورة**');
await page.click('button[data-pay="mixed"]'); await page.waitForTimeout(140);
ok(await page.locator('.btn:has-text("وزّع المبلغ")').isDisabled(),
   'ولا بيعَ قبل توزيع المبلغ');
ok((await viewText()).includes('حدّد جزءاً نقديّاً'), 'ويُقال ذلك');
ok(await page.locator('button[data-mix]').count() === 3, 'وثلاثةُ أزرارِ نِسَب');
await page.click('button[data-mix]:has-text("50٪")'); await page.waitForTimeout(150);
ok((await viewText()).includes('يساوي الفاتورة'), '**والمجموعُ صار يساويها**');
ok(!(await page.locator('.btn:has-text("إتمام البيع")').isDisabled()), 'والبيعُ انفتح');
const mixText = await viewText();
ok(mixText.includes('من المحفظة'), 'ويُعرَض الجزءان معاً');

console.log('\n⑦ حسابُ الشركة — سقفٌ وباقة');
await page.click('button[data-pay="corporate"]'); await page.waitForTimeout(140);
ok((await viewText()).includes('باقة المؤسسة'), 'المجّانيّة: مقفلٌ بباقة المؤسسة');
ok(await page.locator('.btn:has-text("باقة المؤسسة")').isDisabled(), 'ولا بيعَ به');
await setPlan('enterprise'); await page.waitForTimeout(150);
ok(await page.locator('[data-corp]').count() === 2, 'والمؤسسةُ تُظهر الحسابين');
ok(await page.locator('.btn:has-text("اختر حساب الشركة")').isDisabled(), 'ولا بيعَ بلا حساب');
ok((await viewText()).includes('قارب السقف'), 'والحسابُ القاربُ سقفَه موسوم');
// **والفهرسُ صفريُّ الأساس** — الحسابُ الأوّل يجب أن يُختار.
await page.click('[data-corp="0"]'); await page.waitForTimeout(150);
ok(!(await page.locator('.btn:has-text("إتمام البيع")').isDisabled()),
   '**والحسابُ الأوّل (الفهرس صفر) يُختار فعلاً**');

console.log('\n⑧ الترقيةُ تفتح الكتالوج');
await page.click('#back').catch(() => {});
while (await page.locator('#back').isVisible()) { await page.click('#back'); await page.waitForTimeout(70); }
ok(await page.locator('.tile.lock:has-text("الأصناف والباركود")').count() === 0,
   'الأصنافُ انفتحت في المؤسسة');
await page.click('.tile:has-text("الكاشير")'); await page.waitForTimeout(150);
ok((await viewText()).includes('إضافة صنف'), 'والإضافةُ صارت من كتالوج');
// **والسلّةُ لا تُفرَّغ بالترقية — وهذا صحيح.** أوّلُ صياغةٍ اشترطت
// اختفاءَ السطر الحرّ بعدها، **والمحاكي كان محقّاً**: ما وُضع في السلّة
// يبقى. فيُحذف السطرُ صراحةً قبل قياس الكتالوج.
await page.click('.row[data-line="0"]'); await page.waitForTimeout(150);
await page.click('.btn.ghost:has-text("إضافة صنف")'); await page.waitForTimeout(140);
ok((await title()) === 'اختيار صنف', 'قائمةُ الأصناف تفتح');
ok((await viewText()).includes('قارب النفاد'), 'والصنفُ القاربُ نفادُه موسوم');
await page.click('.row[data-add="0"]'); await page.waitForTimeout(160);
ok((await viewText()).includes('أرز'), 'والصنفُ أُضيف');
ok(!(await viewText()).includes('سطر حرّ'), 'ولا سطرَ حرٍّ بعد الترقية');

console.log('\n⑨ الإتمام');
await page.click('.btn:has-text("إتمام البيع")'); await page.waitForTimeout(170);
ok((await title()) === 'تمّ البيع', 'شاشةُ النجاح');
ok((await viewText()).includes('تقسيم الفاتورة'), 'وتقسيمُ الفاتورة معروض');
ok((await viewText()).includes('لا يُشترى'), 'ويُقال إنّه من المربّع لا من الباقة');

console.log('\n⑩ المرتجعُ من المربّع — والصلاحيّةُ تحكمه');
while (await page.locator('#back').isVisible()) { await page.click('#back'); await page.waitForTimeout(70); }
await page.click('.tile:has-text("المرتجعات")'); await page.waitForTimeout(150);
ok((await viewText()).includes('من مربّع التجزئة'), 'ويُقال إنّه من النشاط لا الباقة');
await setRole('pos');
await page.click('.tile:has-text("الكاشير")').catch(() => {});
await page.waitForTimeout(120);
while (await page.locator('#back').isVisible()) { await page.click('#back'); await page.waitForTimeout(70); }
ok(await page.locator('.tile:has-text("مرتجع")').count() === 0,
   'والكاشيرُ بلا صلاحيّةٍ لا يرى بلاطةَ المرتجع');
await setPerm('refunds');
ok(await page.locator('.tile:has-text("مرتجع")').count() === 1, 'ومنحُها يُظهرها');

console.log('\n⑪ الكاشير لا يرى المحفظة');
ok((await title()).includes('ياسر'), 'شاشتُه باسمه');
ok((await viewText()).includes('لا يظهر لك رصيدُ المحفظة'), 'والرصيدُ غائبٌ ومُعلَّل');
await page.click('#menu'); await page.waitForTimeout(240);
const db = await page.textContent('#dbody');
ok(!db.includes('محفظتي') && !db.includes('مزايا باقتي'), 'ودرجُه بلا محفظةٍ ولا باقة');
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(230);

console.log('\n⑫ البيعُ الآجل — صلاحيّةُ دورٍ لا باقة');
await page.click('.tile:has-text("الكاشير")'); await page.waitForTimeout(150);
await page.click('.btn.ghost'); await page.waitForTimeout(130);
await page.click('.row[data-add="1"]').catch(() => {});
await page.waitForTimeout(150);
await page.click('button[data-pay="credit"]'); await page.waitForTimeout(140);
ok((await viewText()).includes('غيرُ مُصرَّحٍ لك'), 'الكاشيرُ بلا صلاحيّةٍ يُمنَع');
ok(await page.locator('.btn:has-text("الآجلُ غيرُ مُصرَّحٍ لك")').isDisabled(), 'والبيعُ موقوف');
await setPerm('credit');
ok(!(await page.locator('.btn:has-text("إتمام البيع")').isDisabled()), 'ومنحُ الصلاحيّة يفتحه');

console.log('\n⑬ لوحةُ «ما يقوله الخادم»');
await setRole('owner'); await setPlan('free');
const v = await page.textContent('#vList');
ok(v.includes('مربّع التجزئة'), 'المربّعُ يُنسب إليه ما يمنحه');
ok(v.includes('تقسيم الفاتورة'), 'وتقسيمُ الفاتورة منه');
ok(v.includes('تبيعها الباقةُ لا يمنحها النشاط'),
   '**والأصنافُ يُقال فيها ذلك بنصّه**');
const on0 = await page.locator('#vList .dot.on').count();
await setPlan('enterprise');
const on2 = await page.locator('#vList .dot.on').count();
ok(on2 > on0, `الترقيةُ تُشعل نقاطاً (${on0} → ${on2})`);

console.log('\n⑭ لا خطأ في أيّ سجلّ');
const fontHost = errs.filter(e => e.includes('Failed to load resource'));
const real = errs.filter(e => !e.includes('Failed to load resource'));
ok(real.length === 0, 'صفرُ أخطاءٍ في الصفحة', real.join(' | '));
if (fontHost.length) {
  console.log('  ⓘ مُخطّى: مضيفُ الخطّ لا يُبلَغ من هذه الحاوية — والبديلُ العربيُّ يعمل.');
}

try {
  await page.screenshot({ path: fileURLToPath(new URL('./لقطة-محاكي-التجزئة.png', import.meta.url)) });
} catch { /* اللقطةُ مساعدةٌ لا شرط */ }

await browser.close();
console.log(`\n══════════════════\n  نجح ${pass} · فشل ${fail}\n`);
process.exit(fail ? 1 : 0);
