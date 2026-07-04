// AMIAL-UI-ADMIN-001 — اختبار تدفّق لوحة الإدارة عبر متصفّح حقيقي (Playwright).
//
// يقود Chromium فعلياً: يفتح صفحة الدخول، يملأ النموذج (هاتف/كلمة سرّ/كابتشا)،
// يسجّل الدخول، يصل للوحة المعلومات، يتنقّل بين الشاشات، ويتحقّق أنّ الأزرار
// والعناصر التفاعلية موجودة وتعمل. تغطية UI حقيقية للوحة الويب.
//
// التشغيل: BASE_URL=... CAPTCHA=... node scripts/ui/admin_flow.mjs

import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8199';
const CAPTCHA = process.env.CAPTCHA || ''; // نصّ الكابتشا (نقرؤه من الجلسة في الـharness)
const ADMIN_PHONE = process.env.ADMIN_PHONE || '967700000000';
const ADMIN_PASS = process.env.ADMIN_PASS || 'Admin@2026';

let pass = 0, fail = 0;
const step = (name, ok, detail = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}`); }
  else { fail++; console.log(`  ✗ ${name}  — ${detail}`); }
};

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

console.log('\n═══ تدفّق لوحة الإدارة عبر متصفّح حقيقي (Chromium) ═══\n');

// 1) صفحة الدخول تُصيّر بنموذجها
let r = await page.goto(BASE + '/admin/auth/login', { waitUntil: 'domcontentloaded' });
step('صفحة الدخول تُصيّر (200)', r && r.status() === 200, 'status=' + (r && r.status()));
const hasForm = await page.locator('[data-testid=login-form]').count();
const btns1 = await page.locator('button, a[href], input').count();
step('نموذج الدخول + عناصره موجودة', hasForm > 0 && btns1 >= 3, `form=${hasForm} elements=${btns1}`);

// 2) ملء وتسجيل الدخول (الكابتشا تُمرَّر من الـharness)
await page.fill('[data-testid=login-phone]', ADMIN_PHONE);
await page.fill('[data-testid=login-password]', ADMIN_PASS);
// حقل الكابتشا required في HTML — نملؤه دائماً (الخادم يتجاهله في وضع العرض)
await page.fill('[data-testid=login-captcha]', CAPTCHA || 'demo');
await Promise.all([
  page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
  page.click('[data-testid=login-submit]'),
]);

const url = page.url();
const onDashboard = url.includes('/admin') && !url.includes('/auth/login');
step('الدخول ينقل للوحة المعلومات', onDashboard, 'url=' + url);

// 3) لوحة المعلومات: العناصر والأزرار
if (onDashboard) {
  const sidebar = await page.locator('[data-testid=admin-sidebar]').count();
  const navLinks = await page.locator('[data-testid=admin-sidebar] a').count();
  const statCards = await page.locator('.stat-card').count();
  step('الشريط الجانبي + روابط التنقّل تظهر', sidebar > 0 && navLinks >= 2, `sidebar=${sidebar} links=${navLinks}`);
  step('بطاقات الإحصاءات تظهر', statCards >= 3, `cards=${statCards}`);

  // 4) التنقّل: زرّ اللوحة التنفيذية
  const execBtn = page.locator('[data-testid=btn-executive]');
  if (await execBtn.count()) {
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      execBtn.first().click(),
    ]);
    const execOk = page.url().includes('executive');
    step('زرّ "اللوحة التنفيذية" يعمل وينقل', execOk, 'url=' + page.url());
    await page.goBack({ waitUntil: 'domcontentloaded' }).catch(() => {});
  }

  // 5) جولة على كل شاشات الأدمن: تُصيّر + تحوي عناصر تفاعلية (تغطية شاملة)
  const screens = [
    { path: '/admin/amial/executive', name: 'اللوحة التنفيذية' },
    { path: '/admin/amial/fees',      name: 'الرسوم' },
    { path: '/admin/amial/legal',     name: 'المستندات القانونية' },
    { path: '/admin/amial/audit',     name: 'سجلّ التدقيق' },
  ];
  for (const s of screens) {
    let ok = false, els = 0;
    try {
      const rr = await page.goto(BASE + s.path, { waitUntil: 'domcontentloaded', timeout: 12000 });
      els = await page.locator('button, a[href], input, select').count();
      const noError = (await page.locator('text=/Exception|ErrorException|not found/i').count()) === 0;
      ok = rr && rr.status() === 200 && els > 0 && noError;
    } catch (e) { /* fail below */ }
    step(`شاشة "${s.name}" تُصيّر مع عناصر تفاعلية`, ok, `elements=${els}`);
  }

  // 6) تسجيل الخروج يعمل
  await page.goto(BASE + '/admin', { waitUntil: 'domcontentloaded' });
  const logout = page.locator('[data-testid=nav-logout]');
  if (await logout.count()) {
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      logout.first().click(),
    ]);
    step('تسجيل الخروج يعيد لصفحة الدخول', page.url().includes('login'), 'url=' + page.url());
  }
}

await browser.close();

console.log(`\n─── الخلاصة ───\nنجح ${pass} | فشل ${fail}`);
console.log(fail === 0 ? 'VERDICT: PASS ✓ لوحة الويب تعمل عبر متصفّح حقيقي (دخول→لوحة→تنقّل→خروج)'
                       : 'VERDICT: راجع الإخفاقات');
process.exit(fail === 0 ? 0 : 1);
