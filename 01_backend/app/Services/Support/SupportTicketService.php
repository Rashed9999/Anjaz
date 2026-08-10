<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\User;
use App\Services\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-MERCHANT-CENTER-002 — **بابٌ واحدٌ لفتح التذكرة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا خدمةٌ لا نسخةٌ ثانية:** فتحُ التذكرة كان مكتوباً داخل
 * `SupportConsoleController::createTicket` — رقمُ التذكرة، وحدثُ
 * الإنشاء، وسطرُ التدقيق، كلُّه في المتحكّم. ونسخُه إلى مركز التاجر
 * يعني بابين: أحدُهما يُصلَح والآخر يُنسى.
 *
 * **وأخطرُ ما يُنسى هنا `nextTicketNumber()`**: يقرأ أكبرَ رقمٍ ثمّ يزيد
 * واحداً، و`lockForUpdate` فيه **لا تعني شيئاً خارج معاملة**. فنسخةٌ
 * ثانيةٌ تنساها تُنتج رقمَي تذكرةٍ متطابقين لطلبين متزامنين — ولا يظهر
 * ذلك إلّا يوم يتّصل عميلان برقمٍ واحد.
 *
 * فالمعاملةُ هنا، ومن يفتح تذكرةً يمرّ بها.
 */
class SupportTicketService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * يفتح تذكرةً لصاحب الحساب `$subject` بيد المشغّل `$actor`.
     *
     * @param  array{subject:string, category?:string|null, priority?:string|null,
     *               transaction_ref?:string|null, description?:string|null}  $data
     *
     * @throws DomainException إن كان الموضوع فارغاً أو الصنف/الأولويّة غير معروفين
     */
    public function open(User $actor, User $subject, array $data): SupportTicket
    {
        $title = trim((string) ($data['subject'] ?? ''));

        if (mb_strlen($title) < 5) {
            throw new DomainException('اكتب موضوع التذكرة (٥ أحرف فأكثر)');
        }

        $category = $data['category'] ?? 'other';
        $priority = $data['priority'] ?? 'normal';

        // **يُفحص هنا لا في المتحكّم**: من يدخل من الباب الثاني يُفحص أيضاً.
        if (! in_array($category, SupportTicket::CATEGORIES, true)) {
            throw new DomainException('صنف التذكرة غير معروف');
        }

        if (! in_array($priority, SupportTicket::PRIORITIES, true)) {
            throw new DomainException('أولويّة التذكرة غير معروفة');
        }

        $ticket = DB::transaction(function () use ($actor, $subject, $title, $category, $priority, $data) {
            $t = SupportTicket::create([
                'ticket_number' => SupportTicket::nextTicketNumber(),
                'user_id' => $subject->id,
                'opened_by_admin_id' => $actor->id,
                'transaction_ref' => $data['transaction_ref'] ?? null,
                'category' => $category,
                'priority' => $priority,
                'status' => 'open',
                'subject' => $title,
                'description' => $data['description'] ?? null,
            ]);

            SupportTicketEvent::create([
                'ticket_id' => $t->id,
                'admin_id' => $actor->id,
                'event_type' => 'created',
                'new_value' => 'open',
                'note' => $t->subject,
            ]);

            return $t;
        });

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'support_ticket',
            'subject_id' => (string) $ticket->id,
            'action' => 'SUPPORT_TICKET_CREATED',
            'decision_code' => 'OK',
            'reason' => $ticket->subject,
        ]);

        return $ticket->fresh();
    }

    /**
     * الحالاتُ التي تُعدّ «مفتوحة».
     *
     * كانت الشاشةُ تعدّ `['open','in_progress']` — و`in_progress` ليست
     * من `SupportTicket::STATUSES` أصلاً. فتذكرةٌ قيد التحقيق أو تنتظر
     * العميل كانت **تُحسب مغلقة**، والرقمُ المعروض أقلّ من الحقيقة بلا
     * أن يُخطئ شيء.
     */
    public const OPEN_STATUSES = ['open', 'investigating', 'waiting_customer'];
}
