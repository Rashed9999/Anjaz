// AMIAL-LOADTEST-001 (v1.0-E)
//
// K6 load test scenario: محاكاة send_money تحت ضغط.
//
// **التشغيل:**
//   k6 run --vus 100 --duration 5m loadtests/send_money.js
//   k6 run --stage 1m:50,3m:500,1m:1000,2m:500,1m:0 loadtests/send_money.js
//
// **يفترض:**
//   - BASE_URL configured في env
//   - يوجد مستخدمون موثوقون مع tokens
//   - .env: K6_BASE_URL=https://staging.amialpay.com K6_TOKENS=token1,token2,...
//
// **الأهداف:**
//   - 100 VU لمدة 5 دقائق
//   - p95 < 500ms
//   - error rate < 0.5%
//   - لا 5xx errors
//
// **ما يُحدد إن نجح؟**
//   - response time مستقر
//   - DB connections لا تنفد
//   - queue depth لا يتجاوز 10k
//   - no deadlocks في الـ logs

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';
import { randomIntBetween } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

// ============ Metrics مخصصة ============
const sendMoneyDuration = new Trend('send_money_duration_ms');
const sendMoneySuccess = new Counter('send_money_success');
const sendMoneyFailed = new Counter('send_money_failed');
const insufficientBalance = new Counter('insufficient_balance');
const errorRate = new Rate('errors');

// ============ Configuration ============
const BASE_URL = __ENV.K6_BASE_URL || 'http://localhost:8000';
const TOKENS = (__ENV.K6_TOKENS || '').split(',').filter(t => t.length > 0);
const RECEIVERS = (__ENV.K6_RECEIVERS || '').split(',').filter(r => r.length > 0);

if (TOKENS.length === 0 || RECEIVERS.length === 0) {
    throw new Error('K6_TOKENS and K6_RECEIVERS must be set in env');
}

export const options = {
    // ====== مرحلي - ramp up & down ======
    stages: [
        { duration: '1m', target: 50 },   // warmup: 0→50 VU
        { duration: '3m', target: 200 },  // increase: 50→200 VU
        { duration: '5m', target: 200 },  // sustain: 200 VU
        { duration: '2m', target: 500 },  // spike: 200→500 VU
        { duration: '3m', target: 500 },  // sustain spike
        { duration: '2m', target: 0 },    // cool down
    ],
    thresholds: {
        // فشل لو p95 > 1s
        'http_req_duration': ['p(95)<1000'],
        // فشل لو error rate > 1%
        'errors': ['rate<0.01'],
        // فشل لو send_money p99 > 2s
        'send_money_duration_ms': ['p(99)<2000'],
    },
};

// ============ السيناريو ============
export default function () {
    const token = TOKENS[randomIntBetween(0, TOKENS.length - 1)];
    const receiver = RECEIVERS[randomIntBetween(0, RECEIVERS.length - 1)];
    const amount = randomIntBetween(1, 100); // مبالغ صغيرة

    group('send_money', () => {
        const payload = JSON.stringify({
            to_user: receiver,
            amount: amount.toString(),
            note: 'k6 load test',
        });

        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${token}`,
            'Idempotency-Key': `k6-${Date.now()}-${randomIntBetween(1, 100000)}`,
            'X-Amial-Zone': 'SOUTH',
        };

        const start = Date.now();
        const res = http.post(`${BASE_URL}/api/v1/customer/send-money`, payload, {
            headers,
            timeout: '10s',
        });
        const duration = Date.now() - start;

        sendMoneyDuration.add(duration);

        const success = check(res, {
            'status is 200 or 402': (r) => r.status === 200 || r.status === 402,
            'no 5xx errors': (r) => r.status < 500,
            'response is JSON': (r) => r.headers['Content-Type']?.includes('application/json'),
            'has success field': (r) => {
                try { return 'success' in r.json(); } catch { return false; }
            },
        });

        if (res.status === 200) {
            sendMoneySuccess.add(1);
        } else if (res.status === 402) {
            insufficientBalance.add(1);  // متوقع: رصيد غير كافي بعد تحويلات متعددة
        } else {
            sendMoneyFailed.add(1);
            console.error(`Failed: status=${res.status}, body=${res.body?.substring(0, 200)}`);
        }

        errorRate.add(!success);
    });

    // think time بين الطلبات (محاكاة سلوك بشري)
    sleep(randomIntBetween(1, 3));
}

// ============ Summary ============
export function handleSummary(data) {
    return {
        'stdout': textSummary(data),
        'loadtest_results.json': JSON.stringify(data, null, 2),
    };
}

function textSummary(data) {
    const metrics = data.metrics;
    return `
============================================================
Amial Pay - Send Money Load Test Results
============================================================
Total requests:       ${metrics.http_reqs?.values.count || 0}
  Success (200):      ${metrics.send_money_success?.values.count || 0}
  Insufficient (402): ${metrics.insufficient_balance?.values.count || 0}
  Failed (5xx/other): ${metrics.send_money_failed?.values.count || 0}

Response times:
  p50: ${(metrics.http_req_duration?.values['p(50)'] || 0).toFixed(2)} ms
  p95: ${(metrics.http_req_duration?.values['p(95)'] || 0).toFixed(2)} ms
  p99: ${(metrics.http_req_duration?.values['p(99)'] || 0).toFixed(2)} ms

send_money specific:
  p99: ${(metrics.send_money_duration_ms?.values['p(99)'] || 0).toFixed(2)} ms

Error rate: ${((metrics.errors?.values.rate || 0) * 100).toFixed(3)}%

Test duration: ${(data.state?.testRunDurationMs / 1000).toFixed(1)}s
VUs max: ${metrics.vus_max?.values.max || 0}

Thresholds:
${Object.entries(metrics).filter(([_, m]) => m.thresholds).map(([name, m]) =>
    Object.entries(m.thresholds).map(([threshold, result]) =>
        `  ${name}.${threshold}: ${result.ok ? '✓ PASS' : '✗ FAIL'}`
    ).join('\n')
).join('\n')}
============================================================
`;
}
