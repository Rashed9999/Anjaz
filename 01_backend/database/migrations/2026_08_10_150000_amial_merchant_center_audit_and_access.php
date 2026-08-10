<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MERCHANT-CENTER-001 — **حدُّ المسؤوليّة، وأثرُ من تجاوزه**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الخطأ الذي يُصلَح هنا:** بُني في اللوحة عرضٌ لأصناف التاجر وهالكِه
 * وأسعارِه بأسمائها. وأميال ليست ERP للتاجر — هي منصّةٌ ماليّة.
 *
 * | يملكها التاجر | تملكها أميال |
 * |---|---|
 * | المنتجات · المخزون · الموردون · الموظّفون · التشغيل اليوميّ | الحساب · الأموال · العمليّات · التسويات · العمولات · المخاطر · الامتثال · الاشتراك |
 *
 * **ولا يعني ذلك «لا يُرى أبداً»** — يعني **«لا يُرى بلا سبب»**. فمن
 * احتاج تفصيلَ عمليّةٍ لتحقيقٍ أو دعمٍ يفتح إذناً مؤقّتاً بسببٍ مكتوب،
 * ويُسجَّل. **والفرقُ بين «مفتوحٌ للجميع» و«يُفتح بسببٍ ويُسجَّل» هو
 * الفرقُ بين تسرُّبٍ ورقابة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا سجلَّ فعلٍ إداريٍّ موحَّداً كان موجوداً**: قِيس أنّ في القاعدة
 * `rbac_audit_log` و`audit_decisions` و`settlement_audit_logs` — ثلاثةَ
 * سجلّاتٍ لثلاثة نطاقات، **ولا سجلَّ يجيب «ماذا فعلت الإدارةُ بهذا
 * التاجر؟»** في مكانٍ واحد. وهو أوّلُ سؤالٍ يُسأل عند شكوى.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── سجلُّ كلّ فعلٍ إداريٍّ على تاجر ────────────────────────────
        Schema::create('merchant_admin_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 32)->unique();   // AUD-928173

            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('actor_admin_id');
            $table->string('actor_role', 40)->nullable();

            $table->string('action', 60);      // freeze · unfreeze · plan_change …
            $table->string('target', 60)->nullable();  // account · service · session …
            $table->unsignedBigInteger('target_id')->nullable();

            // **قبل وبعد** — وفعلٌ بلا حالتين لا يُراجَع ولا يُعكَس.
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();

            // **والسببُ إلزاميّ** — «جُمّد» بلا سبب سؤالٌ بلا جواب بعد شهر.
            $table->string('reason', 500);

            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 64)->nullable();

            $table->enum('result', ['ok', 'failed'])->default('ok');
            $table->string('failure_message', 255)->nullable();

            $table->timestamps();

            $table->index(['merchant_user_id', 'created_at']);
            $table->index(['actor_admin_id', 'created_at']);
            $table->index('action');
        });

        // ── إذنُ اطّلاعٍ مؤقّت على تفصيلٍ تشغيليٍّ يخصّ التاجر ──────────
        //
        // **بلا هذا الجدول يصير الاستثناءُ قاعدة.** فمن أراد تفصيلاً يفتح
        // إذناً بسببٍ وأجل، وينتهي وحدَه — ويبقى أثرُه.
        Schema::create('merchant_data_access_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 32)->unique();

            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('admin_id');

            // ما نطاقُ الإذن: operational (أصناف/مخزون) · financial_detail
            $table->enum('scope', ['operational', 'financial_detail'])->default('operational');

            $table->string('reason', 500);
            $table->string('ticket_ref', 64)->nullable();

            // **ولا إذنَ بلا أجل** — وإذنٌ دائمٌ ليس إذناً، هو صلاحيّة.
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();

            // كم مرّةً استُعمل — إذنٌ يُفتح ولا يُستعمل إشارةٌ في ذاته.
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index(['merchant_user_id', 'admin_id', 'expires_at'], 'mdag_lookup_idx');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_data_access_grants');
        Schema::dropIfExists('merchant_admin_actions');
    }
};
