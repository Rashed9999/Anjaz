<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\Fuel\FuelDelivery;
use App\Models\Fuel\FuelNozzle;
use App\Models\Fuel\FuelPriceVersion;
use App\Models\Fuel\FuelStockReconciliation;
use App\Models\Fuel\FuelTank;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelShift;
use App\Models\FuelStation;
use App\Models\Merchant\MerchantRole;
use App\Models\User;
use App\Services\Fuel\FuelDeliveryService;
use App\Services\Fuel\FuelPriceService;
use App\Services\Fuel\FuelShiftCashService;
use App\Services\Fuel\FuelTankService;
use App\Services\Fuel\FuelWetStockService;
use App\Services\Merchant\MerchantPermissionService;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-FUEL-VERTICAL-001 — نقاطُ نهاية المراحل ١–٧.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وكلُّ فعلٍ خلفَه صلاحيّة.** إخفاءُ الزرّ في الواجهة ليس أماناً: من
 * يعرف المسار ينادي بلا زرّ. فالسؤالُ يُطرح هنا قبل كلّ فعل، بنطاقِه
 * وحدِّه.
 */
class FuelVerticalController extends Controller
{
    public function __construct(
        private readonly FuelTankService $tanks,
        private readonly FuelDeliveryService $deliveries,
        private readonly FuelWetStockService $wetStock,
        private readonly FuelPriceService $prices,
        private readonly FuelShiftCashService $shiftCash,
        private readonly MerchantPermissionService $perm,
    ) {
    }

    // ══════════════════════════════════════════════════════════════════
    //  أدوات
    // ══════════════════════════════════════════════════════════════════

    private function actor(Request $request): User
    {
        return $request->user();
    }

    /**
     * محطّةُ المستخدم — **من هويّته لا من الطلب**.
     *
     * معرّفٌ يأتي من المتصفّح يمكن تغييره، وما لا يُقبل من الطلب لا يحتاج
     * فحصاً. (القاعدة الثامنة.)
     */
    private function station(Request $request): FuelStation
    {
        $merchantId = $this->perm->merchantIdFor($this->actor($request));

        $station = FuelStation::where('merchant_user_id', $merchantId)->first();

        if ($station) {
            return $station;
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-VERTICAL-BOOTSTRAP-001 — **حسابٌ يُنشأ «محطّة وقود» ولا
        // محطّةَ له.**
        //
        // لوحةُ الإدارة تكتب `business_type = fuel` في ملفّ التاجر ولا
        // تُنشئ صفَّ `fuel_stations`. فالتاجرُ يدخل التطبيق، وتفتح شاشةُ
        // «لوحة المحطة»، وتردّ: **«لا توجد محطة مرتبطة بهذا الحساب»**.
        //
        // وهي رسالةٌ صحيحةٌ وعديمةُ الفائدة: الحسابُ أُنشئ محطّةً قبل
        // دقائق، ولا شيءَ في التطبيق يُنشئ المحطّة، ولا في اللوحة زرٌّ
        // يُنشئها. **بابٌ مسدود.**
        //
        // ولا خطأَ في أيّ سجلّ: الإنشاءُ نجح، والملفُّ صحيح، والدخولُ
        // صحيح — والقطاعُ وحدَه بلا سجلّ.
        //
        // **وثلاثةُ متحكّماتٍ أخرى تُنشئ عند الحاجة** (`FuelStationController`
        // و`PharmacyService` و`WholesaleService` كلُّها `getOrCreate`).
        // وهذا وحدَه كان يرفض — سلوكان لقطاعٍ واحد.
        //
        // فصار يُنشئها كأخواته. **والمنبعُ أُصلح أيضاً** في
        // `AdminHubController`: القطاعُ يُبنى مع الحساب لا عند أوّل فتح.
        $merchant = User::find($merchantId);

        if (! $merchant) {
            throw new DomainException('الحساب غير موجود');
        }

        return app(\App\Services\FuelStationService::class)->getOrCreateStation($merchant);
    }

    private function ok(array $data = [], string $message = ''): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    private function fail(string $message, int $code = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null], $code);
    }

    /** يلفّ كلَّ فعلٍ: يفحص الصلاحيّة ويحوّل خطأَ المجال إلى ردٍّ مفهوم. */
    private function guarded(
        Request $request, string $permission, callable $fn,
        ?string $amount = null, array $context = [],
    ): JsonResponse {
        try {
            $this->perm->assert($this->actor($request), $permission, $context, $amount);

            return $fn();
        } catch (DomainException|\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('تعذّر إتمام العملية — حاول مرة أخرى', 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  الخزّانات والمسدسات
    // ══════════════════════════════════════════════════════════════════

    public function tanks(Request $request): JsonResponse
    {
        return $this->guarded($request, P::FUEL_TANK_VIEW, function () use ($request) {
            return $this->ok(['tanks' => $this->tanks->overview($this->station($request))]);
        });
    }

    public function addTank(Request $request): JsonResponse
    {
        return $this->guarded($request, P::FUEL_TANK_MANAGE, function () use ($request) {
            $tank = $this->tanks->addTank($this->station($request), $request->all());

            return $this->ok(['tank_id' => $tank->id], 'أُضيف الخزان');
        });
    }

    public function recordDip(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::FUEL_DIP_RECORD, function () use ($request, $id) {
            $tank = $this->ownTank($request, $id);

            $dip = $this->tanks->recordDip(
                $tank, $this->actor($request),
                (string) $request->input('dip_liters'),
                (string) $request->input('dip_type', 'spot'),
                $request->input('shift_id') ? (int) $request->input('shift_id') : null,
                $request->input('note'),
                $request->input('temperature_c'),
            );

            return $this->ok([
                'dip_id' => $dip->id,
                // **يُعاد الفرقُ فوراً**: من يقيس يريد أن يعرف الآن.
                'dip_vs_book' => bcsub((string) $dip->dip_liters, (string) $tank->book_liters, 3),
            ], 'سُجّل القياس');
        });
    }

    public function addNozzle(Request $request, int $pumpId): JsonResponse
    {
        return $this->guarded($request, P::FUEL_PUMP_MANAGE, function () use ($request, $pumpId) {
            $station = $this->station($request);
            $pump = FuelPump::where('id', $pumpId)->where('station_id', $station->id)->first();

            if (! $pump) {
                return $this->fail('المضخة غير موجودة');
            }

            $n = $this->tanks->addNozzle($pump, $request->all());

            return $this->ok(['nozzle_id' => $n->id], 'أُضيف المسدس');
        });
    }

    public function linkNozzleToTank(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::FUEL_PUMP_MANAGE, function () use ($request, $id) {
            $station = $this->station($request);

            $nozzle = FuelNozzle::whereHas('pump',
                fn ($q) => $q->where('station_id', $station->id))->find($id);

            if (! $nozzle) {
                return $this->fail('المسدس غير موجود');
            }

            $this->tanks->linkNozzleToTank($nozzle, (int) $request->input('tank_id'));

            return $this->ok([], 'رُبط المسدس بالخزان');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  التوريدات
    // ══════════════════════════════════════════════════════════════════

    public function deliveries(Request $request): JsonResponse
    {
        return $this->guarded($request, P::FUEL_DELIVERY_RECEIVE, function () use ($request) {
            $rows = FuelDelivery::where('station_id', $this->station($request)->id)
                ->with(['tank:id,tank_number,name', 'supplier:id,name'])
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn (FuelDelivery $d) => [
                    'id' => (int) $d->id,
                    'tank' => $d->tank->name ?? "خزان {$d->tank?->tank_number}",
                    'supplier' => $d->supplier->name ?? '—',
                    'quantity_liters' => (string) $d->quantity_liters,
                    'status' => $d->status,
                    'status_ar' => match ($d->status) {
                        'received' => 'مستلم',
                        'verified' => 'مُتحقَّق',
                        'posted' => 'مُرحَّل',
                        'rejected' => 'مرفوض',
                    },
                    'measured_variance' => $d->measuredVariance(),
                    'received_at' => $d->received_at?->toIso8601String(),
                    'posted_at' => $d->posted_at?->toIso8601String(),
                ])->all();

            return $this->ok(['deliveries' => $rows]);
        });
    }

    public function receiveDelivery(Request $request): JsonResponse
    {
        return $this->guarded($request, P::FUEL_DELIVERY_RECEIVE, function () use ($request) {
            $station = $this->station($request);
            $tank = $this->ownTank($request, (int) $request->input('tank_id'));

            $d = $this->deliveries->receive($station, $tank, $this->actor($request), $request->all());

            return $this->ok(['delivery_id' => $d->id],
                'سُجّل التوريد — لن يرتفع المخزون قبل التحقق والترحيل');
        });
    }

    public function verifyDelivery(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::FUEL_DELIVERY_VERIFY, function () use ($request, $id) {
            $d = $this->ownDelivery($request, $id);

            $this->deliveries->verify(
                $d, $this->actor($request),
                $request->input('dip_before_liters'),
                $request->input('dip_after_liters'),
            );

            return $this->ok([], 'طابق القياسُ الفاتورة — جاهز للترحيل');
        });
    }

    public function postDelivery(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::FUEL_DELIVERY_POST, function () use ($request, $id) {
            $d = $this->ownDelivery($request, $id);

            $this->deliveries->post($d, $this->actor($request));

            return $this->ok([], 'رُحّل التوريد وارتفع المخزون');
        });
    }

    public function addSupplier(Request $request): JsonResponse
    {
        return $this->guarded($request, P::FUEL_DELIVERY_RECEIVE, function () use ($request) {
            $s = $this->deliveries->addSupplier($this->actor($request), $request->all());

            return $this->ok(['supplier_id' => $s->id], 'أُضيف المورد');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  المصالحة
    // ══════════════════════════════════════════════════════════════════

    public function reconciliationPreview(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::FUEL_RECON_VIEW, function () use ($request, $id) {
            $tank = $this->ownTank($request, $id);

            $from = $request->input('from')
                ? new \DateTimeImmutable((string) $request->input('from'))
                : now()->startOfDay()->toDateTimeImmutable();

            $to = $request->input('to')
                ? new \DateTimeImmutable((string) $request->input('to'))
                : now()->toDateTimeImmutable();

            return $this->ok($this->wetStock->compute(
                $tank, $from, $to, $request->input('actual_closing_liters')));
        });
    }

    public function reconcile(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::FUEL_RECON_VIEW, function () use ($request, $id) {
            $tank = $this->ownTank($request, $id);

            $recon = $this->wetStock->reconcile(
                $tank, $this->actor($request),
                new \DateTimeImmutable((string) $request->input('from', now()->startOfDay())),
                new \DateTimeImmutable((string) $request->input('to', now())),
                $request->input('actual_closing_liters'),
                $request->input('shift_id') ? (int) $request->input('shift_id') : null,
            );

            return $this->ok([
                'recon_id' => $recon->id,
                'variance_liters' => (string) $recon->variance_liters,
                'status' => $recon->status,
            ], $recon->status === 'within_tolerance'
                ? 'الفرق ضمن الحدّ المقبول'
                : 'فرقٌ يتجاوز الحدّ — فُتح تحقيق');
        });
    }

    public function variances(Request $request): JsonResponse
    {
        return $this->guarded($request, P::FUEL_RECON_VIEW, function () use ($request) {
            $rows = FuelStockReconciliation::where('station_id', $this->station($request)->id)
                ->with('tank:id,tank_number,name')
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn (FuelStockReconciliation $r) => [
                    'id' => (int) $r->id,
                    'tank' => $r->tank->name ?? "خزان {$r->tank?->tank_number}",
                    'period_end' => $r->period_end?->toIso8601String(),
                    'expected' => (string) $r->expected_closing_liters,
                    'actual' => (string) $r->actual_closing_liters,
                    'variance_liters' => (string) $r->variance_liters,
                    'variance_percent' => (string) $r->variance_percent,
                    'is_loss' => $r->isLoss(),
                    'status' => $r->status,
                ])->all();

            // **واللتراتُ غيرُ المنسوبة تُقال** — مسدسٌ بلا خزّانٍ يُخرج
            // لتراتِه من المعادلة كلِّها، فيظهر فائضٌ يُقرأ ربحاً.
            $unattributed = $this->wetStock->unattributedLiters(
                $this->station($request),
                now()->subDays(30)->toDateTimeImmutable(),
                now()->toDateTimeImmutable(),
            );

            return $this->ok([
                'variances' => $rows,
                'unattributed_liters_30d' => $unattributed,
                'unattributed_note' => bccomp($unattributed, '0', 3) > 0
                    ? 'لترات بيعت من مسدسات غير مربوطة بخزانات — اربطها وإلا بقيت خارج المصالحة'
                    : null,
            ]);
        });
    }

    public function resolveVariance(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::FUEL_RECON_RESOLVE, function () use ($request, $id) {
            $recon = FuelStockReconciliation::where('station_id', $this->station($request)->id)
                ->find($id);

            if (! $recon) {
                return $this->fail('السجل غير موجود');
            }

            $this->wetStock->resolve(
                $recon, $this->actor($request),
                (string) $request->input('note', ''),
                (string) $request->input('status', 'resolved'),
            );

            return $this->ok([], 'أُغلق التحقيق');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  الأسعار
    // ══════════════════════════════════════════════════════════════════

    public function proposePrice(Request $request): JsonResponse
    {
        return $this->guarded($request, P::FUEL_PRICE_PROPOSE, function () use ($request) {
            $station = $this->station($request);

            $product = FuelProduct::where('station_id', $station->id)
                ->find($request->input('fuel_product_id'));

            if (! $product) {
                return $this->fail('نوع الوقود غير موجود');
            }

            $v = $this->prices->propose(
                $product, $this->actor($request),
                (string) $request->input('price_per_liter'),
                (string) $request->input('reason', ''),
                $request->input('effective_from')
                    ? new \DateTimeImmutable((string) $request->input('effective_from'))
                    : null,
            );

            return $this->ok(['version_id' => $v->id],
                'اقتُرح السعر — لن يسري قبل الاعتماد');
        });
    }

    public function pendingPrices(Request $request): JsonResponse
    {
        return $this->guarded($request, P::FUEL_PRICE_VIEW, function () use ($request) {
            return $this->ok(['pending' => $this->prices->pending($this->station($request)->id)]);
        });
    }

    public function approvePrice(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::FUEL_PRICE_APPROVE, function () use ($request, $id) {
            $v = FuelPriceVersion::where('station_id', $this->station($request)->id)->find($id);

            if (! $v) {
                return $this->fail('النسخة غير موجودة');
            }

            $this->prices->approve($v, $this->actor($request));

            return $this->ok([], 'اعتُمد السعر وسرى');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  نقدُ الوردية
    // ══════════════════════════════════════════════════════════════════

    public function recordCashMovement(Request $request, int $shiftId): JsonResponse
    {
        return $this->guarded($request, P::CASH_MOVE,
            function () use ($request, $shiftId) {
                $shift = FuelShift::where('station_id', $this->station($request)->id)
                    ->find($shiftId);

                if (! $shift) {
                    return $this->fail('الوردية غير موجودة');
                }

                $m = $this->shiftCash->record(
                    $shift, $this->actor($request),
                    (string) $request->input('direction'),
                    (string) $request->input('reason'),
                    (string) $request->input('amount'),
                    $request->input('note'),
                    $request->input('reference'),
                );

                return $this->ok([
                    'movement_id' => $m->id,
                    'summary' => $this->shiftCash->summarise($shift),
                ], 'سُجّلت الحركة');
            },
            amount: (string) $request->input('amount', '0'),
        );
    }

    public function shiftCash(Request $request, int $shiftId): JsonResponse
    {
        return $this->guarded($request, P::CASH_COUNT, function () use ($request, $shiftId) {
            $shift = FuelShift::where('station_id', $this->station($request)->id)->find($shiftId);

            if (! $shift) {
                return $this->fail('الوردية غير موجودة');
            }

            return $this->ok([
                'movements' => $this->shiftCash->movements($shift),
                'summary' => $this->shiftCash->summarise($shift),
            ]);
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  الأدوار والصلاحيّات
    // ══════════════════════════════════════════════════════════════════

    /**
     * **صلاحيّاتي** — تُبنى منها القائمة الجانبيّة في التطبيق.
     *
     * ولا تحتاج صلاحيّةً: كلُّ مستخدمٍ يسأل عن نفسه.
     */
    public function myPermissions(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        return $this->ok([
            'is_owner' => $this->perm->isOwner($user),
            'permissions' => $this->perm->effective($user),
            'catalogue' => P::catalogue(),
        ]);
    }

    public function roles(Request $request): JsonResponse
    {
        return $this->guarded($request, P::ROLE_VIEW, function () use ($request) {
            $merchantId = $this->perm->merchantIdFor($this->actor($request));

            $roles = MerchantRole::where('merchant_user_id', $merchantId)
                ->with('permissions')->orderBy('id')->get()
                ->map(fn (MerchantRole $r) => [
                    'id' => (int) $r->id,
                    'code' => $r->code,
                    'name_ar' => $r->name_ar,
                    'is_system' => (bool) $r->is_system,
                    'is_active' => (bool) $r->is_active,
                    'permissions' => $r->permissions->map(fn ($p) => [
                        'code' => $p->permission_code,
                        'scope_type' => $p->scope_type,
                        'max_amount' => $p->max_amount ? (string) $p->max_amount : null,
                        'approval' => $p->approval,
                    ])->all(),
                ])->all();

            return $this->ok(['roles' => $roles, 'catalogue' => P::catalogue()]);
        });
    }

    public function seedRoles(Request $request): JsonResponse
    {
        return $this->guarded($request, P::ROLE_MANAGE, function () use ($request) {
            $merchant = User::findOrFail(
                $this->perm->merchantIdFor($this->actor($request)));

            $roles = $this->perm->seedFuelRoles($merchant);

            return $this->ok(['count' => count($roles)],
                'أُنشئت الأدوار الستة — عدّلها كما تشاء');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  مركزُ العمليّات
    // ══════════════════════════════════════════════════════════════════

    /**
     * الحالةُ الآن — مضخّاتٌ وخزّاناتٌ ووردية ومعلَّقات في نداءٍ واحد.
     */
    public function operationsCenter(Request $request): JsonResponse
    {
        return $this->guarded($request, P::FUEL_PUMP_VIEW, function () use ($request) {
            $station = $this->station($request);

            $shift = FuelShift::where('station_id', $station->id)
                ->where('status', 'open')->first();

            $pumps = FuelPump::where('station_id', $station->id)
                ->with('nozzles')->orderBy('pump_number')->get()
                ->map(fn (FuelPump $p) => [
                    'id' => (int) $p->id,
                    'pump_number' => (int) $p->pump_number,
                    'is_active' => (bool) $p->is_active,
                    'nozzles' => $p->nozzles->map(fn (FuelNozzle $n) => [
                        'id' => (int) $n->id,
                        'nozzle_number' => (int) $n->nozzle_number,
                        'tank_id' => $n->tank_id ? (int) $n->tank_id : null,
                        // **مسدسٌ بلا خزّان يُقال صراحةً** — لتراتُه خارج
                        // المصالحة، وسكوتُنا عنه يُنتج فائضاً يُقرأ ربحاً.
                        'unlinked' => $n->tank_id === null,
                        'meter' => (string) $n->current_meter_reading,
                    ])->all(),
                ])->all();

            $unlinked = collect($pumps)->flatMap(fn ($p) => $p['nozzles'])
                ->where('unlinked', true)->count();

            return $this->ok([
                'station' => ['id' => $station->id, 'name' => $station->station_name],
                'shift' => $shift ? [
                    'id' => (int) $shift->id,
                    'opened_at' => $shift->opened_at?->toIso8601String(),
                    'cash' => $this->shiftCash->summarise($shift),
                ] : null,
                'shift_note' => $shift ? null : 'لا وردية مفتوحة — لا بيع قبل فتحها',
                'pumps' => $pumps,
                'tanks' => $this->tanks->overview($station),
                'unlinked_nozzles' => $unlinked,
                'pending_deliveries' => FuelDelivery::where('station_id', $station->id)
                    ->whereIn('status', ['received', 'verified'])->count(),
                'open_variances' => FuelStockReconciliation::where('station_id', $station->id)
                    ->where('status', 'investigating')->count(),
                'pending_prices' => FuelPriceVersion::where('station_id', $station->id)
                    ->where('status', 'pending_approval')->count(),
            ]);
        });
    }

    // ══════════════════════════════════════════════════════════════════

    private function ownTank(Request $request, int $id): FuelTank
    {
        $tank = FuelTank::where('station_id', $this->station($request)->id)->find($id);

        if (! $tank) {
            throw new DomainException('الخزان غير موجود');
        }

        return $tank;
    }

    private function ownDelivery(Request $request, int $id): FuelDelivery
    {
        $d = FuelDelivery::where('station_id', $this->station($request)->id)->find($id);

        if (! $d) {
            throw new DomainException('التوريد غير موجود');
        }

        return $d;
    }
}
