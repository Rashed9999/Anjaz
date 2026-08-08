<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-RECIPIENTS-001 · AMIAL-HOME-006
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:**
 *
 * قائمةُ «تحويل سريع» كانت تُقرأ من `SharedPreferences` وحدها. فمن أعاد
 * تثبيت التطبيق — أو بدّل هاتفه — **بدأ من الصفر**: «لا مستلمين بعد»
 * وهو يحوّل منذ شهور.
 *
 * ولا خطأ في أيّ سجلّ: القائمةُ فارغةٌ فتُعرض بطاقةُ الدعوة، وكأنّه
 * مستعملٌ جديد. **وهذا صنفُ العطل الذي لا يُبلَّغ عنه** — يظنّه صاحبُه
 * سلوكاً طبيعيّاً للتطبيق.
 */
class RecentRecipientsGuardTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $phone): User
    {
        return User::factory()->create(['phone' => $phone]);
    }

    private function sent(User $from, User $to, string $when): void
    {
        Receipt::create([
            'receipt_number' => 'R' . uniqid(),
            'verification_code' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'receipt_type' => 'send_money',
            'reference_transaction_id' => 0,
            'reference_type' => 'transaction',
            'reference_id' => 0,
            'user_id' => $from->id,
            'counterparty_user_id' => $to->id,
            'amount' => 1000,
            'fee' => 0,
            'net_amount' => 1000,
            'direction' => 'debit',
            'status' => 'pdf_generated',
            'issued_at' => $when,
        ]);
    }

    /**
     * @test
     *
     * **المستلمون يُشتقّون من السجلّ — فيَنجون من إعادة التثبيت.**
     *
     * ══════════════════════════════════════════════════════════════════
     * ولا يُفحص وجودُ النقطة: **يُفحص أنّها تُرجع من حُوّل له فعلاً**.
     * فنقطةٌ تردّ قائمةً فارغةً دائماً تمرّ على حارسٍ يسأل «أتستجيب؟».
     */
    public function recipients_come_from_the_ledger_not_the_phone(): void
    {
        $me = $this->customer('770000001');
        $ali = $this->customer('770000002');
        $sara = $this->customer('770000003');

        $this->sent($me, $ali, now()->subDays(10)->toDateTimeString());
        $this->sent($me, $sara, now()->subDay()->toDateTimeString());
        $this->sent($me, $ali, now()->subDays(9)->toDateTimeString());

        $res = $this->actingAs($me, 'api')
            ->withHeaders(['device-id' => 'REC-TEST'])
            ->getJson('/api/v1/amial/receipts/recent-recipients');

        $res->assertOk();

        $items = $res->json('meta.items');

        $this->assertCount(2, $items,
            'عددُ المستلمين خطأ — يُتوقَّع طرفان لا أكثر ولا أقلّ');

        // **والأقربُ زمناً أوّلاً** — لا الأكثرُ عدداً.
        // فمن حوّلتَ له أمس أقربُ ممّن حوّلتَ له عشراً قبل سنة.
        $this->assertSame($sara->id, $items[0]['user_id'],
            'الترتيبُ ليس بالأحدث — سارة آخرُ من حُوّل له وعليّ أكثرُ عدداً');

        $this->assertSame(2, $items[1]['times'],
            'عددُ المرّات لا يُحسب');

        $this->assertSame($ali->phone, $items[1]['phone'],
            'رقمُ المستلم ناقص — بلا رقمٍ لا يُعاد التحويل بضغطة');
    }

    /**
     * @test
     *
     * **ولا يظهر مستلمٌ لم يُحوَّل له، ولا نفسُ صاحب الحساب.**
     *
     * فقائمةٌ فيها من لم تُحوّل له تُنتج تحويلاً إلى الرقم الخطأ بضغطة —
     * وهي أخطرُ من قائمةٍ فارغة.
     */
    public function the_list_holds_only_real_outgoing_counterparties(): void
    {
        $me = $this->customer('770000010');
        $other = $this->customer('770000011');
        $stranger = $this->customer('770000012');

        $this->sent($me, $other, now()->toDateTimeString());

        // تحويلٌ وارد: الطرفُ الآخرُ ليس مستلماً منّي.
        Receipt::create([
            'receipt_number' => 'R' . uniqid(),
            'verification_code' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'receipt_type' => 'send_money',
            'reference_transaction_id' => 0,
            'reference_type' => 'transaction',
            'reference_id' => 0,
            'user_id' => $me->id,
            'counterparty_user_id' => $stranger->id,
            'amount' => 500, 'fee' => 0, 'net_amount' => 500,
            'direction' => 'credit', 'status' => 'pdf_generated',
            'issued_at' => now()->toDateTimeString(),
        ]);

        // وتحويلٌ إلى النفس (يقع في بعض التسويات) لا يُقترح.
        $this->sent($me, $me, now()->toDateTimeString());

        $items = $this->actingAs($me, 'api')
            ->withHeaders(['device-id' => 'REC-TEST'])
            ->getJson('/api/v1/amial/receipts/recent-recipients')
            ->json('meta.items');

        $ids = array_column($items, 'user_id');

        $this->assertSame([$other->id], $ids,
            'القائمةُ تحمل من لم يُحوَّل له — تحويلٌ خاطئٌ بضغطة');
    }

    /**
     * @test
     *
     * **والتطبيقُ يقرأ الخادمَ أوّلاً، ويُبقي المحلّيَّ احتياطاً.**
     *
     * فحذفُ المحلّيّ يترك الشاشةَ فارغةً على شبكةٍ منقطعة، والاكتفاءُ به
     * يُعيد العطلَ نفسَه.
     */
    public function the_app_prefers_the_server_and_keeps_the_local_fallback(): void
    {
        $src = file_get_contents(base_path(
            '../02_flutter_app/lib/features/home/screens/amial_customer_home_screen.dart'));

        $this->assertStringContainsString('receipts/recent-recipients', $src,
            'الشاشةُ لا تنادي مستلمي الخادم');

        $this->assertStringContainsString('if (_serverRecipients.isNotEmpty)', $src,
            'الخادمُ لا يسبق المحلّيّ');

        $this->assertStringContainsString('c.sendMoneySuggestList', $src,
            'المحلّيُّ حُذف — تُعرض «لا مستلمين» على شبكةٍ منقطعة');
    }

    /**
     * @test
     *
     * **والخدماتُ قبل «آخر العمليّات» في الشاشة الرئيسيّة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * الشاشةُ تُفتح لِيُفعَل شيء، لا لِيُقرأ ما مضى. وكان السجلُّ يسبق
     * الخدمات، فيُمرَّر ستّةُ صفوفٍ من عمليّاتٍ منتهية قبل بلوغ أوّل زرٍّ
     * يعمل.
     *
     * و«آخرُ العمليّات» له بابُه في الشريط السفليّ — أمّا الخدماتُ فلا
     * بابَ لها غير هذه الشاشة. (صفحةٌ لا يُوصل إليها ليست مبنيّة.)
     */
    public function services_appear_before_the_activity_log(): void
    {
        $src = file_get_contents(base_path(
            '../02_flutter_app/lib/features/home/screens/amial_customer_home_screen.dart'));

        // يُقاس موضعُ الاستدعاء في شجرة البناء، لا موضعُ تعريف الدالّة.
        preg_match('/child: Column\(\s*crossAxisAlignment.*?\n                \],/s', $src, $m);

        $tree = $m[0] ?? $src;

        $services = strpos($tree, '_servicesGrid()');
        $recent = strpos($tree, '_recentSection()');

        $this->assertNotFalse($services, 'شبكةُ الخدمات ليست في شجرة البناء');
        $this->assertNotFalse($recent, 'قسمُ آخر العمليّات ليس في شجرة البناء');

        $this->assertLessThan($recent, $services,
            'السجلُّ يسبق الخدمات — تُمرَّر عمليّاتٌ منتهية قبل أوّل زرٍّ يعمل');
    }
}
