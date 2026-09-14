<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\KycForensicWatermarkService;
use App\Services\KycDocumentService;
use App\Support\PlatformAccessTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class KycForensicWatermarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_preview_is_a_watermarked_copy_with_a_trace_to_the_viewer(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not available in this PHP runtime.');
        }

        $subject = User::factory()->create();
        $viewer = User::factory()->create(['type' => 0, 'role' => 'super_admin']);
        $document = app(KycDocumentService::class)->upload(
            $subject,
            KycDocument::TYPE_ID_FRONT,
            UploadedFile::fake()->image('identity.jpg', 800, 520),
        );

        $original = app(KycDocumentService::class)->decrypt($document);
        $preview = app(KycForensicWatermarkService::class)->render(
            $document,
            $viewer,
            'اختبار المعاينة الجنائية',
        );

        $this->assertMatchesRegularExpression('/^AM-[A-F0-9]{20}$/', $preview['trace_code']);
        $this->assertSame('image/jpeg', $preview['mime']);
        $this->assertNotSame(hash('sha256', $original), hash('sha256', $preview['bytes']),
            'المعاينة هي الأصل نفسه — لم تُحرق العلامة داخل البكسلات');
        $this->assertNotFalse(@imagecreatefromstring($preview['bytes']),
            'العلامة أفسدت الصورة ولم تعد قابلة للعرض');

        $this->assertDatabaseHas('kyc_forensic_views', [
            'trace_code' => $preview['trace_code'],
            'document_id' => $document->id,
            'subject_user_id' => $subject->id,
            'actor_user_id' => $viewer->id,
            'doc_type' => KycDocument::TYPE_ID_FRONT,
        ]);
        $this->assertDatabaseHas('pii_access_logs', [
            'actor_user_id' => $viewer->id,
            'subject_id' => $subject->id,
            'field_name' => 'kyc_document:' . KycDocument::TYPE_ID_FRONT,
        ]);
    }

    public function test_unsupported_preview_fails_closed_instead_of_returning_the_original(): void
    {
        $subject = User::factory()->create();
        $viewer = User::factory()->create(['type' => 0, 'role' => 'super_admin']);
        $document = app(KycDocumentService::class)->upload(
            $subject,
            KycDocument::TYPE_ID_FRONT,
            UploadedFile::fake()->createWithContent('identity.pdf', '%PDF-1.4 fake')
                ->mimeType('application/pdf'),
        );

        try {
            app(KycForensicWatermarkService::class)->render($document, $viewer, 'PDF test');
            $this->fail('صيغة غير قابلة للحرق خرجت كأنها معاينة آمنة.');
        } catch (RuntimeException $e) {
            $this->assertSame('KYC_SECURE_PREVIEW_UNSUPPORTED_FORMAT', $e->getMessage());
        }

        $this->assertSame(0, DB::table('kyc_forensic_views')->where('document_id', $document->id)->count(),
            'سُجّل Trace على أنه نسخة صادرة بينما لم تُصدر معاينة آمنة.');
    }

    public function test_biometric_permission_is_independently_grantable(): void
    {
        $this->assertTrue(
            PlatformAccessTabs::isGrantable('platform.customers.kyc.biometric.view'),
            'صلاحية السيلفي ليست منفصلة في شاشة أدوار الموظفين.',
        );
    }
}
