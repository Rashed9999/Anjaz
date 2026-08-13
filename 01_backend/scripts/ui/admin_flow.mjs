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

// 2) ملء وتسجيل ا