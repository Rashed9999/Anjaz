<?php

/**
 * أميال باي — مِقياسُ الضغط الماليّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لمَ هذا الملفّ موجود:**
 *
 * الاختباراتُ كلُّها تُشغَّل في **عمليّةٍ واحدةٍ متتابعة**. فمسارٌ يقرأ
 * الرصيدَ ثمّ يخصمه في خطوتين يمرّ فيها أبداً — لأنّ لا أحدَ يتحرّك بينهما.
 *
 * **والضغطُ يُدخل ما لا يُدخله التتابع: التداخل.** شبّاكان يقرآن الرصيدَ
 * نفسَه في اللحظة نفسِها، فيقول كلٌّ منهما «يكفي»، ثمّ يخصمان معاً.
 * والنتيجةُ مالٌ خرج من العدم.
 *
 * فهذا المقياسُ يُشغّل **عمليّاتٍ حقيقيّةً متوازية** على قاعدة البيانات
 * نفسِها، ثمّ يسأل سؤالاً واحداً: **هل بقي المجموع كما كان؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الاستعمال:**
 *
 *   php scripts/load-test.php                       # كلّ المشاهد
 *   php scripts/load-test.php --scenario=transfer
 *   php scripts/load-test.php --workers=16 --ops=40
 *
 * ولا يُشغَّل على قاعدةٍ فيها بياناتُ إنتاج: يُنشئ حسابات ويحرّك عليها
 * مالاً. وهو يرفض العمل إن كان `APP_ENV=production`.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashTill;
use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\User;
use App\Services\AgentCounterService;
use Illuminate\Support\Facades\DB;

// ── الوسائط ────────────────────────────────────────────────────────────

$opt = getopt('', [
    'scenario::', 'workers::', 'ops::', 'worker::', 'run::', 'barrier::',
]);

$scenario = $opt['scenario'] ?? 'all';
$workers = max(2, (int) ($opt['workers'] ?? 8));
$ops = max(1, (int) ($opt['ops'] ?? 10));

if (app()->environment('production')) {
    fwrite(STDERR, "مرفوض: هذا المقياس يحرّك مالاً حقيقيّاً — لا يُشغَّل على الإنتاج.\n");
    exit(2);
}

// ── وضعُ العامل: يُستدعى من الأب، ينفّذ ويخرج ────────────────────────────

if (isset($opt['worker'])) {
    exit(runWorker(
        (int) $opt['worker'],
        (string) ($opt['run'] ?? ''),
        (string) $scenario,
        $ops,
        (float) ($opt['barrier'] ?? 0),
    ));
}

// ── الأب ───────────────────────────────────────────────────────────────

$scenarios = $scenario === 'all'
    ? ['transfer', 'counter-withdraw', 'counter-deposit']
    : [$scenario];

line('═══════════════════════════════════════════════════════════════');
line('  مِقياسُ الضغط الماليّ — أميال باي');
line(sprintf('  عمّالٌ متوازون: %d   ·   عمليّاتٌ لكلّ عامل: %d', $workers, $ops));
line('═══════════════════════════════════════════════════════════════');

$failed = 0;

foreach ($scenarios as $s) {
    line('');
    $failed += runScenario($s, $workers, $ops) ? 0 : 1;
}

line('');
line('═══════════════════════════════════════════════════════════════');

if ($failed > 0) {
    line(sprintf('  ✗ %d مشهدٌ كسر ثابتاً ماليّاً — لا يُطلق تحت ضغط.', $failed));
    exit(1);
}

line('  ✓ كلّ الثوابت الماليّة صمدت تحت التوازي.');
exit(0);

// ══════════════════════════════════════════════════════════════════════
//  المشاهد
// ══════════════════════════════════════════════════════════════════════

function runScenario(string $s, int $workers, int $ops): bool
{
    $run = 'LT' . strtoupper(bin2hex(random_bytes(4)));

    line('───────────────────────────────────────────────────────────────');
    line("  المشهد: {$s}   (المعرّف {$run})");
    line('───────────────────────────────────────────────────────────────');

    $before = match ($s) {
        'transfer' => setupTransfer($run, $workers),
        'counter-withdraw' => setupCounter($run, $workers, 'withdraw'),
        'counter-deposit' => setupCounter($run, $workers, 'deposit'),
        default => throw new InvalidArgumentException("مشهدٌ مجهول: {$s}"),
    };

    $stats = spawn($run, $s, $workers, $ops);

    $ok = match ($s) {
        'transfer' => verifyTransfer($before, $stats),
        'counter-withdraw', 'counter-deposit' => verifyCounter($before, $stats, $s),
    };

    cleanup($run);

    return $ok;
}

// ── ① تحويلٌ بين عملاء تحت ضغط ─────────────────────────────────────────

/**
 * حلقةٌ مغلقة: كلُّ واحدٍ يُحوّل إلى التالي. **والمجموعُ لا يتغيّر**
 * إلّا بمقدار ما التقطته المنصّة رسماً.
 */
function setupTransfer(string $run, int $workers): array
{
    feeScheme('SEND_MONEY');

    $ids = [];

    for ($i = 0; $i < $workers; $i++) {
        $ids[] = disposableUser($run, $i, '100000');
    }

    return [
        'ids' => $ids,
        'total' => poolTotal($ids),
        'platform_before' => platformEarned($ids),
    ];
}

function verifyTransfer(array $before, array $stats): bool
{
    $after = poolTotal($before['ids']);
    $fees = bcsub(platformEarned($before['ids']), $before['platform_before'], 4);

    // ما خرج من الحلقة يجب أن يكون بالضبط ما دخل خزينةَ المنصّة.
    $expected = bcsub($before['total'], $fees, 4);

    report($stats);

    $ok = true;

    $ok = check(
        bccomp($after, $expected, 4) === 0,
        'حِفظُ المال',
        sprintf('قبل %s − رسوم %s = %s   ·   بعد %s',
            $before['total'], $fees, $expected, $after),
        'مالٌ ظهر أو اختفى: مجموعُ الحلقة لا يساوي ما دخلها ناقصَ الرسوم.',
    ) && $ok;

    $ok = check(
        negativeCount($before['ids']) === 0,
        'لا رصيدَ سالب',
        sprintf('%d محفظةً فُحصت', count($before['ids'])),
        'رصيدٌ سالب: خُصم من محفظةٍ ما لا تملكه.',
    ) && $ok;

    $ok = checkLedgerBalanced() && $ok;

    $ok = check(
        $stats['ok'] > 0,
        'العمليّاتُ وصلت',
        sprintf('%d نجحت · %d رُفضت · %d عطلت', $stats['ok'], $stats['rejected'], $stats['error']),
        'لم تنجح عمليّةٌ واحدة — المقياسُ يقيس فراغاً ولا يحرس شيئاً.',
    ) && $ok;

    $ok = checkNoServerErrors($stats) && $ok;

    return $ok;
}

/**
 * **المدينُ يساوي الدائن.** ولو انقطعت معاملةٌ في منتصفها تحت الضغط —
 * جمودُ قفلٍ، انقطاعُ اتّصال — لبقي شطرُ قيدٍ بلا شطره.
 */
function checkLedgerBalanced(): bool
{
    [$d, $c] = ledgerSides();

    return check(
        bccomp($d, $c, 4) === 0,
        'الدفترُ متوازن',
        sprintf('مدين %s · دائن %s', $d, $c),
        'قيدٌ غيرُ متوازن: معاملةٌ انقطعت في منتصفها وبقي شطرُها.',
    );
}

/**
 * **الرفضُ السليم ليس عطلاً — والعطلُ ليس رفضاً.**
 *
 * فمن رُفض لقلّة رصيدٍ يجب أن يرى «لا يكفي»، ومن أصاب النظامَ عطلٌ يرى
 * ٥٠٠. وخلطُهما يعني أنّ موظّفَ الشبّاك يرى «خطأ في الخادم» حيث الصواب
 * «رصيد العميل لا يكفي» — فيتّصل بالدعم على أمرٍ طبيعيّ.
 */
function checkNoServerErrors(array $stats): bool
{
    $faults = array_count_values($stats['faults'] ?? []);
    $detail = '';

    foreach ($faults as $msg => $n) {
        $detail .= sprintf("\n        ×%d  %s", $n, $msg);
    }

    return check(
        $stats['error'] === 0,
        'لا عطلَ غيرُ متوقّع',
        sprintf('%d عمليّةً رُفضت رفضاً معلوماً · %d عطلت',
            $stats['rejected'], $stats['error']),
        "عمليّاتٌ خرجت باستثناءٍ غيرِ مصنَّف — وهو ٥٠٠ في وجه المستعمل.\n"
        . '      والرفضُ لقلّة الرصيد حالةٌ متوقّعة تُقال بلغةٍ مفهومة لا استثناءٍ عامّ.'
        . $detail,
    );
}

// ── ② و③ شبّاك الفرع تحت ضغط ───────────────────────────────────────────

/**
 * **مورِدٌ واحدٌ محدود وعدّةُ طالبين.**
 *
 * في السحب: عميلٌ رصيدُه يكفي عمليّتين، وثمانيةُ شبابيك تطلب معاً.
 * وفي الإيداع: فرعٌ رصيدُه الإلكترونيّ يكفي عمليّتين، والطلبُ نفسُه.
 *
 * فإن مرّ أكثرُ مِمّا يسمح به المورد، فالفحصُ يقع خارج القفل.
 */
function setupCounter(string $run, int $workers, string $kind): array
{
    feeScheme($kind === 'withdraw' ? 'AGENT_WITHDRAW' : 'AGENT_DEPOSIT');

    $agent = disposableUser($run, 900, '0', 'agent');
    $branchUser = disposableUser($run, 901, $kind === 'deposit' ? '2500' : '0', 'agent');
    $customer = disposableUser($run, 902, $kind === 'withdraw' ? '2500' : '100000');

    $branch = AgentBranch::create([
        'agent_user_id' => $agent,
        'branch_user_id' => $branchUser,
        'name' => "فرع {$run}",
        'code' => $run,
        'is_active' => true,
    ]);

    // نقدٌ في الدرج يكفي كلَّ المحاولات — فالمورِدُ المحدود هنا هو
    // **الرصيد** لا الورق، وإلّا حجب الدرجُ العطلَ الذي نقيسه.
    AgentCashTill::updateOrCreate(
        ['branch_id' => $branch->id],
        ['cash_on_hand' => '10000000', 'max_cash_on_hand' => '0', 'min_cash_alert' => '0'],
    );

    return [
        'kind' => $kind,
        'branch_id' => $branch->id,
        'ids' => [$agent, $branchUser, $customer],
        'constrained' => $kind === 'withdraw' ? $customer : $branchUser,
        'budget' => '2500',
        'unit' => '1000',
        'total' => poolTotal([$agent, $branchUser, $customer]),
        'platform_before' => platformEarned([$agent, $branchUser, $customer]),
    ];
}

function verifyCounter(array $before, array $stats, string $s): bool
{
    report($stats);

    $balance = walletOf($before['constrained']);
    $ok = true;

    $ok = check(
        bccomp($balance, '0', 4) >= 0,
        'لا رصيدَ سالب',
        sprintf('رصيدُ الطرف المحدود بعد الضغط = %s', $balance),
        "رصيدٌ سالب بعد التوازي: الفحصُ «هل يكفي؟» وقع **قبل** القفل،\n"
        . "      فقرأه كلُّ شبّاكٍ قبل أن يخصم الآخر، ومرّ الجميع.\n"
        . '      وهذا مالٌ لم يكن موجوداً — أُنشئ من العدم.',
    ) && $ok;

    // ميزانيّةُ ٢٥٠٠ ووحدةُ ١٠٠٠ ⇒ عمليّتان على الأكثر (مع الرسم أقلّ).
    $ok = check(
        $stats['ok'] <= 2,
        'المورِدُ لم يُتجاوَز',
        sprintf('نجحت %d عمليّة · الميزانيّة تسمح بعمليّتين', $stats['ok']),
        sprintf(
            "نجحت %d عمليّةٍ والميزانيّةُ تسمح بعمليّتين — الفحصُ خارج القفل.",
            $stats['ok'],
        ),
    ) && $ok;

    $after = bcadd(poolTotal($before['ids']), bcsub(platformEarned($before['ids']), $before['platform_before'], 4), 4);

    $ok = check(
        bccomp($after, $before['total'], 4) === 0,
        'حِفظُ المال',
        sprintf('قبل %s · بعد %s', $before['total'], $after),
        'مجموعُ الفرع والعميل والمنصّة تغيّر — مالٌ ظهر أو اختفى.',
    ) && $ok;

    $ok = checkLedgerBalanced() && $ok;

    $ok = check(
        $stats['ok'] + $stats['rejected'] > 0,
        'العمليّاتُ وصلت',
        sprintf('%d نجحت · %d رُفضت · %d عطلت', $stats['ok'], $stats['rejected'], $stats['error']),
        'لم تصل عمليّةٌ واحدة — المقياسُ يقيس فراغاً.',
    ) && $ok;

    $ok = checkNoServerErrors($stats) && $ok;

    return $ok;
}

// ══════════════════════════════════════════════════════════════════════
//  العامل — عمليّةٌ مستقلّةٌ باتّصالها الخاصّ
// ══════════════════════════════════════════════════════════════════════

function runWorker(int $id, string $run, string $s, int $ops, float $barrier): int
{
    // **حاجزُ البدء**: يُنتظر حتّى لحظةٍ واحدةٍ للجميع. وبلا هذا يبدأ
    // الأوّلُ وينتهي قبل أن يُقلع الأخير، فلا يقع تداخلٌ ولا يُقاس شيء.
    while (microtime(true) < $barrier) {
        usleep(1000);
    }

    $ok = $rejected = $error = 0;
    $latencies = [];
    $faults = [];

    for ($i = 0; $i < $ops; $i++) {
        $t0 = microtime(true);

        try {
            match ($s) {
                'transfer' => workerTransfer($run, $id),
                'counter-withdraw' => workerCounter($run, 'withdraw'),
                'counter-deposit' => workerCounter($run, 'deposit'),
            };
            $ok++;
        } catch (\App\Exceptions\InsufficientBalanceException|DomainException $e) {
            // **رفضٌ سليم** — النظامُ قال «لا يكفي». وهذا هو المطلوب.
            $rejected++;
        } catch (\Throwable $e) {
            $error++;

            // **يُحمَل الخطأُ في النتيجة لا يُكتب في stderr وحده.**
            // فعطلٌ نادرٌ يقع مرّةً في اثنتي عشرة جولة يضيع لو كان مطبوعاً
            // في مجرًى يُرشَّح — وقد ضاع فعلاً مرّةً في هذه الجلسة، فبقي
            // «عطلان» رقماً بلا سبب. والنادرُ تحت الضغط هو أخطرُ ما يُقاس.
            $faults[] = sprintf('%s: %s',
                class_basename($e), mb_substr($e->getMessage(), 0, 200));
        }

        $latencies[] = (microtime(true) - $t0) * 1000;
    }

    echo json_encode([
        'ok' => $ok, 'rejected' => $rejected, 'error' => $error,
        'latencies' => $latencies, 'faults' => $faults,
    ], JSON_UNESCAPED_UNICODE), "\n";

    return 0;
}

function workerTransfer(string $run, int $id): void
{
    $ids = disposableIds($run);
    sort($ids);

    $from = $ids[$id % count($ids)];
    $to = $ids[($id + 1) % count($ids)];

    $fee = app(\App\Services\FeeService::class)
        ->calculate('SEND_MONEY', '100', ['zone_code' => 'SOUTH', 'applies_to' => 'customer']);

    transferRunner()->run($from, $to, '100', (string) ($fee['fee'] ?? '0'));
}

/**
 * غلافٌ رقيقٌ على سمة المعاملات — فهي سمةٌ لا خدمة.
 *
 * ويُنشأ **صنفاً مجهولاً عند النداء** لا صنفاً مُعلَناً في ذيل الملفّ:
 * العاملُ يخرج بـ`exit()` قبل أن يبلغ التنفيذُ ذيلَ الملفّ، فصنفٌ يستعمل
 * سمةً لا يُرفَع مبكّراً ولا يوجد حين يُطلب. (وقد وقع هذا فعلاً، ومسكه
 * حارسُ «العمليّاتُ وصلت» — ولولاه لقرأنا خضرةً على فراغ.)
 */
function transferRunner(): object
{
    static $runner = null;

    return $runner ??= new class
    {
        use \App\Traits\TransactionTrait;

        public function run(int $from, int $to, string $amount, string $fee): void
        {
            $this->customer_send_money_transaction($from, $to, $amount, $fee);
        }
    };
}

function workerCounter(string $run, string $kind): void
{
    $branch = AgentBranch::where('code', $run)->firstOrFail();
    $customer = User::where('email', 'like', "{$run}-902@%")->firstOrFail();
    $actor = User::findOrFail($branch->agent_user_id);

    $svc = app(AgentCounterService::class);

    $kind === 'withdraw'
        ? $svc->withdraw($branch, $customer, '1000', $actor)
        : $svc->deposit($branch, $customer, '1000', $actor);
}

// ══════════════════════════════════════════════════════════════════════
//  الأدوات
// ══════════════════════════════════════════════════════════════════════

function spawn(string $run, string $s, int $workers, int $ops): array
{
    $php = PHP_BINARY;
    $self = __FILE__;
    $barrier = microtime(true) + 1.5;

    $procs = [];
    $pipes = [];

    for ($i = 0; $i < $workers; $i++) {
        $cmd = sprintf(
            '%s %s --worker=%d --run=%s --scenario=%s --ops=%d --barrier=%.4f',
            escapeshellarg($php), escapeshellarg($self),
            $i, escapeshellarg($run), escapeshellarg($s), $ops, $barrier,
        );

        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);

        if (! is_resource($p)) {
            throw new RuntimeException("تعذّر إقلاع العامل {$i}");
        }

        $procs[$i] = $p;
        $pipes[$i] = $pp;
    }

    $agg = ['ok' => 0, 'rejected' => 0, 'error' => 0, 'latencies' => [], 'faults' => []];
    $t0 = microtime(true);

    foreach ($procs as $i => $p) {
        $out = stream_get_contents($pipes[$i][1]);
        $err = stream_get_contents($pipes[$i][2]);

        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        proc_close($p);

        if ($err !== '') {
            fwrite(STDERR, $err);
        }

        $row = json_decode(trim(strrchr("\n" . trim($out), "\n") ?: '{}'), true);

        if (! is_array($row)) {
            $agg['error'] += 1;

            continue;
        }

        $agg['ok'] += $row['ok'] ?? 0;
        $agg['rejected'] += $row['rejected'] ?? 0;
        $agg['error'] += $row['error'] ?? 0;
        $agg['latencies'] = array_merge($agg['latencies'], $row['latencies'] ?? []);
        $agg['faults'] = array_merge($agg['faults'], $row['faults'] ?? []);
    }

    $agg['wall'] = microtime(true) - $t0 - 1.5;

    return $agg;
}

function report(array $s): void
{
    $l = $s['latencies'];
    sort($l);

    $n = count($l);
    $p = static fn (float $q): string => $n
        ? number_format($l[min($n - 1, (int) floor($q * $n))], 1) . ' ms'
        : '—';

    $total = $s['ok'] + $s['rejected'] + $s['error'];

    line(sprintf('  زمنُ الجولة  : %.2f ث   ·   %d محاولة   ·   %.1f عمليّة/ث',
        max($s['wall'], 0.001), $total, $total / max($s['wall'], 0.001)));
    line(sprintf('  الزمن        : وسيط %s · p95 %s · p99 %s', $p(0.50), $p(0.95), $p(0.99)));
}

function check(bool $pass, string $name, string $measured, string $why): bool
{
    line(sprintf('  %s %-22s %s', $pass ? '✓' : '✗', $name, $measured));

    if (! $pass) {
        line('      ' . $why);
    }

    return $pass;
}

function feeScheme(string $code): void
{
    FeeScheme::updateOrCreate(
        ['code' => $code, 'zone_code' => 'SOUTH',
            'applies_to' => $code === 'SEND_MONEY' ? 'customer' : 'agent'],
        [
            'fee_type' => 'percent_plus_fixed',
            'percent_rate' => '1.00', 'fixed_amount' => '5.0000',
            'min_fee' => '0', 'max_fee' => '100000', 'bearer' => 'sender',
            'platform_share_percent' => '100.00', 'agent_share_percent' => '0.00',
            'version' => 1, 'is_active' => true,
        ],
    );
}

function disposableUser(string $run, int $i, string $balance, string $role = 'customer'): int
{
    $u = User::factory()->create([
        'f_name' => 'ضغط', 'l_name' => (string) $i,
        'email' => "{$run}-{$i}@load.test",
        'role' => $role,
        'zone_code' => 'SOUTH',
    ]);

    EMoney::create([
        'user_id' => $u->id, 'current_balance' => $balance,
        'pending_balance' => '0', 'held_balance' => '0',
        'charge_earned' => '0', 'zone_code' => 'SOUTH', 'version' => 0,
    ]);

    return $u->id;
}

/** @return array<int,int> */
function disposableIds(string $run): array
{
    return User::where('email', 'like', "{$run}-%@load.test")
        ->where('role', 'customer')->pluck('id')->all();
}

function poolTotal(array $ids): string
{
    return (string) EMoney::whereIn('user_id', $ids)
        ->get()->reduce(
            static fn ($c, $w) => bcadd($c, (string) $w->current_balance, 4), '0');
}

function walletOf(int $id): string
{
    return (string) (EMoney::where('user_id', $id)->value('current_balance') ?? '0');
}

/**
 * ما التقطته المنصّةُ رسماً **من عمليّات هذه الجولة وحدَها** — ويُحسب من
 * قيود الدفتر لا من عمودٍ مخزَّن.
 *
 * ولا يُقرأ من `charge_earned` ولا من `ledger_accounts.current_balance`:
 * كلاهما لقطةٌ لا مصدر، وميزانٌ يُبنى من عمودٍ مخزَّن يُثبت أنّ العمود
 * يساوي نفسَه. (القاعدةُ السادسة.)
 *
 * **ولا يُقرأ من `platform_fee_entries` وحدَه**: قِيس فوُجد أنّ رسمَ شبّاك
 * الوكيل لا يمرّ بذلك الجدول إطلاقاً — يذهب إلى حساب `PLATFORM_FEE` في
 * الدفتر مباشرةً. فالدفترُ وحدَه يرى القناتين.
 *
 * **والقصرُ على قيود الجولة ليس تجميلاً:** مجموعٌ عامٌّ يجعل جولتين
 * متزامنتين تقرأ كلٌّ منهما رسومَ الأخرى، **فيظهر أحمرُ كاذبٌ على نظامٍ
 * سليم**. وقد وقع ذلك فعلاً. (حارسٌ يكذب أسوأ من غيابه.)
 *
 * @param  array<int,int>  $userIds
 */
function platformEarned(array $userIds = []): string
{
    $feeId = DB::table('ledger_accounts')
        ->where('account_code', 'like', '%PLATFORM_FEE%')->value('id');

    if (! $feeId) {
        return '0';
    }

    $q = DB::table('ledger_entry_lines')->where('account_id', $feeId);

    if ($userIds !== []) {
        // حساباتُ محافظ هذه الجولة، ثمّ القيودُ التي تمسّها.
        $mine = DB::table('ledger_accounts')
            ->whereIn('owner_user_id', $userIds)->pluck('id');

        $journals = DB::table('ledger_entry_lines')
            ->whereIn('account_id', $mine)->distinct()->pluck('journal_entry_id');

        $q->whereIn('journal_entry_id', $journals);
    }

    $rows = $q->selectRaw("direction, COALESCE(SUM(amount),0) s")
        ->groupBy('direction')->pluck('s', 'direction');

    return bcsub((string) ($rows['credit'] ?? '0'), (string) ($rows['debit'] ?? '0'), 4);
}

/**
 * **الدفترُ متوازن؟** مجموعُ المدين يساوي مجموعَ الدائن — دائماً وبلا
 * استثناء. وقيدٌ غيرُ متوازنٍ يعني معاملةً انقطعت في منتصفها.
 *
 * @return array{0:string,1:string}
 */
function ledgerSides(): array
{
    $rows = DB::table('ledger_entry_lines')
        ->selectRaw("direction, COALESCE(SUM(amount),0) s")
        ->groupBy('direction')->pluck('s', 'direction');

    return [(string) ($rows['debit'] ?? '0'), (string) ($rows['credit'] ?? '0')];
}

function negativeCount(array $ids): int
{
    return EMoney::whereIn('user_id', $ids)->where('current_balance', '<', 0)->count();
}

function cleanup(string $run): void
{
    $ids = User::where('email', 'like', "{$run}-%@load.test")->pluck('id')->all();

    if ($ids === []) {
        return;
    }

    DB::transaction(function () use ($ids, $run) {
        AgentCashTill::whereIn('branch_id',
            AgentBranch::where('code', $run)->pluck('id'))->delete();
        AgentBranch::where('code', $run)->delete();
        DB::table('transactions')->whereIn('user_id', $ids)->delete();
        EMoney::whereIn('user_id', $ids)->delete();
        User::whereIn('id', $ids)->delete();
    });
}

function line(string $s): void
{
    echo $s, "\n";
}
