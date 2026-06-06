<?php

namespace Tests\Feature;

use App\Jobs\CheckExpiringSubscriptionsJob;
use App\Models\MerchantProfile;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Notifications\DailySubscriptionDigestNotification;
use App\Notifications\SubscriptionExpiringSoonNotification;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SubscriptionNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function expiring_soon_notification_has_correct_urgency_for_different_days(): void
    {
        $user = User::factory()->create(['type' => 3]);
        $profile = MerchantProfile::create([
            'user_id' => $user->id, 'verification_status' => 'verified',
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_STARTER,
            'subscription_expires_at' => now()->addDays(7),
        ]);

        $notif = new SubscriptionExpiringSoonNotification($profile, 7);
        $arr = $notif->toArray($user);
        $this->assertEquals('medium', $arr['urgency']);

        $notif1 = new SubscriptionExpiringSoonNotification($profile, 1);
        $this->assertEquals('high', $notif1->toArray($user)['urgency']);

        $notif0 = new SubscriptionExpiringSoonNotification($profile, 0);
        $this->assertEquals('critical', $notif0->toArray($user)['urgency']);
    }

    /** @test */
    public function job_sends_notification_to_merchant_expiring_in_3_days(): void
    {
        $user = User::factory()->create(['type' => 3]);
        MerchantProfile::create([
            'user_id' => $user->id, 'verification_status' => 'verified',
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_STARTER,
            'subscription_expires_at' => now()->addDays(3),
        ]);

        $job = new CheckExpiringSubscriptionsJob();
        $job->handle(app(\App\Services\SubscriptionService::class));

        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $user->id, 'type' => 'subscription_expiring',
        ]);
    }

    /** @test */
    public function job_does_not_notify_for_5_days_left(): void
    {
        $user = User::factory()->create(['type' => 3]);
        MerchantProfile::create([
            'user_id' => $user->id, 'verification_status' => 'verified',
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_STARTER,
            'subscription_expires_at' => now()->addDays(5),
        ]);

        $job = new CheckExpiringSubscriptionsJob();
        $job->handle(app(\App\Services\SubscriptionService::class));

        // العتبات: 7, 3, 1, 0 — 5 ليست منها
        $this->assertDatabaseMissing('amial_notifications', [
            'user_id' => $user->id, 'type' => 'subscription_expiring',
        ]);
    }

    /** @test */
    public function admin_receives_daily_digest(): void
    {
        $admin = User::factory()->create(['type' => 1]);

        $job = new CheckExpiringSubscriptionsJob();
        $job->handle(app(\App\Services\SubscriptionService::class));

        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $admin->id, 'type' => 'subscription_daily_digest',
        ]);
    }
}
