# AMIAL-NOTIFICATIONS-001 — مركز الإشعارات

## الغرض
ملء الفجوة: شعار الجرس كان في كل شاشة لكن لا backend خلفه. الآن النظام عامل ومتكامل مع الديون تلقائياً.

## ما اكتمل

### Backend
- **Migration `notifications`**: user_id (index)، type، title، body، icon، action_url، data (JSON)، read_at.
- **`Notification` model** + **`NotificationService`** (dispatch، list، count، markRead، markAllRead).
- **`NotificationController` + 4 endpoints**:
  - `GET /api/v1/amial/notifications` (paginated، فلتر unread_only).
  - `GET /api/v1/amial/notifications/unread-count`.
  - `POST /api/v1/amial/notifications/{id}/read`.
  - `POST /api/v1/amial/notifications/read-all`.
- **التكامل التلقائي مع نظام الديون**:
  - عند بيع آجل → إشعار للعميل (إن مسجّل في أميال باي).
  - عند سداد → إشعار للعميل.
  - عند تجاوز الحد → إشعار للتاجر.
  - الإشعارات **best-effort** — فشلها لا يكسر القيد.
- **8 اختبارات**.
- **Push FCM موحّد** لكل إشعار داخلي عبر Queue بعد نجاح commit، مع صوت وقناة Android الموحدة.
- **سجل تسليم خارجي** `notification_delivery_logs`: قبول المزود/الفشل/التخطي/المحاولة ومرجع FCM دون تخزين token أو payload حساس.
- المسارات القديمة المباشرة وTopic broadcasts تكتب في سجل التسليم نفسه، ولوحة الإدارة لا تعرض نجاحاً إذا رفض FCM الإرسال.

### Flutter
- `NotificationsCenterRepo` + `NotificationsCenterController` (مع pagination + infinite scroll).
- `NotificationsCenterScreen`:
  - فلتر "غير المقروءة فقط" (toggle).
  - عدد غير المقروء.
  - أيقونة ولون لكل type.
  - تنسيق زمني نسبي ("منذ N دقيقة").
  - markRead عند الضغط على إشعار.
  - markAllRead في الـ AppBar.
  - infinite scroll.
- **جرس الإشعارات + badge** في لوحة التاجر — يفتح المركز تلقائياً ويُحدّث العدد عند العودة.

## أنواع الإشعارات المدعومة (TYPES)
- `transfer_received` / `transfer_sent`
- `withdrawal_completed` / `withdrawal_failed` / `withdrawal_pending`
- `credit_sale` / `credit_payment` / `credit_over_limit`
- `merchant_payment_received`
- `system` / `promo` / `terms_update`

## ما بقي
- إعدادات المستخدم الدقيقة لتشغيل/إيقاف أنواع إشعارات بعينها لم تُبنَ بعد.
- `provider_accepted` يعني أن FCM قبل الرسالة؛ لا يُقدَّم على أنه إثبات أن نظام التشغيل عرضها على شاشة الجهاز.
- إرسال Topic الجماعي موجود من شاشة الإدارة ويُسجَّل كقناة `fcm_topic` في سجل التسليم.

## للتحقّق
```bash
php artisan migrate
php artisan test --filter=Notification
flutter analyze lib/features/notification/
```

## الفحص الذي أجريت
هذا الملف يصف البنية الحالية؛ حالة CI لكل تعديل تُراجع من GitHub Actions ولا يُفترض نجاحها قبل اكتمال التشغيل.

## كيف تستخدمه في كود آخر
```php
app(NotificationService::class)->dispatch(
    $user,
    type: 'transfer_received',
    title: 'حوالة واردة',
    body: 'استلمت 5000 ر.ي من أحمد',
    data: ['amount' => '5000', 'sender_phone' => '+967700111'],
);
```
