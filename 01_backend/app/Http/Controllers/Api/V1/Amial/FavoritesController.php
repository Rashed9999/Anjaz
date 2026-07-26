<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\FavouriteNumber;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-FAVORITES-001 — مفضّلة موحّدة لكل ما يستحقّ التكرار.
 *
 * جهة اتصال، رقم حساب، تاجر، وعملية سابقة تُعاد بنفس تفاصيلها. الجدول
 * نفسه الذي يخدم خصم رسوم المفضّلة — بلا نظام موازٍ.
 *
 * **التبديل لا الإضافة:** الواجهة `toggle` لا `store`، لأن الزرّ في
 * التطبيق نجمة: ضغطة تُضيف وضغطة تُزيل. واجهة إضافة وحدها تعني أن إزالة
 * المفضّلة تحتاج شاشة أخرى، فلا يزيلها أحد وتتراكم القائمة حتى تُهجَر.
 */
class FavoritesController extends Controller
{
    /** GET /favorites?kind=contact — القائمة، مجموعةً بالنوع. */
    public function index(Request $request): JsonResponse
    {
        $query = FavouriteNumber::where('user_id', $request->user()->id);

        if ($request->filled('kind')) {
            $query->where('kind', $request->query('kind'));
        }

        $items = $query->latest()->get()->map(fn (FavouriteNumber $f) => $this->present($f));

        return response()->json([
            'success' => true,
            'data' => $items->values(),
            'meta' => [
                'total' => $items->count(),
                'by_kind' => $items->groupBy('kind')->map->count(),
                // حدّ جهات الاتصال وحده مضبوط في الإعدادات (يرتبط بالخصم).
                'contact_limit' => (int) (Helpers::get_business_settings('favorite_number_limit') ?? 0),
            ],
        ]);
    }

    /**
     * POST /favorites/toggle — يضيف إن غابت، ويزيل إن وُجدت.
     */
    public function toggle(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kind' => 'required|string|in:' . implode(',', FavouriteNumber::KINDS),
            'value' => 'required|string|max:64',
            'label' => 'nullable|string|max:120',
            'metadata' => 'nullable|array',
            // نوع جهة الاتصال (أهل/وكيل/غيرهم) — للتوافق مع الشاشة القديمة
            'type' => 'nullable|string|in:f_and_f,agent,others',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $userId = $request->user()->id;
        $kind = $request->input('kind');
        // الهاتف يُطبَّع قبل التخزين: نفس الرقم بصيغ مختلفة (+967…، 00967…،
        // 77…) كان يُنشئ ثلاث مفضّلات ولا يُطابق أيّها الخصمَ.
        $value = $kind === FavouriteNumber::KIND_CONTACT
            ? Phone::canonical($request->input('value'))
            : trim($request->input('value'));

        $existing = FavouriteNumber::where('user_id', $userId)
            ->where('kind', $kind)->where('value', $value)->first();

        if ($existing) {
            $existing->delete();

            return response()->json([
                'success' => true,
                'favorited' => false,
                'message' => 'أُزيلت من المفضّلة',
            ]);
        }

        if ($kind === FavouriteNumber::KIND_CONTACT && !$this->contactLimitAllows($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'بلغتَ الحدّ الأقصى لجهات الاتصال المفضّلة. أزل واحدة أولاً.',
            ], 422);
        }

        $favorite = FavouriteNumber::create([
            'user_id' => $userId,
            'kind' => $kind,
            'value' => $value,
            'name' => $request->input('label') ?: $value,
            'phone' => $kind === FavouriteNumber::KIND_CONTACT ? $value : null,
            'type' => $request->input('type', 'others'),
            'metadata' => $request->input('metadata'),
        ]);

        return response()->json([
            'success' => true,
            'favorited' => true,
            'message' => 'أُضيفت إلى المفضّلة',
            'data' => $this->present($favorite),
        ]);
    }

    /** DELETE /favorites/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = FavouriteNumber::where('user_id', $request->user()->id)
            ->where('id', $id)->delete();

        if (!$deleted) {
            return response()->json(['success' => false, 'message' => 'غير موجودة'], 404);
        }

        return response()->json(['success' => true, 'message' => 'أُزيلت من المفضّلة']);
    }

    /**
     * POST /favorites/check — هل هذه القيم مفضّلة؟
     *
     * دفعةً واحدة لا واحدةً واحدة: شاشة فيها عشرون عملية تحتاج معرفة حالة
     * النجمة لكلٍّ منها، وعشرون نداءً يعني شاشة تتلعثم عند كل فتح.
     */
    public function check(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kind' => 'required|string|in:' . implode(',', FavouriteNumber::KINDS),
            'values' => 'required|array|max:100',
            'values.*' => 'string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $kind = $request->input('kind');
        $values = array_map(
            fn ($v) => $kind === FavouriteNumber::KIND_CONTACT ? Phone::canonical($v) : trim($v),
            $request->input('values')
        );

        $found = FavouriteNumber::where('user_id', $request->user()->id)
            ->where('kind', $kind)->whereIn('value', $values)
            ->pluck('value')->all();

        return response()->json(['success' => true, 'data' => array_values($found)]);
    }

    private function contactLimitAllows(int $userId): bool
    {
        $limit = (int) (Helpers::get_business_settings('favorite_number_limit') ?? 0);
        if ($limit <= 0) {
            return true; // 0 = بلا حدّ
        }

        return FavouriteNumber::where('user_id', $userId)->contacts()->count() < $limit;
    }

    private function present(FavouriteNumber $f): array
    {
        return [
            'id' => $f->id,
            'kind' => $f->kind ?: FavouriteNumber::KIND_CONTACT,
            'value' => $f->value ?: $f->phone,
            'label' => $f->name,
            'type' => $f->type,
            'metadata' => $f->metadata ?: (object) [],
            'created_at' => optional($f->created_at)->toIso8601String(),
        ];
    }
}
