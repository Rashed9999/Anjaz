<?php

namespace Tests\Feature;

use App\Models\ReportExport;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-REPORTS-001 (v2.11) — اختبارات نظام التقارير.
 */
class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReportService::class);
        Storage::fake('local');
    }

    /** @test */
    public function it_creates_report_request()
    {
        $user = User::factory()->create();
        $export = $this->service->request($user, 'user_transactions', 'csv');

        $this->assertEquals('pending', $export->status);
        $this->assertNotEmpty($export->export_ulid);
        $this->assertNotNull($export->expires_at);
    }

    /** @test */
    public function it_rejects_invalid_report_type()
    {
        $user = User::factory()->create();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('نوع تقرير غير صالح');
        $this->service->request($user, 'fake_report', 'csv');
    }

    /** @test */
    public function it_rejects_invalid_format()
    {
        $user = User::factory()->create();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('صيغة غير صالحة');
        $this->service->request($user, 'user_transactions', 'docx');
    }

    /** @test */
    public function it_generates_user_transactions_csv()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // عمليات
        DB::table('transactions')->insert([
            'transaction_id' => 'RPT1',
            'user_id' => $user->id, 'from_user_id' => $user->id, 'to_user_id' => $other->id,
            'transaction_type' => 1, 'amount' => 5000,
            'debit' => 5000, 'credit' => 0, 'balance' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $export = $this->service->request($user, 'user_transactions', 'csv');
        $this->service->generate($export);

        $export->refresh();
        $this->assertEquals('ready', $export->status);
        $this->assertEquals(1, $export->row_count);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));
    }

    /** @test */
    public function csv_has_bom_for_arabic()
    {
        $user = User::factory()->create();
        $export = $this->service->request($user, 'user_transactions', 'csv');
        $this->service->generate($export);

        $export->refresh();
        $content = Storage::disk('local')->get($export->file_path);
        // BOM في البداية لدعم العربية في Excel
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
    }

    /** @test */
    public function platform_performance_report_works()
    {
        $admin = User::factory()->create(['type' => 0]);
        User::factory()->count(3)->create(['type' => 3]); // 3 تجار

        $export = $this->service->request($admin, 'platform_performance', 'csv', [], 'admin');
        $this->service->generate($export);

        $export->refresh();
        $this->assertEquals('ready', $export->status);
        $this->assertGreaterThan(0, $export->row_count);
    }

    /** @test */
    public function failed_generation_marks_status()
    {
        $user = User::factory()->create();
        $export = $this->service->request($user, 'user_transactions', 'csv');

        // أفسد المسار لإجبار فشل (نحذف القرص المزيّف غير ممكن، لذا نختبر النوع غير المعروف)
        $export->update(['report_type' => 'corrupted_type']);

        try {
            $this->service->generate($export);
        } catch (\Throwable $e) {}

        $export->refresh();
        $this->assertEquals('failed', $export->status);
        $this->assertNotNull($export->error_message);
    }

    /** @test */
    public function cleanup_removes_expired_reports()
    {
        $user = User::factory()->create();
        // تقرير منتهي
        ReportExport::create([
            'export_ulid' => 'EXP_OLD', 'requested_by_user_id' => $user->id,
            'report_type' => 'user_transactions', 'format' => 'csv',
            'status' => 'ready', 'file_path' => 'reports/old.csv',
            'expires_at' => now()->subDay(),
        ]);

        $count = $this->service->cleanupExpired();
        $this->assertEquals(1, $count);
        $this->assertEquals('expired', ReportExport::where('export_ulid', 'EXP_OLD')->value('status'));
    }

    /** @test */
    public function is_ready_checks_expiry()
    {
        $user = User::factory()->create();
        $ready = ReportExport::create([
            'export_ulid' => 'EXP_R', 'requested_by_user_id' => $user->id,
            'report_type' => 'user_transactions', 'format' => 'csv',
            'status' => 'ready', 'file_path' => 'reports/r.csv',
            'expires_at' => now()->addDay(),
        ]);
        $expired = ReportExport::create([
            'export_ulid' => 'EXP_E', 'requested_by_user_id' => $user->id,
            'report_type' => 'user_transactions', 'format' => 'csv',
            'status' => 'ready', 'file_path' => 'reports/e.csv',
            'expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($ready->isReady());
        $this->assertFalse($expired->isReady());
    }

    /** @test */
    public function merchant_ledger_report_type_is_valid()
    {
        $merchant = User::factory()->create(['type' => 3]);
        $export = $this->service->request($merchant, 'merchant_ledger', 'csv',
            ['merchant_user_id' => $merchant->id], 'merchant');
        // يجب أن يُنشأ دون خطأ
        $this->assertEquals('merchant_ledger', $export->report_type);
    }
}
