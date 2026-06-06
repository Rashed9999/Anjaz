/**
 * AMIAL-LOADTEST-002 — اختبار تحمّل متدرّج شامل كل المزايا
 *
 * مراحل متصاعدة للمستخدمين المتزامنين (VUs):
 *   20 → 100 → 500 → 1000 → 4000 → 10000
 *
 * في كل تكرار، يختار كل مستخدم عملية من **كل مزايا أميال** (موزّعة بأوزان واقعية):
 *   تحويل، دفع QR للتاجر، طلب أموال، إيصالات، إشعارات، الملف الشخصي،
 *   محطة وقود، صيدلية، جملة، كاشير (بيع)، Safe-Pay، تقسيم فواتير،
 *   تبرّعات، دفع فواتير، الصناديق العائلية، الاشتراكات.
 *
 * التجهيز (staging فقط — §25):
 *   LOADTEST_COUNT=10000 php artisan db:seed --class=LoadTestSeeder
 *   cp storage/app/tokens.json storage/app/merchants.json loadtests/
 *
 * التشغيل:
 *   k6 run -e BASE_URL=https://staging.amialpay.com loadtests/staged_all_features.js
 *
 * ⚠️ 10000 VU يتطلّب مولّد حمل قوي (أو k6 موزّع / k6 Cloud). على جهاز واحد
 *    اضبط السقف عبر: -e PEAK=2000 (يقصّ المراحل عند هذا الحد).
 *
 * ملاحظة صدق: السكربت **يقيس** لا يضمن. عمليات الكتابة الأساسية (تحويل/QR/كاشير)
 * بحمولات حقيقية؛ مزايا الـ POS الأخرى تُقاس عبر نقاط القراءة (dashboards/lists)
 * لتفادي حمولات كتابة تعتمد على بيانات staging — فعّل كتابتها عند تجهيز بياناتها.
 */

import http from 'k6/http';
import { check, group } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';
import { SharedArray } from 'k6/data';

const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8000').replace(/\/$/, '');
const API = `${BASE_URL}/api/v1/amial`;
const PEAK = parseInt(__ENV.PEAK || '10000', 10);

// بيانات مُجهّزة مسبقاً عبر LoadTestSeeder
const customers = new SharedArray('customers', () => JSON.parse(open('./tokens.json')));     // [{token, account_number, pin}]
const merchants = new SharedArray('merchants', () => JSON.parse(open('./merchants.json')));  // [{token, account_number}]
// مجموعة تجّار POS كاملة التهيئة (اختياري) — يُنتجها LoadTestSeeder كـ pos.json
const posPool = new SharedArray('pos', () => {
  try { return JSON.parse(open('./pos.json')); } catch (e) { return []; }
});                                                                                          // [{token, fuel:{pump_id,fuel_product_id}, pharmacy:{product_id}, wholesale:{customer_id,product_id}, cashier}]

// مقاييس لكل ميزة
const featureRate = {};
const featureLatency = {};
const FEATURES = [
  'transfer', 'qr_pay', 'request_money', 'receipts', 'notifications', 'profile',
  'fuel', 'pharmacy', 'wholesale', 'cashier', 'safe_pay', 'split_bill',
  'donations', 'bill_pay', 'funds', 'subscriptions',
];
for (const f of FEATURES) {
  featureRate[f] = new Rate(`feat_${f}_ok`);
  featureLatency[f] = new Trend(`feat_${f}_ms`, true);
}
const bizErrors = new Counter('business_errors');
// أخطاء الخادم (5xx) — مؤشّر مباشر على deadlocks/انهيار المعاملة تحت الضغط
const serverErrors = new Counter('server_errors');

// قصّ المراحل عند PEAK (لمولّد حمل واحد)
const cap = (t) => Math.min(t, PEAK);

// عمليات الكتابة لمزايا POS مفعّلة عبر -e POS_WRITES=1 (تتطلّب بيانات POS في staging)
const POS_WRITES = __ENV.POS_WRITES === '1';

/**
 * بناء السيناريو:
 *   - BURST=<n> → دفعة لحظية: n مستخدم ينفّذون عملية واحدة في نفس اللحظة
 *                 (مثلاً -e BURST=2000 → 2000 عملية متزامنة). اختياري -e BURST_OP=transfer.
 *   - STAGE=<n> → مرحلة مفردة بثبات (للتقرير المنفصل لكل مستوى عبر run_stages.sh).
 *   - بدون شيء → التدرّج الكامل 20→100→500→1000→4000→10000.
 */
function buildScenarios() {
  if (__ENV.BURST) {
    return {
      burst: {
        executor: 'per-vu-iterations',
        vus: cap(parseInt(__ENV.BURST, 10)),
        iterations: 1,
        maxDuration: __ENV.BURST_MAX || '2m',
      },
    };
  }
  if (__ENV.STAGE) {
    return {
      all_features: {
        executor: 'constant-vus',
        vus: cap(parseInt(__ENV.STAGE, 10)),
        duration: __ENV.STAGE_DURATION || '2m',
      },
    };
  }
  return {
    all_features: {
      executor: 'ramping-vus',
      startVUs: 0,
      gracefulRampDown: '30s',
      stages: [
        { duration: '30s', target: cap(20) },
        { duration: '1m',  target: cap(20) },
        { duration: '30s', target: cap(100) },
        { duration: '1m',  target: cap(100) },
        { duration: '30s', target: cap(500) },
        { duration: '1m',  target: cap(500) },
        { duration: '30s', target: cap(1000) },
        { duration: '2m',  target: cap(1000) },
        { duration: '1m',  target: cap(4000) },
        { duration: '2m',  target: cap(4000) },
        { duration: '1m',  target: cap(10000) },
        { duration: '3m',  target: cap(10000) },
        { duration: '1m',  target: 0 },
      ],
    },
  };
}

/**
 * عتبات القبول — تختلف حسب الوضع:
 *   - Burst (دفعة لحظية): صارمة — صفر أخطاء خادم (لا deadlocks)، نجاح ≥99%.
 *   - تدرّج/مرحلة: عتبات تشغيلية عادية.
 */
function buildThresholds() {
  if (__ENV.BURST) {
    const n = cap(parseInt(__ENV.BURST, 10));
    const t = {
      // مؤشّر "لا deadlocks": صفر أخطاء خادم 5xx مسموح بها
      server_errors: ['count==0'],
      // ≤1% فشل نقل تحت الذروة
      http_req_failed: [`rate<0.01`],
      // زمن أعلى مقبول عند التزامن الأقصى
      http_req_duration: ['p(95)<5000'],
      // ≤1% أخطاء عمل غير متوقّعة
      business_errors: [`count<${Math.ceil(n * 0.01)}`],
    };
    if (__ENV.BURST_OP) {
      // دفعة موجّهة → نجاح ≥99% للعملية المستهدَفة
      t[`feat_${__ENV.BURST_OP}_ok`] = ['rate>0.99'];
    } else {
      // دفعة مختلطة → نجاح الفحوص ≥98%
      t['checks'] = ['rate>0.98'];
    }
    return t;
  }
  // الوضع العادي (تدرّج/مرحلة)
  return {
    http_req_failed: ['rate<0.02'],            // <2% أخطاء نقل
    http_req_duration: ['p(95)<3000'],         // 95% تحت 3 ثوانٍ
    server_errors: ['count==0'],               // لا أخطاء خادم
    'feat_transfer_ok': ['rate>0.97'],
    'feat_qr_pay_ok': ['rate>0.97'],
    'feat_cashier_ok': ['rate>0.95'],
  };
}

export const options = {
  scenarios: buildScenarios(),
  thresholds: buildThresholds(),
};

// ---- أدوات مساعدة ----
function customer() { return customers[(__VU + __ITER) % customers.length]; }
function merchant() { return merchants[(__VU * 7 + __ITER) % merchants.length]; }

function headers(token, idemPrefix) {
  const h = {
    Authorization: `Bearer ${token}`,
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };
  if (idemPrefix) h['Idempotency-Key'] = `${idemPrefix}-${__VU}-${__ITER}-${Date.now()}`;
  return { headers: h, timeout: '15s' };
}

// نجاح "وظيفي": 2xx أو 422 (رفض عمل متوقّع كرصيد/تحقق) — وليس 5xx/فشل نقل
function ok(res, feature) {
  const good = res.status >= 200 && res.status < 300;
  const expected = res.status === 422 || res.status === 409; // قرار عمل/idempotency
  featureLatency[feature].add(res.timings.duration);
  featureRate[feature].add(good || expected);
  if (res.status >= 500) serverErrors.add(1);   // 5xx = deadlock/crash محتمل
  if (!good && !expected) bizErrors.add(1);
  return good;
}

// ---- عمليات كل ميزة ----
function opTransfer() {
  const me = customer();
  const to = merchant();
  const body = JSON.stringify({ recipient: to.account_number, amount: '1000', pin: me.pin });
  ok(http.post(`${API}/transfer`, body, headers(me.token, 'tr')), 'transfer');
}

function opQrPay() {
  const me = customer();
  const to = merchant();
  // quote ثم pay (تدفّق الدفع للتاجر)
  const q = http.post(`${API}/merchant/quote`, JSON.stringify({ merchant: to.account_number, amount: '500' }), headers(me.token));
  ok(q, 'qr_pay');
  const pay = http.post(`${API}/merchant/pay`, JSON.stringify({ merchant: to.account_number, amount: '500', pin: me.pin }), headers(me.token, 'qr'));
  ok(pay, 'qr_pay');
}

function opRequestMoney() {
  const me = customer();
  const to = customer();
  ok(http.post(`${API}/payment-requests/request`, JSON.stringify({ from: to.account_number, amount: '300' }), headers(me.token, 'rq')), 'request_money');
}

function opReceipts()      { ok(http.get(`${API}/receipts?page=1`, headers(customer().token)), 'receipts'); }
function opNotifications() {
  const t = customer().token;
  ok(http.get(`${API}/notifications`, headers(t)), 'notifications');
  ok(http.get(`${API}/notifications/unread-count`, headers(t)), 'notifications');
}
function opProfile()       { ok(http.get(`${API}/me`, headers(customer().token)), 'profile'); }

// تاجر من مجموعة POS المُهيّأة (بمعرّفات حقيقية)؛ null إن لم تُجهّز pos.json
function posMerchant() {
  return posPool.length ? posPool[(__VU + __ITER) % posPool.length] : null;
}

// عنصر عشوائي من مصفوفة (لتنويع المنتجات/العملاء)
function rand(arr) {
  return (arr && arr.length) ? arr[Math.floor(Math.random() * arr.length)] : null;
}

// مزايا POS — قراءة (dashboards) دائماً + كتابة حقيقية عند POS_WRITES=1 ووجود pos.json.
// الحمولات والمعرّفات مطابقة لقواعد التحقق وكيانات staging الفعلية.
function opFuel() {
  ok(http.get(`${API}/fuel/dashboard`, headers(merchant().token)), 'fuel');
  const p = posMerchant();
  if (POS_WRITES && p && p.fuel) {
    const fpId = rand(p.fuel.fuel_product_ids);
    if (fpId) {
      const sale = JSON.stringify({
        pump_id: p.fuel.pump_id, fuel_product_id: fpId,
        sale_type: 'by_amount', amount: '5000', payment_method: 'cash',
      });
      ok(http.post(`${API}/fuel/sales`, sale, headers(p.token, 'fuel')), 'fuel');
    }
  }
}

function opPharmacy() {
  ok(http.get(`${API}/pharmacy/dashboard`, headers(merchant().token)), 'pharmacy');
  const p = posMerchant();
  if (POS_WRITES && p && p.pharmacy) {
    const prodId = rand(p.pharmacy.product_ids);
    if (prodId) {
      const sale = JSON.stringify({
        items: [{ product_id: prodId, quantity: 1 }],
        payment_method: 'cash',
      });
      ok(http.post(`${API}/pharmacy/sales`, sale, headers(p.token, 'phar')), 'pharmacy');
    }
  }
}

function opWholesale() {
  ok(http.get(`${API}/wholesale/dashboard`, headers(merchant().token)), 'wholesale');
  const p = posMerchant();
  if (POS_WRITES && p && p.wholesale) {
    const custId = rand(p.wholesale.customer_ids);
    const prodId = rand(p.wholesale.product_ids);
    if (custId && prodId) {
      const inv = JSON.stringify({
        customer_id: custId, payment_type: 'cash',
        items: [{ product_id: prodId, quantity: 1 }],
      });
      ok(http.post(`${API}/wholesale/invoices`, inv, headers(p.token, 'whol')), 'wholesale');
    }
  }
}

function opCashier() {
  const p = posMerchant();
  const token = (POS_WRITES && p) ? p.token : merchant().token;
  ok(http.get(`${API}/cashier/products`, headers(token)), 'cashier');
  // بيع كاشير (كتابة) — حمولة مطابقة لـ CashierController::recordSale
  const sale = JSON.stringify({
    total: '50', payment_method: 'cash',
    items: [{ name: 'LoadItem', qty: 1, price: 50 }],
  });
  ok(http.post(`${API}/cashier/sales`, sale, headers(token, 'csh')), 'cashier');
}

function opSafePay()    { ok(http.get(`${API}/safe-payments`, headers(customer().token)), 'safe_pay'); }
function opSplitBill()  { ok(http.get(`${API}/safe-payments`, headers(customer().token)), 'split_bill'); }
function opDonations()  { ok(http.get(`${API}/donations`, headers(customer().token)), 'donations'); }
function opBillPay()    { ok(http.get(`${API}/bill-pay/providers`, headers(customer().token)), 'bill_pay'); }
function opFunds()      { ok(http.get(`${API}/funds`, headers(customer().token)), 'funds'); }
function opSubs()       { ok(http.get(`${API}/subscriptions`, headers(customer().token)), 'subscriptions'); }

// توزيع الأوزان (المحرّك المالي يأخذ حملاً أكبر — واقعي)
const WORKLOAD = [
  { fn: opTransfer,     w: 22, g: 'transfer' },
  { fn: opQrPay,        w: 20, g: 'qr_pay' },
  { fn: opProfile,      w: 10, g: 'profile' },
  { fn: opReceipts,     w: 8,  g: 'receipts' },
  { fn: opNotifications,w: 8,  g: 'notifications' },
  { fn: opRequestMoney, w: 5,  g: 'request_money' },
  { fn: opCashier,      w: 5,  g: 'cashier' },
  { fn: opFuel,         w: 4,  g: 'fuel' },
  { fn: opPharmacy,     w: 3,  g: 'pharmacy' },
  { fn: opWholesale,    w: 3,  g: 'wholesale' },
  { fn: opBillPay,      w: 3,  g: 'bill_pay' },
  { fn: opSafePay,      w: 2,  g: 'safe_pay' },
  { fn: opSplitBill,    w: 2,  g: 'split_bill' },
  { fn: opDonations,    w: 2,  g: 'donations' },
  { fn: opFunds,        w: 2,  g: 'funds' },
  { fn: opSubs,         w: 1,  g: 'subscriptions' },
];
const TOTAL_W = WORKLOAD.reduce((s, x) => s + x.w, 0);

function pick() {
  let r = Math.random() * TOTAL_W;
  for (const item of WORKLOAD) {
    r -= item.w;
    if (r <= 0) return item;
  }
  return WORKLOAD[0];
}

// خريطة أسماء العمليات لوضع الدفعة المُوجّهة (-e BURST_OP=transfer)
const OP_MAP = {
  transfer: opTransfer, qr_pay: opQrPay, request_money: opRequestMoney,
  receipts: opReceipts, notifications: opNotifications, profile: opProfile,
  fuel: opFuel, pharmacy: opPharmacy, wholesale: opWholesale, cashier: opCashier,
  safe_pay: opSafePay, split_bill: opSplitBill, donations: opDonations,
  bill_pay: opBillPay, funds: opFunds, subscriptions: opSubs,
};

export default function () {
  // دفعة موجّهة لنوع عملية واحد (اختبار تزامن نقي، مثل 2000 تحويل لحظي)
  if (__ENV.BURST_OP && OP_MAP[__ENV.BURST_OP]) {
    group(__ENV.BURST_OP, () => OP_MAP[__ENV.BURST_OP]());
    return;
  }
  const item = pick();
  group(item.g, () => item.fn());
}

/**
 * تقرير موجز في نهاية التشغيل (إضافةً لمخرجات k6 القياسية).
 */
export function handleSummary(data) {
  const line = (k) => {
    const m = data.metrics[k];
    return m ? `${k}: ${JSON.stringify(m.values)}` : `${k}: -`;
  };
  const out = [
    '==== ملخّص أميال — اختبار متدرّج شامل ====',
    line('http_req_duration'),
    line('http_req_failed'),
    line('server_errors'),
    line('business_errors'),
    ...FEATURES.map((f) => line(`feat_${f}_ok`)),
  ].join('\n');
  return {
    stdout: out + '\n',
    'loadtests/summary.json': JSON.stringify(data, null, 2),
  };
}
