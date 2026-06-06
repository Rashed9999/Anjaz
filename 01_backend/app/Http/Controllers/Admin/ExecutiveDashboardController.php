<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ExecutiveDashboardService;
use Illuminate\Http\JsonResponse;

/**
 * AMIAL-EXEC-DASHBOARD-001 — اللوحة التنفيذية العليا (للإدارة العليا).
 *
 * تجمع كل مؤشّرات النظام في شاشة واحدة: المحافظ، المدفوعات، الشراء، التجار،
 * محطات الوقود، المستخدمون، التنبيهات الأمنية، الإيرادات، حالة الخوادم.
 */
class ExecutiveDashboardController extends Controller
{
    public function __construct(private readonly ExecutiveDashboardService $dashboard)
    {
    }

    /** عرض Blade للوحة الأدمن. */
    public function index()
    {
        return view('admin-views.amial.executive.index', [
            'data' => $this->dashboard->summary(),
        ]);
    }

    /** JSON API — لاستهلاك تطبيق الإدارة (Flutter) أو التحديث اللحظي. */
    public function summary(): JsonResponse
    {
        return new JsonResponse([
            'response_code' => 'success',
            'data' => $this->dashboard->summary(),
        ]);
    }
}
