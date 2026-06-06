// AMIAL-LOADTEST-001 (v1.0-E)
//
// K6 load test: login flood (محاكاة 1000 user يحاولون login في نفس الوقت)
//
// **التشغيل:**
//   k6 run --vus 1000 --duration 2m loadtests/login_flood.js

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Counter } from 'k6/metrics';
import { randomIntBetween } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

const errorRate = new Rate('errors');
const successfulLogins = new Counter('successful_logins');
const rateLimitHits = new Counter('rate_limit_hits');

const BASE_URL = __ENV.K6_BASE_URL || 'http://localhost:8000';
const USERS = JSON.parse(__ENV.K6_USERS || '[]'); // [{phone:..., pin:...}, ...]

if (USERS.length === 0) {
    throw new Error('K6_USERS must be a JSON array of {phone, pin}');
}

export const options = {
    stages: [
        { duration: '30s', target: 500 },
        { duration: '1m', target: 1000 },
        { duration: '30s', target: 0 },
    ],
    thresholds: {
        'http_req_duration': ['p(95)<2000'],
        'errors': ['rate<0.1'], // 10% (logins تحت ضغط قد تفشل من rate limit)
    },
};

export default function () {
    const user = USERS[randomIntBetween(0, USERS.length - 1)];

    const payload = JSON.stringify({
        phone: user.phone,
        password: user.pin || user.password,
    });

    const res = http.post(`${BASE_URL}/api/v1/auth/login`, payload, {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
        timeout: '15s',
    });

    const success = check(res, {
        'status acceptable (200/401/429)': (r) => [200, 401, 429].includes(r.status),
        'no 5xx': (r) => r.status < 500,
    });

    if (res.status === 200) successfulLogins.add(1);
    if (res.status === 429) rateLimitHits.add(1);

    errorRate.add(!success);

    sleep(randomIntBetween(1, 5));
}
