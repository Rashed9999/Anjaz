<?php

namespace Tests\Feature;

use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentStaffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-SITE-001 + AMIAL-UNIFIED-WEB-LOGIN-001.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الموقع أوّلاً: كلّ صفحةٍ تُفتح، وكلّ رابطٍ في الرأس والذيل يصل.**
 *
 * ورابطٌ مكسورٌ في القالب المشترك لا يُنتج خطأً في أيّ سجلّ — الصفحة تردّ
 * ٢٠٠ والرابط يعمل ويذهب إلى ٤٠٤. ولأنّه في القالب فهو مكسورٌ في **كلّ**
 * صفحة. فيُتتبَّع كلُّ رابطٍ داخليّ ويُطلب فعلاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والدخول: أخطرُ ما فيه ليس أن يفشل، بل أن ينجح على الحارس الخطأ.**
 *
 * فجلسةٌ على حارس `user` من بوّابة الوكيل عطّلت لوحة الإدارة كلّها بحلقة
 * توجيهٍ كلُّ ردٍّ فيها 302 سليم. لذلك لا يُكتفى بـ«ذهب إلى لوحته»: يُفحص
 * **أيُّ حارسٍ فُتح وأيٌّ بقي مغلقاً** في كلّ مسار.
 */
class SiteAndUnifiedLoginTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════════════════════════════════════
    // الموقع
    // ══════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * كلّ صفحةٍ تُفتح ولا تنهار.
     */
    public function every_public_page_opens(): void
    {
        foreach (['/', '/personal', '/business', '/exchange', '/about',
                  '/security', '/faq', '/contact', '/terms', '/privacy', '/login'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    /**
     * @test
     *
     * **الموقع لا يمسّ قاعدة البيانات.**
     *
     * لأنّه أوّل ما يراه الزائر: عطلٌ في قاعدةٍ داخليّة لا يجوز أن يجعل
     * «الشركة كلّها لا تعمل» في نظر من يمرّ. ويُفحص بمنع الاتّصال فعلاً
     * لا بقراءة الشيفرة.
     */
    public function the_public_site_survives_a_dead_database(): void
    {
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');

        try {
            // منفذٌ لا يُصغي عليه أحد — قطعٌ حقيقيّ لا محاكاة.
            config(['database.connections.mysql.port' => 1]);
            \DB::purge('mysql');

            foreach (['/', '/personal', '/exchange', '/faq'] as $path) {
                $this->get($path)->assertOk();
            }
        } finally {
            // **تُعاد الإعدادات هنا لا في نهاية الدالّة.** فـ`RefreshDatabase`
            // تتراجع عن معاملتها بعد الاختبار، وبقاءُ الاتّصال مقطوعاً يُسقط
            // التنظيف نفسه — فيبدو الموقعُ ساقطاً وهو سليم. وقد وقع.
            config(['database.connections.mysql.host' => $host,
                    'database.connections.mysql.port' => $port]);
            \DB::purge('mysql');
            \DB::reconnect('mysql');
        }
    }

    /**
     * @test
     *
     * **كلّ رابطٍ داخليٍّ في الموقع يصل إلى صفحةٍ قائمة.**
     *
     * ورابطٌ مكسورٌ في القالب المشترك مكسورٌ في كلّ صفحة — ولا يشتكي.
     */
    public function every_internal_link_resolves(): void
    {
        $checked = [];

        foreach (['/', '/personal', '/business', '/exchange', '/about',
                  '/security', '/faq', '/contact', '/terms', '/privacy'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match_all('~href="([^"]+)"~', $html, $m);

            foreach ($m[1] as $href) {
                // روابط داخليّة فقط (نسبيّة أو على مضيف الاختبار).
                $target = str_replace(config('app.url'), '', $href);

                if ($target === '' || ! str_starts_with($target, '/')) {
                    continue;
                }

                if (str_starts_with($target, '/assets/')) {
                    // **بصمةُ النسخة تُنزع قبل الفحص.** الأصولُ تُطلب بـ
                    // `?v=...` لكسر ذاكرة المتصفّح، والقرصُ لا يعرف الاستعلام
                    // — فكان الحارسُ يبحث عن ملفٍّ اسمُه فيه `?v=` ولا يجده،
                    // ويتّهم أصلاً موجوداً بالغياب.
                    $file = strtok($target, '?');

                    $this->assertFileExists(public_path(ltrim($file, '/')),
                        "أصلٌ مفقود: {$target} (في {$path})");
                    continue;
                }

                if (isset($checked[$target])) {
                    continue;
                }

                $checked[$target] = true;

                $status = $this->get($target)->getStatusCode();

                $this->assertNotSame(404, $status,
                    "رابطٌ مكسور في {$path}: {$target}");
                $this->assertLessThan(500, $status,
                    "رابطٌ يُنهي الصفحة في {$path}: {$target}");
            }
        }

        $this->assertNotEmpty($checked, 'لم يُفحص أيّ رابط — التعبير النمطيّ لا يلتقط شيئاً');
    }

    /**
     * @test
     *
     * لا مضيف خارجيّ في الموقع كلّه.
     */
    public function the_site_depends_on_no_external_host(): void
    {
        foreach (['/', '/exchange', '/login'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            foreach (['//cdn.', '//fonts.googleapis', '//cdnjs.', '//unpkg.',
                      '//ajax.google', '//maxcdn.'] as $needle) {
                $this->assertStringNotContainsString($needle, $html,
                    "{$path} يعتمد على مضيفٍ خارجيّ: {$needle}");
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    // الدخول الموحّد
    // ══════════════════════════════════════════════════════════════

    private function makeCompany(string $phone = '967771900001'): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'البسيري', 'l_name' => 'للصرافة', 'phone' => $phone,
            'type' => AGENT_TYPE, 'password' => Hash::make('secret123'),
            'is_kyc_verified' => 1, 'is_active' => 1,
        ])->save();
        EMoney::create(['user_id' => $u->id, 'current_balance' => '0']);

        return $u;
    }

    private function makeAdmin(string $phone = '967771900009'): User
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'مدير', 'l_name' => 'المنصّة', 'phone' => $phone,
            'type' => ADMIN_TYPE, 'password' => Hash::make('admin12345'),
            'is_active' => 1,
        ])->save();

        return $u;
    }

    /**
     * @test
     *
     * **موظّف الصرافة برمزه → بوّابة الوكيل، على حارس `agent_staff` وحده.**
     */
    public function a_teller_enters_the_agent_portal_and_only_that_guard(): void
    {
        $company = $this->makeCompany();
        app(AgentStaffService::class)->ensureHeadOfficeAccount($company, 'hq123456');

        $staff = AgentStaff::where('agent_user_id', $company->id)->firstOrFail();

        $this->post('/login', [
            'username' => $staff->username,
            'password' => 'hq123456',
        ])->assertRedirect(route('agent.dashboard'));

        $this->assertTrue(Auth::guard('agent_staff')->check(), 'لم تُفتح جلسة البوّابة');
        $this->assertFalse(Auth::guard('user')->check(),
            'فُتحت جلسةٌ على حارس لوحة الإدارة — وهي التي تُنتج حلقة التوجيه');
    }

    /**
     * @test
     *
     * **الأدمن بهاتفه → لوحة الإدارة، على حارس `user` وحده.**
     */
    public function an_admin_enters_the_admin_panel_and_only_that_guard(): void
    {
        $admin = $this->makeAdmin();

        $this->post('/login', [
            'username' => $admin->phone,
            'password' => 'admin12345',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(Auth::guard('user')->check(), 'لم تُفتح جلسة الإدارة');
        $this->assertFalse(Auth::guard('agent_staff')->check(),
            'بقيت جلسة البوّابة مفتوحةً مع جلسة الإدارة');
    }

    /**
     * @test
     *
     * **صاحب شركة الصرافة بهاتفه → البوّابة، لا لوحة الإدارة.**
     *
     * وهذا هو الفرق الذي عطّل لوحة الإدارة سابقاً: الهاتفان يدخلان من
     * الحقل نفسه، والفارق نوعُ الحساب لا نيّةُ الكاتب.
     */
    public function a_company_owner_lands_in_the_portal_not_the_admin_panel(): void
    {
        $company = $this->makeCompany('967771900002');

        $this->post('/login', [
            'username' => $company->phone,
            'password' => 'secret123',
        ])->assertRedirect(route('agent.dashboard'));

        $this->assertTrue(Auth::guard('agent_staff')->check());
        $this->assertFalse(Auth::guard('user')->check());
    }

    /**
     * @test
     *
     * **العميل يُقال له أين يذهب — لا «بيانات خاطئة».**
     *
     * فبياناتُه صحيحة. وقولُ «خاطئة» يجعله يُعيد كتابة كلمةٍ صحيحةٍ عشر
     * مرّاتٍ ثمّ يظنّ حسابه ضاع. (القاعدة السابعة: الغياب يُقال بسببه.)
     */
    public function a_customer_is_told_where_to_go_instead_of_being_called_wrong(): void
    {
        $u = new User();
        $u->forceFill([
            'f_name' => 'عميل', 'l_name' => 'تجريبيّ', 'phone' => '967771900003',
            'type' => CUSTOMER_TYPE, 'password' => Hash::make('secret123'), 'is_active' => 1,
        ])->save();

        $this->from('/login')->post('/login', [
            'username' => $u->phone,
            'password' => 'secret123',
        ])->assertRedirect('/login')
          ->assertSessionHasErrors('username');

        $this->assertStringContainsString('تطبيق أميال باي',
            session('errors')->first('username'),
            'لم يُدلّ العميل على التطبيق');

        $this->assertFalse(Auth::guard('user')->check());
        $this->assertFalse(Auth::guard('agent_staff')->check());
    }

    /**
     * @test
     *
     * **كلمةُ سرٍّ خاطئة لا تفتح شيئاً — ولا تُفرّق بين معرّفٍ قائمٍ وغائب.**
     *
     * ورسالةٌ مثل «هذا الرمز غير مسجَّل» تُمكّن من عدّ الحسابات القائمة بلا
     * كلمة سرّ. ورموزُ الموظّفين متسلسلةٌ يسهل تخمينها.
     */
    public function a_wrong_password_opens_nothing_and_leaks_nothing(): void
    {
        $company = $this->makeCompany('967771900004');
        app(AgentStaffService::class)->ensureHeadOfficeAccount($company, 'hq123456');
        $staff = AgentStaff::where('agent_user_id', $company->id)->firstOrFail();

        $this->from('/login')->post('/login', [
            'username' => $staff->username, 'password' => 'wrong-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('username');

        $existing = session('errors')->first('username');

        $this->flushSession();

        $this->from('/login')->post('/login', [
            'username' => 'NO-SUCH-CODE-999', 'password' => 'wrong-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('username');

        $this->assertSame($existing, session('errors')->first('username'),
            'الرسالتان مختلفتان — فيُعرَف من الردّ أيُّ معرّفٍ قائم');

        $this->assertFalse(Auth::guard('agent_staff')->check());
        $this->assertFalse(Auth::guard('user')->check());
    }

    /**
     * @test
     *
     * **الكابتشا تظهر بعد الفشل لا قبله.**
     *
     * وكابتشا في كلّ دخولٍ ضريبةٌ على الصرّاف الذي يدخل مرّاتٍ في اليوم،
     * وتُدفع بلا مقابل: الآلةُ لا تظهر إلّا بعد محاولاتٍ فاشلة.
     */
    public function the_captcha_appears_only_after_repeated_failures(): void
    {
        // **النصُّ المرصود هو ما في الصفحة فعلاً**: «رمز التحقق». وكان
        // الحارسُ يبحث عن «اكتب ما في الصورة» — صياغةٌ لم تُستعمل قطّ في
        // شاشة الدخول الموحّد، فيسقط وهو يصف سلوكاً سليماً.
        $this->get('/login')->assertOk()->assertDontSee('رمز التحقق');

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['username' => 'NOPE-' . $i, 'password' => 'x-wrong-1']);
        }

        $this->get('/login')->assertOk()->assertSee('رمز التحقق');
    }

    /**
     * @test
     *
     * **البابان القديمان يبقيان يعملان.**
     *
     * التطبيقُ وروابطُ الناس ومجموعةُ الاختبارات تعتمد عليهما، وإغلاقُهما
     * اليوم يكسر ما يعمل مقابل لا شيء.
     */
    public function the_two_original_login_doors_still_work(): void
    {
        $this->get('/agent/login')->assertOk();
        $this->get('/admin/auth/login')->assertOk();
    }

    /**
     * @test
     *
     * **الجلسة لا تتسرّب من حارسٍ إلى آخر — والاختبار يُنتج التسرُّب فعلاً.**
     *
     * ══════════════════════════════════════════════════════════════
     * **وقد كتبتُ هذا الحارس أوّلاً فمرّ وهو لا يحرس شيئاً.**
     *
     * كان يدخل بحسابٍ واحدٍ ثمّ ينفي فتح الحارس الآخر — والحارس الآخر لم
     * يكن مفتوحاً أصلاً، فالنفي يصحّ تلقائيّاً. وأزلتُ `logout()` من
     * الشيفرة عمداً **فمرّ الاختبار كما هو**.
     *
     * فالخطر لا يقع بدخولٍ واحد: يقع حين يدخل المتصفّح نفسه بابين. لذلك
     * يُفتح الحارس الأوّل فعلاً، ثمّ يُدخَل بالثاني، ويُفحص أنّ الأوّل
     * أُغلق. (القاعدة الثانية: حارسٌ لم يسقط ليس حارساً.)
     */
    public function a_session_never_leaks_from_one_guard_to_the_other(): void
    {
        $company = $this->makeCompany('967771900006');
        $hq      = app(AgentStaffService::class)->ensureHeadOfficeAccount($company, 'hq123456');
        $admin   = $this->makeAdmin('967771900007');

        // ── الاتّجاه الأوّل: بوّابة الوكيل ثمّ لوحة الإدارة ──────────
        //
        // وهو الاتّجاه الذي عطّل لوحة الإدارة كلّها: جلسةٌ على حارس `user`
        // من البوّابة تُنتج حلقةً كلُّ ردٍّ فيها 302 سليم.
        Auth::guard('agent_staff')->login($hq);
        $this->assertTrue(Auth::guard('agent_staff')->check());

        $this->post('/login', [
            'username' => $admin->phone,
            'password' => 'admin12345',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(Auth::guard('user')->check(), 'لم تُفتح جلسة الإدارة');
        $this->assertFalse(Auth::guard('agent_staff')->check(),
            'بقيت جلسة البوّابة مفتوحةً — المتصفّح الآن داخلٌ على حارسين معاً');

        // ── والاتّجاه المعاكس: لا يُكتفى بواحد ─────────────────────
        //
        // (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبَر من مدخليها.)
        Auth::guard('user')->login($admin);
        $this->assertTrue(Auth::guard('user')->check());

        $this->post('/login', [
            'username' => $hq->username,
            'password' => 'hq123456',
        ])->assertRedirect(route('agent.dashboard'));

        $this->assertTrue(Auth::guard('agent_staff')->check(), 'لم تُفتح جلسة البوّابة');
        $this->assertFalse(Auth::guard('user')->check(),
            'بقيت جلسة الإدارة مفتوحةً مع جلسة البوّابة');
    }

    /**
     * @test
     *
     * **صفحةُ الدخول تُعرَض دائماً — ولا تُحوَّل ولو كانت هناك جلسة.**
     *
     * ══════════════════════════════════════════════════════════════
     * **وهذا الاختبار كان يُثبّت العطل لا يمنعه.**
     *
     * كان اسمُه «من كان داخلاً يُرسَل إلى لوحته»، ويؤكّد التحويل. وهو ما
     * صنع الفخّ: من جرّب الدخول برمز صرّافٍ مرّةً بقيت جلستُه، فصارت كلُّ
     * ضغطةٍ على «تسجيل الدخول» في الموقع ترميه إلى بوّابة الوكيل — ولا
     * يرى النموذج إطلاقاً، ولا سبيل له إلى لوحةٍ أخرى.
     *
     * وأخبرني صاحبُ المشروع بالعطل أربع مرّات. والردّ ٣٠٢ صحيح، والزرّ
     * يعمل، ولا خطأ في أيّ سجلّ — فبقي الاختبار أخضرَ والعطل قائم.
     *
     * **واختبارٌ يُثبّت سلوكاً خاطئاً أسوأ من غيابه:** يجعل الإصلاح يبدو
     * كسراً.
     */
    public function the_login_page_is_always_shown_even_with_a_live_session(): void
    {
        $company = $this->makeCompany('967771900005');
        $hq = app(AgentStaffService::class)->ensureHeadOfficeAccount($company, 'hq123456');

        Auth::guard('agent_staff')->login($hq);

        $html = $this->get('/login')->assertOk()->getContent();

        // النموذج نفسه — لا تحويل.
        $this->assertStringContainsString('name="password"', $html,
            'نموذج الدخول لا يظهر لمن له جلسة — فلا سبيل له إلى حسابٍ آخر');

        // ويُقال له من هو، ويُترك له البابان.
        // **بلا حركةٍ زائدة**: الصفحة تكتب «أنت داخل الآن»، والحارسُ كان
        // يطلب «داخلٌ». وفرقُ حركةٍ واحدةٍ يُسقط مطابقةً نصّيّةً ويصف
        // سلوكاً سليماً بالكسر — فيُرصد النصُّ كما هو مكتوب.
        $this->assertStringContainsString('أنت داخل الآن', $html);
        $this->assertStringContainsString(route('agent.dashboard'), $html,
            'لا بابَ يُتابع به إلى لوحته');
        $this->assertStringContainsString(route('agent.logout'), $html,
            'لا بابَ يخرج به ليدخل بحسابٍ آخر');
    }

    /**
     * @test
     *
     * **كلُّ بوّابةٍ معروضةٍ يُفتح منها بابٌ فعليّ.**
     *
     * ══════════════════════════════════════════════════════════════
     * **العطل: ثلاثةُ سطورٍ تشبه القائمة ولا يُفتح منها شيء.**
     *
     * عرضتُ البوّابات نصّاً غير قابلٍ للضغط، وكتبتُ أنّها «إخبارٌ لا
     * اختيار» لأنّ الصلاحيّة تُقرأ من الحساب. والمبدأ صحيح وطُبّق في غير
     * موضعه: قائمةٌ تقول «أنا أدمن» لا تمنح شيئاً، لكنّ **رابطاً إلى
     * صفحة دخولٍ عامّة تنقّلٌ لا تصريح**.
     *
     * فلم يجد صاحب المشروع سبيلاً إلى لوحة الإدارة من الموقع إطلاقاً،
     * وأخبرني **أربع مرّات**. ولا شيء في أيّ سجلّ: الصفحة تردّ ٢٠٠،
     * والنصّ ظاهر، ولا زرَّ مكسوراً — لأنّه لا زرَّ أصلاً.
     *
     * (مهارة `amial-interactive-ui`: «No fake UI. Everything visible
     * must function.»)
     */
    public function every_portal_shown_opens_a_real_door(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        foreach ([
            route('admin.auth.login') => 'لوحة الإدارة',
            route('agent.login')      => 'بوّابة شركات الصرافة',
        ] as $url => $label) {
            $path = parse_url($url, PHP_URL_PATH);

            $this->assertMatchesRegularExpression(
                '~<a[^>]+href="[^"]*' . preg_quote($path, '~') . '"~',
                $html,
                "«{$label}» معروضةٌ في /login بلا رابطٍ يُفتح منه");

            // ولا يكفي وجودُ الرابط: يُطلب فعلاً.
            $this->assertSame(200, $this->get($path)->getStatusCode(),
                "رابط «{$label}» موجودٌ ولا يفتح: {$path}");
        }
    }

    /**
     * @test
     *
     * **وبابُ الإدارة ظاهرٌ في الموقع كلّه لا في صفحة الدخول وحدها.**
     *
     * فمن أراد الإدارة كان عليه أن يكتب العنوان بيده — وهو ما لا يعرفه.
     */
    public function the_admin_door_is_visible_from_every_page(): void
    {
        foreach (['/', '/exchange', '/faq'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            // **يُفحص داخل الترويسة وحدها.**
            //
            // كتبتُه أوّلاً يبحث في الصفحة كلّها — فمرّ بعد نزع الرابط من
            // الترويسة عمداً، لأنّ الذيل يحمل الرابط نفسه. أي أنّه كان
            // يفحص وجودَ الذيل لا وجودَ الباب.
            //
            // والذيلُ لا يكفي: من يفتح الصفحة على هاتفٍ لا يصل إليه إلّا
            // بتمريرٍ طويل، ولا يخطر له أنّ بابَ الإدارة هناك.
            preg_match('~<header[^>]*class="[^"]*site-head[^"]*".*?</header>~s', $html, $m);

            $this->assertNotEmpty($m, "لا ترويسة في {$path} — تغيّرت بنية القالب");

            $this->assertStringContainsString(route('admin.auth.login'), $m[0],
                "لا بابَ للإدارة في ترويسة {$path} — فيُطلب من الأدمن أن يكتب العنوان بيده");
        }
    }

    /**
     * @test
     *
     * **والصفحة لا تُقرأ «بوّابة وكيل».**
     *
     * كان المثالُ في الحقل رمزَ صرّافٍ وحده (`MKL-014`) والسطرُ تحته يبدأ
     * بـ«الصرّاف يدخل برمزه» — فقرأها الأدمن بوّابةَ وكيلٍ وظنّ أنّه في
     * المكان الخطأ. وهو ما قاله أربع مرّات.
     */
    public function the_login_page_does_not_read_as_the_agent_portal(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('9677', $html,
            'المثال في الحقل رمزُ صرّافٍ وحده — فتُقرأ الصفحة بوّابةَ وكيل');

        $this->assertStringNotContainsString('الصرّاف يدخل برمزه', $html,
            'السطر ما زال يبدأ بالصرّاف — فيظنّ غيرُه أنّها ليست له');
    }
}
