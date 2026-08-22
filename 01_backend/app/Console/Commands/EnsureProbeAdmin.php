<?php

namespace App\Console\Commands;

use App\Models\FeeScheme;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AMIAL-FEE-TRUTH-024 — **حسابُ المسبار يُصنَع، لا يُفترَض وجودُه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ المكتوبُ في `CLAUDE.md`:** مسبارُ `press-every-button.py` —
 * ٢٢٥ سطراً — **لم يُنتج نتيجةً واحدةً منذ كُتب**، لأنّ كلمةَ المرور فيه
 * سرٌّ مخترَعٌ لا حسابَ به. فيخرج بالرمز ٢ قبل أن يضغط زرّاً.
 *
 * وفي هذه الجلسة تكرّر الشكلُ نفسُه بسببٍ آخر: **قاعدةُ التطوير تُستعاد
 * في تمرين التعافي**، فيذهب الحسابُ الذي بُذر يدويّاً — ويقول المسبارُ
 * «بياناتُ الحساب خاطئة» **وهي سليمة**.
 *
 * فصار الحسابُ يُصنَع بأمرٍ يُشغَّل قبل المسبار، ويُعاد صنعُه بلا ضرر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُشغَّل في الإنتاج أبداً.** حسابُ إدارةٍ بكلمة مرورٍ معروفةٍ في
 * ملفٍّ مفتوح هو **بابٌ خلفيّ**، وسهولةُ إنشائه لا تعني أنّه يُنشأ حيث
 * يوجد مالٌ حقيقيّ.
 */
class EnsureProbeAdmin extends Command
{
    protected $signature = 'amial:probe-admin
                            {--phone=967700000000 : هاتفُ حساب المسبار}
                            {--password=Admin@2026 : كلمةُ مروره}
                            {--pin=4321 : رمزُ دخوله الوظيفيّ — أربعةُ أرقام}';

    protected $description = 'يُنشئ حسابَ مسبارٍ للوحة الإدارة (بيئاتُ التطوير والاختبار وحدَها)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('لا يُنشأ حسابُ مسبارٍ في الإنتاج — بابٌ خلفيٌّ بكلمة مرورٍ معروفة.');

            return self::FAILURE;
        }

        $phone = (string) $this->option('phone');
        $password = (string) $this->option('password');

        $user = User::where('phone', $phone)->first() ?? new User();

        // `forceFill` لأنّ `type` و`role` خارج `$fillable` عمداً: نوعُ
        // الحساب لا يُكتب من طلبٍ، **ويُكتب هنا صراحةً بقرار**.
        $user->forceFill([
            'f_name' => 'مشرف', 'l_name' => 'المسبار',
            'phone' => $phone, 'dial_country_code' => '+967',
            'type' => ADMIN_TYPE, 'role' => 'super_admin',
            'password' => Hash::make($password),
            'is_phone_verified' => 1, 'is_active' => 1,
            'zone_code' => 'SOUTH',
        ])->save();

        // **والدورُ يُقرأ ولا يُخترَع** — إن لم تُشغَّل هجرةُ الأدوار بعد،
        // يُقال ذلك ولا يُصنَع دورٌ نصفُ مُعرَّف.
        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        if ($roleId === null) {
            $this->warn('الدورُ `platform_admin` غيرُ موجود — شغّل الهجرات أوّلاً. '
                . 'الحسابُ أُنشئ بلا صلاحيّاتِ منصّة.');
        } else {
            DB::table('admin_user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $roleId],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        // ══════════════════════════════════════════════════════════════
        // **وتسعيرتان** — فمسبارٌ على جدولٍ فارغٍ لا يجد زرّاً يضغطه،
        // فيمرّ ويُقرأ نجاحاً. (‏«مسحٌ على قاعدةٍ فارغةٍ يُطمئن ولا يفحص».)
        $seeded = 0;

        foreach ([
            ['SEND_MONEY', 'customer', '1.5000', '0'],
            ['CASH_OUT', 'customer', '2.0000', '30'],
        ] as [$code, $actor, $pct, $comm]) {
            $exists = FeeScheme::where('code', $code)->where('zone_code', 'SOUTH')
                ->where('applies_to', $actor)->where('is_active', true)->exists();

            if ($exists) {
                continue;
            }

            FeeScheme::create([
                'code' => $code, 'zone_code' => 'SOUTH', 'applies_to' => $actor,
                'fee_type' => 'percent', 'percent_rate' => $pct, 'fixed_amount' => '0',
                'agent_commission_percent' => $comm, 'agent_commission_fixed' => '0',
                'bearer' => 'sender', 'version' => 1, 'is_active' => true,
                'effective_from' => now()->subDay(),
            ]);

            $seeded++;
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-AUTH-PIN — **ورمزُ الدخول يُصدَر لحساب المسبار.**
        //
        // حلّ رمزُ PIN محلَّ الكابتشا في باب اللوحة، **فعميت ثلاثةُ
        // مسابرَ دفعةً واحدة**: مركزُ الرسوم، وتغطيةُ التدفّقات، وضغطُ
        // كلّ زرّ. وقالت البوّابةُ صادقةً «الطبقةُ مُخطّاةٌ ولا تُعدّ
        // نجاحاً» — فلم تكذب، لكنّ فحصين اختفيا من ٣٣.
        //
        // **ومسبارٌ لا يدخل ليس مسباراً** — وقد سبق أن بقي
        // `press-every-button.py` سنةً بلا نتيجةٍ واحدةٍ لسرٍّ مخترَع.
        $pin = (string) $this->option('pin');

        app(\App\Services\PlatformLoginPinService::class)->issue(
            $user, $pin, null, 'probe_admin', mustChange: false,
            deliveryStatus: 'not_required',
        );

        $this->info(sprintf('حسابُ المسبار #%d جاهزٌ (%s) · تسعيراتٌ مضافة: %d · PIN مُصدَر',
            $user->id, $phone, $seeded));

        return self::SUCCESS;
    }
}
