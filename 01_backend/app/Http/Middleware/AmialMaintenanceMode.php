<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-OPS-001 — وضع الصيانة المُدار من لوحة الأدمن (بلا أوامر باكند).
 *
 * يُفعَّل من «مركز الإعدادات» (addon_settings: maintenance_mode/ops_config).
 * عند التفعيل: كل طلبات الـ API تُرفض بـ 503 + رسالة، **ما عدا**:
 *   - مسارات الدخول/المصادقة (ليتمكّن الأدمن من الدخول وإيقاف الصيانة)
 *   - مسارات الأدمن /admin/ (محمية أصلاً بـ auth + صلاحية)
 *   - ping/health (للمراقبة الخارجية)
 *   - أي مستخدم مصادَق بصلاحية أدمن
 *
 * آمن بالتصميم: أي خطأ في القراءة (جدول غائب…) → مرور طبيعي (لا يُقفل النظام خطأً).
 */
class AmialMaintenanceMode
{
    private const ALLOW_PATTERNS = [
        'api/v1/amial/admin/',   // لوحة الأدمن (لإيقاف الصيانة)
        'api/v1/amial/ping',
        'api/v1/auth/',          // الدخول الموحّد
        'api/v1/customer/auth/',
        'api/v1/agent/auth/',
        'health',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $config = self::config();
        if (!$config['enabled']) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');
        foreach (self::ALLOW_PATTERNS as $allowed) {
            if (str_contains($path, $allowed)) {
                return $next($request);
            }
        }

        // الأدمن المصادَق يمرّ دائماً
        try {
            $user = $request->user('api') ?? $request->user();
            if ($user && ($user->role === 'admin' || (int) ($user->type ?? -1) === 1)) {
                return $next($request);
            }
        } catch (\Throwable $e) {
            // فشل حلّ المستخدم لا يغيّر القرار
        }

        return new JsonResponse([
            'success' => false,
            'code' => 'MAINTENANCE',
            'message' => $config['message'],
            'errors' => (object) [],
            'meta' => (object) [],
        ], 503);
    }

    /** يقرأ إعداد الصيانة بأمان (أي خطأ => معطّل). */
    public static function config(): array
    {
        try {
            $row = config_settings('maintenance_mode', 'ops_config');
            $values = $row && $row->live_values ? (array) json_decode($row->live_values, true) : [];
            return [
                'enabled' => (int) ($values['enabled'] ?? 0) === 1,
                'message' => (string) ($values['message'] ?? 'النظام تحت الصيانة مؤقتاً — نعود قريباً'),
            ];
        } catch (\Throwable $e) {
            return ['enabled' => false, 'message' => ''];
        }
    }
}
