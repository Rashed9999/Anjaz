<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AMIAL-PERF-PHONE-001 — **المساراتُ الساخنة تُقرأ بفهرس، لا بمسحٍ كامل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** كان في `users` ثلاثةَ عشرَ فهرساً — على المحافظة
 * وعلى الشريحة وعلى حالة الحياة وعلى ثلاثة أعمدةِ بحثٍ معمّاة —
 * **ولا واحدَ على `phone`**، وهو العمودُ الذي تبحث به الشيفرةُ في ٥٧
 * موضعاً، وفي كلّ تسجيلِ دخولٍ وتحقّقِ OTP وتحويلٍ وطلبِ مال.
 *
 * ```
 * EXPLAIN SELECT id FROM users WHERE phone IN (?,?,?,?)
 * type=ALL   possible_keys=NULL   key=NULL
 * ```
 *
 * **ولا يُمسك هذا بمجموعة اختبارات**: قاعدةُ الاختبار فارغة، ومسحُ جدولٍ
 * فارغٍ أسرعُ من قراءة فهرس. فكلُّ الاختبارات تمرّ والمنصّةُ تبطؤ خطّيّاً
 * مع كلّ مستخدمٍ جديد — **عطلٌ لا يظهر إلّا بعد النجاح**.
 *
 * فيُفحص وجودُ الفهرس نفسِه، لا زمنُ الاستعلام.
 */
class HotPathIndexGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * الأعمدةُ التي تُقرأ في كلّ عمليّةٍ تقريباً، ولكلٍّ سببُه.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function hotColumns(): array
    {
        return [
            'هاتفُ المستخدم' => ['users', 'phone'],
            'نوعُ الحساب' => ['users', 'type'],
        ];
    }

    /**
     * @dataProvider hotColumns
     */
    public function test_a_hot_column_is_indexed(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            $this->markTestSkipped("لا عمود {$table}.{$column} في هذا المخطّط");
        }

        // **الفهرسُ المركّبُ يُقبل إن كان العمودُ أوّلَه** — MySQL يستعمل
        // بادئةَ الفهرس، ولا يستعمله إن كان العمودُ في وسطه.
        $leading = collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->where('Seq_in_index', 1)
            ->pluck('Column_name')
            ->all();

        $this->assertContains($column, $leading,
            "«{$table}.{$column}» بلا فهرسٍ يقوده — وكلُّ بحثٍ به يمسح "
            . 'الجدول كلَّه. والكلفةُ خطّيّةٌ بعدد الصفوف، ولا تظهر في '
            . 'الاختبارات لأنّ قاعدتها فارغة.');
    }

    /** **والمخطّطُ يُسأل كما يسأله المحرّك** — لا كما نظنّ. */
    public function test_the_phone_lookup_actually_uses_the_index(): void
    {
        $plan = DB::select(
            'EXPLAIN SELECT id FROM users WHERE phone IN (?,?,?,?)',
            ['+967771234567', '967771234567', '00967771234567', '771234567'],
        )[0];

        // على جدولٍ فارغٍ يختار المحرّك المسحَ ولو وُجد الفهرس — فالمقصودُ
        // أن يكون الفهرسُ **مرشَّحاً**، أي ظاهراً في `possible_keys`.
        $this->assertNotNull($plan->possible_keys,
            'لا فهرسَ مرشَّحٌ للبحث بالهاتف — المحرّكُ لا يملك إلّا المسح');

        $this->assertStringContainsString('phone', (string) $plan->possible_keys);
    }
}
