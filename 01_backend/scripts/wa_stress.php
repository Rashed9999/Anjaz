<?php
/**
 * AMIAL-WA-STRESS-001 — اختبار ضغط بوت واتساب (5000 رسالة في نفس الدقيقة).
 *
 * يُطلق رسائل ويبهوك موقَّعة HMAC عبر HTTP Kernel الحقيقي (كامل سلسلة
 * middleware بما فيها throttle والتوقيع) من عدّة عمليات نظام متوازية، ويقيس:
 *   - هل ضاعت رسائل؟ (مقبولة بـ 200 لكن لم تُعالَج)
 *   - هل التكرارات تُعالَج مرّة واحدة فقط؟ (idempotency تحت تزامن حقيقي)
 *   - هل التوقيع الخاطئ لا يُعالَج؟ (يُعاد 200 عمداً بلا معالجة)
 *   - زمن استجابة الويبهوك (ما تراه Meta) p50/p95/max
 *
 * «المعالجة الفعلية» تُقاس بمفاتيح wa_msg_seen في كاش database (ذرّي
 * كالإنتاج على Redis) — المفتاح يُسجَّل فقط بعد نجاح التوقيع وبدء المعالجة.
 *
 * الأوضاع: setup | burst <n> <offset> <latfile> <start> | dup <id> <start> | badsig <n> | seen
 * يُشغَّل مع: DB_DATABASE=amial_conc CACHE_STORE=database
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const SECRET = 'stress-secret';

config([
    'services.whatsapp.app_secret'   => SECRET,
    'services.whatsapp.access_token' => 'fake-token-for-stress',
    'services.whatsapp.verify_token' => 'stress-verify',
]);
Http::fake(); // لا HTTP خارجي حقيقي — ردود البوت تُعترَض وتُحصى

/** يبني طلب ويبهوك Meta موقَّعاً (أو بتوقيع فاسد). */
function makeRequest(string $msgId, string $text, bool $goodSig = true): Request
{
    $payload = json_encode([
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'id' => $msgId, 'from' => '967771234567', 'type' => 'text',
                        'text' => ['body' => $text],
                    ]],
                ],
            ]],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $sig = $goodSig
        ? 'sha256=' . hash_hmac('sha256', $payload, SECRET)
        : 'sha256=' . str_repeat('0', 64);

    return Request::create('/wa/webhook', 'POST', server: [
        'CONTENT_TYPE'              => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256'  => $sig,
    ], content: $payload);
}

/** يمرّر الطلب عبر Kernel كاملاً + terminate (المعالجة الخلفية) ثم يصفّر callbacks. */
function fire($app, $kernel, Request $req): array
{
    $t0 = microtime(true);
    $resp = $kernel->handle($req);          // ما تراه Meta (الردّ الفوري)
    $latencyMs = (microtime(true) - $t0) * 1000;
    $kernel->terminate($req, $resp);        // المعالجة بعد الردّ (terminating)

    // في FPM كل طلب عملية جديدة؛ هنا نصفّر terminating callbacks يدوياً
    // كي لا تتراكم عبر طلبات الحلقة الواحدة (أمانة القياس).
    $ref = new ReflectionProperty($app, 'terminatingCallbacks');
    $ref->setValue($app, []);

    return [$resp->getStatusCode(), $latencyMs];
}

$mode = $argv[1] ?? 'seen';

switch ($mode) {
    case 'setup':
        Artisan::call('migrate:fresh', ['--force' => true]);
        // جدولا cache/cache_locks ليسا ضمن هجرات المشروع (إنتاجه على Redis)
        foreach (['cache' => 'CREATE TABLE IF NOT EXISTS cache (
                    `key` VARCHAR(191) PRIMARY KEY, `value` MEDIUMTEXT, expiration INT)',
                  'cache_locks' => 'CREATE TABLE IF NOT EXISTS cache_locks (
                    `key` VARCHAR(191) PRIMARY KEY, owner VARCHAR(191), expiration INT)'] as $sql) {
            DB::statement($sql);
        }
        DB::table('cache')->delete();
        echo "setup done (fresh db + cache tables + empty cache)\n";
        break;

    // عاصفة رسائل فريدة: burst <count> <offset> <latencyFile> <startEpoch>
    case 'burst':
        $n      = (int) $argv[2];
        $offset = (int) $argv[3];
        $latF   = $argv[4];
        $start  = (float) $argv[5];
        while (microtime(true) < $start) usleep(500);

        $ok = $throttled = $other = 0;
        $lats = [];
        for ($i = 0; $i < $n; $i++) {
            $req = makeRequest('wamid.STRESS.' . ($offset + $i), 'رصيدي');
            [$code, $ms] = fire($app, $kernel, $req);
            $lats[] = $ms;
            if ($code === 200) $ok++;
            elseif ($code === 429) $throttled++;
            else $other++;
        }
        file_put_contents($latF, implode("\n", array_map(fn ($v) => round($v, 3), $lats)));
        echo "STATS ok={$ok} throttled={$throttled} other={$other}\n";
        break;

    // تكرار متزامن لنفس الرسالة: dup <msgId> <startEpoch>
    case 'dup':
        $msgId = $argv[2];
        $start = (float) $argv[3];
        while (microtime(true) < $start) usleep(500);
        $req = makeRequest($msgId, 'رصيدي');
        [$code] = fire($app, $kernel, $req);
        echo "DUP code={$code}\n";
        break;

    // توقيعات فاسدة: badsig <n>
    case 'badsig':
        $n = (int) $argv[2];
        $ok = 0;
        for ($i = 0; $i < $n; $i++) {
            $req = makeRequest("wamid.BADSIG.{$i}", 'رصيدي', goodSig: false);
            [$code] = fire($app, $kernel, $req);
            if ($code === 200) $ok++;
        }
        echo "BADSIG returned200={$ok}/{$n}\n";
        break;

    // عدّ الرسائل المُعالَجة فعلاً (مفاتيح wa_msg_seen في كاش database)
    case 'seen':
        $prefix = config('cache.prefix', '');
        $seen = DB::table('cache')->where('key', 'like', "%wa_msg_seen%")->count();
        echo "SEEN={$seen}\n";
        break;
}
