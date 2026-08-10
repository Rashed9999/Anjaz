<?php

namespace App\Models\AdminCenter;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-MERCHANT-CENTER-001 — أثرُ فعلٍ إداريٍّ على تاجر.
 *
 * **ولا يُحذف ولا يُعدَّل** — والتصحيحُ فعلٌ جديدٌ يشير إلى سابقه.
 */
class MerchantAdminAction extends Model
{
    protected $table = 'merchant_admin_actions';

    protected $fillable = [
        'uuid', 'reference', 'merchant_user_id', 'actor_admin_id', 'actor_role',
        'action', 'target', 'target_id', 'before_state', 'after_state',
        'reason', 'ip_address', 'user_agent', 'request_id',
        'result', 'failure_message',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'actor_admin_id' => 'integer',
        'before_state' => 'array',
        'after_state' => 'array',
    ];

    /** الأفعالُ المعروفة — **وفعلٌ خارجها يُرفض** لا يُسجَّل بلا اسم. */
    public const ACTIONS = [
        'account.freeze' => 'تجميد الحساب',
        'account.unfreeze' => 'فكّ التجميد',
        'service.suspend' => 'تعليق خدمة',
        'service.resume' => 'استئناف خدمة',
        'sessions.revoke' => 'إنهاء كل الجلسات',
        'device.block' => 'حظر جهاز',
        'device.trust' => 'اعتماد جهاز',
        'staff.disable' => 'تعطيل موظّف لأمر أمني',
        'plan.change' => 'تغيير الباقة',
        'capability.grant' => 'منح قدرة',
        'capability.revoke' => 'سحب قدرة',
        'kyc.approve' => 'اعتماد التوثيق',
        'kyc.reject' => 'رفض التوثيق',
        'risk.tier' => 'تغيير درجة المخاطر',
        'access.grant' => 'فتح إذن اطّلاع',
        'access.revoke' => 'إلغاء إذن اطّلاع',
        'note.add' => 'إضافة ملاحظة',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_admin_id');
    }

    public function actionAr(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /** «نشط ← مجمَّد» — يُقرأ في سطرٍ واحد. */
    public function transition(): ?string
    {
        $b = $this->before_state['label'] ?? null;
        $a = $this->after_state['label'] ?? null;

        return ($b !== null && $a !== null) ? "{$b} ← {$a}" : null;
    }
}
