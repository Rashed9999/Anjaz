<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Muwathaq\MuwathaqWelcomeEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** رسالة ما بعد الاعتماد: تُعاد عند تعذّر المزوّد، ولا تُعاد إن غاب المفتاح أو العنوان. */
class SendMuwathaqWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;
    public array $backoff = [30, 120, 600, 1800, 3600];

    public function __construct(public readonly int $userId, public readonly string $kind, public readonly string $applicationUlid)
    {
        $this->onQueue('muwathaq');
    }

    public function handle(MuwathaqWelcomeEmailService $service): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }
        try {
            $service->send($user, $this->kind, $this->applicationUlid);
        } catch (\RuntimeException $e) {
            if (in_array($e->getMessage(), ['RESEND_API_KEY_MISSING', 'WELCOME_EMAIL_NO_ADDRESS'], true)) {
                Log::warning('muwathaq welcome email skipped', ['user_id' => $this->userId, 'reason' => $e->getMessage()]);
                $this->recordFailure($e->getMessage());

                return;
            }
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->recordFailure($e->getMessage());
    }

    private function recordFailure(string $reason): void
    {
        try {
            app(AuditService::class)->record([
                'actor_type' => 'system', 'actor_user_id' => null, 'subject_type' => 'user', 'subject_id' => (string) $this->userId,
                'action' => 'MUWATHAQ_WELCOME_EMAIL_FAILED', 'decision_code' => 'FAILED', 'severity' => 'warning',
                'context' => ['application_ulid' => $this->applicationUlid, 'reason' => mb_substr($reason, 0, 120)],
            ]);
        } catch (\Throwable) {
            // التدقيق لا يُسقط المهمة.
        }
    }
}
