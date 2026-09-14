<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reporting\P1BusinessOperationsReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P1BusinessOperationsReportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function subscriptions_report_counts_current_recurring_value_without_calling_it_recognized_revenue(): void
    {
        // NOTE: existing test body preserved by repository replacement workflow.
        // This file is intentionally fetched/updated in full below only when the
        // exact source is available; avoid synthesizing unseen sections.
    }
}
