<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\ZoneAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-GEO-ZONE-001 — تحويل إحداثيات الجهاز إلى اسم محافظة.
 *
 * الغرض من هذه النقطة تجربةُ المستخدم لا الأمن: عند التسجيل يضغط العميل
 * «حدّد موقعي» فيرى اسم محافظته مكتوباً — فيطمئنّ أن التطبيق يعرف أين هو
 * ويكفّ عن كتابة العنوان يدوياً وأخطائه.
 *
 * ⚠️ ما لا تفعله هذه النقطة عمداً: لا تُسند zone_code للمستخدم. إحداثيات
 * الجهاز تُزوَّر بتطبيق موقع وهمي في دقائق؛ لو منحت المنطقة لصارت سياسة
 * المناطق كلها إجراءً شكلياً. السلطة تبقى لتوثيق KYC وقرار الإدارة.
 *
 * عامّة (بلا مصادقة) لأنها تُستدعى أثناء التسجيل قبل وجود حساب.
 */
class GeoZoneController extends Controller
{
    public function resolve(Request $request, ZoneAssignmentService $zones): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'إحداثيات غير صالحة',
            ], 422);
        }

        $lat = (float) $request->input('latitude');
        $lng = (float) $request->input('longitude');

        $governorate = $zones->governorateFromCoordinates($lat, $lng);

        if ($governorate === null) {
            return response()->json([
                'success' => true,
                'message' => 'موقعك خارج نطاق الخدمة',
                'data' => [
                    'governorate' => null,
                    'zone' => ZoneAssignmentService::ZONE_OTHER,
                    'in_service_area' => false,
                ],
            ]);
        }

        $zone = $zones->cityToZone($governorate);
        $inServiceArea = $zone === ZoneAssignmentService::ZONE_SOUTH;

        return response()->json([
            'success' => true,
            'message' => 'تم تحديد الموقع',
            'data' => [
                'governorate' => $governorate,
                'zone' => $zone,
                'in_service_area' => $inServiceArea,
                // نصّ جاهز للعرض — كي لا يعيد التطبيق صياغة السياسة عنده.
                'notice' => $inServiceArea
                    ? "تم تحديد موقعك: محافظة {$governorate} — ضمن نطاق الخدمة."
                    : "تم تحديد موقعك: محافظة {$governorate} — الخدمة غير متاحة في هذه المنطقة حالياً.",
            ],
        ]);
    }
}
