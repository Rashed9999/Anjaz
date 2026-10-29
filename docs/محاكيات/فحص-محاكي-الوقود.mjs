// AMIAL-SIM-FUEL-001 — **مسبارُ محاكي محطة الوقود.**
//
// ══════════════════════════════════════════════════════════════════════
// يثبت أخصَّ ما في هذا القطاع: **ما ليس فيه.** سأل صاحبُ المشروع «لماذا
// تاجر وقود لديه أصناف ومخزون؟» فقِيس فإذا الوقودُ يصله ٣٩ ميزةً منها
// الأصنافُ والمخزون. فصار «لا ينطبق» صنفاً ثالثاً لا يفتحه أيُّ دفع.
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

const FILE = new URL('./محاكي-محطة-الوقود.html', import.meta.url).href;

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
ok((await title()).includes('النصر'), 'الرئيسية تفتح باسم المحطة');
ok((await viewText()).includes('رصيد المحفظة'), 'صاحبُ المحطّة يرى رصيده');
ok(!(await page.locator('#back').isVisible()), 'لا زرَّ رجوعٍ في الجذر');
ok((await viewText()).includes('ولا أصنافَ ولا مخزونَ'), 'والرئيسيّةُ تقول ما ليس فيها');

console.log('\n② الدرج — بنودُ التطبيق');
await page.click('#menu'); await page.waitForTimeout(260);
for (const s of ['بيع الوقود', 'بيع آجل وبطاقة شركة', 'تشغيل المضخات والخزانات',
                 'الخزانات والقياسات', 'توريدات الوقود', 'الورديات وسجل البيع',
                 'أسعار الوقود', 'حسابات الشركات والبطاقات',
                 'فواتير ومبيعات الوقود', 'فريق المحطة وصلاحياته', 'إعدادات المحطة']) {
  ok((await page.textContent('#dbody')).includes(s), `بند «${s}»`);
}
ok(!(await page.textContent('#dbody')).includes('الأصناف'), 'ولا بندَ أصنافٍ في درج المحطّة');
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(260);

console.log('\n③ **«لا ينطبق» ليست «مقفلة»** — وهو ما سأل عنه صاحبُ المشروع');
await page.click('#menu'); await page.waitForTimeout(240);
await page.click('.ditem:has-text("مزايا باقتي")'); await page.waitForTimeout(180);
ok((await title()) === 'مزايا باقتي', 'شاشةُ المزايا تفتح');
ok((await viewText()).includes('لا تنطبق على محطة وقود'), 'وفيها قسمٌ ثالثٌ قائمٌ بذاته');
for (const n of ['الأصناف', 'المخزون', 'الموردون', 'دفتر الديون']) {
  ok((await viewText()).includes(n), `«${n}» مذكورةٌ في «لا تنطبق»`);
}
ok((await viewText()).includes('الترقيةُ لا تفتحها أبداً'), 'ويُقال إنّ الترقيةَ لا تفتحها');

const neverBefore = await page.locator('.chip.bd:has-text("لا ينطبق")').count();
await setPlan('enterprise');
const neverAfter = await page.locator('.chip.bd:has-text("لا ينطبق")').count();
ok(neverBefore === neverAfter && neverAfter > 0,
   `**ولا تنقص بأعلى باقة** (${neverBefore} → ${neverAfter})`);
await setPlan('free');

console.log('\n④ دفترُ الدَين ليس من المحطّة — الائتمانُ ببطاقة');
const v0 = await page.textContent('#vList');
ok(v0.includes('دفتر الديون'), 'اللوحةُ تذكره');
ok(v0.includes('ببطاقاتٍ لا بدفترِ دَين'), 'وتقول لماذا — كما في `VerticalRegistry`');
ok(await page.locator('#vList .dot.never').count() >= 3, 'وله نقطةٌ حمراءُ لا رماديّة');

console.log('\n⑤ لوحةُ الأرقام — وهي التي اختفت مرّةً ثمّ عادت');
await home();
await page.click('.tile:has-text("بيع الوقود")'); await page.waitForTimeout(140);
ok((await title()) === 'بيع الوقود', 'شاشةُ البيع تفتح');
ok(await page.locator('.keypad button').count() === 12, 'واللوحةُ اثنتا عشرةَ خانة');
ok(await page.locator('.quick button').count() === 4, 'وأربعةُ أزرارٍ سريعة');

await tap('5'); await tap('0'); await tap('0'); await tap('0');
ok((await viewText()).includes('5,000'), 'الإدخالُ يظهر');
ok((await viewText()).includes('لتر'), 'ويُحوَّل إلى لترات');
await tap('⌫');
ok((await viewText()).includes('500 ر.ي'), 'والمسحُ يعمل');
await tap('C');
ok((await viewText()).includes('0 ر.ي'), 'والتصفيرُ يعمل');

await page.click('.quick button:has-text("10k")'); await page.waitForTimeout(120);
ok((await viewText()).includes('10,000'), 'والزرُّ السريع يملأ');

await page.click('button[data-mode="litre"]'); await page.waitForTimeout(120);
ok((await viewText()).includes('0 لتر'), 'وتبديلُ الوضع يُصفّر');
await tap('2'); await tap('0');
ok((await viewText()).includes('20 لتر'), 'والإدخالُ باللتر يعمل');
const beforeFuel = await viewText();
await page.click('button[data-fuel="1"]'); await page.waitForTimeout(120);
ok((await viewText()) !== beforeFuel, 'وتبديلُ نوع الوقود يغيّر الحساب');
await page.click('button[data-fuel="0"]'); await page.waitForTimeout(100);

console.log('\n⑥ المضخةُ المتوقّفة لا يُسجَّل عليها بيع');
await page.click('button[data-pump="3"]'); await page.waitForTimeout(130);
ok((await viewText()).includes('هذه المضخة متوقّفة'), 'ويُقال ذلك في الشاشة');
ok(await page.locator('.btn:has-text("المضخة متوقّفة")').isDisabled(), 'والبيعُ موقوف');
await page.click('button[data-pump="0"]'); await page.waitForTimeout(120);
ok(!(await page.locator('.btn:has-text("تسجيل البيع")').isDisabled()), 'ومضخّةٌ تعمل تُعيده');

console.log('\n⑦ ما يتجاوز الخزّان يُرفَض');
await page.click('button[data-mode="litre"]'); await page.waitForTimeout(110);
for (const d of ['9', '9', '9', '9', '9']) await tap(d);   // ٩٩٩٩٩ لتر
ok(await page.locator('.btn:has-text("يتجاوز الخزّان")').isDisabled(),
   '**الكمّيّةُ فوق رصيد الخزّان تمنع البيع**');
ok((await viewText()).includes('رصيدَ الخزّان'), 'والرسالةُ تقول السبب');
await tap('C'); await page.click('button[data-mode="money"]'); await page.waitForTimeout(110);

console.log('\n⑧ أميال باي على اللوحة');
await page.click('.quick button:has-text("10k")'); await page.waitForTimeout(110);
ok(await page.locator('.seg button[data-pay]').count() >= 2, 'طريقتان على اللوحة');
await page.click('button[data-pay="amial_pay"]'); await page.waitForTimeout(120);
await page.click('.btn:has-text("عرض رمز الدفع")'); await page.waitForTimeout(150);
ok(await page.locator('.qr').count() === 1, 'رمزُ الدفع مرسوم');
ok(await page.locator('input').count() === 0, 'ولا حقلَ مرجعٍ يكتبه الموظّف');
await page.click('.btn:has-text("محاكاة: دفع العميل")'); await page.waitForTimeout(170);
ok((await viewText()).includes('دُفعت'), 'ووصلت الحركة');
ok(!(await page.locator('.btn:has-text("تسجيل البيع")').isDisabled()), 'والبيعُ انفتح');

console.log('\n⑨ الشاشتان لا تُستبدل إحداهما بالأخرى');
ok(await page.locator('.btn.ghost:has-text("بيع آجل وبطاقة شركة")').count() === 1,
   'واللوحةُ تقود إلى المطوّلة');
// **ويُرفَع المبلغُ إلى ٥٠ ألفاً قبل اختبار السقف.** أوّلُ صياغةٍ تركت
// ١٠ آلافٍ وسقفَ البطاقة المتبقّي ٢٠ ألفاً، فلم يتجاوزه — **والمحاكي
// كان محقّاً والمسبارُ خاطئاً**. والمبلغُ يُدخَل من اللوحة لأنّ
// المطوّلةَ تقرؤه ولا تُدخله.
await page.click('.quick button:has-text("50k")'); await page.waitForTimeout(120);
await page.click('.btn.ghost:has-text("بيع آجل وبطاقة شركة")'); await page.waitForTimeout(160);
ok((await title()) === 'بيع آجل وبطاقة شركة', 'المطوّلةُ تفتح');
ok((await viewText()).includes('باقة الأعمال'), 'وهي مقفلةٌ في المجّانيّة');

await setPlan('business');
ok(await page.locator('button[data-pay]').count() === 4,
   'أربعُ طرقٍ — كما يقبل `FuelStationController`');
for (const m of ['company_card', 'credit', 'cash', 'amial_pay']) {
  ok(await page.locator(`button[data-pay="${m}"]`).count() === 1, `وفيها «${m}»`);
}

console.log('\n⑩ سقفُ البطاقة يُفحَص قبل البيع');
await page.click('button[data-pay="company_card"]'); await page.waitForTimeout(130);
ok(await page.locator('.btn:has-text("اختر البطاقة")').isDisabled(), 'ولا بيعَ بلا بطاقة');
ok((await viewText()).includes('قارب السقف'), 'والبطاقةُ القاربةُ سقفَها موسومة');
await page.click('.row[data-card="2"]'); await page.waitForTimeout(140);   // متبقٍّ ٢٠٠٠٠
ok(await page.locator('.btn:has-text("يتجاوز السقف")').isDisabled(),
   '**والمبلغُ فوق المتبقّي يُرفَض**');
ok((await viewText()).includes('سقف الشركة'), 'والرسالةُ تقول السبب');
await page.click('.row[data-card="0"]'); await page.waitForTimeout(140);   // متبقٍّ ٥٤٠٠٠٠
ok(!(await page.locator('.btn:has-text("تسجيل البيع")').isDisabled()),
   'وبطاقةٌ فيها متّسعٌ تُعيده');
ok((await viewText()).includes('لا يُسجَّل ديناً'), 'ويُقال إنّه لا يُقيَّد ديناً');

console.log('\n⑪ الإتمام — والأثرُ على الخزّان والورديّة');
await page.click('.btn:has-text("تسجيل البيع")'); await page.waitForTimeout(170);
ok((await title()) === 'تمّ البيع', 'شاشةُ النجاح');
ok((await viewText()).includes('خُصم من الخزّان'), 'ويُقال أثرُه على الخزّان');
ok((await viewText()).includes('عدّاد المضخة'), 'وعلى عدّاد المضخة');

console.log('\n⑫ فروقُ الورديّة — و«لم يُقَس» ليس «صفراً»');
await home(); await setPlan('free');
await page.click('.tile:has-text("الورديات")'); await page.waitForTimeout(150);
ok((await viewText()).includes('لم يُقَس'), 'المجّانيّة تقول إنّ الفرقَ لم يُقَس');
ok(!(await viewText()).includes('فرق −'), 'ولا تعرض رقماً مخترَعاً');
await setPlan('business'); await page.waitForTimeout(120);
ok((await viewText()).includes('فرق −١٢ لتر'), 'والأعمالُ تُظهر الفرقَ المقيس');

console.log('\n⑬ موظّفُ المضخة');
await setRole('pos');
ok((await title()).includes('أحمد'), 'شاشتُه باسمه');
ok((await viewText()).includes('لا يظهر لك رصيدُ المحفظة'), 'والرصيدُ غائبٌ ومُعلَّل');
const t0 = await page.locator('.tile').count();
await setPerm('shift'); await page.waitForTimeout(120);
ok((await page.locator('.tile').count()) > t0, 'منحُ صلاحيّةٍ يزيد بلاطةً');
await page.click('#menu'); await page.waitForTimeout(240);
const db = await page.textContent('#dbody');
ok(!db.includes('محفظتي') && !db.includes('أسعار الوقود'),
   'ودرجُه بلا محفظةٍ ولا أسعار');
await page.click('#scrim', { position: { x: 20, y: 300 } }); await page.waitForTimeout(230);
await setPerm('shift'); await page.waitForTimeout(120);
await page.click('.tile:has-text("المضخات")'); await page.waitForTimeout(140);
await home();

console.log('\n⑭ لوحةُ «ما يقوله الخادم»');
await setRole('owner'); await setPlan('free');
const v = await page.textContent('#vList');
ok(v.includes('مربّع الوقود'), 'المربّعُ يُنسب إليه ما يمنحه');
ok(v.includes('ولا تُفتح بأيّ باقة'), 'و«لا ينطبق» تُقال صراحةً');
const on0 = await page.locator('#vList .dot.on').count();
await setPlan('enterprise');
const on2 = await page.locator('#vList .dot.on').count();
ok(on2 > on0, `الترقيةُ تُشعل نقاطاً (${on0} → ${on2})`);
const never2 = await page.locator('#vList .dot.never').count();
ok(never2 >= 3, `**ولا تُطفئ الحمراء** — بقيت ${never2}`);

console.log('\n⑮ لا خطأ في أيّ سجلّ');
const fontHost = errs.filter(e => e.includes('Failed to load resource'));
const real = errs.filter(e => !e.includes('Failed to load resource'));
ok(real.length === 0, 'صفرُ أخطاءٍ في الصفحة', real.join(' | '));
if (fontHost.length) {
  console.log('  ⓘ مُخطّى: مضيفُ الخطّ لا يُبلَغ من هذه الحاوية — والبديلُ العربيُّ يعمل.');
}

try {
  await page.screenshot({ path: fileURLToPath(new URL('./لقطة-محاكي-الوقود.png', import.meta.url)) });
} catch { /* اللقطةُ مساعدةٌ لا شرط */ }

await browser.close();
console.log(`\n══════════════════\n  نجح ${pass} · فشل ${fail}\n`);
process.exit(fail ? 1 : 0);
