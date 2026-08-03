#!/usr/bin/env node
/**
 * scripts/click-check.mjs — الطبقة السابعة: **الضغط**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * لماذا هذا الملفّ موجود
 *
 * زرّ «تحويل رصيد» في مركز الوكلاء كان ينقل إلى صفحة الوكيل بدل أن يفتح
 * نافذة التحويل. ولم يكشفه شيء:
 *
 *   الخدمة تعمل  ·  نقطة النهاية تعمل  ·  الاختبارات ١٥٥٧ تمرّ
 *   الصفحة تردّ ٢٠٠  ·  الجافاسكربت يُحلَّل بلا خطأ  ·  الأصول موجودة
 *
 * لأنّ العطل لم يكن في أيّ طبقةٍ من هذه. كان في **سطرٍ واحد** يقرّر أيّ
 * إجراءٍ تعنيه الضغطة:
 *
 *     const openEl = e.target.closest('[data-act="open"]');
 *
 * والصفّ نفسه يحمل `data-act="open"`، فـ`closest` تصعد من الزرّ إلى الصفّ
 * وتُطابقه — فيبتلع الصفُّ كلَّ ضغطةٍ على أزراره.
 *
 * لا يمسك هذا إلّا متصفّحٌ يضغط فعلاً. فهذا ما يفعله هذا الملفّ: يبني
 * الصفحة من القالب نفسه (نفس الجافاسكربت حرفيّاً)، ويضغط، ويتحقّق ممّا
 * حدث — هل فُتحت نافذة أم وقع انتقال.
 *
 * الاستعمال:  node scripts/click-check.mjs
 * ══════════════════════════════════════════════════════════════════════
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { createRequire } from 'node:module';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

// playwright مثبَّتٌ عامّاً في هذه البيئة ولا يُدرَج في package.json.
// فإن غاب، يُقال ذلك صراحةً ولا يُدّعى نجاح — «غير معروف» ليس صفراً.
let chromium;
try {
    const req = createRequire(import.meta.url);
    ({ chromium } = req(
        req.resolve('playwright', { paths: [process.env.NODE_PATH || '/opt/node22/lib/node_modules'] })
    ));
} catch {
    try { ({ chromium } = await import('playwright')); } catch { chromium = null; }
}

if (!chromium) {
    console.log('  — playwright غير متوفّر — تخطّي فحص الضغط');
    process.exit(0);
}

// ── تحويل القالب إلى صفحةٍ قابلةٍ للفتح ──────────────────────────────
//
// الجافاسكربت يُنقل **حرفيّاً** بلا تعديل: أيّ إعادة صياغةٍ هنا تعني أنّنا
// نختبر نصّاً غير الذي يعمل في اللوحة.
// نصُّ القالب بعد دمج جزئيّاته — قالبٌ يُختبر بلا ما يُضمّنه ليس القالب
// الذي يعمل في اللوحة. (`@once` يُترك: تكراره هنا غير ممكنٍ أصلاً.)
function bladeSource(file, depth = 0) {
    let s = readFileSync(resolve(ROOT, file), 'utf8');

    if (depth < 4) {
        s = s.replace(/^[ \t]*@include\(\s*['"]([^'"]+)['"]\s*\)\s*$/gm, (m, dotted) => {
            const f = 'resources/views/' + dotted.replace(/\./g, '/') + '.blade.php';
            try { return bladeSource(f, depth + 1); } catch { return ''; }
        });
    }

    return s;
}

function bladeToHtml(file, vars) {
    let s = bladeSource(file);

    s = s.replace(/\{\{--[\s\S]*?--\}\}/g, '');                       // تعليقات Blade
    s = s.replace(/\{\{\s*([\s\S]*?)\s*\}\}/g, (m, expr) => {          // {{ ... }}
        for (const [k, v] of Object.entries(vars)) if (expr.includes(k)) return v;
        return '';
    });
    // توجيهات Blade: تُحذف الأسطر نفسها ويُبقى ما بينها
    s = s.replace(/^\s*@(extends|section|endsection|push|endpush|include|if|elseif|else|endif|foreach|endforeach)\b.*$/gm, '');

    const scripts = [...s.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]);
    const body = s.replace(/<script[\s\S]*?<\/script>/g, '');
    const bootstrap = readFileSync(resolve(ROOT, 'public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js'), 'utf8');

    return `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8">
<meta name="csrf-token" content="test-token"></head><body>
${body}
<script>${bootstrap}</script>
${scripts.map(j => `<script>${j}</script>`).join('\n')}
</body></html>`;
}

const HUB_URL = 'http://amial.test/admin/amial/hub/agents';
const COUNTER_URL = 'http://amial.test/agent/counter';
const STAFF_URL = 'http://amial.test/agent/staff-tab';
const DASH_URL = 'http://amial.test/agent/dashboard';

const HUB_HTML = bladeToHtml('resources/views/admin-views/amial/hub/users.blade.php', {
    '$hubSlug': 'agents',
    '$hubType': '1',
    '$hubTitle': 'الوكلاء',
    "url('admin/amial/hub')": 'http://amial.test/admin/amial/hub',
    "route('agent.login')": 'http://amial.test/agent/login',
    "$stats": '0',
});

// صفّان يشبهان ما تُرجعه AdminHubController للوكلاء: وكيلٌ أمّ، وفرعٌ تابع.
const FAKE_ROWS = [
    {
        id: 7, name: 'البسيري للصرافة', phone: '967700000007', balance: 0,
        kyc: 1, has_docs: true, is_active: true,
        agent: { is_branch_account: false, no_branches: false, branches: 2, branches_active: 2, cash_on_hand: 150000, flags: [] },
    },
    {
        id: 8, name: 'فرع المكلا', phone: '967700000008', balance: 0,
        kyc: 1, has_docs: true, is_active: true,
        agent: { is_branch_account: true, parent: { id: 7, name: 'البسيري للصرافة' } },
    },
];

// ── شبّاك الصرّاف ────────────────────────────────────────────────────
//
// يُبنى من `_counter.blade.php` وحدَه: جافاسكربته مكتفٍ بذاته وكلّ عناصره
// في ملفّه، فلا حاجة إلى بقيّة اللوحة.
// **يُبنى مع `_workspace` معاً.** جافاسكربت الشبّاك صار ينادي
// `window.wsAssess` و`wsLogEvent` و`wsOpenRequest` — وهي في مساحة العمل.
// وفحصُ الشبّاك وحدَه يعني فحصَ شاشةٍ لا وجود لها في اللوحة.
const COUNTER_HTML = bladeToHtml('resources/views/agent-views/_counter_ws.blade.php', {
    "url('agent')": 'http://amial.test/agent',
});

const SHIFT_OPEN = {
    shift: {
        status: 'open', cash_on_hand: '50000',
        deposits_total: '0', deposits_count: 0,
        withdrawals_total: '0', withdrawals_count: 0,
    },
    staff: { role: 'teller' },
    can_operate: true,
    why_not: null,
};

// ── لوحة الموظّفين ─────────────────────────────────────────────────
const STAFF_HTML = bladeToHtml('resources/views/agent-views/_staff.blade.php', {
    "url('agent')": 'http://amial.test/agent',
    "route('agent.login')": 'http://amial.test/agent/login',
});

const STAFF_ROW = {
    id: 9, name: 'محمد علي', username: 'MKL-001', role: 'teller', role_label: 'صرّاف (شبّاك)',
    branch: 'فرع المكلا', phone: '967700000009', max_txn_amount: 0,
    last_login_at: '2026-08-01 16:09:35', is_active: true, has_open_shift: false,
    daily_limit: '500000', daily_count_limit: 0,
    daily_hours_expected: '8.00', overtime_policy: 'approved',
    whatsapp: null,
};

// موظّفٌ رقمُه مربوطٌ فعلاً — لأنّ «اربط» و«فُكّ» زرّان مختلفان في حالتين
// مختلفتين، وفحصُ إحداهما لا يفحص الأخرى.
const STAFF_ROW_LINKED = {
    ...STAFF_ROW,
    whatsapp: { number: '967700000009', status: 'active', alerts_enabled: true },
};

const PROFILE_RATED = {
    staff: { ...STAFF_ROW, branch_id: 1, hired_at: '2026-07-01 09:00:00' },
    period: { from: '2026-07-03', to: '2026-08-02' },
    operations: {
        deposits_count: 12, deposits_total: '480000', deposits_biggest: '90000',
        withdrawals_count: 5, withdrawals_total: '150000', withdrawals_biggest: '50000',
        total_count: 17, total_volume: '630000', distinct_customers: 11,
    },
    shifts: {
        opened: 9, closed: 8, still_open: 1, exact_count: 5,
        shortage_count: 2, shortage_total: '8000',
        overage_count: 1, overage_total: '1500',
        pending_review: 2, accuracy_pct: 62.5,
    },
    risk: {
        score: 64, level: 'high', level_label: 'مرتفع', reason: null,
        signals: [
            { key: 'shortage_rate', label: 'تكرار العجز', value: '2 من 8 ورديّة', points: 25, max: 35 },
            { key: 'shortage_weight', label: 'ثقل العجز في حجم عمله', value: '8000 من 630000', points: 25, max: 25 },
            { key: 'overage_rate', label: 'تكرار الفائض', value: '1 من 8 ورديّة', points: 5, max: 15 },
            { key: 'unclosed', label: 'ورديّات تُركت مفتوحة', value: '1', points: 5, max: 15 },
            { key: 'unreviewed', label: 'فروقٌ لم تُراجَع بعد', value: '2', points: 6, max: 10 },
        ],
    },
    recent_shifts: [{
        id: 4, opened_at: '2026-08-01 08:00:00', closed_at: '2026-08-01 18:00:00', status: 'closed',
        opening_float: '100000', deposits_total: '60000', withdrawals_total: '0',
        counted_cash: '155000', variance: '-5000',
        review_status: 'pending', review_label: 'بانتظار مراجعة الإدارة', close_note: 'فرقٌ في العدّ',
    }],
    recent_operations: [{
        id: 1, at: '2026-08-01 09:12:00', reason: 'customer_deposit', label: 'إيداع عميل (نقد داخل)',
        direction: 'in', amount: '2000', reference: 'DEP-ABC', customer: 'راشد معرابي', customer_phone: '783545525',
    }],
};

const PROFILE_UNRATED = {
    ...PROFILE_RATED,
    shifts: {
        opened: 0, closed: 0, still_open: 0, exact_count: 0,
        shortage_count: 0, shortage_total: '0', overage_count: 0, overage_total: '0',
        pending_review: 0, accuracy_pct: null,
    },
    risk: {
        score: null, level: 'unrated', level_label: 'غير مُقيَّم',
        reason: 'أغلق 0 ورديّة فقط — تُحسب الدرجة من 3 فأكثر. وموظّفٌ لم يُختبَر ليس منخفض المخاطر.',
        signals: [],
    },
    recent_shifts: [],
    recent_operations: [],
};

// ── لوحة الوكيل الكاملة: التقارير والرسوم والطباعة ─────────────────
const SETTINGS_URL = 'http://amial.test/agent/settings-tab';
const SETTINGS_HTML = bladeToHtml('resources/views/agent-views/_settings.blade.php', {
    "url('agent')": 'http://amial.test/agent',
});

const DASH_HTML = bladeToHtml('resources/views/agent-views/dashboard.blade.php', {
    "url('agent')": 'http://amial.test/agent',
    "route('agent.login')": 'http://amial.test/agent/login',
    "route('agent.logout')": 'http://amial.test/agent/logout',
    '$role': 'head_office',
    '$staffName': 'البسيري للصرافة',
    '$staffUsername': 'HQ19',
    '$roleLabel': 'الإدارة العامّة',
    '$branchName': '',
});

const REPORT = {"period": {"from": "2026-07-04", "to": "2026-08-02", "days": 30, "prev_from": "2026-06-04", "prev_to": "2026-07-03"}, "agent": {"name": "البسيري للصرافة", "phone": "967771500001"}, "summary": {"volume": {"value": "630000", "previous": "500000", "change_pct": 26.0, "is_new": false}, "deposits": {"value": "480000", "previous": "400000", "change_pct": 20.0, "is_new": false}, "withdrawals": {"value": "150000", "previous": "100000", "change_pct": 50.0, "is_new": false}, "commission": {"value": "6300", "previous": "5000", "change_pct": 26.0, "is_new": false}, "fees": {"value": "12600", "previous": "10000", "change_pct": 26.0, "is_new": false}, "operations": {"value": "17", "previous": "14", "change_pct": 21.4, "is_new": false}, "customers": 11, "avg_ticket": "37058.8235"}, "daily": [{"date": "2026-08-01", "label": "08-01", "deposits": "40000", "withdrawals": "10000", "volume": "50000", "count": 1, "net_cash": "30000"}, {"date": "2026-08-02", "label": "08-02", "deposits": "45000", "withdrawals": "12000", "volume": "57000", "count": 2, "net_cash": "33000"}, {"date": "2026-08-03", "label": "08-03", "deposits": "50000", "withdrawals": "14000", "volume": "64000", "count": 3, "net_cash": "36000"}, {"date": "2026-08-04", "label": "08-04", "deposits": "55000", "withdrawals": "16000", "volume": "71000", "count": 4, "net_cash": "39000"}, {"date": "2026-08-05", "label": "08-05", "deposits": "60000", "withdrawals": "18000", "volume": "78000", "count": 5, "net_cash": "42000"}], "by_branch": [{"id": 1, "name": "فرع المكلا", "code": "MKL", "city": "حضرموت", "is_active": true, "deposits": "380000", "deposits_count": 9, "withdrawals": "120000", "withdrawals_count": 4, "volume": "500000", "commission": "5000", "fees": "10000", "shifts": 6, "shortage_total": "8000", "overage_total": "1500", "cash_on_hand": "2000000", "idle": false}, {"id": 2, "name": "فرع سيحوت", "code": "SHT", "city": "المهرة", "is_active": true, "deposits": "0", "deposits_count": 0, "withdrawals": "0", "withdrawals_count": 0, "volume": "0", "commission": "0", "fees": "0", "shifts": 0, "shortage_total": "0", "overage_total": "0", "cash_on_hand": "0", "idle": true}], "by_staff": [{"id": 9, "name": "محمد علي", "username": "MKL-001", "branch": "فرع المكلا", "deposits": "380000", "withdrawals": "120000", "volume": "500000", "operations": 13, "shifts_closed": 6, "accuracy_pct": 66.7, "shortage_count": 2, "shortage_total": "8000", "overage_count": 1, "overage_total": "1500"}], "variances": {"rows": [{"shift_id": 4, "date": "2026-08-01", "branch": "فرع المكلا", "staff": "محمد علي", "variance": "-5000", "kind": "shortage", "review_status": "pending", "review_label": "بانتظار مراجعة الإدارة", "note": "فرقٌ في العدّ"}], "shortage_count": 2, "shortage_total": "8000", "overage_count": 1, "overage_total": "1500"}, "settlements": {"expected_days": 30, "filed": 28, "missing": 2, "on_time": 26, "late": 2, "accepted": 27, "rejected": 1, "on_time_pct": 92.9, "rows": [{"date": "2026-08-01", "status": "accepted", "status_label": "قُبلت ونُفّذ التحويل", "window_state": "on_time", "conversion": "topup", "conversion_label": "يسلّم نقداً ويستلم رصيداً إلكترونيّاً", "conversion_amount": "260000", "deposits_total": "380000", "withdrawals_total": "120000"}]}, "generated_at": "2026-08-02 23:15:00"};

// ── مساحةُ عمل الصرّاف ─────────────────────────────────────────────
const WORKSPACE = {
    staff: {id: 9, name: 'محمد علي', username: 'MKL-001', role: 'teller',
            role_label: 'صرّاف (شبّاك)', branch: 'فرع المكلا', branch_code: 'MKL'},
    kpis: {drawer_cash: '50000', drawer_known: true, branch_emoney: '900000',
           branch_safe: '2000000', safe_known: true, customers_served: 3,
           deposits_total: '120000', deposits_count: 4,
           withdrawals_total: '30000', withdrawals_count: 1,
           commission_today: '1200', pending_requests: 0, risk_alerts: 1},
    permissions: [
        {key: 'deposit', label: 'إيداع نقديّ للعميل', allowed: true},
        {key: 'withdraw', label: 'صرف سحبٍ برمز العميل', allowed: true},
        {key: 'adjust_balance', label: 'تعديل رصيدٍ يدويّاً', allowed: false},
    ],
    // **`null` تعني «بلا حدّ خاصّ» لا صفراً** — وهي الحالة التي يجب أن
    // تُختبَر: قلبُ قراءتها يجعل الصرّاف يظنّ نفسه ممنوعاً من كلّ شيء.
    limits: {per_operation: null, daily: '500000', daily_used: '150000',
             daily_remaining: '350000', count_limit: null, count_used: 5,
             count_remaining: null, rejected_today: 0},
    systems: [
        {key: 'database', label: 'قاعدة البيانات', state: 'ok', note: '2 مِلّي'},
        {key: 'printer', label: 'الطابعة', state: 'not_integrated', note: 'لا يتّصل به النظام'},
    ],
    announcements: [{id: 1, severity: 'warning', icon: '⚠️', title: 'صيانة الليلة',
                     body: 'من ١٢ إلى ١', at: '2026-08-03 09:00:00'}],
    recent_customers: [{id: 42, name: 'راشد معرابي', phone: '783545525',
                        last_at: '2026-08-03 09:00:00', ops: 3}],
    requests: [],
    day_log: {shift_open: true, shift_id: 7, opened_at: '2026-08-03 08:00:00',
              minutes_on_shift: 125, last_activity_at: '2026-08-03 09:50:00',
              last_login_at: '2026-08-03 07:58:00'},
    panic: {has_open: false, number: null, status_label: null, geo_possible: false},
    server_time: '2026-08-03 10:05:00', server_ms: Date.now(),
};

// ── كشف ساعات الدوام ───────────────────────────────────────────────
const TIMESHEET = {
    period: {from: '2026-08-01', to: '2026-08-31'},
    staff: {id: 9, name: 'محمد علي', username: 'MKL-001',
            daily_hours_expected: 8, overtime_policy: 'approved'},
    totals: {worked: '14س', worked_minutes: 840, break: '45د',
             unverified: '4س', unverified_minutes: 240, idle: '2س',
             overtime: '2س 30د', overtime_minutes: 150, sessions: 2, days_worked: 2},
    overtime: {policy: 'approved', occurred: '2س 30د', payable: '0د', pending: '2س 30د',
               note: 'الإضافيّ لا يُقَرّ إلّا بموافقة المدير على يومه',
               rows: [{date: '2026-08-03', overtime: '2س 30د', approved: false,
                       label: '⏳ واقعٌ بلا موافقة'}]},
    daily: [{date: '2026-08-03', worked: '8س', worked_minutes: 480, break: '45د',
             unverified: '4س', unverified_minutes: 240, idle: '2س', sessions: 1,
             expected: '8س', overtime: '2س 30د', overtime_minutes: 150, short_minutes: 0}],
    weekly: [{key: '2026-08-03', worked: '14س', worked_minutes: 840,
              unverified: '4س', idle: '2س', sessions: 2}],
    monthly: [{key: '2026-08', worked: '14س', worked_minutes: 840,
               unverified: '4س', idle: '2س', sessions: 2}],
    sessions_list: [],
    integrity: {ok: true, checked: 4, broken_at: null, reason: null},
};

// وسجلٌّ مكسور — لأنّ الحالتين تُعرَضان بشكلين، وفحصُ السليمة وحدها
// يترك الإنذار الذي يهمّ بلا فحص.
const TIMESHEET_BROKEN = {
    ...TIMESHEET,
    integrity: {ok: false, checked: 3, broken_at: 12, reason: 'محتوى الحدث تغيّر بعد كتابته'},
};

// كلّ نداءٍ يُسجَّل، لأنّ سؤال هذا الفحص ليس «هل ظهر خطأ» بل **هل وصل
// الطلب أصلاً**. وزرُّ الإيداع الميّت كان يسقط قبل أيّ نداء.
const STUBS = `
window.__calls = [];
window.__printed = false;
window.__lastAlert = '';
// print تُسجَّل ولا تُنفَّذ: نافذةُ طباعةٍ حقيقيّة تُجمّد المتصفّح بلا رادّ.
window.print = () => { window.__printed = true; };
// النوافذ تُسجَّل ولا تُفتح: نافذةُ طباعةٍ حقيقيّة تُجمّد الفحص.
window.__opened = [];
window.open = (u) => { window.__opened.push(String(u)); return null; };
window.alert = (m) => { window.__lastAlert = String(m ?? ''); };
window.prompt = () => 'سبب مكتوب';
window.confirm = () => true;
const _json = (o) => new Response(JSON.stringify(o), {status: 200, headers: {'Content-Type': 'application/json'}});
window.fetch = async (url, opts) => {
    const u = String(url);
    window.__calls.push({url: u, method: (opts && opts.method) || 'GET'});

    // نقاطُ تبويبات الوكلاء في لوحة الإدارة — حمولةٌ فارغةٌ **حسنةُ الشكل**.
    // فحمولةٌ ناقصةُ المفاتيح تُسقط الشيفرة بخطأٍ ليس من صنعها، فيضيع
    // الفحص في مطاردة أعطالٍ اخترعها الفحص نفسه.
    // **بلا تعبيرٍ نمطيّ هنا عمداً.** هذا النصّ داخل قالبٍ نصّيّ في ملفّ
    // .mjs، و\/ فيه تُصبح / — فانقلب /\/agents\/…/ إلى //agents/… أي
    // **تعليقُ سطر**، فمات سكربت التهيئة كلّه ولم تُستبدَل fetch أصلاً.
    // وظهر العطل بثلاث رسائل لا تدلّ عليه.
    if (u.includes('/reports')) return _json({success: true, meta: {report: ${JSON.stringify(REPORT)}}});

    if (u.includes('/agents/network') || u.includes('/agents/branches')
        || u.includes('/agents/movements') || u.includes('/agents/settlement')
        || u.includes('/agents/daily')) return _json({
        agents: 0, branches: 0, branches_active: 0, agents_without_branch: 0,
        flags: {low_cash: 0, overloaded: 0, not_counted: 0, no_till: 0},
        liability: '0', agent_cash: '0',
        data: [], rows: [], totals: {}, window: {message: ''},
    });

    if (u.includes('users.json')) return _json({data: ${JSON.stringify(FAKE_ROWS)}, current_page: 1, last_page: 1, total: 2});
    if (u.includes('kyc.json'))   return _json({data: []});

    if (u.includes('/staff/9/profile')) return _json({success: true,
        meta: window.__unrated ? ${JSON.stringify(PROFILE_UNRATED)} : ${JSON.stringify(PROFILE_RATED)}});
    if (/\\/staff(\\?|$)/.test(u)) return _json({
        data: [window.__waLinked ? ${JSON.stringify(STAFF_ROW_LINKED)} : ${JSON.stringify(STAFF_ROW)}],
        can_manage: true, roles: {}});
    if (u.includes('/overview')) return _json({success: true, meta: {
        agent: {name: 'البسيري للصرافة', is_branch_account: false},
        branches: [{id: 1, name: 'فرع المكلا', code: 'MKL', city: 'حضرموت', is_active: true,
                    emoney_balance: '18000', cash_on_hand: '2000', cash_is_low: false, cash_is_overloaded: false}],
        totals: {cash_on_hand: '2000', emoney: '18000', branches: 1, low_cash_branches: 0},
        today: {deposits_count: 1, deposits_total: '2000', withdrawals_count: 0, withdrawals_total: '0',
                shifts_open_now: 1, shifts_opened: 1, drawers_cash: '0', branches_idle: 0,
                staff_total: 2, staff_tellers: 1, shifts_with_variance: 0, variance_total: '0'},
    }});
    if (u.includes('/staff/shifts')) return _json({data: []});

    if (u.split('?')[0].endsWith('/settings')) return _json({success: true, meta: {
        company: {name: 'البسيري للصرافة', phone: '967700000007',
                  portal_url: 'http://amial.test/agent/login'},
        me: {name: 'الإدارة', username: 'HQ-001', role_label: 'الإدارة العامّة', can_manage: true},
        branches: [
            {id: 1, name: 'فرع المكلا', code: 'MKL', city: 'حضرموت', is_active: true,
             working_hours: null, min_cash_alert: '0', max_cash_on_hand: '0',
             cash_on_hand: '500000', alerts_configured: false},
        ],
        // اتّصالٌ غير مشفَّر على عنوانٍ رقميّ — وهي حالةُ الإنتاج اليوم،
        // وأهمّ ما يجب أن يُقال فيه الحلّ لا العطل وحده.
        security: {secure: false, scheme: 'http', host: '169.58.24.224', host_is_ip: true,
                   cookie_secure: false,
                   headline: '🔓 الاتّصال غير مشفَّر — كلمات سرّ موظّفيك تمرّ نصّاً صريحاً'},
        announcements: [],
    }});
    if (u.includes('/branches/1/thresholds')) return _json({success: true, message: 'حُفظت حدود فرع المكلا'});

    // مساحةُ عمل الصرّاف — حمولةٌ كاملةُ الشكل: حمولةٌ ناقصةُ المفاتيح
    // تُسقط الشيفرة بخطأٍ ليس من صنعها فيضيع الفحص في مطاردة وهم.
    if (u.includes('/teller/workspace')) return _json({success: true, meta: ${JSON.stringify(WORKSPACE)}});
    if (u.includes('/teller/assess')) return _json({success: true, meta: window.__blocked
        ? {blocked: true, flags: [{level: 'block', key: 'customer_status', icon: '🔴',
             title: 'العميل محظور', advice: 'لا تُنفَّذ عمليّة'}]}
        : {blocked: false, flags: [{level: 'warn', key: 'over_limit', icon: '🟠',
             title: 'المبلغ فوق حدّ عمليّتك (50,000)', advice: 'اطلب موافقة مديرك'}]}});
    if (u.includes('/teller/requests')) return _json({success: true, meta: {rows: []}});
    // **لا علامة اقتباس خلفيّة في هذا التعليق** — النصّ كلّه داخل قالبٍ
    // نصّيّ، وواحدةٌ منها تُنهيه فيصير ما بعده شيفرةً تُنفَّذ.
    // ومسارُ الطلب المفرد يُطابَق بنهايته لا باحتوائه: احتواءُ الجمع
    // يبتلعه دائماً مهما رُتّبت الشروط.
    if (u.split('?')[0].endsWith('/teller/request')) return _json({success: true, message: 'أُرسل الطلب إلى مديرك'});
    if (u.includes('/teller/panic')) return _json({success: true, message: 'أُرسل البلاغ — رقمه PNC-TEST'});
    if (u.includes('/teller/event')) return _json({success: true});
    if (u.includes('/teller/break')) return _json({success: true, message: 'بدأت استراحتُك'});
    if (u.includes('/teller/timesheet')) return _json({success: true,
        meta: window.__chainBroken ? ${JSON.stringify(TIMESHEET_BROKEN)} : ${JSON.stringify(TIMESHEET)}});

    if (u.includes('/counter/state')) return _json(${JSON.stringify(SHIFT_OPEN)});
    if (u.includes('/counter/customer')) return _json({
        success: true,
        meta: {customer: {id: 42, name: 'راشد محمد عوض معرابي', phone: '783545525',
                          can_transact: true, status_label: 'نشط', severity: 'success',
                          kyc_verified: true, photo: null, member_since: '2024-01-01',
                          account_age_days: 900, open_reports: 2,
                          last_operation: {kind: 'إيداع', amount: '5000', at: '2026-08-01 10:00:00'}}},
    });
    if (u.includes('/counter/deposit')) return _json({
        success: true, message: 'أُودع 2,000 ر.ي',
        result: {reference: 'DEP-TEST', fee: '0', commission: '0', receipt_number: 'RCP-2026-000123'},
        shift: ${JSON.stringify(SHIFT_OPEN.shift)},
    });

    return _json({success: true, message: 'تمّ'});
};
`;

if (process.env.CLICK_DUMP) {
    const { writeFileSync } = await import('node:fs');
    writeFileSync('/tmp/stubs-final.js', STUBS);
}

const CASES = [
    // ── التقارير والرسوم والطباعة ───────────────────────────────────
    {
        page: 'dashboard',
        name: 'التقرير يُبنى ورسومُه تُرسَم فعلاً',
        steps: [['click', '#rp-load']],
        expectNav: null,
        dom: [
            ['وصل نداءُ التقرير', `window.__calls.some(c => c.url.includes('/reports'))`],
            ['الرسمُ الخطّيّ مرسوم — لا رسالةُ «لا بيانات»',
                `document.querySelectorAll('#rp-body svg.amial-chart').length >= 3`],
            ['وفيه مسارٌ حقيقيّ لا إطارٌ فارغ',
                `document.querySelector('#rp-body svg.amial-chart path[stroke-width="2.5"]') !== null`],
            ['بطاقاتُ الملخّص ظهرت بنسبة التغيّر',
                `/٪/.test(document.getElementById('rp-body').textContent)`],
            ['جداول الفروع والموظّفين والفروق ظهرت',
                `/فرع المكلا/.test(document.getElementById('rp-body').textContent)
                 && /محمد علي/.test(document.getElementById('rp-body').textContent)`],
            ['وفرعٌ بلا حركةٍ يُقال عنه ذلك لا يُعرَض صفراً بين العاملين',
                `/بلا حركة/.test(document.getElementById('rp-body').textContent)`],
        ],
    },
    {
        // **الطباعة تُقاس بما يظهر على الورقة لا بأنّ الزرّ نُقر.**
        page: 'dashboard',
        name: 'الطباعة تُخرج التقرير وحده بلا شريطٍ ولا أزرار',
        steps: [['click', '#rp-load'], ['print', null]],
        expectNav: null,
        printMedia: true,
        dom: [
            ['التقرير ظاهرٌ على الورقة',
                `getComputedStyle(document.getElementById('ag-reports')).display !== 'none'`],
            ['والشريط الأسود مخفيّ',
                `getComputedStyle(document.querySelector('.topbar')).display === 'none'`],
            ['وأزرارُ التحكّم مخفيّة',
                `getComputedStyle(document.querySelector('.rp-controls')).display === 'none'`],
            ['وترويسةُ الورقة تظهر — اسمُ الشركة والفترة وتاريخ الإصدار',
                `getComputedStyle(document.getElementById('rp-print-head')).display !== 'none'
                 && document.getElementById('rp-h-agent').textContent.includes('البسيري')
                 && document.getElementById('rp-h-period').textContent.includes('2026')`],
        ],
    },
    {
        page: 'dashboard',
        name: 'الطباعة قبل بناء التقرير تُمنع بدل أن تُخرج ورقةً فارغة',
        steps: [['click', '#rp-print']],
        expectNav: null,
        dom: [
            ['لم يُستدعَ print', `window.__printed !== true`],
            ['وقيل للمستعمل ماذا يفعل', `/اعرض التقرير/.test(window.__lastAlert || '')`],
        ],
    },

    // ── لوحة الموظّفين ──────────────────────────────────────────────
    {
        page: 'staff',
        name: 'زر «الملفّ» يفتح ملفّ الموظّف بدرجته وإشاراتها',
        steps: [['click', 'button[data-do="profile"]']],
        expectNav: null,
        expectModal: '#pr-modal',
        dom: [
            ['وصل نداءُ الملفّ',
                `window.__calls.some(c => /\\/staff\\/9\\/profile/.test(c.url))`],
            ['الدرجة معروضة', `/\\b64\\b/.test(document.getElementById('pr-body').textContent)`],
            ['والإشارات مفصَّلةٌ لا رقمٌ وحده',
                `/تكرار العجز/.test(document.getElementById('pr-body').textContent)`],
            ['والعجز والفائض منفصلان',
                `/عجز/.test(document.getElementById('pr-body').textContent)
                 && /فائض/.test(document.getElementById('pr-body').textContent)`],
        ],
    },
    {
        // «غير معروف» ليس صفراً: من لم يُختبَر لا يُعرَض أخضرَ.
        page: 'staff-unrated',
        name: 'موظّفٌ بلا ورديّات يُعرَض «غير محسوبة» لا صفراً',
        steps: [['click', 'button[data-do="profile"]']],
        expectNav: null,
        dom: [
            ['تُقال «غير محسوبة»',
                `/غير محسوبة/.test(document.getElementById('pr-body').textContent)`],
            ['ولا تُعرَض درجةٌ خضراء',
                `!document.querySelector('#pr-body .alert-success')`],
        ],
    },

    // ── الإعدادات ────────────────────────────────────────────────────
    {
        page: 'settings',
        name: 'الإعدادات تُحمَّل: الأمان يُقال حلُّه، وحدُّ التنبيه يُقال إن كان غائباً',
        steps: [['wait', 700]],
        expectNav: null,
        dom: [
            ['وصل نداءُ الإعدادات',
                `window.__calls.some(c => c.url.includes('/settings'))`],
            // **العطل يُقال ومعه حلُّه.** «الاتّصال غير مشفَّر» وحدها
            // تُخيف ولا تُرشد، فيقرؤها صاحب الشركة ولا يعرف ما يفعل.
            ['يُقال إنّ الاتّصال غير مشفَّر',
                `/غير مشفَّر/.test(document.getElementById('sg-security').textContent)`],
            ['ويُقال إنّ العنوان الرقميّ لا تُصدَر له شهادة',
                `/لا تُصدَر لعنوان رقميّ/.test(document.getElementById('sg-security').textContent)`],
            ['وتُذكر الخطوة العمليّة في Coolify',
                `/Domains/.test(document.getElementById('sg-security').textContent)`],
            // حدٌّ صفريّ يجب أن يُقال «غائب» لا أن يُعرَض رقماً.
            ['وحدُّ التنبيه الغائب يُقال صراحةً',
                `/لن تصلك رسالة نقدٍ منخفض/.test(document.getElementById('sg-branches').textContent)`],
        ],
    },
    {
        page: 'settings',
        name: 'زرّ «حفظ حدود الفرع» يرسل العتبة فعلاً',
        steps: [
            ['wait', 700],
            ['fill', '[data-sg-branch="1"] [data-sg="min"]', '100000'],
            ['click', 'button[data-sg-save="1"]'],
            ['wait', 400],
        ],
        expectNav: null,
        dom: [
            ['وصل الحفظ إلى الخادم',
                `window.__calls.some(c => c.method === 'POST' && c.url.includes('/branches/1/thresholds'))`],
        ],
    },

    // ── حدود الموظّف ودوامه ─────────────────────────────────────────
    {
        page: 'staff',
        name: 'زرّ «حدود ودوام» يفتح النافذة بقيمها الحاليّة ويحفظ',
        steps: [
            ['click', 'button[data-do="limits"]'],
            ['fill', '#lm-hours', '9'],
            ['click', '#lm-save'],
        ],
        expectNav: null,
        dom: [
            // **النافذة تفتح بالقيم الحاليّة لا فارغة.** وفارغةً يُصفّر
            // الحفظُ كلَّ ما لم يُعدَّل — فمن جاء ليغيّر ساعةً يمحو حدوداً.
            ['الحدّ اليوميّ الحاليّ مملوء',
                `document.getElementById('lm-daily').value === '500000'`],
            ['وسياسة الإضافيّ الحاليّة مختارة',
                `document.getElementById('lm-ot').value === 'approved'`],
            ['ووصل الحفظ إلى الخادم',
                `window.__calls.some(c => c.method === 'POST' && c.url.includes('/staff/9/limits'))`],
        ],
    },

    // ── ربط واتساب الموظّف ──────────────────────────────────────────
    //
    // **الحالة التي أدخلت هذين الفحصين:** نقطتا `wa.link` و`wa.unlink`
    // كانتا مبنيّتين ومُختبَرتين ومسجَّلتين في `routes/agent.php` — ولا زرّ
    // لهما في أيّ شاشة. الاختبارات تمرّ، والميزة غير موجودة عند المستعمل.
    {
        page: 'staff',
        name: 'زر «واتساب» يفتح النافذة ويرسل رمز الربط فعلاً',
        steps: [
            ['click', 'button[data-do="wa"]'],
            ['fill', '#wa-phone', '967700000009'],
            ['click', '#wa-send'],
        ],
        expectNav: null,
        dom: [
            ['وصل نداءُ الربط إلى الخادم',
                `window.__calls.some(c => c.method === 'POST' && /\\/staff\\/9\\/whatsapp$/.test(c.url))`],
            ['ولم يُنادَ فكُّ الربط بالخطأ',
                `!window.__calls.some(c => c.url.includes('/whatsapp/unlink'))`],
        ],
    },
    {
        // موظّفٌ غير مربوط لا يُعرَض له زرُّ فكّ: زرٌّ يفكّ ما ليس مربوطاً
        // يجعل المدير يظنّ أنّ ربطاً قائمٌ فيبحث عن سبب صمت البوت.
        page: 'staff',
        name: 'غير المربوط: لا زرَّ فكّ، والحالة تُقال صراحةً',
        steps: [['click', 'button[data-do="wa"]']],
        expectNav: null,
        expectModal: '#wa-modal',
        dom: [
            ['زرّ الفكّ مخفيّ', `document.getElementById('wa-unlink').style.display === 'none'`],
            ['والحالة مكتوبة', `/لا رقم مربوطاً/.test(document.getElementById('wa-state').textContent)`],
        ],
    },
    {
        page: 'staff-wa-linked',
        name: 'المربوط: تظهر حالتُه في الجدول ويعمل زرّ الفكّ',
        steps: [
            ['click', 'button[data-do="wa"]'],
            ['click', '#wa-unlink'],
        ],
        expectNav: null,
        dom: [
            ['حالةُ الربط معروضةٌ في الصفّ',
                `/مربوط/.test(document.getElementById('st-tbody').textContent)`],
            ['ووصل نداءُ الفكّ',
                `window.__calls.some(c => c.method === 'POST' && c.url.includes('/staff/9/whatsapp/unlink'))`],
        ],
    },

    // ── ١٨/٣/٦/٨) مساحةُ عمل الصرّاف ────────────────────────────────
    {
        page: 'counter',
        name: 'مساحة العمل تُحمَّل: مؤشّرات وحدود وصلاحيات وتعاميم',
        steps: [],
        waitFor: '#ws[data-boot="1"]',
        expectNav: null,
        dom: [
            ['وصل نداءُ مساحة العمل',
                `window.__calls.some(c => c.url.includes('/teller/workspace'))`],
            ['المؤشّرات مرسومة',
                `/عملاء خدمتَهم اليوم/.test(document.getElementById('ws-kpis').textContent)`],
            ['والحدّ المتبقّي معروضٌ بالرقم',
                `/350,000/.test(document.getElementById('ws-limits').textContent)`],
            ['وما بلا حدٍّ يُقال «بلا حدّ خاصّ» لا صفراً',
                `/بلا حدّ خاصّ/.test(document.getElementById('ws-limits').textContent)`],
            ['الصلاحيات المرفوضة تُعرَض مرفوضةً',
                `/❌/.test(document.getElementById('ws-perms').textContent)`],
            ['وتعميم الإدارة ظاهر',
                `/صيانة الليلة/.test(document.getElementById('ws-announce').textContent)`],
            ['والطابعة تُقال «غير مربوطة» لا خضراء',
                `/لا يتّصل به النظام/.test(document.getElementById('ws-systems').textContent)`],
            ['وفتحُ الشاشة سُجّل في التدقيق',
                `window.__calls.some(c => c.method === 'POST' && c.url.includes('/teller/event'))`],
        ],
    },
    {
        // ٤) الإشارة تظهر **وهو يكتب** — لا بعد أن يضغط.
        page: 'counter',
        name: 'إشارة الخطر تظهر أثناء كتابة المبلغ ويظهر معها طريقُ الخروج',
        steps: [
            ['fill', '#ct-phone', '783545525'],
            ['click', '#ct-find'],
            ['fill', '#ct-amount', '90000'],
            ['wait', 900],
        ],
        expectNav: null,
        dom: [
            ['وصل نداءُ الفحص قبل أيّ تنفيذ',
                `window.__calls.some(c => c.url.includes('/teller/assess'))`],
            ['ولم يُنفَّذ إيداعٌ أصلاً',
                `!window.__calls.some(c => c.url.includes('/counter/deposit'))`],
            ['الإشارة معروضة',
                `/فوق حدّ عمليّتك/.test(document.getElementById('ct-flags').textContent)`],
            ['وزرّ طلب الموافقة ظهر بدل الرفض المسدود',
                `document.getElementById('ct-ask').style.display !== 'none'`],
        ],
    },
    {
        page: 'counter',
        name: 'الإشارة الحمراء تُعطّل زرّ الإيداع نفسه',
        init: 'window.__blocked = true;',
        steps: [
            ['fill', '#ct-phone', '783545525'],
            ['click', '#ct-find'],
            ['fill', '#ct-amount', '5000'],
            ['wait', 900],
        ],
        expectNav: null,
        dom: [
            ['زرّ الإيداع معطَّل', `document.getElementById('ct-deposit').disabled === true`],
            ['والسبب مكتوب', `/محظور/.test(document.getElementById('ct-flags').textContent)`],
        ],
    },
    {
        // ١٧) الرفض وحده يدفع إلى التقسيم — فالطلب هو الطريق.
        page: 'counter',
        name: 'زرّ «اطلب موافقة مديرك» يفتح النافذة ويُرسل الطلب فعلاً',
        steps: [
            ['fill', '#ct-phone', '783545525'],
            ['click', '#ct-find'],
            ['fill', '#ct-amount', '90000'],
            ['wait', 900],
            ['click', '#ct-ask'],
            ['fill', '#ws-req-reason', 'العميل تاجرٌ معروف ومعه فاتورة موثّقة'],
            ['click', '#ws-req-send'],
        ],
        expectNav: null,
        dom: [
            // **بلا تعبيرٍ نمطيّ هنا.** هذه السلسلة قالبٌ نصّيّ، و`\\/` فيه
            // تصير `/` — فينقلب `/…/` إلى تعليقِ سطرٍ يبتلع بقيّة الشرط،
            // فيُقرأ التحقُّق «لم يصل الطلب» والطلبُ واصل. وقد وقع هذا
            // مرّتين في هذه الجلسة وحدها.
            ['وصل الطلب إلى الخادم',
                `window.__calls.some(c => c.method === 'POST'
                    && c.url.split('?')[0].endsWith('/teller/request'))`],
            ['والمبلغ نُقل إلى النافذة تلقائياً',
                `document.getElementById('ws-req-amount').value === '90000'`],
        ],
    },
    {
        // ١٣) زرّ الطوارئ — والموقع لا يُنتظر أكثر من ثلاث ثوانٍ.
        page: 'counter',
        name: 'زرّ الطوارئ يُرسل البلاغ ولا ينتظر موقعاً على اتّصالٍ غير مشفَّر',
        steps: [
            ['click', '#ws-panic'],
            ['click', '#ws-panic-send'],
            ['wait', 600],
        ],
        expectNav: null,
        dom: [
            ['وصل البلاغ إلى الخادم',
                `window.__calls.some(c => c.method === 'POST' && c.url.includes('/teller/panic'))`],
            ['وقيل للموظّف أنّ الموقع لن يُرسَل ولماذا',
                `/غير مشفَّر/.test(document.getElementById('ws-panic-note').textContent)`],
        ],
    },

    // ── ساعاتُ الدوام ────────────────────────────────────────────────
    {
        page: 'counter',
        name: 'زرّ «كشف ساعاتي» يفتح الكشف بيوميّه وأسبوعيّه وشهريّه',
        steps: [['click', '#ws-sheet'], ['wait', 600]],
        expectNav: null,
        expectModal: '#ws-sheet-modal',
        dom: [
            ['وصل نداءُ الكشف',
                `window.__calls.some(c => c.url.includes('/teller/timesheet'))`],
            ['الإجماليّ بالساعات والدقائق لا بالكسور',
                `/14س/.test(document.getElementById('ws-sheet-body').textContent)`],
            ['واليوميّ مفصَّل', `/2026-08-03/.test(document.getElementById('ws-sheet-body').textContent)`],
            ['والأسبوعيّ والشهريّ معروضان',
                `/أسبوعيّاً/.test(document.getElementById('ws-sheet-body').textContent)
                 && /شهريّاً/.test(document.getElementById('ws-sheet-body').textContent)`],
            ['و«غير المؤكَّد» يُعرَض ولا يُخفى',
                `/غير مؤكَّد/.test(document.getElementById('ws-sheet-body').textContent)`],
            ['والإضافيّ الواقع بلا موافقة يُقال إنّه بلا موافقة',
                `/بلا موافقة/.test(document.getElementById('ws-sheet-body').textContent)`],
        ],
    },
    {
        // **إنذارُ السلسلة المكسورة هو الحالة التي تهمّ.** وفحصُ السليمة
        // وحدها يترك الإنذار بلا فحص — وهو ما يُبنى عليه القرار.
        page: 'counter',
        name: 'سجلٌّ مكسور: يُصرَّح به فوق الأرقام لا تحتها',
        init: 'window.__chainBroken = true;',
        steps: [['click', '#ws-sheet'], ['wait', 600]],
        expectNav: null,
        dom: [
            ['الإنذار ظاهر',
                `/غير سليم/.test(document.getElementById('ws-sheet-body').textContent)`],
            ['ويقول ماذا يُفعل',
                `/لا تُبنَ على هذه الأرقام قرارات/.test(document.getElementById('ws-sheet-body').textContent)`],
            ['وهو **فوق** الجدول لا تحته',
                `document.getElementById('ws-sheet-body').innerHTML.indexOf('غير سليم')
                 < document.getElementById('ws-sheet-body').innerHTML.indexOf('<table')`],
        ],
    },
    {
        page: 'counter',
        name: 'زرّ الاستراحة يرسل الطلب ويقلب حالته',
        steps: [['click', '#ws-break'], ['wait', 400]],
        expectNav: null,
        dom: [
            ['وصل نداءُ الاستراحة',
                `window.__calls.some(c => c.method === 'POST' && c.url.includes('/teller/break'))`],
            ['والزرّ صار «أنهِ الاستراحة»',
                `/أنهِ الاستراحة/.test(document.getElementById('ws-break').textContent)`],
        ],
    },

    // ── شبّاك الصرّاف ────────────────────────────────────────────────
    {
        // العطل الذي أدخل هذه الحالة: سطرٌ يقرأ `$('ct-withdraw').disabled`
        // على زرٍّ أُزيل، **خارج `try`**، فيموت المعالج قبل أيّ طلب. الزرّ
        // يُضغط ولا يحدث شيء ولا رسالة.
        page: 'counter',
        name: 'زر «إيداع» يرسل الطلب فعلاً ويعرض النتيجة',
        steps: [
            ['fill', '#ct-phone', '783545525'],
            ['click', '#ct-find'],
            ['fill', '#ct-amount', '2000'],
            ['click', '#ct-deposit'],
        ],
        expectNav: null,
        dom: [
            ['وصل نداءُ الإيداع إلى الخادم',
                `window.__calls.some(c => c.method === 'POST' && c.url.includes('/counter/deposit'))`],
            ['وظهرت نتيجةُ نجاحٍ للصرّاف',
                `/alert-success/.test(document.getElementById('ct-result').innerHTML)`],
            ['وأُفرغ حقل المبلغ استعداداً للعملية التالية',
                `document.getElementById('ct-amount').value === ''`],
        ],
    },
    {
        // **الإيصال اختياريّ.** والزرّ يبقى بعد العملية حتى لو رفض العميل
        // الورقة ثمّ عاد يطلبها.
        page: 'counter',
        name: 'الإيداع يعرض زرّ طباعة الإيصال ويفتحه تلقائياً',
        steps: [
            ['fill', '#ct-phone', '783545525'],
            ['click', '#ct-find'],
            ['fill', '#ct-amount', '2000'],
            ['click', '#ct-deposit'],
        ],
        expectNav: null,
        dom: [
            ['زرّ الطباعة ظهر برقم الإيصال',
                `/RCP-2026-000123/.test(document.getElementById('ct-result').innerHTML)`],
            ['ورابطُه يفتح صفحة الإيصال',
                `document.querySelector('#ct-result a[href*="/receipt/RCP-2026-000123"]') !== null`],
            ['وفُتحت نافذةُ الطباعة تلقائياً',
                `window.__opened.some(u => u.includes('/receipt/RCP-2026-000123') && u.includes('auto=1'))`],
        ],
    },
    {
        page: 'counter',
        name: 'إلغاءُ الطباعة التلقائيّة يمنع فتح النافذة ويُبقي الزرّ',
        steps: [
            ['fill', '#ct-phone', '783545525'],
            ['click', '#ct-find'],
            ['click', '#ct-autoprint'],
            ['fill', '#ct-amount', '2000'],
            ['click', '#ct-deposit'],
        ],
        expectNav: null,
        dom: [
            ['لم تُفتح نافذة', `window.__opened.length === 0`],
            ['والزرّ ما زال متاحاً لمن يطلب الورقة',
                `document.querySelector('#ct-result a[href*="/receipt/"]') !== null`],
        ],
    },
    {
        page: 'counter',
        name: 'زر «بحث» يجد العميل ويفتح لوحة العملية',
        steps: [
            ['fill', '#ct-phone', '783545525'],
            ['click', '#ct-find'],
        ],
        expectNav: null,
        dom: [
            ['اسم العميل ظهر', `/راشد/.test(document.getElementById('ct-customer').textContent)`],
            ['ولوحة العملية فُتحت', `document.getElementById('ct-op').style.display !== 'none'`],
        ],
    },

    // ── مركز الوكلاء ────────────────────────────────────────────────
    {
        name: 'زر «تحويل رصيد» يفتح نافذة التحويل ولا ينقل إلى ملفّ الوكيل',
        click: 'button[data-act="transfer"]',
        expectModal: '#modal-transfer',
        expectNav: null,
    },
    {
        name: 'زر «التفاصيل» ينقل إلى صفحة الحساب',
        click: 'button[data-act="open"]',
        expectNav: /\/hub\/account\/7$/,
    },
    {
        name: 'الضغط على الصفّ خارج الأزرار ينقل إلى صفحة الحساب',
        click: 'tr[data-act="open"] td:nth-child(2)',
        expectNav: /\/hub\/account\/7$/,
    },
    {
        name: 'زر «تجميد» ينفّذ ولا ينقل',
        click: 'button[data-act="toggle"]',
        expectNav: null,
    },
    {
        // المنصّة ──► الوكيل الأمّ ──► الفرع. والخدمة ترفض تمويل الفرع على
        // كلّ حال؛ وهذا يتحقّق أنّ الشاشة تقول **السبب** بدل أن تَصُدّ بعد
        // الضغط.
        name: 'صفّ الفرع لا يعرض زرّ تحويل ويقول لماذا',
        click: null,
        expectNav: null,
        dom: [
            ['لا زرّ تحويل على صفّ الفرع',
                `!document.querySelector('tr[data-id="8"] button[data-act="transfer"]')`],
            ['والسبب مكتوبٌ في مكانه',
                `/يُموَّل من البسيري/.test(document.querySelector('tr[data-id="8"]').textContent)`],
            ['وزرّ التحويل قائمٌ على صفّ الوكيل الأمّ',
                `!!document.querySelector('tr[data-id="7"] button[data-act="transfer"]')`],
        ],
    },
];

const PAGES = {
    hub: { url: HUB_URL, html: HUB_HTML, ready: 'tr[data-act="open"]' },
    counter: { url: COUNTER_URL, html: COUNTER_HTML, ready: '#ct-modes' },
    staff: { url: STAFF_URL, html: STAFF_HTML, ready: 'button[data-do="profile"]' },
    'staff-unrated': { url: STAFF_URL, html: STAFF_HTML, ready: 'button[data-do="profile"]',
                       init: 'window.__unrated = true;' },
    'staff-wa-linked': { url: STAFF_URL, html: STAFF_HTML, ready: 'button[data-do="wa"]',
                         init: 'window.__waLinked = true;' },
    dashboard: { url: DASH_URL, html: DASH_HTML, ready: '#rp-load' },
    // شاشةُ الإعدادات تُحمَّل عند فتح تبويبها لا عند تحميل الصفحة، فتُنادى
    // `load()` هنا صراحةً — وهو ما يفعله `shown.bs.tab` في اللوحة.
    settings: { url: SETTINGS_URL, html: SETTINGS_HTML, ready: '#sg-security' },
};

const browser = await chromium.launch({ args: ['--no-sandbox'] });
let pass = 0, fail = 0;

for (const c of CASES) {
    const target = PAGES[c.page || 'hub'];
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    let navigated = null;

    await page.route('**/*', async (route) => {
        const u = route.request().url();
        if (u === target.url) {
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: target.html });
        }
        if (route.request().resourceType() === 'document') navigated = u;   // انتقالٌ وقع
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<html><body>x</body></html>' });
    });

    if (target.init) await page.addInitScript(target.init);
    if (c.init) await page.addInitScript(c.init);
    await page.addInitScript(STUBS);

    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(err.message));
    if (process.env.CLICK_DEBUG) {
        page.on('console', (m) => console.log('    [console]', m.type(), m.text().slice(0, 200)));
    }

    try {
        await page.goto(target.url, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector(target.ready, { timeout: 5000 });

        // شرطُ جهوزيّةٍ خاصٌّ بالحالة: شاشةٌ تُحمَّل بنداءٍ لا تكون جاهزةً
        // بمجرّد ظهور هيكلها. وانتظارُ زمنٍ ثابتٍ بدلاً منه يجعل الفحص
        // يمرّ على آلةٍ سريعة ويسقط على بطيئة — وهو أسوأ من ألّا يوجد.
        if (c.waitFor) await page.waitForSelector(c.waitFor, { timeout: 5000 });

        for (const [action, sel, val] of (c.steps || [])) {
            if (action === 'fill') await page.fill(sel, val);
            else if (action === 'print') await page.evaluate(() => window.print());
            else if (action === 'wait') await page.waitForTimeout(Number(sel) || 300);
            else await page.click(sel);
            await page.waitForTimeout(250);
        }

        // الورقة تُقاس في وسط الطباعة لا وسط الشاشة: قواعد @media print
        // لا تنطبق إلّا فيه، وفحصُها على الشاشة يقيس شيئاً آخر.
        if (c.printMedia) {
            await page.emulateMedia({ media: 'print' });
            await page.waitForTimeout(150);
        }

        if (c.click) {
            await page.click(c.click);
            await page.waitForTimeout(700);   // مهلة الانتقال وحركة النافذة
        }

        const modalShown = c.expectModal
            ? await page.evaluate((sel) => !!document.querySelector(sel)?.classList.contains('show'), c.expectModal)
            : null;

        const problems = [];
        if (jsErrors.length) problems.push(`خطأ جافاسكربت: ${jsErrors.join(' | ')}`);
        if (c.expectNav === null && navigated) problems.push(`انتقل إلى ${navigated} والمفترض ألّا ينتقل`);
        if (c.expectNav instanceof RegExp) {
            if (!navigated) problems.push('لم ينتقل والمفترض أن ينتقل');
            else if (!c.expectNav.test(navigated)) problems.push(`انتقل إلى ${navigated} ولا يطابق المتوقَّع`);
        }
        if (c.expectModal && modalShown !== true) problems.push(`النافذة ${c.expectModal} لم تُفتح`);

        for (const [desc, js] of (c.dom || [])) {
            const okDom = await page.evaluate(`Boolean(${js})`).catch(() => false);
            if (!okDom) problems.push(`تحقُّق غير محقَّق: ${desc}`);
        }

        if (problems.length) { console.log(`  \x1b[31m✗\x1b[0m ${c.name}\n      ${problems.join('\n      ')}`); fail++; }
        else { console.log(`  \x1b[32m✓\x1b[0m ${c.name}`); pass++; }
    } catch (err) {
        const extra = jsErrors.length ? `\n      خطأ جافاسكربت: ${jsErrors.join(' | ')}` : '';
        console.log(`  \x1b[31m✗\x1b[0m ${c.name}\n      ${err.message.split('\n')[0]}${extra}`);
        fail++;
    } finally {
        await ctx.close();
    }
}

await browser.close();
process.exit(fail === 0 ? 0 : 1);
