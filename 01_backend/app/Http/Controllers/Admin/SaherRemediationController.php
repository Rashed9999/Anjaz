<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SaherRemediationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SaherRemediationController extends Controller
{
    public function open(Request $request, int $id, SaherRemediationService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:20|max:1000',
            'confirm' => 'required|in:OPEN_REMEDIATION_CASE',
        ]);

        $finding = DB::table('saher_findings')->where('id', $id)->first();
        abort_if($finding === null, 404);

        $created = $service->open($finding, $request->user()->id, $data['reason']);

        return back()->with('success', $created
            ? 'أُنشئ طلب معالجة موثق؛ لم يُغيّر الكود أو حالة الاكتشاف.'
            : 'يوجد طلب معالجة سابق لنفس الاكتشاف؛ لم يُكرَّر.');
    }
}
