<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-NO-ACCOUNT-CREATION-001 — أميال باي لا ينشئ حسابات، ولا واجهته تقول إنه ينشئ.
 *
 * **سؤال صاحب المشروع من لقطة:** «لماذا أميال باي يستطيع إنشاء حساب؟». الخادم كان يمنع (410 على كل مسارات التسجيل)،
 * لكنّ نافذة «ملف فتح عميل» بقيت في لوحة الإدارة بكل حقولها وزرّ «حفظ ملف الفتح وإنشاء الحساب» — واجهةٌ تقول ما لا يفعله
 * الخادم، فتُوهم المشغّل بأنّ هذا الباب مفتوح، وتُغريه بإدخال بيانات هويّة وكلمة سرٍّ وPIN في نموذجٍ لا يحفظ شيئاً.
 */
class HubHasNoAccountCreationFormTest extends TestCase
{
    /** @test */
    public function the_admin_hub_page_has_no_account_creation_form_and_points_to_muwathaq(): void
    {
        $view = file_get_contents(resource_path('views/admin-views/amial/hub/users.blade.php'));

        foreach (['opening-dossier-form', 'add-submit', 'حفظ ملف الفتح وإنشاء الحساب', 'name="identity_front"', 'name="password"'] as $needle) {
            $this->assertStringNotContainsString($needle, $view, "نموذجُ فتح الحساب ما زال في لوحة أميال باي ({$needle})");
        }

        $this->assertStringContainsString('أميال باي لا ينشئ حسابات', $view);
        $this->assertStringContainsString('/open-account?type=customer', $view, 'لا رابط إلى موثّق لفتح العميل');
        $this->assertStringContainsString('/accounts/new-agent', $view, 'لا رابط إلى موثّق لفتح الوكيل');
    }

    /** @test */
    public function the_page_script_no_longer_references_removed_elements(): void
    {
        $view = file_get_contents(resource_path('views/admin-views/amial/hub/users.blade.php'));

        // مرجعٌ إلى عنصرٍ حُذف يقرأ null فيموت المعالج كلُّه صامتاً (القاعدة التاسعة).
        $this->assertStringNotContainsString("getElementById('add-submit')", $view);
        $this->assertStringNotContainsString("getElementById('add-error')", $view);
        $this->assertStringNotContainsString("getElementById('opening-dossier-form')", $view);
    }
}
