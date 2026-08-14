<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PERF-PHONE-001 — **أكثرُ استعلامٍ في المنصّة يمسح الجدول كلَّه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس** (على القاعدة نفسِها، قبل هذه الهجرة):
 *
 *     EXPLAIN SELECT id FROM users WHERE phone IN (?,?,?,?)
 *     type=ALL   possible_keys=NULL   key=NULL   Extra=Using where
 *
 * `type=ALL` تعني **مسحاً كاملاً**، و`key=NULL` تعني أنّ المحرّك لم يجد
 * فهرساً يستعمله. وفي `users` ثلاثةَ عشرَ فهرساً — **ولا واحدَ منها على
 * `phone`**. الفهارسُ الموجودة على `phone_blind_index` (عمودُ بحثٍ معمّى
 * لبيانات PII)، **والشيفرةُ لا تبحث به**: تبحث بـ`phone` مباشرةً في
 * **٥٧ موضعاً**.
 *
 * **وهذا الاستعلامُ يقع في كلّ:** تسجيلِ دخول · تحقّقِ OTP · تحويلٍ ·
 * طلبِ مال · فحصِ مستلم · بحثِ الدعم · إيداعِ الشبّاك.
 *
 * و`Phone::variants()` تولّد أربعَ صيغٍ للرقم الواحد (`+967…` · `967…` ·
 * `00967…` · `77…`)، فيصير `IN (?,?,?,?)` — **مسحٌ واحدٌ يفحص أربعَ
 * قيمٍ لكلّ صفّ**. والكلفةُ خطّيّةٌ بعدد المستخدمين: ألفُ مستخدمٍ اليوم
 * مقبول، ومئةُ ألفٍ غداً تعني ثانيةً كاملةً لكلّ محاولةِ دخول.
 *
 * ولا يظهر في الاختبارات: قاعدةُ الاختبار فارغة، والمسحُ الكاملُ لجدولٍ
 * فارغٍ أسرعُ من قراءة فهرس.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ ليس فهرساً فريداً:** الأرقامُ مخزَّنةٌ بصيغٍ مختلفةٍ عبر تاريخ
 * المشروع، وقد يوجد صفّان بالرقم نفسه بصيغتين. وفهرسٌ فريدٌ يُسقط الهجرة
 * على الإنتاج. التفرُّدُ يُفرض في الشيفرة، والفهرسُ هنا للسرعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'phone')) {
            return;
        }

        if ($this->hasIndex('users_phone_lookup_idx')) {
            return;
        }

        Schema::table('users', function ($table) {
            $table->index('phone', 'users_phone_lookup_idx');
        });

        // **والنوعُ يُفهرس معه**: كلُّ بحثٍ عن مستلمٍ يقرن الهاتفَ بالنوع
        // (`type != ADMIN_TYPE`)، وكلُّ لوحةٍ تُصفّي بالنوع.
        if (Schema::hasColumn('users', 'type') && ! $this->hasIndex('users_type_idx')) {
            Schema::table('users', function ($table) {
                $table->index('type', 'users_type_idx');
            });
        }
    }

    public function down(): void
    {
        // **تراجُعٌ يعمل على قاعدةٍ فيها بيانات** — وهجرةٌ لا تتراجع
        // تُسقط كلَّ اختبارٍ يستعمل `DatabaseMigrations`. (وقع هذا فعلاً.)
        foreach (['users_phone_lookup_idx', 'users_type_idx'] as $name) {
            if ($this->hasIndex($name)) {
                Schema::table('users', function ($table) use ($name) {
                    $table->dropIndex($name);
                });
            }
        }
    }

    private function hasIndex(string $name): bool
    {
        if (! Schema::hasTable('users')) {
            return false;
        }

        return DB::select(
            'SHOW INDEX FROM `users` WHERE Key_name = ?', [$name]
        ) !== [];
    }
};
