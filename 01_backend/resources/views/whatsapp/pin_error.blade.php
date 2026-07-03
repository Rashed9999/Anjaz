<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>أميال باي — خطأ</title>
<style>
  body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #fff1f2;
    min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { background: white; border-radius: 16px; padding: 40px 24px;
    max-width: 360px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
  .icon { font-size: 64px; margin-bottom: 16px; }
  h2 { color: #dc2626; font-size: 20px; margin-bottom: 8px; }
  p { color: #6b7280; font-size: 14px; }
</style>
</head>
<body>
<div class="card">
  <div class="icon">❌</div>
  <h2>فشلت العملية</h2>
  <p>{{ $message ?? 'حدث خطأ غير متوقّع.' }}</p>
  <p style="margin-top:12px; font-size:12px; color:#9ca3af;">
    اكتب <strong>قائمة</strong> على واتساب للمحاولة مجدّداً.
  </p>
</div>
</body>
</html>
