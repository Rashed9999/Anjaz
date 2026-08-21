<?php

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * يقرر من يمكن لمركز العملاء أن يتعامل معه.
 *
 * لا يكفي أن يمنع الموظف من لمس حسابه فقط. فقد يحمل حسابٌ من نوع customer
 * دوراً تشغيلياً، كما أن الوكيل والتاجر لهما مساراتٍ وسياساتٍ أخرى. إبقاء هذا
 * الحارس بجانب الـorchestrator يجعل كل إجراء يستعمل القاعدة نفسها.
 */
class CustomerCenterTargetPolicy
{
    public function assertActionable(User $customer, User $actor): void
    {
        if ((int) $customer->id === (int) $actor->id) {
            throw new DomainException('FOUR_EYES_VIOLATION: لا تُنفَّذ الإجراءات على حسابك الشخصيّ');
        }

        if ((int) $customer->type !== CUSTOMER_TYPE) {
            throw new DomainException('CUSTOMER_SCOPE_REQUIRED: الإجراء متاح للعملاء فقط');
        }

        // الحسابات التي أسندت إليها أدوار منصة ليست أهداف دعم عادية، حتى لو
        // كان type قديمًا أو هُجّر خطأً. لا نُخفي هذا خلف شرط type وحده.
        if (DB::table('admin_user_roles')->where('user_id', $customer->id)->exists()) {
            throw new DomainException('PRIVILEGED_TARGET_FORBIDDEN: لا تُنفَّذ إجراءات مركز العملاء على حساب موظف منصّة');
        }
    }
}
