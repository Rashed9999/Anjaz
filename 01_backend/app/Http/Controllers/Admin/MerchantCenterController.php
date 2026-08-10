<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminCenter\MerchantDataAccessGrant;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Models\UserLogHistory;
use App\Services\Access\EntitlementService;
use App\Services\AdminCenter\MerchantAdminActionService;
use App\Services\AdminCenter\MerchantCenterService;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-MERCHANT-CENTER-001 — **مركزُ التاجر: يُرى ويُفعَل، لا يُقرأ فحسب**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الخطأ الذي يُصلَح هنا مرّتان:**
 *
 * ① **حدُّ المسؤوليّة** — أميال منصّةٌ ماليّة لا ERP للتاجر. فلا أصنافَ
 *   ولا مخزونَ ولا موردين في هذا المركز، **إلّا بإذنٍ مؤقّتٍ بسببٍ مكتوب**.
 *
 * ② **الشاشةُ أداةُ عملٍ لا لوحةُ قراءة.** فلكلّ رقمٍ تعمّقٌ، ولكلّ صفٍّ
 *   فعل، ولكلّ فعلٍ تأكيدٌ وسببٌ وأثر. **وجدولٌ يُعرض ولا يُفعَل منه شيء
 *   يُجبر الموظّف على فتح القاعدة يدويّاً** — وهناك لا صلاحيّة ولا سجلّ.
 */
class MerchantCenterController extends Controller
{
    public function __construct(
        private readonly MerchantCenterService $center,
        private readonly MerchantAdminActionService $actions,
        private readonly EntitlementService $entitlements,
        private readonly FeatureAccessService $access,
        private readonly \App\Services\Support\SupportTicketService $tickets,
    ) {
    }

    public function page(Request $request, int $id)
    {
        return view('admin-views.amial.merchant-center.index', ['merchantId' => $id]);
    }

    private function ok(array $data, string $message = ''): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    private function fail(string $message, int $code = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $code);
    }

    /** يلفّ كلَّ قراءةٍ: يحوّل خطأَ المجال إلى ردٍّ مفهوم. */
    private function read(callable $fn): JsonResponse
    {
        try {
            return $this->ok($fn());
        } catch (DomainException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('تعذّر جلب البيانات — حاول مرة أخرى', 500);
        }
    }

    /**
     * يلفّ كلَّ فعل: **سببٌ إلزاميٌّ ثمّ تنفيذٌ ثمّ أثر**.
     *
     * والسببُ يُقرأ هنا لا في كلّ فعلٍ على حدة — فواحدٌ يُنسى.
     */
    private function act(Request $request, callable $fn): JsonResponse
    {
        $reason = trim((string) $request->input('reason', ''));

        if (mb_strlen($reason) < 5) {
            return $this->fail('اكتب سبب الإجراء (٥ أحرف فأكثر) — إجراءٌ بلا سبب لا يُراجَع');
        }

        try {
            return $fn($reason);
        } catch (DomainException|\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('تعذّر تنفيذ الإجراء — والمحاولة مسجَّلة', 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  القراءات — قسمٌ لكلّ تبويب
    // ══════════════════════════════════════════════════════════════════

    /**
     * نظرةٌ واحدة: الملفّ + المال + النبض + المخاطر — **لا ستّة نداءات**.
     *
     * ══════════════════════════════════════════════════════════════════
     * **والعطلُ الذي كان هنا يستحقّ أن يُكتب:** هذه النقطةُ محميّةٌ
     * بـ`platform.customers.view` — وهي أدنى صلاحيّةٍ في اللوحة — وكانت
     * تُرجع `money()` و`risk()` **بلا فحص**. فموظّفُ الدعم الذي يفتح
     * ملفّ تاجرٍ ليردّ على تذكرة كان يقرأ رصيده وتسوياته ودرجةَ مخاطره
     * من نقطةٍ لا تطلب أيّاً من صلاحيّاتها.
     *
     * وحارسُ المسار كان سليماً — والتسريبُ في **الحمولة**. فحمايةُ الباب
     * لا تكفي إن كان ما يخرج منه أوسعَ ممّا يحرسه.
     *
     * فصار كلُّ جزءٍ يُبنى بشرط قسمه، والغائبُ **يُقال غيابُه صراحةً**
     * (القاعدة ٧: «غير معروف» ليس صفراً — وهنا: «ليس لك» ليس صفراً).
     */
    public function overview(Request $request, int $id): JsonResponse
    {
        return $this->read(function () use ($request, $id) {
            $m = $this->center->merchant($id);
            $admin = $request->user();

            $sections = $this->center->sectionsFor($admin);

            return [
                'sections' => $sections,
                'my_roles' => $admin->platformRoleLabels(),
                // **الأفعالُ تُقال للشاشة صراحةً** — لا تُستنتج من التبويبات.
                // فمن يقرأ المخاطر ليس بالضرورة من يجمّد، ومن يرى الاشتراك
                // ليس من يغيّر الباقة. وزرٌّ يُعرض ثمّ يردّ ٤٠٣ خدعةٌ لا حراسة.
                'can' => [
                    'freeze' => $admin->hasPlatformPermission('platform.customers.freeze'),
                    'plan' => $admin->hasPlatformPermission('platform.settings.manage'),
                    'ticket' => $admin->hasPlatformPermission('platform.tickets.manage'),
                    'investigate' => $admin->hasPlatformPermission('platform.merchants.investigate'),
                ],
                'profile' => $this->center->profile($m),
                'money' => $this->center->maySee($admin, 'money')
                    ? $this->center->money($m)
                    : ['restricted' => true, 'note' => 'المركز المالي لفريق المالية — دورُك لا يشمله'],
                'pulse' => $this->center->pulse($m),
                'risk' => $this->center->maySee($admin, 'risk')
                    ? $this->center->risk($m)
                    : ['restricted' => true, 'note' => 'قسم المخاطر لفريق المخاطر — دورُك لا يشمله'],
                // **حدُّ المسؤوليّة يُقال في الشاشة نفسها** — لا في وثيقة.
                'boundary' => [
                    'owned_by_merchant' => 'المنتجات · المخزون · الموردون · '
                        . 'أسعار الشراء · أدوار الموظفين · التشغيل اليومي',
                    'owned_by_amial' => 'الحساب · الأموال · العمليات · التسويات · '
                        . 'العمولات · المخاطر · الامتثال · الاشتراك · الأجهزة',
                ],
            ];
        });
    }

    public function money(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => $this->center->money($this->center->merchant($id)));
    }

    public function statement(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => $this->center->statement(
            $this->center->merchant($id),
            $request->query('from'), $request->query('to'),
            (int) $request->query('limit', 100),
        ));
    }

    public function settlements(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => $this->center->settlements($this->center->merchant($id)));
    }

    public function operations(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => $this->center->operations(
            $this->center->merchant($id), (int) $request->query('days', 30)));
    }

    /** **الدرجةُ التالية**: نوعُ عمليّةٍ يُنقر فتُفتح عمليّاته. */
    public function operationsOfType(Request $request, int $id, string $type): JsonResponse
    {
        return $this->read(fn () => [
            'type' => $type,
            'rows' => $this->center->operationsOfType($this->center->merchant($id), $type),
        ]);
    }

    public function risk(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => $this->center->risk($this->center->merchant($id)));
    }

    public function staff(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => $this->center->staff($this->center->merchant($id)));
    }

    public function devices(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => $this->center->devices($this->center->merchant($id)));
    }

    public function compliance(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => $this->center->compliance($this->center->merchant($id)));
    }

    public function support(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => $this->center->support($this->center->merchant($id)));
    }

    /** الاشتراك — **الباقة وحالتها وما تفتحه**، من محرّك القدرات. */
    public function subscription(Request $request, int $id): JsonResponse
    {
        return $this->read(function () use ($id) {
            $m = $this->center->merchant($id);
            $p = MerchantProfile::where('user_id', $id)->first();
            $manifest = $this->entitlements->manifestFor($m);

            return [
                'plan' => $p->subscription_plan ?? A::PLAN_FREE,
                'plan_name' => A::PLAN_LABELS[$p->subscription_plan ?? A::PLAN_FREE] ?? '—',
                'price_monthly' => A::PLAN_PRICES_SAR[$p->subscription_plan ?? A::PLAN_FREE] ?? 0,
                'expires_at' => optional($p->subscription_expires_at ?? null)->format('Y-m-d'),
                'notes' => $p->subscription_notes ?? null,
                'summary' => $manifest['summary'],
                'available_plans' => array_map(fn ($code) => [
                    'code' => $code,
                    'name' => A::PLAN_LABELS[$code] ?? $code,
                    'price' => A::PLAN_PRICES_SAR[$code] ?? 0,
                ], A::ALL_PLANS),
            ];
        });
    }

    public function auditTrail(Request $request, int $id): JsonResponse
    {
        return $this->read(fn () => [
            'actions' => $this->actions->trail($id, (int) $request->query('limit', 100)),
            'grants' => $this->actions->grantsFor($id),
        ]);
    }

    /**
     * التفصيلُ التشغيليّ — **يُرفض بلا إذنٍ سارٍ، ويقول كيف يُفتح**.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولماذا ٤٢٣ لا ٤٠٣:** كانت هذه النقطةُ تردّ ٤٠٣ في حالتين
     * مختلفتين تماماً — «دورُك لا يشمل التحقيق» (من الوسيط) و«دورُك
     * يشمله ولا إذنَ سارياً» (من هنا). والرمزُ واحدٌ والمعنيان ضدّان:
     * الأوّلُ بابٌ مغلقٌ لا يُطرق، والثاني بابٌ لك مفتاحُه ولم تفتحه بعد.
     *
     * وأثرُ الخلط عمليّ: موظّفُ المخاطر يرى «لا تملك صلاحية هذا الإجراء»
     * فيذهب يطلب صلاحيّةً يملكها أصلاً، بدل أن يضغط «فتح إذن اطّلاع»
     * وهو على بُعد زرٍّ منه. (وهو نفسُ درس صفحة ٤١٩ في `CLAUDE.md`:
     * رسالةٌ لا تدلّ على سببها تُرسل من يصدّقها خلف عطلٍ لا وجود له.)
     *
     * فصارت **٤٢٣ Locked**: المورد قائمٌ وأنت أهلٌ له، وهو مقفولٌ حتّى
     * يُفتح إذنٌ بسببٍ وأجل.
     */
    public function operationalDetail(Request $request, int $id): JsonResponse
    {
        try {
            return $this->ok($this->center->operationalDetail(
                $request->user(), $this->center->merchant($id)));
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => 'ACCESS_GRANT_REQUIRED',
                'message' => $e->getMessage(),
                'meta' => [
                    'scope' => MerchantDataAccessGrant::SCOPE_OPERATIONAL,
                    'unlock' => 'اضغط «فتح إذن اطّلاع مؤقّت» في تبويب سجل التدقيق',
                ],
            ], 423);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  الأفعال — **كلٌّ بسببٍ وأثر**
    // ══════════════════════════════════════════════════════════════════

    public function toggleFreeze(Request $request, int $id): JsonResponse
    {
        return $this->act($request, function (string $reason) use ($request, $id) {
            $m = $this->center->merchant($id);

            if ((int) ($m->type ?? -1) === ADMIN_TYPE) {
                throw new DomainException('لا يُجمَّد حساب أدمن من هنا');
            }

            $was = (int) $m->is_active === 1;

            $this->actions->perform(
                actor: $request->user(),
                merchantUserId: $id,
                action: $was ? 'account.freeze' : 'account.unfreeze',
                reason: $reason,
                beforeState: ['label' => $was ? 'نشط' : 'مجمَّد', 'is_active' => $was],
                work: function () use ($m, $was) {
                    $m->is_active = $was ? 0 : 1;
                    $m->save();

                    return ['label' => $was ? 'مجمَّد' : 'نشط', 'is_active' => ! $was];
                },
                request: $request,
                target: 'account',
                targetId: $id,
            );

            return $this->ok(['is_active' => ! $was],
                $was ? 'جُمّد الحساب' : 'فُكّ التجميد');
        });
    }

    /** إنهاءُ كلّ الجلسات — **فعلٌ أمنيٌّ يُستعمل في التحقيق**. */
    public function revokeSessions(Request $request, int $id): JsonResponse
    {
        return $this->act($request, function (string $reason) use ($request, $id) {
            $ids = array_merge([$id],
                PosUser::where('merchant_user_id', $id)->whereNotNull('user_id')
                    ->pluck('user_id')->all());

            $before = UserLogHistory::whereIn('user_id', $ids)->where('is_active', true)->count();

            $this->actions->perform(
                actor: $request->user(),
                merchantUserId: $id,
                action: 'sessions.revoke',
                reason: $reason,
                beforeState: ['label' => "{$before} جلسة نشطة", 'active_sessions' => $before],
                work: function () use ($ids) {
                    $n = UserLogHistory::whereIn('user_id', $ids)
                        ->where('is_active', true)->update(['is_active' => false]);

                    return ['label' => 'لا جلسات نشطة', 'revoked' => $n];
                },
                request: $request,
                target: 'sessions',
            );

            return $this->ok(['revoked' => $before], "أُنهيت {$before} جلسة");
        });
    }

    public function toggleDevice(Request $request, int $id, int $deviceId): JsonResponse
    {
        return $this->act($request, function (string $reason) use ($request, $id, $deviceId) {
            $d = UserLogHistory::find($deviceId);
            if (! $d) {
                throw new DomainException('الجهاز غير موجود');
            }

            $wasBlocked = (bool) $d->is_blocked;

            $this->actions->perform(
                actor: $request->user(),
                merchantUserId: $id,
                action: $wasBlocked ? 'device.trust' : 'device.block',
                reason: $reason,
                beforeState: ['label' => $wasBlocked ? 'محظور' : 'مسموح'],
                work: function () use ($d, $wasBlocked, $reason, $request) {
                    $d->update([
                        'is_blocked' => ! $wasBlocked,
                        'blocked_at' => $wasBlocked ? null : now(),
                        'blocked_by_user_id' => $wasBlocked ? null : $request->user()->id,
                        'block_reason' => $wasBlocked ? null : $reason,
                    ]);

                    return ['label' => $wasBlocked ? 'مسموح' : 'محظور'];
                },
                request: $request,
                target: 'device',
                targetId: $deviceId,
            );

            return $this->ok([], $wasBlocked ? 'رُفع الحظر عن الجهاز' : 'حُظر الجهاز');
        });
    }

    /**
     * تعطيلُ موظّفٍ **لأمرٍ أمنيٍّ وحده**.
     *
     * **ولا يُعاد تفعيلُه من هنا** — إعادتُه شأنُ التاجر. فأميال تُوقف
     * عند الخطر ولا تُدير موظّفي غيرها.
     */
    public function disableStaff(Request $request, int $id, int $posId): JsonResponse
    {
        return $this->act($request, function (string $reason) use ($request, $id, $posId) {
            $pos = PosUser::where('id', $posId)->where('merchant_user_id', $id)->first();
            if (! $pos) {
                throw new DomainException('الموظّف غير موجود لدى هذا التاجر');
            }
            if (! $pos->is_active) {
                throw new DomainException('الموظّف معطَّل مسبقاً');
            }

            $this->actions->perform(
                actor: $request->user(),
                merchantUserId: $id,
                action: 'staff.disable',
                reason: $reason,
                beforeState: ['label' => 'نشط', 'name' => $pos->display_name],
                work: function () use ($pos) {
                    $pos->update(['is_active' => false]);

                    return ['label' => 'معطَّل'];
                },
                request: $request,
                target: 'staff',
                targetId: $posId,
            );

            return $this->ok([], 'عُطّل الموظّف لأمر أمني — وإعادته شأن التاجر');
        });
    }

    public function changePlan(Request $request, int $id): JsonResponse
    {
        return $this->act($request, function (string $reason) use ($request, $id) {
            $plan = (string) $request->input('plan');
            if (! in_array($plan, A::ALL_PLANS, true)) {
                throw new DomainException('باقة غير معروفة');
            }

            $p = MerchantProfile::where('user_id', $id)->first();
            if (! $p) {
                throw new DomainException('لا ملفّ تاجر لهذا الحساب');
            }

            $was = $p->subscription_plan ?? A::PLAN_FREE;
            $months = max(0, min(36, (int) $request->input('months', 0)));

            $this->actions->perform(
                actor: $request->user(),
                merchantUserId: $id,
                action: 'plan.change',
                reason: $reason,
                beforeState: ['label' => A::PLAN_LABELS[$was] ?? $was, 'plan' => $was],
                work: function () use ($p, $plan, $months, $reason) {
                    $this->access->updateMerchantPlan(
                        $p, $plan,
                        $months > 0 ? now()->addMonths($months) : null,
                        $reason,
                    );

                    return ['label' => A::PLAN_LABELS[$plan] ?? $plan, 'plan' => $plan];
                },
                request: $request,
                target: 'subscription',
            );

            $this->entitlements->forget($id);

            return $this->ok(['plan' => $plan],
                'صارت الباقة: ' . (A::PLAN_LABELS[$plan] ?? $plan));
        });
    }

    /** فتحُ إذنِ اطّلاعٍ على التفصيل التشغيليّ — **بأجلٍ ومرجعِ تذكرة**. */
    public function grantAccess(Request $request, int $id): JsonResponse
    {
        return $this->act($request, function (string $reason) use ($request, $id) {
            $grant = $this->actions->grantAccess(
                actor: $request->user(),
                merchantUserId: $id,
                scope: (string) $request->input('scope', MerchantDataAccessGrant::SCOPE_OPERATIONAL),
                reason: $reason,
                hours: (int) $request->input('hours', 4),
                ticketRef: $request->input('ticket_ref'),
                request: $request,
            );

            return $this->ok(
                ['reference' => $grant->reference, 'expires_at' => $grant->expires_at->format('Y-m-d H:i')],
                'فُتح إذن اطّلاع حتى ' . $grant->expires_at->format('H:i'));
        });
    }

    public function revokeAccess(Request $request, int $id, int $grantId): JsonResponse
    {
        return $this->act($request, function (string $reason) use ($request, $id, $grantId) {
            $grant = MerchantDataAccessGrant::where('id', $grantId)
                ->where('merchant_user_id', $id)->first();
            if (! $grant) {
                throw new DomainException('الإذن غير موجود');
            }

            $this->actions->revokeAccess($request->user(), $grant, $reason, $request);

            return $this->ok([], 'أُلغي الإذن');
        });
    }

    /** ملاحظةٌ إداريّةٌ على الملفّ — **تُسجَّل كأيّ فعل**. */
    public function addNote(Request $request, int $id): JsonResponse
    {
        return $this->act($request, function (string $reason) use ($request, $id) {
            $this->actions->perform(
                actor: $request->user(),
                merchantUserId: $id,
                action: 'note.add',
                reason: $reason,
                beforeState: [],
                work: fn () => ['label' => 'ملاحظة'],
                request: $request,
                target: 'note',
            );

            return $this->ok([], 'أُضيفت الملاحظة إلى السجل');
        });
    }

    /**
     * **فتحُ تذكرةٍ للتاجر من داخل مركزه** — AMIAL-MERCHANT-CENTER-002.
     *
     * كان القسمُ يعرض التذاكر ولا يفتح واحدة. فمن يقرأ شكوى تاجرٍ في
     * المركز كان يخرج منه إلى شاشةٍ أخرى ويبحث عن الحساب مرّةً ثانية —
     * وبينهما يضيع السياق الذي فتح المركزَ من أجله.
     *
     * **ولا يمرّ بـ`act()`**: سببُ الأفعال الخطِرة يُطلب لأنّها تغيّر
     * شيئاً على التاجر، والتذكرةُ لا تغيّر عليه شيئاً — موضوعُها هو
     * سببُها، ويُفحص طولُه في الخدمة. وطلبُ سببٍ فوق الموضوع يُنتج
     * حقلين يُكتب فيهما الشيءُ نفسه.
     */
    public function openTicket(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'category' => 'nullable|string',
            'priority' => 'nullable|string',
            'description' => 'nullable|string|max:5000',
            'transaction_ref' => 'nullable|string|max:40',
        ]);

        try {
            $ticket = $this->tickets->open(
                $request->user(),
                $this->center->merchant($id),
                $data,
            );
        } catch (DomainException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('تعذّر فتح التذكرة — حاول مرة أخرى', 500);
        }

        // **وتُسجَّل في سجلّ التاجر أيضاً** — فمن يقرأ تاريخ هذا الحساب
        // يرى أنّ أميال فتحت له تذكرةً، لا أن يبحث عنها في نظامٍ آخر.
        $this->actions->perform(
            actor: $request->user(),
            merchantUserId: $id,
            action: 'ticket.open',
            reason: $ticket->subject,
            beforeState: [],
            work: fn () => ['ticket' => $ticket->ticket_number],
            request: $request,
            target: 'ticket',
            targetId: (string) $ticket->id,
        );

        return $this->ok(['ticket_number' => $ticket->ticket_number], 'فُتحت التذكرة ' . $ticket->ticket_number);
    }
}
