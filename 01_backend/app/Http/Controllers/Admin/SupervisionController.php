<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SupervisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * AMIAL-SUPERVISION-001 — لوحة الإشراف.
 *
 * تجيب عن «هل يعمل الفريق كما ينبغي الآن؟» لا عن «ماذا حدث لهذه المعاملة؟»
 * — والثاني عملُ سجلّ التدقيق، ويُسأل بعد الشكوى لا قبلها.
 */
class SupervisionController extends Controller
{
    public function __construct(private readonly SupervisionService $supervision)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
        ]);

        return view('admin-views.amial.supervision.index', [
            'snapshot' => $this->supervision->snapshot(
                isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null,
                isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null,
            ),
        ]);
    }
}
