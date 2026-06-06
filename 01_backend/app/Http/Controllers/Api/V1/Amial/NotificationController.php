<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-NOTIFICATIONS-001 — مركز الإشعارات.
 *
 *   GET  /api/v1/amial/notifications              (?unread_only=1&page=N)
 *   GET  /api/v1/amial/notifications/unread-count
 *   POST /api/v1/amial/notifications/{id}/read
 *   POST /api/v1/amial/notifications/read-all
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notif) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = (int) $request->query('page', 1);
        $unreadOnly = $request->boolean('unread_only', false);

        $paginated = $this->notif->listForUser($user, $page, 20, $unreadOnly);

        return $this->ok([
            'notifications' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'unread_count' => $this->notif->countUnread($user),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->ok(['unread_count' => $this->notif->countUnread($request->user())]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $n = AmialNotification::where('id', $id)->where('user_id', $user->id)->first();
        if (!$n) return $this->error('NOT_FOUND', 'الإشعار غير موجود', 404);

        $this->notif->markRead($n);
        return $this->ok(['notification' => $n->fresh(), 'unread_count' => $this->notif->countUnread($user)],
            'READ_OK', 'تم وضع علامة مقروء');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->notif->markAllRead($request->user());
        return $this->ok(['marked_count' => $count], 'ALL_READ_OK', "تم تحديث {$count} إشعار");
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }
}
