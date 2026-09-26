<?php

namespace App\Services;

use App\Models\AuditDecision;
use App\Models\User;
use DomainException;

/**
 * AMIAL-FOUR-EYES-001 — من نفّذ لا يراجع.
 *
 * **القاعدة:** من عدّل معاملةً أو اعتمدها لا يجوز أن يعود إليها مراجعاً أو
 * معدِّلاً. وهي أقدم ضابط في المحاسبة، وسببه أن الرقابة الذاتية ليست رقابة:
 * من أخطأ لا يرى خطأه، ومن تعمّد يُغطّي أثره.
 *
 * **ما كان قائماً وما كان ناقصاً:**
 * ApprovalService يمنع صاحب الطلب من اعتماد طلبه — وهو الشقّ الأوّل. أمّا
 * النزاعات فبلا ضابط إطلاقاً: من عالج الأدلّة يستطيع الفصل، ومن فصل يستطيع
 * إعادة الفصل. وهذا أخطر موضع، إذ الفصل في النزاع يُحرّك مالاً بين طرفين.
 *
 * **ولماذا خدمة عامّة لا شرطٌ في كل مسار:**
 * الشرط المتكرّر يُنسى في المسار التالي — كما نُسي في النزاعات. والقاعدة
 * المركزية تُختبر مرّة وتُطبَّق حيث تُستدعى، ويبقى النسيان ظاهراً في مكان
 * واحد يمكن فحصه.
 *
 * **المصدر هو سجلّ التدقيق** لا حقلٌ في الصفّ: `audit_decisions` غير قابل
 * للحذف ولا التعديل (يُمنع في النموذج نفسه)، فتاريخُ من فعل ماذا لا يُمحى.
 * ولو اعتمدنا حقلاً واحداً مثل `admin_resolved_by` لضاع التاريخ عند أوّل
 * قرار ثانٍ يكتب فوقه.
 */
class FourEyesService
{
    /**
     * يمنع من سبق أن فعل شيئاً بهذا الموضوع من مراجعته.
     *
     * @param  string[]  $blockingActions أفعالٌ سابقة تمنع المراجعة. فارغة =
     *                   أيّ فعل سابق يمنع.
     *
     * @throws DomainException FOUR_EYES_VIOLATION
     */
    public function assertNotPreviousActor(
        string $subjectType,
        string|int $subjectId,
        User $reviewer,
        array $blockingActions = [],
    ): void {
        if ($this->previousActionsBy($subjectType, $subjectId, $reviewer, $blockingActions) > 0) {
            throw new DomainException('FOUR_EYES_VIOLATION');
        }
    }

    /**
     * القاعدة نفسها حين يكون التاريخ في جدولٍ آخر.
     *
     * AMIAL-FOUR-EYES-002 — التسويات تُسجَّل في `settlement_audit_logs` لا في
     * `audit_decisions`، فلا تراها `assertNotPreviousActor`. والحلّ ليس تكرار
     * الشرط هناك: الشرط المكرّر يُنسى في المسار التالي — وهو بالضبط سبب خلوّ
     * النزاعات من الضابط سنواتٍ رغم وجوده في الاعتمادات.
     *
     * فيُستدعى هذا وتُمرَّر إليه قائمةُ من فَعَلَ سابقاً من مصدرها. يبقى
     * القرارُ ورمزُ الخطأ ونصّه في مكان واحد يُختبر مرّة.
     *
     * @param  array<int|string>  $previousActorIds معرّفات من فعل سابقاً.
     *
     * @throws DomainException FOUR_EYES_VIOLATION
     */
    public function assertNotAmongPreviousActors(array $previousActorIds, User $reviewer): void
    {
        $ids = array_map('intval', array_filter($previousActorIds, fn ($v) => $v !== null));

        if (in_array((int) $reviewer->id, $ids, true)) {
            throw new DomainException('FOUR_EYES_VIOLATION');
        }
    }

    /** كم فعلاً سابقاً لهذا الشخص على هذا الموضوع. */
    private function previousActionsBy(
        string $subjectType,
        string|int $subjectId,
        User $actor,
        array $blockingActions = [],
    ): int {
        $q = AuditDecision::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) $subjectId)
            ->where('actor_user_id', $actor->id);

        if ($blockingActions !== []) {
            $q->whereIn('action', $blockingActions);
        }

        return $q->count();
    }

    /**
     * من سبق أن تعامل مع هذا الموضوع — تُعرض في اللوحة قبل فتح القرار.
     *
     * إظهارُها قبل الضغط أنفع من منعٍ بعده: المشرف يعرف أن زميله عالجها
     * فيحيلها بدل أن يصطدم برسالة رفض بعد أن كتب حيثياته.
     *
     * @return array<int, array{user_id:int, name:string, actions:int, last_at:string}>
     */
    public function actorsOn(string $subjectType, string|int $subjectId): array
    {
        return AuditDecision::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) $subjectId)
            ->whereNotNull('actor_user_id')
            ->selectRaw('actor_user_id, COUNT(*) as actions, MAX(created_at) as last_at')
            ->groupBy('actor_user_id')
            ->get()
            ->map(function ($row) {
                $u = User::find($row->actor_user_id);
                return [
                    'user_id' => (int) $row->actor_user_id,
                    'name' => $u ? trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? '')) : '—',
                    'actions' => (int) $row->actions,
                    'last_at' => (string) $row->last_at,
                ];
            })
            ->all();
    }

    /** رسالة موحّدة — تُعرض للمشغّل فيفهم أنه منعٌ مقصود لا عطل. */
    public static function message(): string
    {
        return 'لا يمكنك مراجعة معاملة سبق أن تعاملت معها — يلزم موظّف آخر (قاعدة أربع عيون)';
    }
}
