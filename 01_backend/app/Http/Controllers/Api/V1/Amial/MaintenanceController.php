<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Exceptions\FeatureHasFundsException;
use App\Exceptions\FeatureBalanceUnknownException;
use App\Models\FeatureFlag;
use App\Services\ControlCenterService;
use App\Http\Controllers\Controller;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-MAINT-001 — لوحة «الصيانة الأولية»: تشغيل/إيقاف الميزات من لوحة الأدمن.
 *
 * قاعدة الأمان: لا تُغلق ميزة إلا إذا كان تراكمها المالي = صفر.
 */
class MaintenanceController extends Controller
{
    public function __construct(
        private readonly FeatureFlagService $features,
        private readonly ControlCenterService $controls,
    ) {}

    /** GET /admin/maintenance — كل الميزات + حالتها + تراكمها المالي الحيّ. */
    public function index(Request $request): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        return $this->ok(['features' => $this->features->overview()]);
    }

    /** POST /admin/maintenance/{key}/enable  {note?} */
    public function enable(Request $request, string $key): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        try {
            $this->denyLegacyHighRisk($key);
            $flag = $this->features->enable($request->user(), $key, $request->input('note'));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 'يستلزم هذا الإجراء موافقة مستقلة من مركز التحكم.', 409);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('FEATURE_NOT_FOUND', 'الميزة غير موجودة', 404);
        }

        return $this->ok(['feature' => $flag], 'OK', "تم تشغيل «{$flag->name_ar}»");
    }

    /** POST /admin/maintenance/{key}/disable  {note?} */
    public function disable(Request $request, string $key): JsonResponse
    {
        if ($resp = $this->requireAdmin($request)) return $resp;

        try {
            // Keep the historical funds error for existing API clients, even when
            // the governance approval will be required after funds reach zero.
            $out = $this->features->outstandingBalance($key);
            if ($out['count'] < 0) throw new FeatureBalanceUnknownException($key);
            if (bccomp($out['amount'], '0', 4) > 0) throw new FeatureHasFundsException($key, $out['amount'], $out['count']);
            $this->denyLegacyHighRisk($key);
            $flag = $this->features->disable($request->user(), $key, $request->input('note'));
        } catch (FeatureBalanceUnknownException $e) {
            return $this->error('BALANCE_UNKNOWN', $e->getMessage(), 409);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 'يستلزم هذا الإجراء موافقة مستقلة من مركز التحكم.', 409);
        } catch (FeatureHasFundsException $e) {
            // الحارس المالي منع الإيقاف
            return new JsonResponse([
                'success' => false,
                'code' => 'FEATURE_HAS_FUNDS',
                'message' => $e->getMessage(),
                'errors' => (object) [],
                'meta' => [
                    'feature' => $e->featureKey,
                    'outstanding_amount' => $e->outstandingAmount,
                    'items_count' => $e->itemsCount,
                ],
            ], 409);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('FEATURE_NOT_FOUND', 'الميزة غير موجودة', 404);
        }

        return $this->ok(['feature' => $flag], 'OK', "تم إيقاف «{$flag->name_ar}» للصيانة");
    }

    // Old web/API maintenance endpoints must not bypass maker-checker on
    // financial, merchant or core features. Use Control Center for those.
    private function denyLegacyHighRisk(string $key): void
    {
        $flag = FeatureFlag::where('key', $key)->firstOrFail();
        if ($this->controls->needsApproval($flag)) {
            throw new \DomainException('CONTROL_CENTER_APPROVAL_REQUIRED');
        }
    }

    // ================= Helpers =================

    private function requireAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user && ((int) ($user->type ?? -1) === 0
            || in_array($user->role ?? null, ['admin', 'super_admin'], true));
        $isAdmin = $isAdmin && $user->hasPlatformPermission('platform.settings.update');

        return $isAdmin ? null : $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
