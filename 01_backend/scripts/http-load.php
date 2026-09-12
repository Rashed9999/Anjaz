<?php

/**
 * AMIAL-HTTP-LOAD-001 — **قياسُ الطبقة الكاملة عبر HTTP.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الفجوةُ التي يسدّها:** كلُّ قياسٍ في هذا المشروع يتخطّى HTTP.
 *
 *   `load-test.php`  →  يستدعي الخدماتِ **مباشرةً** (306 عمليّة/ث)
 *   `bench.php`      →  `LedgerService` مباشرةً
 *   مجموعةُ الاختبارات →  عمليّةٌ واحدةٌ متتابعة
 *
 * **فلا شيءَ يقيس ما بين الطلب والخدمة:** التوجيهَ، والوسائطَ العشرَ،
 * والمصادقةَ، وحدَّ المعدّل (وهو يكتب في القاعدة!)، وتسلسلَ الردّ.
 *
 * وقد قِيس أنّ نداءً واحداً يكلّف ٥ استعلاماتٍ و٢٠–٤٢ms **في اختبار**.
 * وهذا يقيسه تحت التوازي.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وما لا يقيسه يُقال صراحةً — فأداةٌ تدّعي أكثرَ ممّا تقيس أسوأ من
 * غيابها:**
 *
 *   ✗ **سقفُ php-fpm** — الخادمُ هنا `php -S`، وسقفُ FPM يُقاس على
 *     الحاوية المنشورة وحدَها. **وهذا هو العنقُ الحقيقيّ** (كان 5،
 *     وصار 24 في `Dockerfile`).
 *   ✗ nginx وضغطُ الشبكة وTLS
 *   ✗ زمنُ الرحلة من هاتف العميل في اليمن
 *
 * فالرقمُ الخارجُ منه **حدٌّ أعلى للتطبيق**، لا وعدٌ بالإنتاج.
 *
 * ══════════════════════════════════════════════════════════════════════
 * الاستعمال:
 *
 *     php scripts/http-load.php                 # 8 متوازية × 40 طلباً
 *     php scripts/http-load.php 24 60           # 24 متوازية × 60
 *     BASE=https://amialpay.com php scripts/http-load.php 24 60
 *
 * ومع `BASE` يقيس **الخادمَ الحقيقيّ بسقف FPM الحقيقيّ** — وهي الجولةُ
 * التي تُجاب بها «هل يتحمّل ألفي مستخدم؟».
 */

$concurrency = max(1, (int) ($argv[1] ?? 8));
$perWorker = max(1, (int) ($argv[2] ?? 40));

$external = getenv('BASE') ?: '';
// **يُهيَّأ قبل كلّ فرع** — فمتغيّرٌ غيرُ معرَّفٍ يُقرأ `false` صامتاً،
// فيمرّ حكمُ الطاقة على خادم تطويرٍ كأنّه إنتاج.
$isDevServer = false;
$root = dirname(__DIR__);

// ── الخادم: خارجيٌّ إن أُعطي، وإلّا محلّيٌّ مؤقّت ──────────────────────
$serverPid = null;

if ($external !== '') {
    $base = rtrim($external, '/');
    printf("الهدف: خادمٌ خارجيّ — %s\n", $base);

    // ══════════════════════════════════════════════════════════════════
    // AMIAL-LOAD-TRUTH-001 — **سطرٌ يدّعي ما لم يُقَس.**
    //
    // كان يُطبع «هذه الجولةُ تمرّ بـnginx وphp-fpm الحقيقيّين» لمجرّد أنّ
    // `BASE` مضبوط. ووُجّه إلى `php artisan serve` — **خادمٌ أحاديُّ
    // الخيط يخدم طلباً واحداً في كلّ لحظة** — فأخرج 16 طلباً/ث وطبع
    // السطرَ نفسَه، ثمّ حكم «دونَ الحدّ المقدَّر لألفي مستخدم».
    //
    // **وهو حكمٌ كاذبٌ على بنيةٍ سليمة**: الرقمُ سقفُ خادم التطوير لا
    // سقفُ الإنتاج. وتقريرُ طاقةٍ خاطئٌ يُرسل صاحبَ المشروع يشتري
    // عتاداً لا يحتاجه، أو يؤجّل إطلاقاً جاهزاً.
    //
    // فيُقرأ الخادمُ من ترويسة `Server` ويُقال ما هو. **ومن لم يُعرَف
    // يُقال إنّه لم يُعرَف** — لا يُفترَض إنتاجاً. (القاعدةُ السابعة.)
    // ══════════════════════════════════════════════════════════════════
    $banner = @get_headers(rtrim($base, '/') . '/health/readiness', true) ?: [];

    $pick = static function (array $h, string $key): string {
        foreach ($h as $k => $v) {
            if (strcasecmp((string) $k, $key) !== 0) {
                continue;
            }

            return trim((string) (is_array($v) ? end($v) : $v));
        }

        return '';
    };

    $server = $pick($banner, 'Server');
    $poweredBy = $pick($banner, 'X-Powered-By');

    // **وبصمةُ خادم التطوير غيابُ `Server` مع حضور `X-Powered-By: PHP`.**
    // فـnginx وapache يُعلنان أنفسهما، والمدمجُ في PHP لا يُرسل `Server`
    // إطلاقاً. وقِيس ذلك على الجولة نفسِها لا افتُرض.
    $isProdStack = stripos($server, 'nginx') !== false
        || stripos($server, 'apache') !== false;

    $isDevServer = ! $isProdStack
        && (stripos($server, 'Development Server') !== false
            || ($server === '' && stripos($poweredBy, 'PHP') === 0));

    if ($isDevServer) {
        printf("  ⚠ خادمُ تطويرٍ أحاديُّ الخيط (%s) — **لا nginx ولا FPM**.\n",
            $server !== '' ? $server : $poweredBy);
        printf("     الرقمُ أدناه سقفُه هو، لا سقفُ الإنتاج، ولا يصلح\n");
        printf("     حكماً على الطاقة. شغّله على الخادم الحقيقيّ.\n\n");
    } elseif ($isProdStack) {
        printf("  ✓ الخادمُ «%s» — جولةٌ تمرّ بمكدّس الإنتاج.\n\n", trim($server));
    } else {
        printf("  ؟ لم تُعرَف هويّةُ الخادم (لا ترويسة Server) — فلا يُقال\n");
        printf("     أهي جولةُ إنتاجٍ أم لا. والرقمُ يُقرأ بهذا القيد.\n\n");
    }
} else {
    $port = 8791;
    $base = "http://127.0.0.1:{$port}";

    // **وعمّالٌ متعدّدون وإلّا قِيس طابورٌ لا تزامن.** خادمُ PHP المدمج
    // أحاديُّ العمليّة افتراضاً، فيُخرج رقماً يساوي 1/التزامن دائماً.
    putenv('PHP_CLI_SERVER_WORKERS=' . $concurrency);

    $cmd = sprintf(
        'PHP_CLI_SERVER_WORKERS=%d php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!',
        $concurrency, $port, escapeshellarg($root . '/public'),
        escapeshellarg($root . '/public/index.php'),
    );

    $serverPid = (int) trim((string) shell_exec($cmd));

    printf("الهدف: خادمٌ محلّيٌّ مؤقّت — %s (عمّال: %d)\n", $base, $concurrency);
    printf("  ⚠ **لا يقيس سقفَ php-fpm** — ذاك على الحاوية المنشورة.\n");
    printf("     شغّله بـ BASE=https://… ليقيس الخادمَ الحقيقيّ.\n\n");

    // انتظارُ الإقلاع — **ولا يُفترَض جاهزاً بعد نومٍ ثابت**.
    $up = false;

    for ($i = 0; $i < 60; $i++) {
        $c = curl_init($base . '/api/v1/amial/ping');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);

        if ($code > 0) { $up = true; break; }

        usleep(250_000);
    }

    if (! $up) {
        fwrite(STDERR, "⛔ لم يُقلع الخادمُ المحلّيّ — لا قياس.\n");
        if ($serverPid) { @exec("kill {$serverPid} 2>/dev/null"); }
        exit(2);
    }
}

// ── المسارات المقيسة ─────────────────────────────────────────────────
// **عامّةٌ عمداً**: مسارٌ يحتاج رمزاً يقيس إصدارَ الرمز لا خدمةَ الطلب،
// وإصدارُه أثقلُ من كلّ ما بعده فيُشوّه الرقم.
$targets = [
    '/api/v1/amial/ping',
    '/api/v1/amial/app-version',
    '/api/v1/amial/geo/governorates',
];

printf("التزامن %d × %d طلباً لكلٍّ = %d طلباً على %d مسارات\n\n",
    $concurrency, $perWorker, $concurrency * $perWorker, count($targets));

// ── الجولة ───────────────────────────────────────────────────────────
$mh = curl_multi_init();
$handles = [];
$started = [];
$latencies = [];
$codes = [];
$queue = [];

for ($w = 0; $w < $concurrency; $w++) {
    for ($i = 0; $i < $perWorker; $i++) {
        $queue[] = $targets[($w + $i) % count($targets)];
    }
}

$t0 = microtime(true);
$inFlight = 0;
$next = 0;

$launch = function () use (&$next, &$queue, &$handles, &$started, &$inFlight, $mh, $base) {
    if ($next >= count($queue)) {
        return false;
    }

    $uri = $queue[$next++];
    $ch = curl_init($base . $uri);

    // **وكلُّ عاملٍ جهازٌ مستقلّ** — وهو ما يقع فعلاً: ألفا هاتفٍ خلف
    // عناوينَ معدودة. وبلا هذا يقيس المسبارُ جهازاً واحداً يُغرق نفسَه،
    // فيُخرج 429 على إصلاحٍ صحيح.
    $device = 'probe-' . str_pad((string) ($next % 4096), 4, '0', STR_PAD_LEFT);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'device-id: ' . $device],
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[(int) $ch] = $ch;
    $started[(int) $ch] = microtime(true);
    $inFlight++;

    return true;
};

for ($i = 0; $i < $concurrency; $i++) {
    $launch();
}

do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);

    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $id = (int) $ch;

        $latencies[] = (microtime(true) - $started[$id]) * 1000;
        $codes[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($handles[$id], $started[$id]);
        $inFlight--;

        $launch();
    }
} while ($running > 0 || $inFlight > 0);

$elapsed = microtime(true) - $t0;
curl_multi_close($mh);

if ($serverPid) {
    @exec("kill {$serverPid} 2>/dev/null");
}

// ── النتيجة ──────────────────────────────────────────────────────────
sort($latencies);
$n = count($latencies);

if ($n === 0) {
    fwrite(STDERR, "⛔ صفرُ طلباتٍ اكتملت — لا قياس.\n");
    exit(2);
}

$pct = fn (float $p) => $latencies[min($n - 1, (int) floor($p * $n))];

$ok = count(array_filter($codes, fn ($c) => $c >= 200 && $c < 400));
$failed = $n - $ok;

printf("═══════════════════════════════════════════════════════\n");
printf("  زمنُ الجولة  : %.2f ث   ·   %d طلباً   ·   %.1f طلب/ث\n",
    $elapsed, $n, $n / max(0.001, $elapsed));
printf("  الكمون       : وسيط %.1f ms · p95 %.1f ms · p99 %.1f ms\n",
    $pct(0.50), $pct(0.95), $pct(0.99));
printf("  الردود       : %d ناجحاً · %d فاشلاً\n", $ok, $failed);

// **والفشلُ يُقال بأصنافه** — «٣ فشلت» لا تدلّ على شيء.
if ($failed > 0) {
    $byCode = array_count_values(array_filter($codes, fn ($c) => $c < 200 || $c >= 400));
    foreach ($byCode as $c => $cnt) {
        printf("                 · %s → %d\n", $c === 0 ? 'انقطاع/مهلة' : $c, $cnt);
    }
}

printf("═══════════════════════════════════════════════════════\n\n");

// ── الحكم ────────────────────────────────────────────────────────────
// **ألفا مستخدمٍ ليسوا ألفي طلبٍ متزامن.** ذروةُ محفظةٍ في تجربةٍ
// معتادةٌ ٢–٥٪ من المسجَّلين نشِطون في اللحظة نفسِها ⇒ ٤٠–١٠٠ متزامناً،
// وكلٌّ يُصدر طلباً كلَّ بضع ثوانٍ ⇒ **~٢٠–٥٠ طلباً في الثانية**.
$rps = $n / max(0.001, $elapsed);
$needed = 50;

if ($failed > $n * 0.01) {
    printf("⛔ معدّلُ الفشل %.1f%% — فوقَ الواحد بالمئة. لا يُقرأ هذا نجاحاً.\n",
        100 * $failed / $n);
    exit(1);
}

// ══════════════════════════════════════════════════════════════════════
// **ولا يُحكَم على الطاقة من خادمٍ ليس خادمَ الإنتاج.**
//
// خادمُ التطوير أحاديُّ الخيط يخدم طلباً واحداً في كلّ لحظة، فسقفُه
// عشراتٌ لا مئات. وحكمُ «دونَ الحدّ» عليه **كاذبٌ على بنيةٍ سليمة**،
// ويُرسل صاحبَ المشروع يشتري عتاداً لا يحتاجه أو يؤجّل إطلاقاً جاهزاً.
//
// فيُقال الرقمُ ويُقال إنّه لا يصلح حكماً — **و«غير معروف» ليس فشلاً
// كما أنّه ليس نجاحاً.** (القاعدةُ السابعة.) والخروجُ صفرٌ لأنّ شيئاً
// لم يسقط؛ والحكمُ يبقى معلَّقاً حتّى تُشغَّل على الخادم الحقيقيّ.
// ══════════════════════════════════════════════════════════════════════
if (! empty($isDevServer)) {
    printf("؟ %.0f طلب/ث على خادم تطوير — **لا يُقرأ حكماً على الطاقة**.\n", $rps);
    printf("   السقفُ هنا سقفُ الخيط الواحد لا سقفُ FPM. والحدُّ المقدَّر\n");
    printf("   لألفي مستخدم ~%d طلب/ث، ويُقاس بـ:\n", $needed);
    printf("   BASE=https://amialpay.com php scripts/http-load.php 24 60\n");
    exit(0);
}

if ($rps < $needed) {
    printf("⚠ %.0f طلب/ث دونَ الحدّ المقدَّر لألفي مستخدم (~%d طلب/ث).\n", $rps, $needed);
    printf("   والحدُّ محسوب: ٢–٥%% من ٢٠٠٠ نشِطون ⇒ ٤٠–١٠٠ متزامناً.\n");
    exit(1);
}

printf("✓ %.0f طلب/ث — فوقَ الحدّ المقدَّر لألفي مستخدم (~%d طلب/ث).\n", $rps, $needed);
printf("  والحدُّ محسوب: ٢–٥%% من ٢٠٠٠ نشِطون ⇒ ٤٠–١٠٠ متزامناً، كلٌّ\n");
printf("  يُصدر طلباً كلَّ بضع ثوانٍ.\n");

exit(0);
