<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** AMIAL-KYC-FORENSIC-002 — من سرّب هذه الصورة؟ */
class KycForensicController extends Controller
{
    public function page()
    {
        $recent = DB::table('kyc_forensic_views as v')
            ->leftJoin('users as a', 'a.id', '=', 'v.actor_user_id')
            ->select([
                'v.trace_code', 'v.document_id', 'v.subject_user_id', 'v.actor_user_id',
                'v.doc_type', 'v.ip_address', 'v.access_reason', 'v.viewed_at',
                'a.f_name as actor_first_name', 'a.l_name as actor_last_name',
            ])
            ->orderByDesc('v.id')
            ->limit(25)
            ->get();

        return view('admin-views.amial.kyc.forensics', compact('recent'));
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trace' => ['required', 'string', 'regex:/^AM-[A-F0-9]{20}$/'],
        ]);

        $row = DB::table('kyc_forensic_views as v')
            ->leftJoin('users as a', 'a.id', '=', 'v.actor_user_id')
            ->where('v.trace_code', strtoupper($data['trace']))
            ->select([
                'v.trace_code', 'v.document_id', 'v.subject_user_id', 'v.actor_user_id',
                'v.doc_type', 'v.watermark_version', 'v.source_mime', 'v.ip_address',
                'v.user_agent', 'v.access_reason', 'v.viewed_at',
                'a.f_name as actor_first_name', 'a.l_name as actor_last_name',
            ])
            ->first();

        if (!$row) {
            return response()->json([
                'success' => false,
                'code' => 'TRACE_NOT_FOUND',
                'message' => 'لم يُعثر على رمز المشاهدة في سجل أميال.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'trace_code' => $row->trace_code,
                'employee' => [
                    'id' => (int) $row->actor_user_id,
                    'name' => trim((string) ($row->actor_first_name . ' ' . $row->actor_last_name)),
                ],
                'subject_user_id' => (int) $row->subject_user_id,
                'document_id' => (int) $row->document_id,
                'doc_type' => $row->doc_type,
                'viewed_at' => $row->viewed_at,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'access_reason' => $row->access_reason,
                'watermark_version' => $row->watermark_version,
                'source_mime' => $row->source_mime,
            ],
        ]);
    }
}
