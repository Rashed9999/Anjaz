<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Models\Settlement;
use App\Models\SettlementPartner;
use App\Services\UniversalSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettlementController extends AmialApiController
{
    public function __construct(
        private readonly UniversalSettlementService $svc,
    ) {}

    /**
     * AMIAL-SETTLEMENT-PANEL-001 — لوحة تسويات الشركاء.
     *
     * لوحة «التسويات» الموجودة في القائمة الجانبية تعرض `AgentSettlement`
     * (تسويات الوكلاء). أمّا `Settlement` — تسويات الشركاء، وهي التي بُنيت
     * عليها **الموافقة المزدوجة** وفصلُ المهام — فسطحها الوحيد كان الـAPI.
     *
     * فبقيت الموافقة المزدوجة ضابطاً لا يراه أحد: تسويةٌ فوق الحدّ توافق
     * عليها مرّة فتبقى `pending_approval` بلا ما يقول إنها تنتظر ثانياً، ومن
     * لا يعرف يظنّ الاعتماد فشل فيعيد الضغط — والنظام يرفضه لأنّه هو نفسه.
     */
    public function page()
    {
        return view('admin-views.amial.settlements_partners.index');
    }

    public function dashboard(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }
        return $this->ok($this->svc->dashboard());
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        // المعتمِدان يُحمَّلان مع القائمة: من يفتح الطابور يحتاج أن يعرف أيّ
        // تسويةٍ تنتظر توقيعاً ثانياً وممّن جاء الأوّل — لا أن يفتح كلّ صفٍّ
        // على حدة ليكتشف ذلك.
        $q = Settlement::with([
            'partner:id,name_ar,code', 'creator:id,f_name,l_name',
            'approver:id,f_name,l_name', 'secondApprover:id,f_name,l_name',
        ])->orderByDesc('created_at');

        if ($request->filled('status'))     $q->where('status', $request->query('status'));
        if ($request->filled('partner_id')) $q->where('destination_partner_id', $request->query('partner_id'));
        if ($request->filled('from'))       $q->where('created_at', '>=', $request->query('from'));
        if ($request->filled('to'))         $q->where('created_at', '<=', $request->query('to'));

        $perPage = min(100, max(10, (int) $request->query('per_page', 20)));
        $data    = $q->paginate($perPage);

        // ══════════════════════════════════════════════════════════════════
        // AMIAL-SETTLE-SECOND-SIG-001 — **قاعدةٌ ماليّةٌ مكتوبةٌ أربعَ مرّات.**
        //
        // `UniversalSettlementService::awaitingSecondApproval` مبنيّةٌ
        // **وبصفرِ مُنادٍ**، والشاشةُ تُعيد اشتقاقَها في جافاسكربت **ثلاثَ
        // مرّات** (شارةُ العدّ، ولونُ الصفّ، وخليّةُ التوقيعين).
        //
        // **والنسخُ الثلاثُ تخالف الأصلَ في شرطٍ واحد:**
        //
        //     PHP : approvals_required >= 2  &&  approved_by
        //           &&  ! second_approved_by  &&  **status = PENDING_APPROVAL**
        //     JS  : الثلاثةُ الأُوَل فقط
        //
        // و`reject()` **لا يمسح `approved_by`**. فتسويةٌ وُقّعت مرّةً ثمّ
        // رُفضت تبقى في الشاشة «بانتظار التوقيع الثاني»، **وتُعدّ في
        // الشارة العليا**. فيفتح مسؤولُ المال طابوراً فيه ما ليس فيه.
        //
        // وهو ما تمنعه `amial-admin-command` بنصّها: «لوحةُ الإدارة يجب
        // ألّا تصير مصدرَ حقيقةٍ ماليّةٍ ثانياً». فيُحسَب هنا مرّةً
        // **من الخدمة نفسِها**، وتقرأ الشاشةُ الحقلَ ولا تشتقّ.
        // ══════════════════════════════════════════════════════════════════
        $svc = app(\App\Services\UniversalSettlementService::class);

        $items = collect($data->items())->map(function (Settlement $s) use ($svc) {
            $row = $s->toArray();
            $row['awaiting_second_approval'] = $svc->awaitingSecondApproval($s);

            return $row;
        })->all();

        return $this->ok([
            'settlements' => $items,
            'pagination'  => [
                'current_page' => $data->currentPage(),
                'last_page'    => $data->lastPage(),
                'total'        => $data->total(),
                'per_page'     => $data->perPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $v = Validator::make($request->all(), [
            'source_account_id'   => 'required|integer|exists:ledger_accounts,id',
            'partner_id'          => 'required|integer|exists:settlement_partners,id',
            'amount'              => 'required|numeric|min:1',
            'currency'            => 'sometimes|string|size:3',
            'destination_account' => 'sometimes|nullable|string|max:120',
            'notes'               => 'sometimes|nullable|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $settlement = $this->svc->create(
                sourceAccountId: (int) $request->input('source_account_id'),
                partnerId:       (int) $request->input('partner_id'),
                amount:          (string) $request->input('amount'),
                actor:           $request->user(),
                options:         $request->only(['currency', 'destination_account', 'notes']),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('FAILED', $e->getMessage(), 500);
        }

        return $this->ok(['settlement' => $settlement->load('partner')], 'CREATED', 'تمّ إنشاء طلب التسوية', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $settlement = Settlement::with([
            'partner', 'sourceAccount', 'creator:id,f_name,l_name',
            'approver:id,f_name,l_name', 'completer:id,f_name,l_name',
            'auditLogs.actor:id,f_name,l_name',
        ])->find($id);

        if (!$settlement) return $this->error('NOT_FOUND', 'التسوية غير موجودة', 404);

        return $this->ok(['settlement' => $settlement]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, fn($s, $u) => $this->svc->submit($s, $u));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, fn($s, $u) =>
            $this->svc->approve($s, $u, $request->input('notes')));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:10|max:500']);
        if ($v->fails()) return $this->validationError($v);

        return $this->transition($request, $id, fn($s, $u) =>
            $this->svc->reject($s, $u, $request->input('reason')));
    }

    public function process(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, fn($s, $u) => $this->svc->startProcessing($s, $u));
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'external_reference' => 'required|string|min:3|max:100',
            'external_bank_ref'  => 'sometimes|nullable|string|max:100',
            'attachment_path'    => 'sometimes|nullable|string|max:255',
            'notes'              => 'sometimes|nullable|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        return $this->transition($request, $id, fn($s, $u) => $this->svc->complete(
            settlement:        $s, actor: $u,
            externalReference: $request->input('external_reference'),
            externalBankRef:   $request->input('external_bank_ref'),
            attachmentPath:    $request->input('attachment_path'),
            notes:             $request->input('notes'),
        ));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:500']);
        if ($v->fails()) return $this->validationError($v);

        return $this->transition($request, $id, fn($s, $u) =>
            $this->svc->cancel($s, $u, $request->input('reason')));
    }

    public function partners(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }
        return $this->ok(['partners' => SettlementPartner::where('is_active', true)->orderBy('name_ar')->get()]);
    }

    public function revenueWallet(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }
        $wallet = $this->svc->getOrCreateRevenueWallet();
        return $this->ok([
            'account_code'    => $wallet->account_code,
            'name'            => $wallet->name_ar,
            'current_balance' => (float) $wallet->current_balance,
            'currency'        => 'YER',
        ]);
    }

    private function transition(Request $request, int $id, callable $fn): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $settlement = Settlement::find($id);
        if (!$settlement) return $this->error('NOT_FOUND', 'التسوية غير موجودة', 404);

        try {
            $updated = $fn($settlement, $request->user());
            return $this->ok(
                ['settlement' => $updated->load('partner')], 'OK',
                "تمّ تحديث حالة التسوية إلى: {$updated->statusLabel()}"
            );
        } catch (\LogicException $e) {
            return $this->error('INVALID_TRANSITION', $e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('FAILED', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('ERROR', 'خطأ غير متوقّع: ' . $e->getMessage(), 500);
        }
    }
}
