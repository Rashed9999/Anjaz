<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-RECEIPT-SETTINGS-001 — إعدادات الفاتورة والطباعة للتاجر.
 *
 * كل تاجر يخصّص فاتورته: الترويسة، التذييل، الهاتف، إظهار الشعار، عرض ورق
 * الطباعة (58/80مم)، ورمز العملة. تُقرأ في كل شاشات الفاتورة الموحّدة.
 *
 *   GET  /api/v1/amial/merchant/receipt-settings
 *   POST /api/v1/amial/merchant/receipt-settings
 *   POST /api/v1/amial/merchant/receipt-settings/logo   (رفع شعار base64)
 */
class MerchantReceiptSettingsController extends Controller
{
    /** القيم الافتراضية للإعدادات. */
    private function defaults(): array
    {
        return [
            'header_note' => '',        // سطر تحت اسم المتجر
            'footer_note' => 'شكراً لتعاملكم معنا',
            'phone' => '',
            'address' => '',
            'show_logo' => true,
            'show_phone' => true,
            'show_address' => true,
            'paper_width' => 80,        // 58 | 80 مم
            'auto_print_receipts' => false,
            'currency_label' => 'ر.ي',
        ];
    }

    /**
     * يعيد مالك المنشأة الذي يجب أن تُقرأ هويّته في كل فاتورة.
     *
     * موظف نقطة البيع لا يملك منشأة مستقلة، لكنّه يجب أن يطبع باسم منشأة
     * مالكه وبنفس الترويسة والشعار. لذلك نسمح له بالقراءة فقط، ولا نسمح
     * له بتغيير الهوية أو إعدادات الفاتورة.
     *
     * @return array{merchant: User, owner: bool}|JsonResponse
     */
    private function merchantContext(Request $request, bool $allowPosRead = false): array|JsonResponse
    {
        $u = $request->user();
        if (!$u) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if ($u->role === A::ROLE_MERCHANT) {
            return ['merchant' => $u, 'owner' => true];
        }
        if ($allowPosRead) {
            $pos = PosUser::active()->where('user_id', $u->id)->first();
            $merchant = $pos ? User::find($pos->merchant_user_id) : null;
            if ($merchant && $merchant->role === A::ROLE_MERCHANT) {
                return ['merchant' => $merchant, 'owner' => false];
            }
        }
        return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
    }

    public function show(Request $request): JsonResponse
    {
        $context = $this->merchantContext($request, true);
        if ($context instanceof JsonResponse) return $context;
        $user = $context['merchant'];

        $profile = MerchantProfile::where('user_id', $user->id)->first();
        $merchant = Merchant::where('user_id', $user->id)->first();

        $settings = array_merge($this->defaults(), (array) ($profile->receipt_settings ?? []));
        // اسم المتجر والشعار من سجلّ التاجر الأساسي
        $settings['store_name'] = $merchant?->store_name ?? trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? ''));
        $settings['logo_url'] = $merchant?->logo_fullpath;

        // ══════════════════════════════════════════════════════════════
        // AMIAL-MULTI-CURRENCY-002 — **السعرُ من المركز لا من يد التاجر.**
        //
        // كان يُقرأ `rate_to_base` الذي يكتبه التاجرُ في شاشته: **رقمٌ بلا
        // مصدرٍ ولا طابعٍ زمنيّ ولا تاريخِ تغييرات**. وأخطرُ ما فيه أنّه
        // **لا يُجمَّد**: يغيّر التاجرُ السعرَ غداً فيتغيّر معه مكافئُ
        // فاتورة الأمس على الورقة نفسِها — أي إعادةُ كتابةِ مستندٍ صدر.
        //
        // ومصدران للسعر (سعرُ التاجر على الورقة وسعرُ المنصّة في المحفظة)
        // يعنيان **رقمين مختلفين على البيعة الواحدة**. فصار السعرُ من
        // `fx_rates` وحدَه، ومعه مصدرُه ولحظتُه.
        // ══════════════════════════════════════════════════════════════
        $fx = app(\App\Services\FxRateService::class)->current();

        $currencies = \App\Models\MerchantCurrency::where('merchant_user_id', $user->id)
            ->where('is_active', true)->orderBy('code')->get()
            ->map(function ($c) use ($fx) {
                $code = strtoupper((string) $c->code);
                $row = $fx[$code] ?? null;

                return [
                    'code' => $code,
                    'symbol' => (string) ($c->symbol ?: ($row ? \App\Support\Money\Currencies::symbol($code) : $code)),
                    'rate_to_base' => $row['rate'] ?? null,
                    'rate_source' => $row['source'] ?? null,
                    'rate_at' => $row['at'] ?? null,
                ];
            })
            // **وعملةٌ بلا سعرٍ تُحذَف من الإيصال ولا تُطبَع بصفر.** مكافئٌ
            // «≈ 0.00 $» على فاتورةٍ أسوأ من غيابه: يقرؤه العميلُ رقماً.
            ->filter(fn ($c) => $c['rate_to_base'] !== null)
            ->values();

        return $this->ok(['settings' => $settings, 'currencies' => $currencies], 'OK', 'إعدادات الفاتورة');
    }

    public function save(Request $request): JsonResponse
    {
        $context = $this->merchantContext($request);
        if ($context instanceof JsonResponse) return $context;
        $user = $context['merchant'];

        $v = Validator::make($request->all(), [
            'header_note' => 'sometimes|nullable|string|max:120',
            'footer_note' => 'sometimes|nullable|string|max:160',
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:160',
            'show_logo' => 'sometimes|boolean',
            'show_phone' => 'sometimes|boolean',
            'show_address' => 'sometimes|boolean',
            'paper_width' => 'sometimes|integer|in:58,80',
            'auto_print_receipts' => 'sometimes|boolean',
            'currency_label' => 'sometimes|nullable|string|max:8',
            'store_name' => 'sometimes|nullable|string|max:120',
        ]);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        $profile = MerchantProfile::where('user_id', $user->id)->first();
        if (!$profile) return $this->error('NO_PROFILE', 'لا يوجد ملف تاجر', 404);

        $current = array_merge($this->defaults(), (array) ($profile->receipt_settings ?? []));
        foreach (array_keys($this->defaults()) as $key) {
            if ($request->has($key)) {
                $current[$key] = $request->input($key);
            }
        }
        $profile->receipt_settings = $current;
        $profile->save();

        // اسم المتجر يُحفظ في سجلّ التاجر الأساسي
        if ($request->filled('store_name')) {
            $merchant = Merchant::where('user_id', $user->id)->first();
            if ($merchant) {
                $merchant->store_name = trim((string) $request->input('store_name'));
                $merchant->save();
            }
        }

        return $this->ok(['settings' => $current], 'SAVED', 'تم حفظ إعدادات الفاتورة');
    }

    /** رفع شعار المتجر (base64) — يُخزَّن في سجلّ التاجر. */
    public function uploadLogo(Request $request): JsonResponse
    {
        $context = $this->merchantContext($request);
        if ($context instanceof JsonResponse) return $context;
        $user = $context['merchant'];

        $v = Validator::make($request->all(), ['logo' => 'required|string']);
        if ($v->fails()) return $this->error('VALIDATION', $v->errors()->first(), 422);

        $merchant = Merchant::where('user_id', $user->id)->first();
        if (!$merchant) return $this->error('NO_MERCHANT', 'لا يوجد سجلّ تاجر', 404);

        try {
            $filename = Helpers::file_uploader('merchant/', 'png', $request->input('logo'));
            $merchant->logo = $filename;
            $merchant->save();
        } catch (\Throwable $e) {
            return $this->error('UPLOAD_FAILED', 'تعذّر رفع الشعار', 422);
        }

        return $this->ok(['logo_url' => $merchant->fresh()->logo_fullpath], 'LOGO_SAVED', 'تم حفظ الشعار');
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
