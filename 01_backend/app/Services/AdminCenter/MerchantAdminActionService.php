<?php

namespace App\Services\AdminCenter;

use App\Models\AdminCenter\MerchantAdminAction;
use App\Models\AdminCenter\MerchantDataAccessGrant;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-MERCHANT-CENTER-001 — **كلُّ فعلٍ إداريٍّ يمرّ من هنا**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا زرَّ يكتب في القاعدة مباشرةً.** الفعلُ يمرّ بأربع مراحل، وسقوطُ
 * أيّها يُلغي الباقي:
 *
 * ```
 * ① فعلٌ معروف   ← اسمٌ من القائمة، لا نصٌّ حرّ
 * ② سببٌ مكتوب   ← «جُمّد» بلا سبب سؤالٌ بلا جواب بعد شهر
 * ③ التنفيذ      ← داخل معاملة، بالتقاط الحالة قبل وبعد
 * ④ الأثر        ← يُكتب حتّى لو فشل التنفيذ
 * ```
 *
 * **والرابعةُ أهمُّها**: محاولةٌ فاشلةٌ لتجميد حسابٍ **حدَثٌ يُسجَّل**.
 * فمن حاول ولم يستطع سؤالٌ يُسأل، وسجلٌّ لا يحفظ إلّا النجاح يُخفي
 * نصفَ ما جرى.
 */
class MerchantAdminActionService
{
    /**
     * تنفيذُ فعلٍ إداريٍّ وتسجيلُه.
     *
     * @param  callable  $work  يُنفَّذ داخل معاملةٍ ويعيد `after_state`
     */
    public function perform(
        User $actor,
        int $merchantUserId,
        string $action,
        string $reason,
        array $beforeState,
        callable $work,
        ?Request $request = null,
        ?string $target = null,
        $targetId = null,
    ): MerchantAdminAction {
        // ① فعلٌ معروف
        if (! array_key_exists($action, MerchantAdminAction::ACTIONS)) {
            throw new DomainException("فعل إداري غير معروف: {$action}");
        }

        // ② سببٌ مكتوب — **وطولٌ معقول**: «تم» ليست سبباً.
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException(
                'اكتب سبباً واضحاً (٥ أحرف فأكثر) — سجلٌّ بلا سبب لا يُراجَع');
        }

        $base = [
            'uuid' => (string) Str::uuid(),
            'reference' => $this->reference(),
            'merchant_user_id' => $merchantUserId,
            'actor_admin_id' => $actor->id,
            'actor_role' => $actor->role ?? null,
            'action' => $action,
            'target' => $target,
            'target_id' => $targetId,
            'before_state' => $beforeState,
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 240, ''),
            'request_id' => $request?->header('X-Request-Id'),
        ];

        try {
            // ③ التنفيذ داخل معاملة
            $after = DB::transaction(static fn () => $work());

            return MerchantAdminAction::create($base + [
                'after_state' => is_array($after) ? $after : ['result' => $after],
                'result' => 'ok',
            ]);
        } catch (\Throwable $e) {
            // ④ **الفشلُ يُسجَّل أيضاً** — انظر شرح الصنف.
            MerchantAdminAction::create($base + [
                'after_state' => null,
                'result' => 'failed',
                'failure_message' => Str::limit($e->getMessage(), 240, ''),
            ]);

            throw $e;
        }
    }

    /** أثرُ التاجر — **مرتّباً من الأحدث، ولا يُحذف منه شيء**. */
    public function trail(int $merchantUserId, int $limit = 100): array
    {
        return MerchantAdminAction::where('merchant_user_id', $merchantUserId)
            ->with('actor:id,f_name,l_name')
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (MerchantAdminAction $a) => [
                'reference' => $a->reference,
                'action' => $a->action,
                'action_ar' => $a->actionAr(),
                'transition' => $a->transition(),
                'actor' => trim(($a->actor->f_name ?? '') . ' ' . ($a->actor->l_name ?? '')) ?: '—',
                'actor_role' => $a->actor_role,
                'reason' => $a->reason,
                'ip' => $a->ip_address,
                'result' => $a->result,
                'failure' => $a->failure_message,
                'at' => $a->created_at?->format('Y-m-d H:i:s'),
            ])->all();
    }

    // ══════════════════════════════════════════════════════════════════
    //  إذنُ الاطّلاع على التفصيل التشغيليّ
    // ══════════════════════════════════════════════════════════════════

    /**
     * فتحُ إذنٍ مؤقّت — **بسببٍ وأجلٍ ومرجعِ تذكرة**.
     *
     * **والحدُّ الأعلى للأجل مقصود**: من احتاج أكثرَ من يومٍ لا يحتاج
     * إذناً — يحتاج قراراً.
     */
    public function grantAccess(
        User $actor, int $merchantUserId, string $scope, string $reason,
        int $hours = 4, ?string $ticketRef = null, ?Request $request = null,
    ): MerchantDataAccessGrant {
        if (! in_array($scope, [
            MerchantDataAccessGrant::SCOPE_OPERATIONAL,
            MerchantDataAccessGrant::SCOPE_FINANCIAL,
        ], true)) {
            throw new DomainException('نطاق إذن غير معروف');
        }

        $hours = max(1, min(24, $hours));

        $grant = MerchantDataAccessGrant::create([
            'uuid' => (string) Str::uuid(),
            'reference' => $this->reference('ACC'),
            'merchant_user_id' => $merchantUserId,
            'admin_id' => $actor->id,
            'scope' => $scope,
            'reason' => trim($reason),
            'ticket_ref' => $ticketRef,
            'expires_at' => now()->addHours($hours),
        ]);

        $this->perform(
            actor: $actor,
            merchantUserId: $merchantUserId,
            action: 'access.grant',
            reason: $reason,
            beforeState: ['label' => 'بلا إذن'],
            work: fn () => ['label' => $grant->scopeAr(), 'expires_at' => $grant->expires_at->toIso8601String()],
            request: $request,
            target: 'data_access',
            targetId: $grant->id,
        );

        return $grant;
    }

    public function revokeAccess(
        User $actor, MerchantDataAccessGrant $grant, string $reason, ?Request $request = null,
    ): void {
        $this->perform(
            actor: $actor,
            merchantUserId: $grant->merchant_user_id,
            action: 'access.revoke',
            reason: $reason,
            beforeState: ['label' => $grant->scopeAr()],
            work: function () use ($grant, $actor) {
                $grant->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);

                return ['label' => 'ملغى'];
            },
            request: $request,
            target: 'data_access',
            targetId: $grant->id,
        );
    }

    /**
     * أيملك هذا المدقّقُ إذناً سارياً؟ — **ويُعدّ الاستعمال**.
     *
     * فإذنٌ يُفتح ولا يُستعمل إشارةٌ في ذاته، وإذنٌ يُستعمل مئةَ مرّةٍ
     * إشارةٌ أخرى.
     */
    public function hasAccess(User $actor, int $merchantUserId, string $scope): bool
    {
        // **الأدمن الأعلى لا يُستثنى** — الاستثناءُ يُفرغ البوّابةَ من معناها،
        // وأكثرُ من يفتح ملفّات التجّار هو الأعلى صلاحيّةً لا الأقلّ.
        $grant = MerchantDataAccessGrant::activeFor($merchantUserId, $actor->id, $scope);

        if (! $grant) {
            return false;
        }

        $grant->increment('use_count');
        $grant->update(['last_used_at' => now()]);

        return true;
    }

    /** أذوناتُ تاجرٍ — سارِيةً ومنتهية، **فالمنتهيةُ سجلٌّ لا نفاية**. */
    public function grantsFor(int $merchantUserId, int $limit = 30): array
    {
        return MerchantDataAccessGrant::where('merchant_user_id', $merchantUserId)
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (MerchantDataAccessGrant $g) => [
                'id' => $g->id,
                'reference' => $g->reference,
                'scope' => $g->scope,
                'scope_ar' => $g->scopeAr(),
                'reason' => $g->reason,
                'ticket_ref' => $g->ticket_ref,
                'expires_at' => $g->expires_at->format('Y-m-d H:i'),
                'active' => $g->isActive(),
                'use_count' => (int) $g->use_count,
                'last_used_at' => $g->last_used_at?->format('Y-m-d H:i'),
            ])->all();
    }

    private function reference(string $prefix = 'AUD'): string
    {
        return $prefix . '-' . now()->format('ymd') . '-' . strtoupper(Str::random(6));
    }
}
