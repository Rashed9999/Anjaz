<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AMIAL-REPORTS-001 (v2.11)
 *
 * GenerateReportJob — يولّد التقرير في الخلفية (عبر queue).
 * من الوثيقة: "التقارير الكبيرة عبر queue".
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 دقائق للتقارير الكبيرة
    public int $tries = 2;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportService $service): void
    {
        $export = ReportExport::find($this->exportId);
        if (!$export || $export->status !== 'pending') {
            return;
        }

        try {
            $service->generate($export);
            \Log::info("Report generated: {$export->export_ulid} ({$export->row_count} rows)");
        } catch (\Throwable $e) {
            \Log::error('Report generation failed', [
                'export' => $export->export_ulid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
