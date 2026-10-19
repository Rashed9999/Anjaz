<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MuwathaqDecisionController extends Controller
{
    public function __construct(private readonly MuwathaqAccountOpeningService $applications) {}

    public function store(Request $request, string $applicationUlid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approved,rejected,needs_more_info,under_review',
            'decision_reference' => 'required|string|min:8|max:100',
            'reason' => 'nullable|string|max:1000',
            'context' => 'nullable|array',
            'context.policy_version' => 'nullable|string|max:64',
            'context.reviewer_reference' => 'nullable|string|max:100',
            'context.risk_band' => 'nullable|string|max:32',
            'context.notes_code' => 'nullable|string|max:64',
            'context.verified_phone' => 'nullable|boolean',
            'context.verified_residence_governorate' => 'nullable|string|in:'
                . implode(',', \App\Support\YemenGovernorates::codes()),
            'context.verified_identity' => 'nullable|boolean',
            'context.verified_owner_identity' => 'nullable|boolean',
            'context.verified_business_requirements' => 'nullable|boolean',
            'context.admin_provisioned' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'code' => 'VALIDATION_FAILED',
                'message' => 'بيانات قرار موثّق غير صحيحة.',
                'errors' => $validator->errors(),
                'meta' => (object) [],
            ], 422);
        }

        try {
            $application = $this->applications->receiveDecision(
                $applicationUlid,
                (string) $request->input('decision'),
                (string) $request->input('decision_reference'),
                $request->input('reason'),
                (array) $request->input('context', []),
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => 'MUWATHAQ_DECISION_REJECTED',
                'message' => $e->getMessage(),
                'errors' => (object) [],
                'meta' => (object) [],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'code' => 'MUWATHAQ_DECISION_APPLIED',
            'message' => 'تم تطبيق قرار موثّق.',
            'errors' => (object) [],
            'meta' => [
                'application_ulid' => $application->application_ulid,
                'status' => $application->status,
                'subject_kind' => $application->subject_kind,
                'verification_path' => $application->verification_path,
            ],
        ]);
    }
}
