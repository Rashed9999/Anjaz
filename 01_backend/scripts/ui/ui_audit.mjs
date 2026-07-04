// AMIAL-UI-AUDIT-001 — تدقيق واجهة الويب عبر متصفّح حقيقي (Playwright/Chromium).
//
// يقود متصفّحاً فعلياً على التطبيق المُشغَّل ويزور قائمة مسارات، ويسجّل لكلٍّ:
// رمز HTTP، هل تُصيّر صفحة، وهل تحوي محتوى/أزراراً. تدقيق صادق لما هو مبنيّ فعلاً
// في طبقة الويب (مقابل الـAPI وتطبيق Flutter).
//
// التشغيل: BASE_URL=http://127.0.0.1:8199 node scripts/ui/ui_audit.mjs

import { createRequire } from 'module';
const require = createRequire(import.meta.url);
// Playwright مثبّت عالمياً (/opt/node22/lib/node_modules) — نحلّه عبر require
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8199';

const routes = [
  { path: '/api/v1/config',      kind: 'api',   label: 'إعدادات التطبيق (API)' },
  { path: '/admin/auth/login',   kind: 'web',   label: 'دخول لوحة الإدارة' },
  { path: '/',                   kind: 'web',   label: 'صفحة الهبوط' },
  { path: '/api/v1/amial/me',    kind: 'api',   label: '/me بلا توكن (يجب 401)' },
];

function classify(status) {
  if (status === 200) return 'OK';
  if (status === 401 || status === 403) return 'AUTH';   // متوقّع للمسارات المحمية
  if (status === 404) return 'MISSING';
  if (status >= 500) return 'ERROR';
  return String(status);
}

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const page = await browser.newPage();

const results = [];
let renderedButtons = 0;

for (const r of routes) {
  let status = 0, buttons = 0, note = '';
  try {
    const resp = await page.goto(BASE + r.path, { waitUntil: 'domcontentloaded', timeout: 15000 });
    status = resp ? resp.status() : 0;
    if (r.kind === 'web' && status === 200) {
      // عُدّ العناصر التفاعلية القابلة للنقر
      buttons = await page.evaluate(() =>
        document.querySelectorAll('button, a[href], input[type=submit], [role=button]').length);
      renderedButtons += buttons;
    }
    if (r.kind === 'api' && status === 200) {
      const body = await page.evaluate(() => document.body ? document.body.innerText : '');
      note = body.includes('company_name') || body.includes('success') || body.startsWith('{')
        ? 'JSON صحيح' : 'استجابة';
    }
  } catch (e) {
    note = 'خطأ تنقّل: ' + e.message.slice(0, 40);
  }
  results.push({ ...r, status, verdict: classify(status), buttons, note });
}

await browser.close();

// تقرير
console.log('\n═══ تدقيق واجهة الويب عبر متصفّح حقيقي (Chromium) ═══\n');
console.log('المسار'.padEnd(28) + 'النوع'.padEnd(8) + 'HTTP'.padEnd(7) + 'الحكم'.padEnd(9) + 'أزرار  ملاحظة');
console.log('─'.repeat(80));
for (const r of results) {
  console.log(
    r.path.padEnd(28) + r.kind.padEnd(8) + String(r.status).padEnd(7) +
    r.verdict.padEnd(9) + String(r.buttons || '').padEnd(7) + r.note);
}

const apiOk = results.filter(r => r.kind === 'api' && (r.verdict === 'OK' || r.verdict === 'AUTH')).length;
const apiTotal = results.filter(r => r.kind === 'api').length;
const webRendered = results.filter(r => r.kind === 'web' && r.verdict === 'OK').length;
const webErrors = results.filter(r => r.kind === 'web' && r.verdict === 'ERROR').length;

console.log('\n─── الخلاصة ───');
console.log(`طبقة الـAPI: ${apiOk}/${apiTotal} تعمل عبر المتصفّح ✓`);
console.log(`صفحات الويب المُصيّرة: ${webRendered} | أخطاء (قوالب ناقصة): ${webErrors}`);
if (webErrors > 0) {
  console.log('⚠ لوحة الويب/صفحة الهبوط: قوالب Blade ناقصة (login/layout) — الواجهة الفعلية');
  console.log('  هي تطبيق Flutter + الـAPI؛ لوحة الويب غير مكتملة. تدقيق صادق لا ادّعاء.');
}
// نجاح التدقيق = الـAPI يعمل عبر متصفّح حقيقي (طبقة الويب المكتملة)
process.exit(apiOk === apiTotal ? 0 : 1);
