<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\OperatorWorkspaceService;
use Illuminate\Contracts\View\View;

/**
 * صفحة عمل موحّدة؛ التبويب ينظّم الواجهات ولا يمنح صلاحية بنفسه.
 *
 * AMIAL-WORKSPACE-002 — **وصارت تقول ما ينتظر لا ما هو متاح.**
 * الأرقامُ كلُّها من `OperatorWorkspaceService`، ولا رقمَ مكتوبٌ في قالب.
 */
class OperatorWorkspaceController extends Controller
{
    public function index(OperatorWorkspaceService $workspace): View
    {
        $user = auth('user')->user();

        // **الافتراضُ منعٌ لا سماح** — وخطأٌ في فحصِ استحقاقٍ واحدٍ لا
        // يُسقط الصفحةَ كلَّها، وهي التي تحمل كلَّ روابط اللوحة.
        $can = function (?string $permission) use ($user): bool {
            if ($permission === null) {
                return true;
            }

            try {
                return $user && method_exists($user, 'hasPlatformPermission')
                    && $user->hasPlatformPermission($permission);
            } catch (\Throwable $e) {
                report($e);

                return false;
            }
        };

        return view('admin-views.amial.ops.workspace', [
            'wsCan' => $can,
            'wsCards' => $workspace->cards($can),
            'wsHealth' => $workspace->health(),
            'wsQueue' => $workspace->queue($can),
        ]);
    }
}
