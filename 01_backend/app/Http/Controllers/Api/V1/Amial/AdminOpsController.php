<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AmialMaintenanceMode;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-OPS-001 — قسم «التشغيل» في مركز الإعدادات (إدارة تشغيلية بلا باكند).
 *
 * Endpoints تحت /api/v1/amial/admin/ops (auth:api + صلاحية أدمن):
 *   GET  /status        ← حالة الصيانة + إعداد إصدار التطبيق
 *   POST /maintenance   ← تفعيل/إيقاف وضع الصيانة (+رسالة)
 *   POST /clear-cache   ← تنظيف كاش التطبيق
 *   POST /app-version   ← ضبط الإصدار الأدنى/الأحدث (+رسالة التحديث)
 *
 * عام (يقرأه التطبيق): GET /api/v1/amial/app-version
 */
class AdminOpsController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        return $this->ok([
            'maintenance' => AmialMaintenanceMode::config(),
            'app_version' => self::versionValues(),
        ]);
    }

    public function setMaintenance(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        $v = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:300'],
        ]);
        if ($v->fails()) return $this->validationError($v);

        $values = [
            'enabled' => $request->boolean('enabled') ? 1 : 0,
            'message' => $request->input('message')
                ?: 'النظام تحت الصيانة مؤقتاً — نعود قريباً',
        ];

        Setting::updateOrCreate(
            ['key_name' => 'maintenance_mode', 'settings_type' => 'ops_config'],
            ['key_name' => 'maintenance_mode', 'settings_type' => 'ops_config',
             'live_values' => $values, 'mode' => 'live', 'is_active' => 1]
        );

        return $this->ok(
            ['maintenance' => ['enabled' => $values['enabled'] === 1, 'message' => $values['message']]],
            'SAVED',
            $values['enabled'] === 1 ? 'تم تفعيل وضع الصيانة' : 'تم إيقاف وضع الصيانة'
        );
    }

    public function clearCache(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        Artisan::call('cache:clear');

        return $this->ok([], 'CLEARED', 'تم تنظيف الكاش');
    }

    public function setAppVersion(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);

        $v = Validator::make($request->all(), [
            'min_version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'],
            'latest_version' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/'],
            'message' => ['nullable', 'string', 'max:300'],
        ]);
        if ($v->fails()) return $this->validationError($v);

        $values = [
            'min_version' => $request->input('min_version'),
            'latest_version' => $request->input('latest_version') ?: $request->input('min_version'),
            'message' => $request->input('message') ?: 'يتوفّر تحديث مهم — يرجى تحديث التطبيق',
        ];

        Setting::updateOrCreate(
            ['key_name' => 'app_version', 'settings_type' => 'ops_config'],
            ['key_name' => 'app_version', 'settings_type' => 'ops_config',
             'live_values' => $values, 'mode' => 'live', 'is_active' => 1]
        );

        return $this->ok(['app_version' => $values], 'SAVED', 'تم حفظ إعداد الإصدار');
    }

    /** GET /api/v1/amial/app-version — عام؛ يفحصه التطبيق عند الإقلاع لفرض التحديث. */
    public function publicAppVersion(): JsonResponse
    {
        return $this->ok(['app_version' => self::versionValues()]);
    }

    private static function versionValues(): array
    {
        try {
            $row = config_settings('app_version', 'ops_config');
            $values = $row && $row->live_values ? (array) json_decode($row->live_values, true) : [];
        } catch (\Throwable $e) {
            $values = [];
        }
        return array_merge([
            'min_version' => '0.0.0',
            'latest_version' => '0.0.0',
            'message' => '',
        ], $values);
    }

    // ============ Helpers (نفس نمط بقية متحكّمات الأدمن) ============

    private function isAdmin(?User $user): bool
    {
        if (!$user) return false;
        return (int) ($user->type ?? -1) === 0
            || in_array($user->role, [A::ROLE_ADMIN, 'super_admin'], true); // AMIAL-FIX(C1): لا النوع 1 (وكيل)
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

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object) [],
        ], 422);
    }
}
