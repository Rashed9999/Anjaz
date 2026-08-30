// AMIAL-SIM-PHARMACY-001 — **مسبارُ محاكي الصيدلية.**
//
// ══════════════════════════════════════════════════════════════════════
// يضغط كلَّ زرّ، ويثبت الموانعَ الثلاثةَ للبيع كما يرميها الخادم:
// وصفةٌ غائبة · تحذيرُ حساسيّةٍ بلا إقرار · دفعٌ لم يصل. ويثبت أخصَّ ما
// في هذا القطاع: **قيدا الوصفة المستقلّان** — الباقةُ والدور.
//
// وESM لا يقرأ `NODE_PATH` — يقرؤه `require` وحدَه. فالنمطُ هو نفسُه في
// `flow-coverage-probe.mjs`.
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

const FILE = new URL('./محاكي-الصيدلية.html', import.meta.url).href;

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
const addMed = async ix => {
  await page.click('.btn.ghost:has-text("إضافة دواء")'); await page.waitForTimeout(110);
  await page.click(`.row[data-add="${ix}"]`); await page.waitForTimeout(140);
};

console.log('\n① الإقلاع والتنبيهات');
ok((await title()).includes('الشفاء'), 'الرئيسية تفتح باسم الصيدلية');
ok((await viewText()).includes('رصيد المحفظة'), 'المالك يرى رصيده');
ok(!(await page.locator('#back').isVisible()), 'لا زرَّ رجوعٍ في الجذر');
ok(await page.locator('.alerts button').count() === 3, 'ثلاثةُ أصنافِ تنبيه');
ok(await page.locator('.alerts .crit').count() === 1, 'والمنتهي يُعرَض حرجاً');

console.log('\n② الدرج — أقسامُ التطبيق');
await page.click('#menu'); await page.waitForTimeout(260);
for (const s of ['بيع الصيدلية والوصفات', 'البيع والتحصيل', 'الأدوية والمخزون',
                 'العملاء والفريق', 'التقارير والمالية']) {
  ok((await page.textContent('#dbody')).includes(s), `قسم «${s}»`);
}
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(260);
ok(!(await page.locator('#drawer.open').count()), 'الدرج يُغلق بالخلفيّة');

console.log('\n③ «عملاء الصيدلية» قريباً — لا مقفلةً بباقة');
ok(await page.locator('.tile.lock:has-text("عملاء الصيدلية")').count() === 1,
   'البلاطةُ معروضةٌ ومقيَّدة');
await page.click('.tile.lock:has-text("عملاء الصيدلية")'); await page.waitForTimeout(150);
ok((await toastText()).includes('قريباً'), 'وتقول «قريباً» لا «باقة كذا»');
await setPlan('enterprise'); await page.waitForTimeout(120);
ok(await page.locator('.tile.lock:has-text("عملاء الصيدلية")').count() === 1,
   '**ولا تنفتح بأعلى باقة** — لأنّها لم تُبَع أصلاً');
await setPlan('free');

console.log('\n④ المانعُ الأوّل: وصفةٌ غائبة');
await home();
await page.click('.tile:has-text("بيع دواء")'); await page.waitForTimeout(130);
ok((await title()) === 'بيع دواء', 'شاشةُ البيع تفتح');
await addMed(0);
ok((await viewText()).includes('باراسيتامول'), 'دواءٌ بلا وصفةٍ يُضاف');
ok(!(await page.locator('.btn:has-text("إتمام البيع")').isDisabled()),
   'والبيعُ متاحٌ به وحدَه');

await addMed(2);
ok((await viewText()).includes('ترامادول'), 'ودواءٌ يستلزم وصفةً يُضاف');
ok(await page.locator('.btn:has-text("يستلزم وصفة")').isDisabled(),
   '**فيُمنع البيع** — كما يرمي `PharmacySaleService`');
ok((await viewText()).includes('تستلزم وصفة طبية'), 'ورسالةُ الخادم بنصّها');

console.log('\n⑤ وقيدا الوصفة مستقلّان — ولكلٍّ رسالتُه');
// مجّانيّ + مالك: الباقةُ هي المانع
ok((await viewText()).includes('الميزةُ غيرُ مشتراة'), 'المجّانيّة: الباقةُ هي المانع');
ok(!(await viewText()).includes('غيرُ مُصرَّحٍ بتوثيق'), 'ولا يُلام الدورُ ظلماً');

await setPlan('business');
ok((await viewText()).includes('لا يُتمّ البيعُ بلا رقمها'), 'الأعمال: البابُ انفتح للمالك');
ok(await page.locator('.btn:has-text("توثيق الوصفة")').count() === 1, 'وزرُّ التوثيق ظهر');

// كاشير بلا صلاحيّة: الدورُ هو المانع رغم أنّ الباقة اشترت
await setRole('pos');
await page.click('.tile:has-text("بيع دواء")'); await page.waitForTimeout(130);
await addMed(2);
ok((await viewText()).includes('غيرُ مُصرَّحٍ بتوثيق الوصفة'),
   '**الباقةُ اشترت والدورُ يمنع** — والرسالةُ تقول ذلك');
ok((await viewText()).includes('pharmacy.prescription.record'), 'وتسمّي الصلاحيّة');
ok(await page.locator('.btn:has-text("توثيق الوصفة")').count() === 0,
   'ولا زرَّ توثيقٍ يُعرَض لمن لا يملكها');

await setPerm('prescription');
ok((await viewText()).includes('لا يُتمّ البيعُ بلا رقمها'), 'ومنحُ الصلاحيّة يفتحه');
ok(await page.locator('.btn:has-text("توثيق الوصفة")').count() === 1, 'وظهر الزرّ');

// والباقةُ تسقط فيعود المنع رغم الصلاحيّة
await setPlan('free');
ok((await viewText()).includes('الميزةُ غيرُ مشتراة'),
   '**وإسقاطُ الباقة يمنع ولو بقيت الصلاحيّة** — لا يُغني أحدُهما عن الآخر');
await setPlan('business');

console.log('\n⑥ توثيقُ الوصفة يرفع المنع');
await page.click('.btn:has-text("توثيق الوصفة")'); await page.waitForTimeout(140);
ok((await title()) === 'توثيق الوصفة', 'شاشةُ التوثيق تفتح');
ok((await viewText()).includes('تُحفَظ على البيعة'), 'ويُقال إنّها تُحفَظ على البيعة');
await page.click('.btn:has-text("حفظ الوصفة")'); await page.waitForTimeout(160);
ok((await viewText()).includes('وُثّقت الوصفة'), 'وتُقال موثَّقة');
ok(!(await page.locator('.btn:has-text("إتمام البيع")').isDisabled()),
   'والبيعُ انفتح بعدها');

console.log('\n⑦ المانعُ الثاني: حساسيّةٌ بلا إقرار — تحذيرٌ لا منع');
await addMed(1);   // أموكسيسيلين — بنسلين
ok((await viewText()).includes('تنبيه حساسية'), 'التحذيرُ ظهر');
ok((await viewText()).includes('بنسلين'), 'ويسمّي المادّة');
ok(await page.locator('.btn:has-text("يستلزم إقرارًا"), .btn:has-text("يستلزم إقراراً")').isDisabled(),
   'والبيعُ موقوفٌ حتّى الإقرار');
ok((await viewText()).includes('لا يمنع البيع'), 'ويُقال إنّه تحذيرٌ لا منع');
await page.click('.btn.ghost:has-text("أُقرّ وأتحمّل")'); await page.waitForTimeout(150);
ok((await toastText()).includes('حُفظ الإقرار'), 'والإقرارُ يُحفَظ مع البيعة');
ok(!(await page.locator('.btn:has-text("إتمام البيع")').isDisabled()), 'والبيعُ انفتح');

console.log('\n⑧ المانعُ الثالث: دفعٌ لم يصل');
ok(await page.locator('button[data-pay]').count() === 3,
   'ثلاثُ طرقِ دفعٍ — كما يقبل `recordSale`');
await page.click('button[data-pay="amial_pay"]'); await page.waitForTimeout(120);
ok(await page.locator('.btn:has-text("بانتظار دفع العميل")').isDisabled(),
   '**لا بيعَ قبل وصول الحركة**');
await page.click('.btn:has-text("عرض رمز الدفع")'); await page.waitForTimeout(150);
ok(await page.locator('.qr').count() === 1, 'رمزُ الدفع مرسوم');
ok(await page.locator('input').count() === 0, 'ولا حقلَ مرجعٍ يكتبه الكاشير');
await page.click('.btn:has-text("محاكاة: دفع العميل")'); await page.waitForTimeout(170);
ok((await viewText()).includes('دُفعت عبر أميال باي'), 'ووصلت الحركة');
ok(!(await page.locator('.btn:has-text("إتمام البيع")').isDisabled()), 'والبيعُ انفتح');

console.log('\n⑨ الإتمام و FEFO');
await page.click('.btn:has-text("إتمام البيع")'); await page.waitForTimeout(170);
ok((await title()) === 'تمّ البيع', 'شاشةُ النجاح');
ok((await viewText()).includes('الأقربُ انتهاءً أوّلاً'), 'ويُقال إنّ الخصم FEFO');
ok((await viewText()).includes('RX-'), 'ورقمُ الوصفة على البيعة');

console.log('\n⑩ الدفعات — والمنتهي لا يُباع');
await setRole('owner'); await setPlan('business');
await page.click('.tile:has-text("الأدوية والدفعات")'); await page.waitForTimeout(140);
ok((await viewText()).includes('لا يُباع منتهي الصلاحية'), 'الشاشةُ تقولها صراحةً');
await page.click('.row:has-text("أموكسيسيلين")'); await page.waitForTimeout(150);
ok((await title()) === 'دفعات الدواء', 'شاشةُ الدفعات تفتح');
ok((await viewText()).includes('منتهية — لا تُباع'), 'والدفعةُ المنتهيةُ موسومةٌ كذلك');
ok((await viewText()).includes('FEFO'), 'ويُقال الترتيب');

console.log('\n⑪ الكاشير لا يرى المحفظة');
await setRole('pos');
ok((await title()).includes('مروان'), 'شاشةُ الكاشير باسمه');
ok((await viewText()).includes('لا يظهر لك رصيدُ المحفظة'), 'والرصيدُ غائبٌ ومُعلَّل');
ok(await page.locator('.tile:has-text("الفريق")').count() === 0, 'ولا بلاطةَ فريق');
await page.click('#menu'); await page.waitForTimeout(260);
const db = await page.textContent('#dbody');
ok(!db.includes('محفظتي') && !db.includes('مزايا باقتي'), 'ودرجُه بلا محفظةٍ ولا باقة');
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(230);

console.log('\n⑫ لوحةُ «ما يقوله الخادم»');
await setRole('owner'); await setPlan('free');
const v = await page.textContent('#vList');
ok(v.includes('مربّع الصيدلية'), 'المربّعُ يُنسب إليه ما يمنحه');
ok(v.includes('قريباً'), 'و«قريباً» صنفٌ ثالثٌ لا مقفلٌ ولا مفتوح');
ok(v.includes('pharmacy.prescription.record'), 'وقيدُ الدور يُعرَض قيداً مستقلّاً');
ok(v.includes('لا الباقةُ اشترتها ولا الدورُ يمنحها')
   || v.includes('الدورُ يمنحها، والباقةُ لم تشترِها'),
   'ويقول أيَّ البابين مغلق');
const on0 = await page.locator('#vList .dot.on').count();
await setPlan('enterprise');
const on2 = await page.locator('#vList .dot.on').count();
ok(on2 > on0, `الترقيةُ تُشعل نقاطاً (${on0} → ${on2})`);

console.log('\n⑬ لا خطأ في أيّ سجلّ');
const fontHost = errs.filter(e => e.includes('Failed to load resource'));
const real = errs.filter(e => !e.includes('Failed to load resource'));
ok(real.length === 0, 'صفرُ أخطاءٍ في الصفحة', real.join(' | '));
if (fontHost.length) {
  console.log('  ⓘ مُخطّى: مضيفُ الخطّ لا يُبلَغ من هذه الحاوية — والبديلُ العربيُّ يعمل.');
}

try {
  await page.screenshot({ path: fileURLToPath(new URL('./لقطة-محاكي-الصيدلية.png', import.meta.url)) });
} catch { /* اللقطةُ مساعدةٌ لا شرط */ }

await browser.close();
console.log(`\n══════════════════\n  نجح ${pass} · فشل ${fail}\n`);
process.exit(fail ? 1 : 0);
