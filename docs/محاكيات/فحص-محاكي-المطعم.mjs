// AMIAL-SIM-RESTAURANT-001 — **مسبارُ محاكي المطعم.**
//
// ══════════════════════════════════════════════════════════════════════
// يثبت أخصَّ ما في هذا القطاع: **مربّعٌ بلا عمقٍ مبيع.** `paidDepth()`
// فارغةٌ للمطعم وحدَه بين القطاعات — فالطاولاتُ والطلباتُ والمطبخُ
// مجّانيّةٌ كلُّها، وما يُشترى يأتي من الباقة العامّة لا من النشاط.
// ويثبت أنّ **الإرسالَ للمطبخ فعلٌ قائمٌ بذاته** لا يقع مع الإقفال.
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

const FILE = new URL('./محاكي-المطعم.html', import.meta.url).href;

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
ok((await title()).includes('البركة'), 'الرئيسية تفتح باسم المطعم');
ok((await viewText()).includes('رصيد المحفظة'), 'صاحبُ المطعم يرى رصيده');
ok(!(await page.locator('#back').isVisible()), 'لا زرَّ رجوعٍ في الجذر');
ok((await viewText()).includes('ولا عمقَ يُباع فيه'), 'ويُقال إنّ المربّعَ بلا عمقٍ مبيع');

console.log('\n② الدرج — أقسامُ التطبيق');
await page.click('#menu'); await page.waitForTimeout(260);
for (const s of ['الطلبات والطاولات', 'البيع والتحصيل',
                 'الفريق والعملاء', 'التقارير والمالية']) {
  ok((await page.textContent('#dbody')).includes(s), `قسم «${s}»`);
}
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(260);

console.log('\n③ ثلاثُ قدراتٍ مجّانيّةٍ من المربّع');
await page.click('#menu'); await page.waitForTimeout(240);
await page.click('.ditem:has-text("مزايا باقتي")'); await page.waitForTimeout(180);
for (const n of ['طاولات المطعم', 'طلبات المطعم', 'شاشة المطبخ']) {
  ok((await viewText()).includes(n), `«${n}» معروضة`);
}
ok((await viewText()).includes('ولا عمقَ قطاعيٌّ يُباع للمطعم'),
   '**ويُقال إنّ `paidDepth()` فارغة**');
ok((await viewText()).includes('paidDepth'), 'وتُسمّى الدالّةُ بنصّها');

console.log('\n④ الصالة — واللونُ ليس وحدَه');
await home();
await page.click('.tile:has-text("الطاولات والطلبات")'); await page.waitForTimeout(150);
ok((await title()) === 'الطاولات والطلبات', 'شاشةُ الصالة تفتح');
ok(await page.locator('[data-table]').count() === 6, 'ستُّ طاولات');
ok((await viewText()).includes('واللونُ ليس وحدَه'), 'ويُقال إنّ الفرقَ ليس باللون وحدَه');

// **والفهرسُ صفريُّ الأساس** — الطاولةُ الأولى يجب أن تُفتح.
await page.click('[data-table="0"]'); await page.waitForTimeout(160);
ok((await title()).includes('طاولة ١'),
   '**والطاولةُ الأولى (الفهرس صفر) تُفتح فعلاً**');
ok((await viewText()).includes('الطلب فارغ'), 'وطلبُها فارغ');

console.log('\n⑤ الطلبُ يُبنى');
await page.click('.btn.ghost:has-text("إضافة صنف")'); await page.waitForTimeout(140);
ok((await title()) === 'إضافة صنف', 'قائمةُ المنيو تفتح');
ok((await viewText()).includes('وهذا منيو العرض'), 'ويُقال إنّ إدارتَه من الباقة');
await page.click('[data-dish="0"]'); await page.waitForTimeout(160);
ok((await viewText()).includes('مندي لحم'), 'والصنفُ أُضيف');
await page.click('.btn.ghost:has-text("إضافة صنف")'); await page.waitForTimeout(130);
await page.click('[data-dish="4"]'); await page.waitForTimeout(160);
ok((await viewText()).includes('عصير مانجو'), 'وصنفٌ ثانٍ');

console.log('\n⑥ **الإرسالُ للمطبخ فعلٌ قائمٌ بذاته**');
ok((await viewText()).includes('لم يُرسَل بعد'), 'ويُقال إنّه لم يُرسَل');
ok((await viewText()).includes('وما لم يُرسَل لا يُحضَّر'), 'وما لم يُرسَل لا يُحضَّر');
await page.click('.btn:has-text("إرسال إلى المطبخ")'); await page.waitForTimeout(160);
ok((await viewText()).includes('أُرسل إلى المطبخ'), 'وأُرسل');

console.log('\n⑦ شاشةُ المطبخ');
await home();
await page.click('.tile:has-text("شاشة المطبخ")'); await page.waitForTimeout(150);
ok((await title()) === 'شاشة المطبخ', 'تفتح');
ok((await viewText()).includes('طاولة ١'), 'والطلبُ فيها');
ok((await viewText()).includes('قيد التحضير'), 'وموسومٌ قيد التحضير');
await page.click('[data-ready="0"]'); await page.waitForTimeout(160);
ok((await toastText()).includes('جهز'), 'والضغطُ يُعلّمه «جهز»');
ok((await viewText()).includes('لم يُرسَل شيء'),
   '**و«لا شيء» تُقال «لم يُرسَل» لا «كلُّ شيءٍ جهز»**');

console.log('\n⑧ الإقفال بالفاتورة — ثلاثُ طرقٍ كما يقبل `closeOrder`');
await home();
await page.click('.tile:has-text("الطاولات والطلبات")'); await page.waitForTimeout(150);
await page.click('[data-table="0"]'); await page.waitForTimeout(160);
ok(await page.locator('button[data-pay]').count() === 3, 'ثلاثُ طرق');
for (const m of ['cash', 'amial_pay', 'credit']) {
  ok(await page.locator(`button[data-pay="${m}"]`).count() === 1, `وفيها «${m}»`);
}
await page.click('button[data-pay="amial_pay"]'); await page.waitForTimeout(140);
ok(await page.locator('.btn:has-text("بانتظار دفع العميل")').isDisabled(),
   'ولا إقفالَ قبل وصول الحركة');
await page.click('.btn:has-text("عرض رمز الدفع")'); await page.waitForTimeout(150);
ok(await page.locator('.qr').count() === 1, 'رمزُ الدفع مرسوم');
ok(await page.locator('input').count() === 0, 'ولا حقلَ مرجعٍ يكتبه الموظّف');
await page.click('.btn:has-text("محاكاة: دفع العميل")'); await page.waitForTimeout(170);
ok((await viewText()).includes('دُفعت'), 'ووصلت الحركة');
await page.click('.btn:has-text("إقفال الطلب بالفاتورة")'); await page.waitForTimeout(170);
ok((await title()) === 'أُقفل الطلب', 'شاشةُ النجاح');
ok((await viewText()).includes('الطاولةُ صارت شاغرة'), 'ويُقال أثرُه على الطاولة');
ok((await viewText()).includes('خرج من المطبخ'), 'وعلى المطبخ');

console.log('\n⑨ موظّفُ الصالة — والصلاحيّاتُ تحكم');
await setRole('pos');
ok((await title()).includes('وليد'), 'شاشتُه باسمه');
ok((await viewText()).includes('لا يظهر لك رصيدُ المحفظة'), 'والرصيدُ غائبٌ ومُعلَّل');
ok((await viewText()).includes('غيرُ مُصرَّح'), 'والإقفالُ غيرُ مُصرَّحٍ له بعد');
ok(await page.locator('.tile:has-text("شاشة المطبخ")').count() === 0,
   'ولا بلاطةَ مطبخٍ بلا صلاحيّة');
await setPerm('kitchen');
ok(await page.locator('.tile:has-text("شاشة المطبخ")').count() === 1, 'ومنحُها يُظهرها');

await page.click('.tile:has-text("الطاولات")'); await page.waitForTimeout(150);
await page.click('[data-table="1"]'); await page.waitForTimeout(160);
ok(await page.locator('.btn:has-text("الإقفالُ غيرُ مُصرَّحٍ لك")').isDisabled(),
   '**والإقفالُ موقوفٌ بلا صلاحيّة** رغم امتلاء الطلب');
await setPerm('close');
ok(!(await page.locator('.btn:has-text("إقفال الطلب بالفاتورة")').isDisabled()),
   'ومنحُها يفتحه');

console.log('\n⑩ درجُ الموظّف');
await page.click('#menu'); await page.waitForTimeout(250);
const db = await page.textContent('#dbody');
ok(!db.includes('محفظتي') && !db.includes('مزايا باقتي'), 'بلا محفظةٍ ولا باقة');
ok(db.includes('شاشة المطبخ'), 'وفيه المطبخُ بعد منحِه');
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(230);

console.log('\n⑪ لوحةُ «ما يقوله الخادم»');
await setRole('owner'); await setPlan('free');
const v = await page.textContent('#vList');
ok(v.includes('مربّع المطعم'), 'المربّعُ يُنسب إليه ما يمنحه');
ok(v.includes('المنيو ليس في المربّع'), '**والمنيو يُقال فيه ذلك**');
const on0 = await page.locator('#vList .dot.on').count();
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
  await page.screenshot({ path: fileURLToPath(new URL('./لقطة-محاكي-المطعم.png', import.meta.url)) });
} catch { /* اللقطةُ مساعدةٌ لا شرط */ }

await browser.close();
console.log(`\n══════════════════\n  نجح ${pass} · فشل ${fail}\n`);
process.exit(fail ? 1 : 0);
