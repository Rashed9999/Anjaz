<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BusinessGateway\BusinessGatewayException;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\BusinessGateway\BusinessPaymentIntentService;
use App\Services\BusinessGateway\BusinessWebhookDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — أوامر تشغيل قناة «أميال أعمال».
 *
 *   amial:business link {merchant_user_id}   ربط تاجر قائم (يدوي، للتجار الحاليين)
 *   amial:business tick                      إنهاء النوايا المنتهية + تسليم الأحداث + تنظيف nonces
 *   amial:business reminders                 تذكير العملاء بديونهم المستحقة لدى تجار أميال أعمال
 *   amial:business credit-release {merchant} --reason=…   (دعم فقط) إرجاع الآجل لأميال باي
 *                                            بعد ترحيل فشل قبل أن يُسجَّل عليه شيء في أميال أعمال
 */
class AmialBusinessGateway extends Command
{
    protected $signature = 'amial:business {action : link|tick|reminders|credit-release} {merchant? : رقم مستخدم التاجر}
        {--reason= : سبب إرجاع الآجل (إلزامي لـ credit-release)}';

    protected $description = 'قناة أميال أعمال: ربط تاجر، أو دورة الصيانة الدورية';

    public function handle(
        BusinessLinkService $links,
        BusinessPaymentIntentService $intents,
        BusinessWebhookDispatcher $dispatcher,
    ): int {
        return match ($this->argument('action')) {
            'link' => $this->link($links),
            'tick' => $this->tick($intents, $dispatcher),
            'reminders' => $this->reminders(),
            'credit-release' => $this->creditRelease(),
            default => $this->invalid(),
        };
    }

    private function link(BusinessLinkService $links): int
    {
        $merchant = User::find((int) $this->argument('merchant'));
        if (!$merchant) {
            $this->error('التاجر غير موجود');

            return self::FAILURE;
        }

        try {
            $link = $links->link($merchant);
        } catch (BusinessGatewayException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("link_id: {$link->link_ulid}");

        return self::SUCCESS;
    }

    private function tick(BusinessPaymentIntentService $intents, BusinessWebhookDispatcher $dispatcher): int
    {
        $expired = $intents->expireDue();
        $expired += app(\App\Services\BusinessGateway\BusinessRefundService::class)->expireDue();
        $sent = $dispatcher->dispatchDue();
        $pruned = DB::table('business_request_nonces')->where('created_at', '<', now()->subDay())->delete();

        $this->line(sprintf(
            'expired=%d delivered=%d failed=%d dead=%d pruned_nonces=%d%s',
            $expired, $sent['delivered'], $sent['failed'], $sent['dead'], $pruned,
            $sent['skipped'] ? ' skipped='.$sent['skipped'] : '',
        ));

        return self::SUCCESS;
    }

    private function reminders(): int
    {
        $sent = app(\App\Services\BusinessGateway\BusinessCustomerDebtService::class)->sendDueReminders();
        $this->line("reminders_sent={$sent}");

        return self::SUCCESS;
    }

    private function creditRelease(): int
    {
        $reason = trim((string) $this->option('reason'));
        $link = \App\Models\BusinessGateway\BusinessLink::where('merchant_user_id', (int) $this->argument('merchant'))->first();
        if (!$link || $reason === '') {
            $this->error('حدّد التاجر المربوط و--reason');

            return self::FAILURE;
        }
        if (!$this->confirm('سيعود أميال باي يقبل حركات الآجل لهذا التاجر. تأكّد أن أميال أعمال لم تُسجّل عليه شيئاً. متابعة؟')) {
            return self::FAILURE;
        }
        app(\App\Services\BusinessGateway\CreditHandoverService::class)->release($link, $reason, null);
        $this->info('أُرجع الآجل لأميال باي');

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('الإجراء: link أو tick أو reminders أو credit-release');

        return self::INVALID;
    }
}
