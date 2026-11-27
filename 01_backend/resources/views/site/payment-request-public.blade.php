<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طلب دفع — أميال باي</title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif; background: #f4f7f6; color: #16302b; margin: 0; padding: 24px; display: flex; align-items: center; justify-content: center; min-height: 100vh; box-sizing: border-box; }
        .card { background: #fff; border-radius: 18px; max-width: 440px; width: 100%; padding: 28px; box-shadow: 0 12px 32px rgba(22, 48, 43, 0.08); text-align: center; }
        .brand { font-size: 20px; font-weight: 800; color: #0f6e5c; margin-bottom: 16px; }
        .amount { font-size: 32px; font-weight: 800; color: #16302b; margin: 12px 0; direction: ltr; }
        .badge { display: inline-block; padding: 6px 14px; border-radius: 999px; font-size: 13px; font-weight: 700; margin-bottom: 16px; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-paid { background: #d4edda; color: #155724; }
        .badge-expired, .badge-cancelled, .badge-declined { background: #f8d7da; color: #721c24; }
        .meta { background: #f8faf9; border-radius: 12px; padding: 14px; text-align: right; font-size: 14px; line-height: 1.8; margin: 16px 0; }
        .code { font-family: monospace; font-size: 18px; font-weight: 700; letter-spacing: 2px; background: #eef4f2; padding: 10px 16px; border-radius: 10px; display: inline-block; margin: 8px 0; direction: ltr; }
        .hint { font-size: 13px; color: #5b6d68; margin-top: 16px; line-height: 1.6; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">أميال باي · Amial Pay</div>
    @if(! $paymentRequest)
        <h2>الطلب غير موجود</h2>
        <p class="hint">تحقق من رمز طلب الدفع أو اطلب رابطاً جديداً من المرسل.</p>
    @else
        @php
            $effectiveStatus = ($paymentRequest->status === 'pending' && $paymentRequest->expires_at && $paymentRequest->expires_at->isPast())
                ? 'expired'
                : $paymentRequest->status;
            $labels = [
                'pending' => 'بانتظار الدفع',
                'paid' => 'تم الدفع',
                'declined' => 'مرفوض',
                'cancelled' => 'ملغى',
                'expired' => 'منتهي الصلاحية',
            ];
        @endphp
        <span class="badge badge-{{ $effectiveStatus }}">{{ $labels[$effectiveStatus] ?? $effectiveStatus }}</span>
        <div class="amount">{{ number_format((float) $paymentRequest->amount, 2) }} YER</div>
        <div class="code">{{ $paymentRequest->short_code }}</div>
        @if($paymentRequest->expires_at)
            <div class="meta">
                <div><strong>صالح حتى:</strong> <span dir="ltr">{{ $paymentRequest->expires_at->format('Y-m-d H:i') }}</span></div>
            </div>
        @endif
        <p class="hint">
            للاطلاع على تفاصيل المرسل وإتمام الدفع بأمان، افتح تطبيق <strong>أميال باي</strong> واختر «دفع طلب مال» ثم أدخل الرمز أعلاه بعد تسجيل الدخول.
        </p>
    @endif
</div>
</body>
</html>
