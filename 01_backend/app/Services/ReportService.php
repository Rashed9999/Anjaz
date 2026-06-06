<?php

namespace App\Services;

use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-REPORTS-001 (v2.11)
 *
 * ReportService — توليد التقارير والتصدير (CSV/PDF).
 *
 * **المبدأ (من الوثيقة):**
 *   التقارير الكبيرة عبر queue. هذا الـ service يُستدعى من Job (الخلفية).
 *
 * **أنواع التقارير:**
 *   - merchant_ledger: دفتر التاجر المحاسبي
 *   - user_transactions: عمليات المستخدم
 *   - platform_performance: أداء المنصة (admin)
 *   - aml_compliance: تقرير الامتثال (admin / البنك المركزي)
 *   - agent_settlement: تسوية الوكلاء (admin)
 *
 * **CSV native** (لا مكتبة خارجية): أخف، أأمن، يكفي للتصدير.
 * PDF عبر dompdf الموجود.
 */
class ReportService
{
    private const RETENTION_DAYS = 7; // التقرير يُحذف بعد أسبوع

    /**
     * إنشاء طلب تقرير (يُستدعى من Controller، ثم يُرسَل لـ queue).
     */
    public function request(
        User $requester,
        string $reportType,
        string $format = 'csv',
        array $parameters = [],
        string $requesterType = 'user',
    ): ReportExport {
        $this->validateReportType($reportType);
        $this->validateFormat($format);

        return ReportExport::create([
            'export_ulid' => (string) Str::ulid(),
            'requested_by_user_id' => $requester->id,
            'requester_type' => $requesterType,
            'report_type' => $reportType,
            'format' => $format,
            'parameters' => $parameters,
            'status' => 'pending',
            'expires_at' => now()->addDays(self::RETENTION_DAYS),
            'zone_code' => 'SOUTH',
        ]);
    }

    /**
     * توليد التقرير فعلياً (يُستدعى من Job في الخلفية).
     */
    public function generate(ReportExport $export): void
    {
        $export->update(['status' => 'processing']);

        try {
            // 1) اجمع بيانات التقرير حسب النوع
            [$headers, $rows] = $this->buildReportData($export);

            // 2) ولّد الملف حسب الصيغة
            $path = match ($export->format) {
                'csv' => $this->writeCsv($export, $headers, $rows),
                'pdf' => $this->writePdf($export, $headers, $rows),
                'excel' => $this->writeCsv($export, $headers, $rows), // CSV متوافق مع Excel
                default => throw new RuntimeException('صيغة غير مدعومة'),
            };

            $export->update([
                'status' => 'ready',
                'file_path' => $path,
                'file_size' => Storage::disk('local')->size($path),
                'row_count' => count($rows),
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $export->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
            throw $e;
        }
    }

    // ============================================================
    // بناء بيانات التقارير
    // ============================================================

    private function buildReportData(ReportExport $export): array
    {
        return match ($export->report_type) {
            'merchant_ledger' => $this->merchantLedgerData($export),
            'user_transactions' => $this->userTransactionsData($export),
            'platform_performance' => $this->platformPerformanceData($export),
            'aml_compliance' => $this->amlComplianceData($export),
            'agent_settlement' => $this->agentSettlementData($export),
            default => throw new RuntimeException('نوع تقرير غير معروف'),
        };
    }

    /** دفتر التاجر المحاسبي */
    private function merchantLedgerData(ReportExport $export): array
    {
        $merchantId = $export->parameters['merchant_user_id'] ?? $export->requested_by_user_id;
        [$from, $to] = $this->dateRange($export);

        $headers = ['التاريخ', 'النوع', 'المرجع', 'الاتجاه', 'المبلغ', 'الرصيد بعد', 'الوصف'];

        $rows = DB::table('ledger_entry_lines as l')
            ->join('ledger_accounts as a', 'l.account_id', '=', 'a.id')
            ->leftJoin('ledger_journal_entries as j', 'l.journal_entry_id', '=', 'j.id')
            ->where('a.account_code', 'USER_WALLET_' . $merchantId)
            ->whereBetween('l.created_at', [$from, $to])
            ->orderBy('l.id')
            ->get(['l.created_at', 'j.source_type', 'j.entry_ulid', 'l.direction',
                   'l.amount', 'l.balance_after', 'j.description_ar'])
            ->map(fn($r) => [
                Carbon::parse($r->created_at)->format('Y-m-d H:i'),
                $this->translateSourceType($r->source_type),
                $r->entry_ulid ?? '-',
                $r->direction === 'credit' ? 'دائن' : 'مدين',
                number_format((float)$r->amount, 2),
                number_format((float)$r->balance_after, 2),
                $r->description_ar ?? '-',
            ])->toArray();

        return [$headers, $rows];
    }

    /** عمليات المستخدم */
    private function userTransactionsData(ReportExport $export): array
    {
        $userId = $export->requested_by_user_id;
        [$from, $to] = $this->dateRange($export);

        $headers = ['التاريخ', 'النوع', 'المبلغ', 'الحالة', 'المرجع'];

        $rows = DB::table('transactions')
            ->where(function ($q) use ($userId) {
                $q->where('from_user_id', $userId)->orWhere('to_user_id', $userId);
            })
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(10000)
            ->get(['created_at', 'transaction_type', 'amount', 'transaction_id', 'from_user_id', 'to_user_id'])
            ->map(fn($r) => [
                Carbon::parse($r->created_at)->format('Y-m-d H:i'),
                $r->from_user_id == $userId ? 'صادر' : 'وارد',
                number_format((float)$r->amount, 2),
                'مكتمل',
                $r->transaction_id ?? '-',
            ])->toArray();

        return [$headers, $rows];
    }

    /** أداء المنصة (admin) */
    private function platformPerformanceData(ReportExport $export): array
    {
        [$from, $to] = $this->dateRange($export);

        $headers = ['المؤشر', 'القيمة'];
        $rows = [
            ['إجمالي المستخدمين', User::count()],
            ['التجار', User::where('type', 3)->count()],
            ['الوكلاء', User::where('type', 1)->count()],
            ['عمليات الفترة', DB::table('transactions')->whereBetween('created_at', [$from, $to])->count()],
            ['حجم الفترة', number_format((float)DB::table('transactions')
                ->whereBetween('created_at', [$from, $to])->sum('amount'), 2)],
        ];

        return [$headers, $rows];
    }

    /** تقرير الامتثال AML (admin / البنك المركزي) */
    private function amlComplianceData(ReportExport $export): array
    {
        [$from, $to] = $this->dateRange($export);

        $headers = ['التاريخ', 'المستخدم', 'النوع', 'القرار', 'النقاط', 'السبب'];

        $rows = DB::table('aml_decisions')
            ->whereBetween('created_at', [$from, $to])
            ->where('final_action', '!=', 'allow')
            ->orderByDesc('created_at')
            ->limit(10000)
            ->get()
            ->map(fn($r) => [
                Carbon::parse($r->created_at)->format('Y-m-d H:i'),
                $r->user_id ?? '-',
                $r->transaction_type ?? '-',
                $r->final_action ?? '-',
                $r->total_risk_score ?? '0',
                mb_substr($r->reason ?? '-', 0, 100),
            ])->toArray();

        return [$headers, $rows];
    }

    /** تسوية الوكلاء (admin) */
    private function agentSettlementData(ReportExport $export): array
    {
        [$from, $to] = $this->dateRange($export);
        $headers = ['التاريخ', 'الوكيل', 'النوع', 'المبلغ', 'الحالة'];

        if (!DB::getSchemaBuilder()->hasTable('agent_settlements')) {
            return [$headers, []];
        }

        $rows = DB::table('agent_settlements')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->limit(10000)
            ->get()
            ->map(fn($r) => [
                Carbon::parse($r->created_at)->format('Y-m-d H:i'),
                $r->agent_user_id ?? '-',
                $r->settlement_type ?? '-',
                number_format((float)($r->amount ?? 0), 2),
                $r->status ?? '-',
            ])->toArray();

        return [$headers, $rows];
    }

    // ============================================================
    // كتابة الملفات
    // ============================================================

    private function writeCsv(ReportExport $export, array $headers, array $rows): string
    {
        $path = $this->buildPath($export, 'csv');
        $full = Storage::disk('local')->path($path);
        @mkdir(dirname($full), 0775, true);

        $fp = fopen($full, 'w');
        // BOM لدعم العربية في Excel
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        return $path;
    }

    private function writePdf(ReportExport $export, array $headers, array $rows): string
    {
        if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new RuntimeException('dompdf غير مثبّت');
        }
        $path = $this->buildPath($export, 'pdf');
        $full = Storage::disk('local')->path($path);
        @mkdir(dirname($full), 0775, true);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.pdf', [
            'title' => $this->translateReportType($export->report_type),
            'headers' => $headers,
            'rows' => $rows,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);
        file_put_contents($full, $pdf->output());

        return $path;
    }

    private function buildPath(ReportExport $export, string $ext): string
    {
        $date = now()->format('Y/m/d');
        return "reports/{$date}/{$export->report_type}_{$export->export_ulid}.{$ext}";
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function dateRange(ReportExport $export): array
    {
        $from = isset($export->parameters['from'])
            ? Carbon::parse($export->parameters['from'])
            : now()->startOfMonth();
        $to = isset($export->parameters['to'])
            ? Carbon::parse($export->parameters['to'])->endOfDay()
            : now()->endOfDay();
        return [$from, $to];
    }

    private function translateSourceType(?string $type): string
    {
        return match ($type) {
            'send_money' => 'تحويل',
            'bill_pay' => 'دفع فاتورة',
            'cash_in' => 'إيداع',
            'cash_out' => 'سحب',
            'safe_payment' => 'دفع آمن',
            'merchant_refund' => 'استرجاع',
            default => $type ?? '-',
        };
    }

    private function translateReportType(string $type): string
    {
        return match ($type) {
            'merchant_ledger' => 'دفتر التاجر المحاسبي',
            'user_transactions' => 'كشف عمليات المستخدم',
            'platform_performance' => 'تقرير أداء المنصة',
            'aml_compliance' => 'تقرير الامتثال (AML)',
            'agent_settlement' => 'تقرير تسوية الوكلاء',
            default => 'تقرير',
        };
    }

    private function validateReportType(string $type): void
    {
        $valid = ['merchant_ledger', 'user_transactions', 'platform_performance',
                  'aml_compliance', 'agent_settlement'];
        if (!in_array($type, $valid, true)) {
            throw new RuntimeException('نوع تقرير غير صالح');
        }
    }

    private function validateFormat(string $format): void
    {
        if (!in_array($format, ['csv', 'pdf', 'excel'], true)) {
            throw new RuntimeException('صيغة غير صالحة');
        }
    }

    /** تنظيف التقارير المنتهية (scheduled) */
    public function cleanupExpired(): int
    {
        $expired = ReportExport::where('expires_at', '<', now())
            ->where('status', 'ready')->get();
        $count = 0;
        foreach ($expired as $export) {
            if ($export->file_path) {
                Storage::disk('local')->delete($export->file_path);
            }
            $export->update(['status' => 'expired', 'file_path' => null]);
            $count++;
        }
        return $count;
    }
}
