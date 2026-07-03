<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>أميال باي — تأكيد التحويل</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Segoe UI', Tahoma, sans-serif;
    background: #f0f4f8;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .card {
    background: white;
    border-radius: 16px;
    padding: 32px 24px;
    width: 100%;
    max-width: 360px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    text-align: center;
  }
  .logo { font-size: 48px; margin-bottom: 8px; }
  .app-name { color: #053391; font-size: 22px; font-weight: 700; margin-bottom: 4px; }
  .subtitle { color: #6b7280; font-size: 14px; margin-bottom: 28px; }
  .label {
    display: block;
    text-align: right;
    font-size: 13px;
    color: #374151;
    font-weight: 600;
    margin-bottom: 8px;
  }
  .pin-input {
    width: 100%;
    padding: 14px;
    font-size: 24px;
    letter-spacing: 10px;
    text-align: center;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    outline: none;
    transition: border-color 0.2s;
    margin-bottom: 20px;
    -webkit-text-security: disc;
  }
  .pin-input:focus { border-color: #053391; }
  .btn {
    width: 100%;
    padding: 14px;
    background: #053391;
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.2s;
  }
  .btn:hover { background: #042a7a; }
  .btn:disabled { background: #9ca3af; cursor: not-allowed; }
  .warning {
    margin-top: 20px;
    padding: 10px 14px;
    background: #fef3c7;
    border-radius: 8px;
    font-size: 12px;
    color: #92400e;
  }
  .timer {
    margin-top: 12px;
    font-size: 12px;
    color: #ef4444;
    font-weight: 600;
  }
</style>
</head>
<body>
<div class="card">
  <div class="logo">🔐</div>
  <div class="app-name">أميال باي</div>
  <div class="subtitle">أدخل PIN لتأكيد التحويل</div>

  <form method="POST" action="/wa/pin/{{ $token }}" id="pinForm">
    @csrf
    <label class="label">رمز PIN الخاصّ بك:</label>
    <input
      type="password"
      name="pin"
      class="pin-input"
      maxlength="6"
      minlength="4"
      inputmode="numeric"
      pattern="[0-9]*"
      autocomplete="off"
      autofocus
      required
    >
    <button type="submit" class="btn" id="submitBtn">
      تأكيد التحويل ✓
    </button>
  </form>

  <div class="warning">
    🔒 هذا الرابط للاستخدام مرّة واحدة فقط وصالح لدقيقتين.
    لا تشاركه مع أحد.
  </div>

  <div class="timer" id="timer"></div>
</div>

<script>
  // Countdown timer
  var seconds = 120;
  var timerEl = document.getElementById('timer');
  var submitBtn = document.getElementById('submitBtn');

  var interval = setInterval(function() {
    seconds--;
    var m = Math.floor(seconds / 60);
    var s = seconds % 60;
    timerEl.textContent = 'ينتهي الرابط خلال: ' + m + ':' + (s < 10 ? '0' : '') + s;

    if (seconds <= 0) {
      clearInterval(interval);
      submitBtn.disabled = true;
      timerEl.textContent = '❌ انتهت صلاحية الرابط. أعِد المحادثة على واتساب.';
    }
  }, 1000);

  // منع الإرسال المزدوج
  document.getElementById('pinForm').addEventListener('submit', function() {
    submitBtn.disabled = true;
    submitBtn.textContent = 'جارٍ التأكيد...';
  });
</script>
</body>
</html>
