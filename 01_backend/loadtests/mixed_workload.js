// AMIAL-LOADTEST-001 (v1.0-E)
//
// K6 mixed workload — محاكاة realistic لـ daily traffic.
//
// **توزيع الـ traffic (مبني على افتراضات):**
//   80% reads (balance, history, providers)
//   15% writes (send_money, contribute)
//   5% heavy (receipts download, exports)
//
// **التشغيل:**
//   k6 run --vus 200 --duration 30m loadtests/mixed_workload.js

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { randomIntBetween } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

const errorRate = new Rate('errors');
const readDuration = new Trend('read_duration_ms');
const writeDuration = new Trend('write_duration_ms');

const BASE_URL = __ENV.K6_BASE_URL || 'http://localhost:8000';
const TOKENS = (__ENV.K6_TOKENS || '').split(',').filter(t => t);
const RECEIVERS = (__ENV.K6_RECEIVERS || '').split(',').filter(r => r);

if (TOKENS.length === 0) throw new Error('K6_TOKENS required');

export const options = {
    stages: [
        { duration: '2m', target: 50 },
        { duration: '10m', target: 200 },
        { duration: '10m', target: 200 },
        { duration: '5m', target: 100 },
        { duration: '3m', target: 0 },
    ],
    thresholds: {
        'http_req_duration': ['p(95)<800'],
        'read_duration_ms': ['p(95)<300'],
        'write_duration_ms': ['p(95)<1500'],
        'errors': ['rate<0.005'],
    },
};

export default function () {
    const token = TOKENS[randomIntBetween(0, TOKENS.length - 1)];
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${token}`,
        'X-Amial-Zone': 'SOUTH',
    };

    const action = pickAction();

    switch (action) {
        case 'read_balance':
            measureRead('/api/v1/customer/wallet/balance', headers);
            break;
        case 'read_history':
            measureRead('/api/v1/customer/transactions?page=1', headers);
            break;
        case 'read_receipts':
            measureRead('/api/v1/amial/receipts?page=1', headers);
            break;
        case 'read_providers':
            measureRead('/api/v1/amial/bill-pay/providers', headers);
            break;
        case 'read_funds':
            measureRead('/api/v1/amial/funds', headers);
            break;
        case 'write_send_money':
            if (RECEIVERS.length > 0) {
                measureSendMoney(headers);
            }
            break;
        case 'write_contribute':
            // مهمل لأنه يحتاج fund_ulid مسبق
            break;
    }

    sleep(randomIntBetween(2, 6));
}

function pickAction() {
    const r = Math.random();
    if (r < 0.30) return 'read_balance';
    if (r < 0.50) return 'read_history';
    if (r < 0.65) return 'read_receipts';
    if (r < 0.75) return 'read_providers';
    if (r < 0.80) return 'read_funds';
    if (r < 0.95) return 'write_send_money';
    return 'write_contribute';
}

function measureRead(path, headers) {
    const start = Date.now();
    const res = http.get(`${BASE_URL}${path}`, { headers, timeout: '10s' });
    readDuration.add(Date.now() - start);
    errorRate.add(!check(res, { 'status < 500': (r) => r.status < 500 }));
}

function measureSendMoney(headers) {
    const receiver = RECEIVERS[randomIntBetween(0, RECEIVERS.length - 1)];
    const amount = randomIntBetween(1, 50);
    headers['Idempotency-Key'] = `k6-mixed-${Date.now()}-${randomIntBetween(1, 100000)}`;

    const start = Date.now();
    const res = http.post(`${BASE_URL}/api/v1/customer/send-money`, JSON.stringify({
        to_user: receiver,
        amount: amount.toString(),
    }), { headers, timeout: '15s' });

    writeDuration.add(Date.now() - start);
    errorRate.add(!check(res, {
        'status acceptable': (r) => [200, 402, 422, 429].includes(r.status),
        'no 5xx': (r) => r.status < 500,
    }));
}
