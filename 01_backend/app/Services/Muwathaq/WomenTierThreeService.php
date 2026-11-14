<?php

namespace App\Services\Muwathaq;

use App\Models\User;
use App\Models\WomenTier3SyncEvent;
use App\Services\Kyc\KycPrivacyService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-WOMEN-TIER3-001 — المستوى الثالث للمرأة: يُقرَّر في أميال باي، وموثّق يُبلَّغ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قرار صاحب المشروع، بنصّه:** شرطُ الوصول للمستوى الثالث تحقّقٌ حضوريٌّ
 * أو اتصالٌ مرئيٌّ تتأكّد به إدارة أميال باي — **والأهمّ: الموافقة والطلب
 * للمستوى الثالث من إدارة أميال باي وحدَها، لا موثّق**.
 *
 * **والتحقّقُ الحضوريّ نفسُه لم يكن ناقصاً — كان مبنيّاً بالفعل ومنسيّاً.**
 * `KycPrivacyService::choose('in_person')` (العميلة تختار) ثم
 * `KycPrivacyService::verifyInPerson()` (المراجع يؤكّد، عبر
 * `KycRestrictedReviewController::verifyInPerson` خلف
 * `platform.customers.kyc.restricted.decide`) — هذا المسار يُسقط شرط
 * السيلفي فعلياً (`GuardedKycDocumentService::tierThreeRequiredDocuments`)
 * ويفتح المستوى الثالث عبر `decide()` الموحَّد، **لأيّ حساب، لا للمرأة
 * وحدها**. فلم يكن ينقص بناءٌ جديد؛ كان ينقص إبلاغُ موثّق بعده.
 *
 * **وهذا وحده الجديد هنا.** موثّق يملك قرار الهوية دائماً (`CAP-01`/`CAP-02`)؛
 * وهنا وحده ينعكس الاتّجاه: أميال باي قرَّر فعلاً (عبر المسار القائم أعلاه)،
 * وموثّق يُخطَر بعد القرار ليبقى سجلّه متزامناً — لا ليوافق أو يراجع. والسببُ
 * أنّ موثّق لا يملك اليوم أيّ بابٍ يمنح المرأة المستوى الثالث (مؤكَّدٌ بكوده:
 * `createMuwathaqOwnedCase` يرفضه صراحةً عند فتح الحساب، و`progressive_tier_upgrade`
 * "الترقية المدعومة حالياً هي الفئة الثانية فقط" عند الترقية اللاحقة).
 */
class WomenTierThreeService
{
    public const TARGET_TIER = 3;

    /**
     * يُستدعى بعد اعتماد المستوى الثالث فعليًّا (من `AccountVerificationDecisionService`)
     * — لا قبله. **لا يُبلَّغ موثّق برفعٍ لم يقع بعد.**
     */
    public function notifyMuwathaq(User $user): ?WomenTier3SyncEvent
    {
        $state = app(KycPrivacyService::class)->forUser($user);
        if (($state['ownership_method'] ?? null) !== 'in_person_verified'
            || empty($state['reviewed_by']) || empty($state['reviewed_at'])) {
            return null;
        }

        $event = DB::transaction(function () use ($user, $state): WomenTier3SyncEvent {
            $existing = WomenTier3SyncEvent::query()
                ->where('user_id', $user->id)
                ->where('decided_by', (int) $state['reviewed_by'])
                ->where('verification_reference', (string) ($state['decision_reason'] ?? ''))
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            return WomenTier3SyncEvent::query()->create([
                'event_ulid' => (string) Str::ulid(),
                'user_id' => $user->id,
                'decided_by' => (int) $state['reviewed_by'],
                'verification_method' => WomenTier3SyncEvent::METHOD_IN_PERSON,
                'verification_reference' => (string) ($state['decision_reason'] ?? ''),
            ]);
        });

        DB::afterCommit(fn () => \App\Jobs\DeliverWomenTier3SyncJob::dispatch($event->id));

        return $event;
    }

    private function bridgeSecret(): string
    {
        return MuwathaqTransportSecrets::amialToMuwathaq();
    }

    /** تسليمٌ موقّع، مثل كل قناة أميال باي الصادرة — idempotent بمعرّف الحدث. */
    public function deliver(int $eventId): bool
    {
        $event = WomenTier3SyncEvent::query()->lockForUpdate()->find($eventId);
        if ($event === null || $event->sync_status === 'delivered') {
            return false;
        }
        $event->attempts++;
        $event->last_attempted_at = now();
        $event->save();

        $user = $event->user()->first();
        $decider = $event->decider()->first();
        $body = json_encode([
            'event_version' => 1,
            'event_type' => 'tier_decision.applied_by_amial',
            'decision' => [
                'event_ulid' => $event->event_ulid,
                'amial_user_id' => $event->user_id,
                'subject_kind' => 'customer',
                'verification_path' => 'women_progressive',
                'target_tier' => self::TARGET_TIER,
                'decided_by' => 'amial_pay_admin',
                'decided_by_reference' => $decider ? trim((string) ($decider->f_name.' '.$decider->l_name)) : null,
                'verification_method' => $event->verification_method,
                'verification_reference' => $event->verification_reference,
                'decided_at' => $event->created_at?->toIso8601String(),
            ],
            'applicant' => [
                'amial_user_id' => $user?->id,
                'legal_name' => $user ? trim((string) ($user->f_name.' '.$user->l_name)) : null,
                'phone' => $user?->phone,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ts = (string) time();
        try {
            $res = \Illuminate\Support\Facades\Http::acceptJson()->withHeaders([
                'X-Amial-Timestamp' => $ts,
                'X-Amial-Signature' => hash_hmac('sha256', $ts.'.'.$body, $this->bridgeSecret()),
                'Idempotency-Key' => $event->event_ulid,
                'Content-Type' => 'application/json',
            ])->timeout((int) config('muwathaq.timeout_seconds', 10))
                ->send('POST', rtrim((string) config('muwathaq.base_url'), '/').'/api/v1/internal/amial-tier-decisions', ['body' => $body]);
        } catch (\Throwable $e) {
            $event->update(['failure_code' => 'NETWORK_ERROR']);
            throw $e;
        }

        if (!$res->successful()) {
            $event->update(['failure_code' => 'HTTP_'.$res->status(), 'response_status' => $res->status()]);
            throw new DomainException('موثّق رفض إشعار المستوى الثالث (HTTP '.$res->status().').');
        }

        $event->update([
            'sync_status' => 'delivered', 'synced_at' => now(),
            'response_status' => $res->status(), 'failure_code' => null,
        ]);

        return true;
    }
}
