/*
 * AMIAL-PILOT-IDEM-002 — مفتاح التفرّد للوحات الويب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * الثمن الذي كاد يُدفع:
 *
 * قِيس على `customer/send-money` أن الطلب نفسه مرتين بمفتاح واحد أوصل
 * المستلِمَ ٢٠٠٠ والمبلغُ ١٠٠٠. ووُصل الوسيط بكل مسار يحرّك مالاً —
 * لكنّ الوسيط بلا مفتاح من العميل **حمايته صفر**: يولّد مفتاحاً
 * عشوائياً لكل طلب، فتصير كل إعادة عمليةً جديدة.
 *
 * ولوحات الإدارة اثنتان وثلاثون نقطةً تحرّك مالاً — اعتمادُ تسوية،
 * تمويلُ وكيل، تحويلُ مركز، إفراجٌ عن دفعة آمنة — **وكلها كانت تُرسل
 * بلا مفتاح.** وضغطتان على «اعتماد» تعتمدان مرتين.
 *
 * ══════════════════════════════════════════════════════════════════════
 * لماذا هنا لا في كل شاشة:
 *
 * خمسٌ وعشرون شاشةً تستعمل `fetch`. وتعديلها واحدةً واحدةً يترك واحدةً
 * منسيّة — وهي بالضبط التي ستُكلّف. فيُلَفّ `fetch` مرّة، في القالب
 * الذي تمرّ منه كل صفحة إدارة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * القاعدة — والفرق بين جوابٍ وصمت:
 *
 *   · المفتاح يُولَّد لكل (طريقة + عنوان + جسد) ويبقى ما دام الطلب معلّقاً.
 *     فضغطتان متتاليتان على الزرّ نفسه تحملان المفتاح نفسه ⇒ عملية واحدة.
 *   · أجاب الخادم ولو بالرفض ⇒ النية حُسمت، فيُتلَف المفتاح. ولولا ذلك
 *     لعلق المستعمل: الوسيط يسجّل المفتاح `failed` عند أي ٤xx ثم يردّ
 *     ٤٠٩ على إعادته.
 *   · لم يصل جواب (انقطاع شبكة) ⇒ يبقى المفتاح، لأننا لا نعلم أوصل
 *     الطلب أم لا. و«لا نعلم» ليست صفراً.
 */
(function () {
    'use strict';

    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return;
    }

    if (window.__amialIdempotencyInstalled) {
        return;
    }
    window.__amialIdempotencyInstalled = true;

    var MUTATING = ['POST', 'PUT', 'PATCH'];
    var inFlight = {};

    function newKey() {
        // ستةٌ وثلاثون محرفاً — والخادم يشترط ما بين ١٦ و١٢٨.
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'web_' + window.crypto.randomUUID();
        }
        var s = '';
        for (var i = 0; i < 4; i++) {
            s += Math.random().toString(36).slice(2, 10);
        }
        return 'web_' + s;
    }

    // هويّة الطلب. الجسد غير النصّي (FormData وملفّات) لا يُقرأ، فتُبنى
    // الهويّة من الطريقة والعنوان وحدهما — وهو يكفي لمنع الضغطة المكرّرة.
    function identity(method, url, body) {
        var payload = '';
        if (typeof body === 'string') {
            payload = body;
        } else if (body instanceof URLSearchParams) {
            payload = body.toString();
        }
        return method + '|' + url + '|' + payload;
    }

    var originalFetch = window.fetch.bind(window);

    window.fetch = function (input, init) {
        init = init || {};

        var method = String(init.method || (input && input.method) || 'GET').toUpperCase();

        if (MUTATING.indexOf(method) === -1) {
            return originalFetch(input, init);
        }

        var url = (typeof input === 'string') ? input : (input && input.url) || '';
        var id = identity(method, url, init.body);

        if (!inFlight[id]) {
            inFlight[id] = newKey();
        }
        var key = inFlight[id];

        // الترويسات تأتي بأشكال ثلاثة (Headers/مصفوفة/كائن) — تُوحَّد ثم
        // تُكتب. ومن أرسل مفتاحه بنفسه فمفتاحه أولى، فلا يُداس.
        var headers = new Headers(init.headers || (input && input.headers) || {});
        if (!headers.has('Idempotency-Key')) {
            headers.set('Idempotency-Key', key);
        }
        init.headers = headers;

        return originalFetch(input, init).then(function (response) {
            delete inFlight[id];   // أجاب الخادم — ولو بالرفض
            return response;
        }).catch(function (error) {
            // صمت: يبقى المفتاح ليكون ما بعده إعادةً لا عمليةً ثانية
            throw error;
        });
    };
})();
