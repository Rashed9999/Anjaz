<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AccountDossierPrintService;
use App\Services\PiiAccessAuditService;
use Illuminate\Http\Request;

/**
 * AMIAL-ACCOUNT-PRINT-001 — طباعةُ ملفّ الحساب من الحساب نفسِه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **بالحساب لا بالمرجع.** الطباعةُ القائمة مفتاحُها `reference` من جدول
 * `registration_dossiers` — فحسابٌ بلا لقطةٍ مؤرشفةٍ لا يُطبَع أصلاً،
 * والمراجعُ الواقفُ على حسابٍ لا يعرف مرجعَه. فالمفتاحُ هنا **مُعرّفُ
 * المستخدم**، وهو ما تعرفه كلُّ شاشةِ حسابٍ في اللوحة.
 *
 * **والصلاحيّةُ `platform.customers.kyc.view`** — وهي نفسُها التي تفتح
 * صورَ الوثائق في لوحة التحقّق (`kyc/documents/{id}/file`). فالورقةُ تحمل
 * ما تحمله تلك الشاشة، **ووضعُها خلف صلاحيّةٍ أدنى يفتح باباً جانبيّاً
 * إلى صورِ الهويّة لمن لا يملك رؤيتَها.**
 *
 * **وكلُّ طباعةٍ تُسجَّل** في سجلّ الوصول إلى البيانات الشخصيّة: ورقةٌ
 * تخرج من النظام تحمل صورةَ هويّةٍ ورقمَها، ومن طبعها ومتى سؤالٌ يُسأل
 * يوماً — والجوابُ يُكتب وقتَ الفعل لا بعده.
 *
 * يظهر في : لوحة الإدارة ← لوحة التحقّق · مركز الحساب · مركز التاجر —
 * زرّ «طباعة ملفّ الحساب». وفي التطبيق: لا.
 *
 * @see \Tests\Feature\AccountDossierPrintGuardTest
 */
class AccountDossierPrintController extends Controller
{
    public function __construct(
        private readonly AccountDossierPrintService $print,
        private readonly PiiAccessAuditService $pii,
    ) {}

    public function show(Request $request, int $user)
    {
        $subject = User::findOrFail($user);

        $this->pii->logAccess(
            $request->user()->id, 'user', $subject->id,
            'account_dossier_pdf', 'export',
            'طباعة ملفّ الحساب مع صور الوثائق');

        $bytes = $this->print->render($subject, $request->user());

        // **والطولُ من البايتات المُرسَلة نفسِها** — فبلا `Content-Length`
        // لا يميّز المتصفّحُ ملفّاً اكتمل من ملفٍّ انقطع، فيُحفَظ نصفُه
        // ويُفتَح فيُرى تالفاً بلا سببٍ ظاهر.
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="amial-account-'.$subject->id.'.pdf"',
        ]);
    }
}
