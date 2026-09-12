<?php

namespace App\Http\Controllers;

use App\Services\DocumentVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * AMIAL-DOC-VERIFY-001 — **البابُ العامُّ للتحقّق من مستند.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان `/v/{code}` مبنيّاً — **ولا صفحةَ يدخل إليها من يحمل ورقةً ويريد
 * كتابةَ رمزها**. ومن لا يملك هاتفاً يمسح QR لا بابَ له إطلاقاً. وهو
 * نمطُ العطل الأكثر تكراراً في المشروع: مبنيٌّ ولا يُوصَل إليه.
 *
 * **ولا مصادقةَ هنا عمداً**: من يتحقّق قد لا يكون صاحبَ الحساب — تاجرٌ
 * يفحص سندَ زبون، أو موظّفٌ يفحص إيصالَ مورّد. والحمايةُ **بحجب البيانات
 * وحدِّ المعدّل** لا بالتسجيل، وإلّا صارت الميزةُ بلا معنى.
 */
class PublicVerificationController extends Controller
{
    public function __construct(private DocumentVerificationService $verifier) {}

    /** الصفحةُ الثابتة: حقلُ الرمز وزرُّ المسح. */
    public function page(Request $request): Response
    {
        $raw = trim((string) $request->query('code', ''));

        // **وصفحةٌ تُفتح بلا رمزٍ ليست خطأً** — هي المدخل. فلا تُعرَض
        // «غير صالح» لمن لم يكتب شيئاً بعد. (القاعدة السابعة.)
        $result = $raw === '' ? null : $this->verifier->verify($raw);

        return response()->view('public.verify', [
            'code' => $raw,
            'result' => $result,
        ], $result === null || $result['found'] ? 200 : 404);
    }

    /** الوصولُ المباشر من مسح رمز السند. */
    public function show(string $code): Response
    {
        $result = $this->verifier->verify($code);

        return response()->view('public.verify', [
            'code' => $code,
            'result' => $result,
        ], $result['found'] ? 200 : 404);
    }

    /** ولِمن يقرؤها آليّاً — التطبيقُ والدعم. */
    public function json(string $code)
    {
        $result = $this->verifier->verify($code);

        return response()->json([
            'success' => $result['found'],
            'code' => $result['found'] ? 'VERIFICATION_OK' : 'DOCUMENT_NOT_FOUND',
            'message' => $result['authenticity_label'],
            'errors' => (object) [],
            'meta' => $result,
        ], $result['found'] ? 200 : 404);
    }
}
