<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-NOTIFICATIONS-001 — اختبارات مركز الإشعارات.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $svc;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(NotificationService::class);
        $this->user = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH']);
    }

    /** @test */
    public function dispatch_creates_notification_for_user(): void
    {
        $n = $this->svc->dispatch(
            $this->user,
            type: 'transfer_received',
            title: 'حوالة واردة',
            body: 'استلمت 5000 ر.ي من أحمد',
            data: ['amount' => '5000', 'sender_phone' => '+967700111'],
        );

        $this->assertSame($this->user->id, $n->user_id);
        $this->assertSame('transfer_received', $n->type);
        $this->assertSame('swap_horiz', $n->icon); // أيقونة افتراضية
        $this->assertNull($n->read_at);
        $this->assertSame('5000', $n->data['amount']);
    }

    /** @test */
    public function dispatch_rejects_empty_title_or_body(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->dispatch($this->user, 'system', '', 'body');
    }

    /** @test */
    public function list_paginates_and_orders_by_newest(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->svc->dispatch($this->user, 'system', "إشعار {$i}", "نص {$i}");
        }

        $page = $this->svc->listForUser($this->user, 1, 20);
        $this->assertSame(5, $page->total());
        $this->assertSame('إشعار 5', $page->items()[0]->title); // الأحدث أولاً
    }

    /** @test */
    public function unread_only_filter_works(): void
    {
        $a = $this->svc->dispatch($this->user, 'system', 'أ', 'نص');
        $b = $this->svc->dispatch($this->user, 'system', 'ب', 'نص');
        $this->svc->markRead($a);

        $unread = $this->svc->listForUser($this->user, 1, 20, true);
        $this->assertSame(1, $unread->total());
        $this->assertSame($b->id, $unread->items()[0]->id);
    }

    /** @test */
    public function mark_read_sets_timestamp_only_once(): void
    {
        $n = $this->svc->dispatch($this->user, 'system', 't', 'b');
        $this->svc->markRead($n);
        $n->refresh();
        $firstTime = $n->read_at;
        $this->assertNotNull($firstTime);

        // ثانية: لا يجب أن يُحدّث
        sleep(1);
        $this->svc->markRead($n);
        $n->refresh();
        $this->assertEquals($firstTime, $n->read_at);
    }

    /** @test */
    public function mark_all_read_returns_count(): void
    {
        $this->svc->dispatch($this->user, 'system', 'a', 'b');
        $this->svc->dispatch($this->user, 'system', 'c', 'd');
        $this->svc->dispatch($this->user, 'system', 'e', 'f');

        $count = $this->svc->markAllRead($this->user);
        $this->assertSame(3, $count);
        $this->assertSame(0, $this->svc->countUnread($this->user));
    }

    /** @test */
    public function count_unread_isolates_per_user(): void
    {
        $other = User::factory()->create(['type' => 2]);
        $this->svc->dispatch($this->user, 'system', 'a', 'b');
        $this->svc->dispatch($other, 'system', 'c', 'd');

        $this->assertSame(1, $this->svc->countUnread($this->user));
        $this->assertSame(1, $this->svc->countUnread($other));
    }

    /** @test */
    public function api_get_notifications_works(): void
    {
        $this->svc->dispatch($this->user, 'system', 'مرحباً', 'نصّ');

        $r = $this->actingAs($this->user, 'api')->getJson('/api/v1/amial/notifications');
        $r->assertOk()
          ->assertJsonPath('success', true)
          ->assertJsonPath('meta.pagination.total', 1)
          ->assertJsonPath('meta.pagination.unread_count', 1);
    }
}
