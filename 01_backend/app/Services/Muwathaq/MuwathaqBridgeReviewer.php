<?php

namespace App\Services\Muwathaq;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * CAP-01 (قرار المالك 2026-11-11): حساب إداري «جسر» محفوظ يُسجَّل مراجعاً شكلياً
 * لكل قرار صادر من موثّق، فيمتلئ reviewed_by وactor_user_id بمعرّف حقيقي
 * وتبقى قاعدة «الأربع عيون» في أميال باي سليمة (المراجع ≠ صاحب الحساب).
 *
 * الحساب لا يدخل أبداً: is_active=0 (بوابة دخول الإدارة ترفض غير الفعّال)،
 * وكلمة مرور عشوائية لا تُحفظ في أي مكان، وبلا أدوار أو صلاحيات.
 * المراجع البشري الحقيقي (المقترح والمعتمد في موثّق) يُكتب في سياق التدقيق.
 */
class MuwathaqBridgeReviewer
{
    public const EMAIL = 'muwathaq-bridge@reviewer.amialpay.invalid';

    public function resolve(): User
    {
        $existing = $this->find();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function (): User {
            // قفل استشاري لتجنّب إنشاء حسابين عند أول قرارين متزامنين.
            $found = User::query()->where('email', self::EMAIL)->where('type', ADMIN_TYPE)->lockForUpdate()->first();
            if ($found !== null) {
                return $found;
            }
            $user = new User();
            $user->forceFill([
                'f_name' => 'موثّق',
                'l_name' => '(جسر القرارات)',
                'email' => self::EMAIL,
                'phone' => null,
                'password' => Hash::make(Str::random(64)),
                'type' => ADMIN_TYPE,
                'role' => 'system',
                'is_active' => 0,
                'is_phone_verified' => 0,
                'is_email_verified' => 0,
                'is_kyc_verified' => 0,
                'image' => 'def.png',
                'unique_id' => (string) Str::uuid(),
            ])->save();

            return $user;
        });
    }

    public function find(): ?User
    {
        return User::query()->where('email', self::EMAIL)->where('type', ADMIN_TYPE)->first();
    }

    public static function isBridge(?User $user): bool
    {
        return $user !== null && (int) $user->type === ADMIN_TYPE && $user->email === self::EMAIL;
    }
}
