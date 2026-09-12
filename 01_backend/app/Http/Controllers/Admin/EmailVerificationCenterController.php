<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMIAL-EMAIL-ADMIN-001 — مركز البريد والتحقق.
 *
 * لا يعرض OTP أو hash أو مفاتيح Resend مطلقاً. البريد يُقنّع افتراضياً،
 * ولا يُكشف كاملاً إلا لمن يحمل صلاحية PII المستقلة. القراءة والإبطال
 * مفصولان بصلاحيتين مستقلتين في routes/admin/email-center.php.
 */
class EmailVerificationCenterController extends Controller
{
    private const PURPOSES = ['registration', 'password_reset', 'pin_recovery', 'email_change'];
    private const STATUSES = ['pending', 'sent', 'delivered', 'delayed', 'bounced', 'complained', 'failed'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request)
    {
        $actor = auth('user')->user();
        $revealPii = (bool) $actor?->hasPlatformPermission('platform.customers.pii.reveal');

        $filters = $this->filters($request);
        $query = $this->challengeQuery($filters);

        $challenges = $query
            ->select([
                'id', 'challenge_id', 'user_id', 'identifier', 'channel', 'purpose',
                'expires_at', 'resend_available_at', 'attempts', 'max_attempts',
                'verified_at', 'consumed_at', 'delivery_status', 'provider_message_id',
                'last_error', 'requested_by_type', 'requested_by_id', 'created_at', 'updated_at',
            ])
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        foreach ($challenges->items() as $row) {
            $row->identifier_display = $revealPii
                ? (string) $row->identifier
                : $this->maskEmail((string) $row->identifier);
            $row->last_error_display = $this->safeProviderError($row->last_error);
        }

        $stats = $this->deliveryStats();
        $identity = $this->identityStats();
        $provider = $this->providerHealth();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor?->id,
            'subject_type' => 'email_verification_center',
            'action' => $revealPii ? 'EMAIL_CENTER_PII_VIEWED' : 'EMAIL_CENTER_VIEWED',
            'decision_code' => 'ALLOW',
            'severity' => $revealPii ? 'notice' : 'info',
            'context' => [
                'purpose' => $filters['purpose'],
                'status' => $filters['status'],
                'query_present' => $filters['q'] !== '',
                'pii_revealed' => $revealPii,
                'result_count' => count($challenges->items()),
            ],
        ]);

        return view('admin-views.amial.email-center.index', [
            'challenges' => $challenges,
            'stats' => $stats,
            'identity' => $identity,
            'provider' => $provider,
            'filters' => $filters,
            'purposes' => self::PURPOSES,
            'statuses' => self::STATUSES,
            'revealPii' => $revealPii,
            'canManage' => (bool) $actor?->hasPlatformPermission('platform.email.manage'),
        ]);
    }

    public function revoke(Request $request, string $challengeId)
    {
        abort_unless((bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $challengeId), 404);

        $actor = auth('user')->user();
        $snapshot = null;
        $changed = false;

        DB::transaction(function () use ($challengeId, &$snapshot, &$changed): void {
            $snapshot = DB::table('otp_challenges')
                ->where('challenge_id', $challengeId)
                ->lockForUpdate()
                ->first(['id', 'challenge_id', 'user_id', 'purpose', 'delivery_status', 'expires_at', 'consumed_at']);

            abort_if($snapshot === null, 404);

            if ($snapshot->consumed_at !== null) {
                return;
            }

            DB::table('otp_challenges')->where('id', $snapshot->id)->update([
                'expires_at' => now(),
                'verification_token_hash' => null,
                'verification_expires_at' => null,
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);
            $changed = true;
        }, 3);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor?->id,
            'subject_type' => 'otp_challenge',
            'subject_id' => $challengeId,
            'action' => 'EMAIL_OTP_CHALLENGE_REVOKED',
            'decision_code' => $changed ? 'REVOKED' : 'ALREADY_CONSUMED',
            'severity' => $changed ? 'warning' : 'info',
            'context' => [
                'user_id' => $snapshot?->user_id,
                'purpose' => $snapshot?->purpose,
                'delivery_status' => $snapshot?->delivery_status,
            ],
        ]);

        return back()->with('success', $changed
            ? 'تم إبطال تحدّي التحقق فوراً، ولن يقبل النظام رمزه أو توكن التحقق بعد الآن.'
            : 'هذا التحدّي مستهلك أو مبطل مسبقاً؛ لم يُغيّر النظام شيئاً.');
    }

    public function export(Request $request): StreamedResponse
    {
        $actor = auth('user')->user();
        $filters = $this->filters($request);

        // التصدير يبقى مقنّعاً حتى لمن يملك PII: الملف يغادر الشاشة وقد يُشارك.
        $rows = $this->challengeQuery($filters)
            ->select([
                'challenge_id', 'user_id', 'identifier', 'purpose', 'delivery_status',
                'attempts', 'max_attempts', 'verified_at', 'consumed_at', 'created_at',
            ])
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor?->id,
            'subject_type' => 'email_verification_center',
            'action' => 'EMAIL_CENTER_EXPORTED',
            'decision_code' => 'ALLOW',
            'severity' => 'notice',
            'context' => [
                'rows' => $rows->count(),
                'purpose' => $filters['purpose'],
                'status' => $filters['status'],
                'query_present' => $filters['q'] !== '',
                'identifiers_masked' => true,
            ],
        ]);

        $filename = 'amial-email-verification-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['challenge_id', 'user_id', 'email_masked', 'purpose', 'delivery_status', 'attempts', 'max_attempts', 'verified_at', 'consumed_at', 'created_at']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->challenge_id,
                    $row->user_id,
                    $this->maskEmail((string) $row->identifier),
                    $row->purpose,
                    $row->delivery_status,
                    $row->attempts,
                    $row->max_attempts,
                    $row->verified_at,
                    $row->consumed_at,
                    $row->created_at,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        $purpose = trim((string) $request->query('purpose', ''));
        $status = trim((string) $request->query('status', ''));
        $q = trim((string) $request->query('q', ''));

        if (! in_array($purpose, self::PURPOSES, true)) {
            $purpose = '';
        }
        if (! in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        return [
            'purpose' => $purpose,
            'status' => $status,
            'q' => mb_substr($q, 0, 120),
        ];
    }

    private function challengeQuery(array $filters)
    {
        $query = DB::table('otp_challenges');

        if ($filters['purpose'] !== '') {
            $query->where('purpose', $filters['purpose']);
        }
        if ($filters['status'] !== '') {
            $query->where('delivery_status', $filters['status']);
        }
        if ($filters['q'] !== '') {
            $needle = mb_strtolower($filters['q']);
            $query->where(function ($q) use ($filters, $needle): void {
                $q->whereRaw('LOWER(identifier) LIKE ?', ['%' . $needle . '%'])
                    ->orWhere('challenge_id', $filters['q'])
                    ->orWhere('provider_message_id', $filters['q']);

                if (ctype_digit($filters['q'])) {
                    $q->orWhere('user_id', (int) $filters['q']);
                }
            });
        }

        return $query;
    }

    private function deliveryStats(): array
    {
        $from = now()->subDay();
        $base = DB::table('otp_challenges')->where('created_at', '>=', $from);
        $total = (clone $base)->count();
        $delivered = (clone $base)->where('delivery_status', 'delivered')->count();

        return [
            'total_24h' => $total,
            'delivered_24h' => $delivered,
            'sent_24h' => (clone $base)->where('delivery_status', 'sent')->count(),
            'delayed_24h' => (clone $base)->where('delivery_status', 'delayed')->count(),
            'bounced_24h' => (clone $base)->where('delivery_status', 'bounced')->count(),
            'complained_24h' => (clone $base)->where('delivery_status', 'complained')->count(),
            'verified_24h' => (clone $base)->whereNotNull('verified_at')->count(),
            'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0.0,
        ];
    }

    private function identityStats(): array
    {
        $hasVerified = Schema::hasColumn('users', 'is_email_verified');
        $realPhoneUsers = DB::table('users')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('phone', 'not like', '9009%');

        $missing = (clone $realPhoneUsers)->where(function ($q): void {
            $q->whereNull('email')->orWhereRaw("TRIM(email) = ''");
        })->count();

        return [
            'canonical' => DB::table('users')->whereNotNull('email_canonical')->count(),
            'verified' => $hasVerified
                ? DB::table('users')->whereNotNull('email_canonical')->where('is_email_verified', 1)->count()
                : null,
            'missing' => $missing,
            'conflicts' => Schema::hasTable('email_identity_conflicts')
                ? DB::table('email_identity_conflicts')->whereNull('resolved_at')->count()
                : 0,
        ];
    }

    private function providerHealth(): array
    {
        return [
            'api_key_configured' => filled(config('amial_otp.resend.api_key')),
            'webhook_secret_configured' => filled(config('amial_otp.resend.webhook_secret')),
            'from_address' => (string) config('amial_otp.resend.from_address'),
            'from_name' => (string) config('amial_otp.resend.from_name'),
            'registration_channel' => (string) config('amial_otp.registration_channel'),
            'password_reset_channel' => (string) config('amial_otp.password_reset_channel'),
            'pin_recovery_channel' => (string) config('amial_otp.pin_recovery_channel'),
            'ttl_minutes' => (int) config('amial_otp.ttl_minutes', 5),
            'resend_seconds' => (int) config('amial_otp.resend_seconds', 60),
            'max_attempts' => (int) config('amial_otp.max_attempts', 5),
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', mb_strtolower(trim($email)), 2), 2, '');
        if ($domain === '') {
            return '***';
        }

        $prefix = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $prefix . '***@' . $domain;
    }

    private function safeProviderError(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }

        $safe = preg_replace('/(?:re|whsec)_[A-Za-z0-9_\-\.]+/i', '[redacted]', $error);
        $safe = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', (string) $safe);

        return Str::limit((string) $safe, 180);
    }
}
