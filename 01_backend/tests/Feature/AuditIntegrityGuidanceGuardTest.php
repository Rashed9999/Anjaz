<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-AUDIT-GUIDANCE-001
 *
 * سجل التدقيق يجب أن يفرّق بين «اختلاف سلامة مثبت» وبين «عبث متعمد مثبت».
 * اختلاف البصمة وحده لا يثبت النية ولا الأثر المالي، ولذلك نحرس الصياغة
 * الإدارية حتى لا تعود الشاشة إلى إنذار قاطع ومضلل.
 */
class AuditIntegrityGuidanceGuardTest extends TestCase
{
    /** @test */
    public function audit_integrity_banner_is_actionable_and_does_not_overclaim_tampering(): void
    {
        $view = file_get_contents(
            resource_path('views/admin-views/amial/audit/index.blade.php')
        );

        $this->assertIsString($view);
        $this->assertStringContainsString('سلامة سجل التدقيق تحتاج تحقيقًا', $view);
        $this->assertStringContainsString('ما الذي نعرفه؟', $view);
        $this->assertStringContainsString('ما الذي لا نعرفه بعد؟', $view);
        $this->assertStringContainsString('هل ثبت أثر مالي؟', $view);
        $this->assertStringContainsString('ماذا أفعل الآن؟', $view);
        $this->assertStringContainsString('ابدأ التحقيق: اعرض السجلات غير المفسرة', $view);
        $this->assertStringContainsString('php artisan amial:audit-verify', $view);
        $this->assertStringContainsString('لا تعد كتابة البصمات', $view);

        $this->assertStringNotContainsString('⚠ عُبث بالسجلّ', $view,
            'اختلاف البصمة لا يثبت وحده عبثاً متعمداً؛ يجب أن يبقى التحذير مبنياً على الدليل.');
    }

    /** @test */
    public function technically_explained_rewrites_are_not_presented_as_an_action_required_alert(): void
    {
        $view = file_get_contents(
            resource_path('views/admin-views/amial/audit/index.blade.php')
        );

        $this->assertStringContainsString('تغيير معروف السبب — لا يحتاج إجراء', $view);
        $this->assertStringContainsString('مفسّر تقنيًا', $view);
    }

    /** @test */
    public function full_chain_verifier_uses_evidence_based_language(): void
    {
        $command = file_get_contents(app_path('Console/Commands/AuditChainVerify.php'));

        $this->assertIsString($command);
        $this->assertStringContainsString('سلامة سجل التدقيق تحتاج تحقيقاً', $command);
        $this->assertStringContainsString('لا يثبت وحده عبثاً متعمداً أو أثراً مالياً', $command);
        $this->assertStringNotContainsString('عُثر على عبث في السلسلة', $command);
    }
}
