<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // AMIAL-PILOT: رُفعت من 40 دقيقة إلى 30 يوماً حتى لا تنتهي الجلسة أثناء
        // التجربة («Token Expired»). قلّلها للإنتاج حسب سياسة الأمان.
        Passport::personalAccessTokensExpireIn(Carbon::now()->addDays(30));

        // ══════════════════════════════════════════════════════════════
        // AMIAL-PASSPORT-KEYS-PERSIST-001 — **كلُّ نشرةٍ كانت تطرد الجميع.**
        //
        // مفاتيحُ Passport كانت في `storage/oauth-*.key`، **والحجمُ الدائمُ
        // الوحيدُ هو `storage/app`** (‏`amial_storage_prod` في
        // `docker-compose.prod.yml`). فالمفاتيحُ خارجَه — تذهب مع الحاوية.
        //
        // فمع كلّ نشرةٍ أو إعادةِ إقلاع: تختفي المفاتيح، فيولّدها
        // `entrypoint` **جديدةً**، **فيبطل كلُّ رمزِ دخولٍ صدر قبلها**.
        // والنتيجةُ التي وصلت شاشةَ صاحب المشروع: «انتهت الجلسة — سجّل
        // الدخول من جديد» في **كلّ شاشةٍ محميّة، لكلّ تاجرٍ في كلّ قطاع**،
        // وهو داخلٌ فعلاً ولم يفعل شيئاً.
        //
        // **والشرطُ كان مكتوباً ولم يُوصَل**: تعليقُ `entrypoint.prod.sh`
        // يقول بنصّه «تحتاج تثبيت storage على volume دائم حتى تبقى الرموز
        // صالحة عبر إعادات التشغيل» — والحجمُ يثبّت `storage/app` لا
        // `storage`. شرطٌ مُعلَنٌ لا يحقّقه أحد.
        //
        // **ولا يُثبَّت `storage` كلُّه**: حجمٌ عليه يحجب ما تحته في الصورة
        // (‏`framework/cache` · `views` · `sessions`) — وهو عطلُ الـPDF
        // نفسُه الذي دُفع ثمنُه من قبل. فتُنقَل المفاتيحُ إلى الحجم القائم.
        //
        // **وثمنُه مرّةٌ واحدة**: الرموزُ الصادرةُ بالمفاتيح القديمة تبطل
        // عند أوّل نشرةٍ بعد هذا — ثمّ تثبت أبداً. وهو أرخصُ من طردٍ
        // في كلّ نشرة.
        // ══════════════════════════════════════════════════════════════
        // **ويُوجَّه فقط حيث توجد المفاتيحُ فعلاً.** توجيهٌ غيرُ مشروطٍ
        // إلى مجلّدٍ فارغٍ يمنع Passport من إيجاد مفتاحٍ إطلاقاً — فيسقط
        // كلُّ دخولٍ برمز. (قِيس: كسر سبعةَ اختباراتٍ محلّيّاً أوّلَ مرّة،
        // لأنّ مفاتيحَ التطوير في `storage/` والمجلّدُ الجديد غيرُ موجود.)
        //
        // فالانتقالُ يقع حين يُهيّئ `entrypoint` المجلّدَ ويرحّل إليه —
        // وقبلها يبقى الافتراضيُّ عاملاً. **لا نافذةَ عطلٍ بين الحالتين.**
        $persistentKeys = storage_path('app/passport');
        if (is_file($persistentKeys.'/oauth-private.key')) {
            Passport::loadKeysFrom($persistentKeys);
        }
    }
}
