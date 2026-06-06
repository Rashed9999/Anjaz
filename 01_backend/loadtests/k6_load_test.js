/**
 * AMIAL-LOAD-TEST — اختبار تحمّل أميال باي
 *
 * السيناريو المطلوب:
 *   - 5000 عميل يحوّل في نفس اللحظة.
 *   - 5000 عميل يدفع لـ 5000 تاجر عبر QR في نفس اللحظة.
 *
 * هذا يُشغَّل على بيئة staging (ليس production، لا بيانات عملاء حقيقية — §25).
 *
 * التشغيل:
 *   1) ثبّت k6: https://k6.io/docs/get-started/installation/
 *   2) جهّز ملف tokens.json (مصفوفة tokens لـ 5000 عميل مُسجّل في staging) +
 *      merchants.json (أرقام حسابات/هواتف 5000 تاجر).
 *   3) k6 run -e BASE_URL=https://staging.amalpay.example load_test.js
 *
 * ملاحظة صدق: الأرقام الناتجة تعتمد على عتادك (CPU/MySQL/Redis/PHP-FPM).
 * هذا السكربت يقيس، لا يضمن — اقرأ التقرير في نهاية التشغيل.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend, Rate } from 'k6/metrics';
import { SharedArray } from 'k6/data';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

// عملاء وتجّار من ملفات تُجهَّز مسبقاً في staging
const customers = new SharedArray('customers', () => JSON.parse(open('./tokens.json')));      // [{token, account_number}]
const merchants = new SharedArray('merchants', () => JSON.parse(open('./merchants.json')));    // [{account_number}]

// مقاييس مخصّصة
const transferOk = new Rate('transfer_success');
const qrOk = new Rate('qr_payment_success');
const transferLatency = new Trend('transfer_latency_ms', true);
const qrLatency = new Trend('qr_payment_latency_ms', true);
const errors = new Counter('business_errors');

export const options = {
  scenarios: {
    // 5000 تحويل في نفس اللحظة (دفعة واحدة)
    transfers_burst: {
      executor: 'per-vu-iterations',
      vus: 5000,
      iterations: 1,
      maxDuration: '2m',
      exec: 'doTransfer',
      startTime: '0s',
    },
    // 5000 دفع QR في نفس اللحظة
    qr_burst: {
      executor: 'per-vu-iterations',
      vus: 5000,
      iterations: 1,
      maxDuration: '2m',
      exec: 'doQrPayment',
      startTime: '0s', // نفس اللحظة
    },
  },
  thresholds: {
    // معايير القبول المقترحة (عدّلها حسب توقعاتك)
    transfer_success: ['rate>0.99'],          // ≥99% نجاح
    qr_payment_success: ['rate>0.99'],
    'transfer_latency_ms': ['p(95)<3000'],    // 95% تحت 3 ثوانٍ
    'qr_payment_latency_ms': ['p(95)<3000'],
    http_req_failed: ['rate<0.01'],           // <1% أخطاء HTTP
  },
};

function authHeaders(token, idem) {
  return {
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'Idempotency-Key': idem,
    },
  };
}

function idem(prefix) {
  return `${prefix}-${__VU}-${__ITER}-${Date.now()}`;
}

// 5000 عميل يحوّلون لتاجر/عميل آخر (تحويل عادي برقم الحساب)
export function doTransfer() {
  const me = customers[__VU % customers.length];
  const to = merchants[(__VU + 1) % merchants.length]; // مستلِم
  const body = JSON.stringify({
    recipient: to.account_number, // التحويل برقم الحساب (AMIAL-ACCOUNT-NUMBER-001)
    amount: '5000',
    transaction_pin: me.pin || '0000',
  });

  const res = http.post(`${BASE_URL}/api/v1/amial/transfer`, body, authHeaders(me.token, idem('tr')));
  transferLatency.add(res.timings.duration);
  const ok = check(res, {
    'transfer 2xx': (r) => r.status >= 200 && r.status < 300,
    'transfer success flag': (r) => {
      try { return r.json('success') === true; } catch (e) { return false; }
    },
  });
  transferOk.add(ok);
  if (!ok) errors.add(1);
}

// 5000 عميل يدفعون لـ 5000 تاجر عبر QR
export function doQrPayment() {
  const me = customers[__VU % customers.length];
  const merchant = merchants[__VU % merchants.length];
  const body = JSON.stringify({
    merchant_account: merchant.account_number,
    amount: '5000',
    channel: 'qr',
  });

  const res = http.post(`${BASE_URL}/api/v1/amial/merchant/pay`, body, authHeaders(me.token, idem('qr')));
  qrLatency.add(res.timings.duration);
  const ok = check(res, {
    'qr 2xx': (r) => r.status >= 200 && r.status < 300,
    'qr success flag': (r) => {
      try { return r.json('success') === true; } catch (e) { return false; }
    },
  });
  qrOk.add(ok);
  if (!ok) errors.add(1);
}
