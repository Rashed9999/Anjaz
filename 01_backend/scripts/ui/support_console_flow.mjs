// AMIAL-OPS-CONSOLE-001 — اختبار واجهة منصة العمليات عبر متصفّح حقيقي (Playwright).
//
// يسجّل دخول الأدمن، يفتح /admin/support-center، يبحث عن عميل حقيقي بهاتفه،
// يفتح ملفه 360°، يتحقّق من البطاقات والأزرار، يفتح تبويب التذاكر والمراقبة،
// ويلتقط لقطات شاشة للعرض.
//
// التشغيل: BASE_URL=... CUSTOMER_PHONE=... node scripts/ui/support_console_flow.mjs

import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8199';
const ADMIN_PHONE = process.env.ADMIN_PHONE || '967700000000';
const ADMIN_PASS = process.env.ADMIN_PASS || 'Admin@2026';
const CUSTOMER_PHONE = process.env.CUSTOMER_PHONE || '';
const SHOTS = process.env.SHOTS_DIR || '/tmp/console_shots';

let pass = 0, fail = 0;
const step = (name, ok, detail = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}`); }
  else { fail++; console.log(`  ✗ ${name}  — ${detail}`); }
};

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();

console.log('\n═══ منصة عمليات الموظفين — اختبار متصفّح حقيقي ═══\n');

// 1) دخول الأدمن
await page.goto(BASE + '/admin/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('[data-testid=login-phone]', ADMIN_PHONE);
await page.fill('[data-testid=login-password]', ADMIN_PASS);
await page.fill('[data-testid=login-captcha]', 'demo');
await Promise.all([
  page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
  page.click('[data-testid=login-submit]'),
]);
step('دخول الأدمن', !page.url().includes('/auth/login'), 'url=' + page.url());

// 2) فتح المنصة
const r = await page.goto(BASE + '/admin/support-center', { waitUntil: 'domcontentloaded' });
step('صفحة المنصة تُصيّر (200)', r && r.status() === 200, 'status=' + (r && r.status()));
step('التبويبات الأربعة موجودة',
  await page.locator('[data-testid=tab-search]').count() > 0 &&
  await page.locator('[data-testid=tab-tx]').count() > 0 &&
  await page.locator('[data-testid=tab-tickets]').count() > 0 &&
  await page.locator('[data-testid=tab-ops]').count() > 0);

// 3) بحث عن عميل حقيقي
if (CUSTOMER_PHONE) {
  await page.fill('[data-testid=search-input]', CUSTOMER_PHONE);
  await page.click('[data-testid=btn-search]');
  await page.waitForSelector('[data-testid^=user-row-]', { timeout: 8000 }).catch(() => {});
  const rows = await page.locator('[data-testid^=user-row-]').count();
  step('البحث بالهاتف يجد العميل', rows > 0, `rows=${rows}`);

  if (rows > 0) {
    await page.locator('[data-testid^=user-row-]').first().click();
    await page.waitForSelector('[data-testid=customer-360]', { timeout: 8000 }).catch(() => {});
    const has360 = await page.locator('[data-testid=customer-360]').count() > 0;
    step('ملف العميل 360° يفتح', has360);
    step('أزرار الإجراءات (تجميد/PIN/جلسات/KYC/تذكرة) موجودة',
      await page.locator('[data-testid=btn-freeze]').count() > 0 &&
      await page.locator('[data-testid=btn-reset-pin]').count() > 0 &&
      await page.locator('[data-testid=btn-revoke]').count() > 0 &&
      await page.locator('[data-testid=btn-kyc]').count() > 0 &&
      await page.locator('[data-testid=btn-open-ticket]').count() > 0);
    await page.screenshot({ path: SHOTS + '/console_customer_360.png', fullPage: true });
  }
}

// 4) تبويب التذاكر
await page.click('[data-testid=tab-tickets]');
await page.click('[data-testid=btn-tickets]');
await page.waitForTimeout(1200);
step('قائمة التذاكر تُحمَّل', (await page.locator('#tickets-list table, #tickets-list .alert, #tickets-list ul').count()) > 0);
await page.screenshot({ path: SHOTS + '/console_tickets.png', fullPage: true });

// 4-ب) تبويب الموافقات (Maker-Checker)
await page.click('[data-testid=tab-approvals]');
await page.click('[data-testid=btn-approvals]');
await page.waitForTimeout(1200);
step('قائمة الموافقات تُحمَّل', (await page.locator('#approvals-list table, #approvals-list .alert').count()) > 0);
await page.screenshot({ path: SHOTS + '/console_approvals.png', fullPage: true });

// 4-ج) تبويب الأمن الداخلي
await page.click('[data-testid=tab-insider]');
await page.click('[data-testid=btn-insider]');
await page.waitForTimeout(1500);
const chainBadge = await page.locator('#insider-chain .alert').count();
const activityTable = await page.locator('#insider-activity table').count();
step('الأمن الداخلي: حالة السلسلة + نشاط الموظفين يظهران', chainBadge > 0 && activityTable > 0,
  `chain=${chainBadge} activity=${activityTable}`);
const chainOk = await page.locator('#insider-chain .alert-success').count();
step('سلسلة سجل التدقيق سليمة (لا عبث)', chainOk > 0);
await page.screenshot({ path: SHOTS + '/console_insider.png', fullPage: true });

// 5) تبويب المراقبة
await page.click('[data-testid=tab-ops]');
await page.click('[data-testid=btn-ops]');
await page.waitForTimeout(1500);
const tiles = await page.locator('#ops-body .card').count();
step('لوحة المراقبة تعرض المؤشرات الحيّة', tiles >= 10, `tiles=${tiles}`);
await page.screenshot({ path: SHOTS + '/console_ops.png', fullPage: true });

await browser.close();
console.log(`\n═══ النتيجة: ${pass} نجح / ${fail} فشل ═══`);
process.exit(fail ? 1 : 0);
