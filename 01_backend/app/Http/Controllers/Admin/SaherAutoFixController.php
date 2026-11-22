<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SaherRouteQuarantineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SaherAutoFixController extends Controller
{
    public function quarantine(Request $request, int $id, SaherRouteQuarantineService $service): RedirectResponse
    {
        $data = $request->validate([
            'confirm' => 'required|in:QUARANTINE',
            'reason' => 'required|string|min:20|max:1000',
        ]);

        $finding = DB::table('saher_findings')->where('id', $id)->first();
        abort_if($finding === null, 404);

        $changed = $service->quarantine($finding, $request->user()->id, $data['reason']);

        return back()->with('success', $changed
            ? 'أُوقف مسار الكتابة فوراً. هذا احتواء مؤقت وليس إصلاحاً نهائياً؛ لا تُغلق الاكتشاف.'
            : 'المسار موقوف مسبقاً؛ لم تُكرَّر العملية.');
    }

    public function release(Request $request, int $id, SaherRouteQuarantineService $service): RedirectResponse
    {
        $data = $request->validate([
            'confirm' => 'required|in:RELEASE',
            'reason' => 'required|string|min:20|max:1000',
        ]);

        $finding = DB::table('saher_findings')->where('id', $id)->first();
        abort_if($finding === null, 404);

        $changed = $service->release($finding, $request->user()->id, $data['reason']);

        return back()->with('success', $changed
            ? 'رُفع الحظر المؤقت؛ تأكد من إصلاح الكود وإعادة فحص ساهر.'
            : 'لا يوجد حظر نشط تابع لهذا الاكتشاف.');
    }
}
